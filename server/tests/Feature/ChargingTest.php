<?php

namespace Tests\Feature;

use App\Services\ChargingHistory;
use App\Services\PulseChartData;
use App\Services\TelemetryEpoch;
use App\Services\WatchStatus;
use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\ChartImage;
use App\Services\Telegram\ChartReports;
use App\Services\Telegram\TechnicalAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChargingTest extends TestCase
{
    use RefreshDatabase;
    private const TOKEN = 'charging-test-token-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampMs((int) floor(microtime(true) * 1000)));
        config(['telegram.owner_id' => '1001', 'telegram.token' => 'test:fake',
            'telemetry.token' => self::TOKEN, 'telemetry.device_id' => 'grandma-watch',
            'telemetry.contact_timeout_ms' => 600_000, 'telegram.timezone' => 'Europe/Minsk']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        app(BotStore::class)->ensureOwner();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function packet(array $changes = []): array
    {
        $now = BotStore::now();
        return array_replace(['event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch',
            'source' => 'heartbeat', 'received_at_ms' => $now, 'watch_sent_at_ms' => $now,
            'bpm' => null, 'measured_at_ms' => null, 'battery_percent' => 80, 'charging' => false,
            'monitoring_status' => 'active', 'wearing_state' => 'off', 'wearing_since_ms' => $now - 3_600_000], $changes);
    }

    private function event(bool $charging, ?int $since = null, array $changes = []): void
    {
        DB::table('watch_events')->insert($this->packet(['charging' => $charging, 'charging_since_ms' => $since] + $changes)
            + ['server_received_at_ms' => BotStore::now(), 'live_contact' => true, 'payload_hash' => str_repeat('a', 64)]);
    }

    private function tick(): void { app(TechnicalAlerts::class)->tick(); }

    private function wait(int $minutes, bool $charging, ?int $since = null): void
    {
        for ($i = 0; $i < $minutes; $i++) {
            Carbon::setTestNow(Carbon::now()->addMinute());
            $this->event($charging, $since); $this->tick();
        }
    }

    private function wearingWarnings(): int
    {
        return DB::table('telegram_outbox')->where('alert_kind', 'wearing')->where('alert_active', true)
            ->where('state', 'pending')->count();
    }

    public function test_charging_suppresses_wearing_and_unplugging_starts_a_full_new_delay(): void
    {
        $this->event(false); $this->tick();
        $this->wait(9, false); $this->assertSame(0, $this->wearingWarnings());
        $start = BotStore::now(); $this->event(true, $start); $this->tick();
        $this->wait(20, true, $start);
        $this->assertSame(0, $this->wearingWarnings());
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'charging')->count());
        app(BotDelivery::class)->flush(); Http::assertSentCount(1);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $stop = BotStore::now(); $this->event(false, $stop); $this->tick();
        $this->wait(9, false, $stop); $this->assertSame(0, $this->wearingWarnings());
        $this->wait(1, false, $stop); $this->assertSame(1, $this->wearingWarnings());
        $this->assertSame(2, DB::table('telegram_outbox')->where('purpose', 'charging')->count());
    }

    public function test_configured_delay_and_off_wrist_without_charger_still_work(): void
    {
        DB::table('monitoring_settings')->insert(['device_id' => 'grandma-watch', 'off_wrist_minutes' => 5]);
        $this->event(false); $this->tick(); $this->wait(4, false);
        $this->assertSame(0, $this->wearingWarnings()); $this->wait(1, false);
        $this->assertSame(1, $this->wearingWarnings());
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'charging')->count());
    }

    public function test_a_delayed_live_unplug_packet_uses_the_recorded_end_for_the_new_delay(): void
    {
        $this->event(true, BotStore::now()); $this->tick();
        Carbon::setTestNow(Carbon::now()->addMinutes(12));
        $stop = BotStore::now() - 120_000;
        $this->event(false, $stop); $this->tick();
        $this->wait(7, false, $stop); $this->assertSame(0, $this->wearingWarnings());
        $this->wait(1, false, $stop); $this->assertSame(1, $this->wearingWarnings());
    }

    public function test_charging_cancels_an_old_pending_wearing_warning_without_fake_recovery(): void
    {
        $this->event(false); $this->tick(); $this->wait(10, false);
        $generation = DB::table('telegram_alerts')->where('kind', 'wearing')->value('generation');
        $this->assertSame(1, $this->wearingWarnings());
        $this->event(true, BotStore::now()); $this->tick();
        $this->assertSame(0, $this->wearingWarnings());
        $this->assertSame(0, DB::table('telegram_outbox')->where('alert_kind', 'wearing')->where('alert_active', false)->count());
        $this->assertGreaterThan($generation, DB::table('telegram_alerts')->where('kind', 'wearing')->value('generation'));
        app(BotDelivery::class)->flush(); Http::assertSentCount(1);
    }

    public function test_delivery_rechecks_charging_before_a_queued_wearing_warning(): void
    {
        $this->event(false); $this->tick(); $this->wait(10, false);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->event(true, BotStore::now()); // Delivery happens before the next alert tick.
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        $this->assertSame(0, $this->wearingWarnings());
    }

    public function test_a_fresh_charge_dataitem_suppresses_wearing_without_refreshing_live_contact(): void
    {
        $this->event(false); $this->tick(); $this->wait(10, false);
        $contact = app(WatchStatus::class)->snapshot()['last_live_contact_at_ms'];
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $this->event(true, BotStore::now(), ['live_contact' => false]);
        $s = app(WatchStatus::class)->snapshot();
        $this->assertSame($contact, $s['last_live_contact_at_ms']);
        $this->assertSame('charging', $s['charging_state']);
        $this->tick(); $this->assertSame(0, $this->wearingWarnings());
    }

    public function test_charging_notices_respect_preferences_and_pulse_is_ineligible_on_charger(): void
    {
        DB::table('telegram_members')->update(['notifications_enabled' => false]);
        DB::table('monitoring_settings')->insert(['device_id' => 'grandma-watch', 'pulse_enabled' => true]);
        $this->event(true, BotStore::now(), ['wearing_state' => 'on', 'bpm' => 150, 'measured_at_ms' => BotStore::now()]);
        $this->tick(); $this->wait(12, true);
        $this->assertSame(0, DB::table('telegram_outbox')->count());
        $this->assertFalse(app(WatchStatus::class)->snapshot()['pulse_eligible']);
    }

    public function test_stale_charging_does_not_create_a_fresh_notice_or_an_unplug_event(): void
    {
        $this->event(true, BotStore::now()); $this->tick();
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->assertSame('unknown', app(WatchStatus::class)->snapshot()['charging_state']);
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        $this->assertSame('cancelled', DB::table('telegram_outbox')->where('purpose', 'charging')->value('state'));
    }

    public function test_history_uses_snapshot_time_and_closes_a_recorded_offline_charging_session(): void
    {
        $end = BotStore::now(); $start = $end - 6 * 3_600_000;
        $a = $start + 30 * 60_000; $b = $start + 3 * 3_600_000;
        $this->event(true, $a, ['watch_sent_at_ms' => $a, 'received_at_ms' => $end,
            'bpm' => 73, 'measured_at_ms' => $start + 1_000, 'live_contact' => false]);
        $this->event(false, $b, ['watch_sent_at_ms' => $b, 'received_at_ms' => $end, 'live_contact' => false]);
        $chart = app(PulseChartData::class)->build($start, $end, 'Europe/Minsk');
        $this->assertSame([[$a, $b]], $chart['charging']['intervals']);
        $this->assertSame($b - $a, $chart['charging']['total_ms']);
        $this->assertSame([[$start + 1_000, 73]], $chart['points']);
        $this->assertSame(1, $chart['report']['pulse']['count']);
    }

    public function test_unknown_telemetry_does_not_colour_an_entire_outage_as_charging(): void
    {
        $end = BotStore::now(); $start = $end - 3_600_000;
        $this->event(true, null, ['watch_sent_at_ms' => $start]);
        $this->assertSame([[$start, $start + 600_000]], app(ChargingHistory::class)->build($start, $end)['intervals']);
        $this->event(false, null, ['watch_sent_at_ms' => $end]);
        $this->assertSame([[$start, $start + 600_000]], app(ChargingHistory::class)->build($start, $end)['intervals']);
    }

    public function test_same_session_timestamp_spans_delayed_snapshots_and_clips_the_window(): void
    {
        $end = BotStore::now(); $start = $end - 3_600_000; $since = $start - 60_000;
        $this->event(true, $since, ['watch_sent_at_ms' => $start - 10_000]);
        $this->event(true, $since, ['watch_sent_at_ms' => $end - 60_000]);
        $this->assertSame([[$start, $end]], app(ChargingHistory::class)->build($start, $end)['intervals']);
    }

    public function test_conflicting_snapshots_are_not_charging_evidence(): void
    {
        $end = BotStore::now(); $start = $end - 3_600_000;
        $this->event(true, $start, ['watch_sent_at_ms' => $start]);
        $this->event(false, $start, ['watch_sent_at_ms' => $start]);
        $this->assertSame([], app(ChargingHistory::class)->build($start, $end)['intervals']);
    }

    public function test_api_stores_transition_and_historical_delivery_does_not_become_live_contact(): void
    {
        $p = $this->packet(['charging' => true, 'charging_since_ms' => BotStore::now() - 10_000, 'live_heartbeat' => false]);
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $p)->assertCreated()->assertJsonPath('live_contact', false);
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->withToken(self::TOKEN)->getJson('/api/v1/history')->assertOk()
            ->assertJsonPath('events.0.charging_since_ms', $p['charging_since_ms']);
        $this->assertSame('unknown', app(WatchStatus::class)->snapshot()['charging_state']);
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $this->packet(['charging_since_ms' => BotStore::now() + 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('charging_since_ms');
    }

    public function test_clear_history_cancels_charge_notices_and_removes_charge_intervals(): void
    {
        $this->event(true, BotStore::now()); $this->tick();
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'charging')->where('state', 'pending')->count());
        $this->assertSame([], app(ChargingHistory::class)->build(BotStore::now() - 3_600_000, BotStore::now())['intervals']);
    }

    public function test_a_post_reset_packet_cannot_restore_a_pre_reset_charging_interval(): void
    {
        $oldStart = BotStore::now() - 3_600_000;
        $this->event(true, $oldStart); $this->tick();
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $at = BotStore::now();
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $this->packet(['charging' => true, 'charging_since_ms' => $oldStart]))
            ->assertCreated();
        $this->assertNull(DB::table('watch_events')->value('charging_since_ms'));
        $this->assertSame([[$at, $at + 60_000]], app(ChargingHistory::class)->build($oldStart, $at + 60_000)['intervals']);
    }

    public function test_png_shows_orange_charging_without_pulse_and_six_and_twelve_hour_captions_explain_it(): void
    {
        $end = BotStore::now(); $start = $end - 6 * 3_600_000;
        $this->event(true, $start + 3_600_000, ['watch_sent_at_ms' => $start + 3_600_000]);
        $this->event(false, $start + 2 * 3_600_000, ['watch_sent_at_ms' => $start + 2 * 3_600_000]);
        $chart = app(ChartReports::class)->build($start, $end, 'Europe/Minsk', 6);
        $png = app(ChartImage::class)->render($chart); $image = imagecreatefromstring($png);
        $this->assertSame(['red' => 246, 'green' => 214, 'blue' => 168, 'alpha' => 0], imagecolorsforindex($image, imagecolorat($image, 370, 350)));
        $this->assertSame(['red' => 238, 'green' => 241, 'blue' => 247, 'alpha' => 0], imagecolorsforindex($image, imagecolorat($image, 850, 350)));
        imagedestroy($image);
        foreach ([6, 12] as $hours) {
            $caption = app(ChartReports::class)->caption($chart + ['hours' => $hours]);
            $this->assertStringContainsString('Оранжевым — часы на зарядке', $caption);
            $this->assertLessThanOrEqual(1024, mb_strlen($caption));
        }
    }
}
