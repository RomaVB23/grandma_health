<?php

namespace Tests\Feature;

use App\Services\TelemetryEpoch;
use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\StatusText;
use App\Services\Telegram\TechnicalAlerts;
use App\Services\WatchStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneStatusTest extends TestCase
{
    use RefreshDatabase;
    private const TOKEN = 'phone-status-test-token-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T11:00:00Z');
        config(['telemetry.token' => self::TOKEN, 'telemetry.device_id' => 'grandma-watch',
            'telegram.owner_id' => '1001', 'telegram.token' => 'local-test-token']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(array $changes = [])
    {
        return $this->withToken(self::TOKEN)->postJson('/api/v1/phone-status', array_replace([
            'device_id' => 'grandma-watch', 'battery_percent' => 72,
            'charging' => false, 'snapshot_at_ms' => BotStore::now(),
        ], $changes));
    }

    private function subscribers(): void
    {
        app(BotStore::class)->ensureOwner();
        DB::table('telegram_members')->insert([
            ['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active', 'alerts_allowed' => true, 'notifications_enabled' => true],
            ['user_id' => 3003, 'name' => 'Muted', 'state' => 'active', 'alerts_allowed' => true, 'notifications_enabled' => false],
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function deliver(): void
    {
        for ($i = 0; $i < 4; $i++) {
            app(BotDelivery::class)->flush();
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
        }
    }

    public function test_phone_endpoint_requires_token_and_configured_device(): void
    {
        $this->postJson('/api/v1/phone-status', [])->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/v1/phone-status', [])->assertUnauthorized();
        $this->report(['device_id' => 'other-phone'])->assertUnprocessable();
        $this->assertDatabaseCount('phone_status', 0);
    }

    public function test_phone_status_never_creates_pulse_watch_contact_or_history(): void
    {
        $this->report()->assertNoContent();
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('phone_battery_percent', 72)->assertJsonPath('phone_charging', false)
            ->assertJsonPath('phone_battery_stale', false)->assertJsonPath('bpm', null)
            ->assertJsonPath('battery_percent', null)->assertJsonPath('contact_recent', false)
            ->assertJsonPath('last_upload_at_ms', null)->assertJsonPath('last_live_contact_at_ms', null);
        $this->assertDatabaseCount('watch_events', 0);
    }

    public function test_missing_phone_data_is_explicit_and_older_watch_clients_remain_supported(): void
    {
        $s = app(WatchStatus::class)->snapshot();
        $this->assertNull($s['phone_battery_percent']);
        $this->assertNull($s['phone_charging']);
        $this->assertTrue($s['phone_battery_stale']);
        $this->assertStringContainsString('Заряд телефона: нет данных', app(StatusText::class)->format($s));
    }

    public function test_bad_percent_missing_fields_and_future_timestamps_are_rejected(): void
    {
        foreach ([['battery_percent' => -1], ['battery_percent' => 101], ['battery_percent' => null],
            ['battery_percent' => 42.5], ['charging' => null], ['charging' => 'unknown'],
            ['snapshot_at_ms' => 0], ['snapshot_at_ms' => BotStore::now() + 120_001]] as $bad) {
            $this->report($bad)->assertUnprocessable();
        }
        $this->withToken(self::TOKEN)->postJson('/api/v1/phone-status', [])->assertUnprocessable();
        $this->assertDatabaseCount('phone_status', 0);
    }

    public function test_zero_and_full_battery_are_real_values(): void
    {
        $this->report(['battery_percent' => 0])->assertNoContent();
        $this->assertSame(0, app(WatchStatus::class)->snapshot()['phone_battery_percent']);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['battery_percent' => 100, 'charging' => true])->assertNoContent();
        $s = app(WatchStatus::class)->snapshot();
        $this->assertSame(100, $s['phone_battery_percent']);
        $this->assertTrue($s['phone_charging']);
        $this->assertStringContainsString('Заряд телефона: 100% · заряжается', app(StatusText::class)->format($s));
    }

    public function test_late_and_duplicate_requests_do_not_replace_or_freshen_phone_state(): void
    {
        $first = BotStore::now();
        $this->report()->assertNoContent();
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['snapshot_at_ms' => $first - 1, 'battery_percent' => 10])->assertNoContent();
        $this->report(['snapshot_at_ms' => $first])->assertNoContent();
        $this->assertDatabaseCount('phone_status', 1);
        $s = app(WatchStatus::class)->snapshot();
        $this->assertSame(72, $s['phone_battery_percent']);
        $this->assertSame($first, $s['phone_last_upload_at_ms']);
    }

    public function test_delayed_sample_and_expired_sample_are_marked_stale(): void
    {
        $this->report(['snapshot_at_ms' => BotStore::now() - 600_000])->assertNoContent();
        $this->assertTrue(app(WatchStatus::class)->snapshot()['phone_battery_stale']);
        $this->report()->assertNoContent();
        $this->assertFalse(app(WatchStatus::class)->snapshot()['phone_battery_stale']);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $s = app(WatchStatus::class)->snapshot();
        $this->assertTrue($s['phone_battery_stale']);
        $this->assertStringContainsString('данные устарели', app(StatusText::class)->format($s));
    }

    public function test_phone_alert_is_deduplicated_has_hysteresis_and_recovers_only_for_notified_members(): void
    {
        $this->subscribers();
        $this->report(['battery_percent' => 20])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(2, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->count());
        $this->assertDatabaseMissing('telegram_outbox', ['user_id' => 3003]);
        $this->deliver();
        $this->report(['battery_percent' => 24])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(2, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->count());
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['battery_percent' => 25])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        app(TechnicalAlerts::class)->tick();
        $this->deliver();
        $this->assertSame(4, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->where('state', 'sent')->count());
        $this->assertDatabaseMissing('telegram_outbox', ['user_id' => 3003, 'alert_active' => false]);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['battery_percent' => 21])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(4, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->count());
    }

    public function test_charging_suppresses_warning_and_recovers_even_at_low_percent(): void
    {
        $this->subscribers();
        $this->report(['battery_percent' => 5, 'charging' => true])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->assertDatabaseMissing('telegram_outbox', ['alert_kind' => 'phone_battery']);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['battery_percent' => 5])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->deliver();
        $this->report(['battery_percent' => 6, 'charging' => true])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->deliver();
        $this->assertSame(4, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->where('state', 'sent')->count());
    }

    public function test_stale_phone_data_does_not_start_or_resolve_an_incident_or_deliver_old_alerts(): void
    {
        $this->subscribers();
        $this->report(['battery_percent' => 5, 'snapshot_at_ms' => BotStore::now() - 600_000])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->assertDatabaseMissing('telegram_outbox', ['alert_kind' => 'phone_battery']);
        $this->report(['battery_percent' => 5])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        app(TechnicalAlerts::class)->tick();
        $this->deliver();
        $this->assertDatabaseHas('telegram_alerts', ['kind' => 'phone_battery', 'active' => true]);
        $this->assertSame(0, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->where('state', 'sent')->count());
        $this->report(['battery_percent' => 50])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(0, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->where('alert_active', false)->count());
    }

    public function test_resolved_undelivered_phone_incident_does_not_send_warning_or_recovery(): void
    {
        $this->subscribers();
        $this->report(['battery_percent' => 20])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report(['battery_percent' => 25])->assertNoContent();
        app(TechnicalAlerts::class)->tick();
        $this->deliver();
        $this->assertSame(0, DB::table('telegram_outbox')->where('alert_kind', 'phone_battery')->where('state', 'sent')->count());
    }

    public function test_history_reset_clears_phone_state_and_blocks_pre_reset_reports_but_keeps_members(): void
    {
        $this->subscribers();
        $old = BotStore::now();
        $this->report()->assertNoContent();
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertDatabaseCount('phone_status', 0);
        $this->report(['snapshot_at_ms' => $old])->assertNoContent();
        $this->assertDatabaseCount('phone_status', 0);
        $this->assertDatabaseCount('telegram_members', 3);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->report()->assertNoContent();
        $this->assertSame(72, app(WatchStatus::class)->snapshot()['phone_battery_percent']);
    }
}
