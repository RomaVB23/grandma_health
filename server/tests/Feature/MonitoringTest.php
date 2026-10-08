<?php

namespace Tests\Feature;

use App\Services\MonitoringSettings;
use App\Services\TelemetryEpoch;
use App\Services\WatchStatus;
use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\TechnicalAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private int $since;
    private string $passwordFile;
    private string $hash;
    private const TOKEN = 'monitoring-test-token-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestampMs((int) floor(microtime(true) * 1000)));
        $this->since = BotStore::now() - 3_600_000;
        config(['telemetry.token' => self::TOKEN, 'telegram.owner_id' => '1001', 'telegram.token' => 'test:fake',
            'telegram.timezone' => 'Europe/Minsk']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        app(BotStore::class)->ensureOwner();
        DB::table('monitoring_settings')->insert(['device_id' => 'grandma-watch', 'pulse_enabled' => true]);
        $this->passwordFile = tempnam(sys_get_temp_dir(), 'monitoring-test-');
        $this->hash = password_hash('monitoring-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        file_put_contents($this->passwordFile, $this->hash);
        config(['dashboard.password_file' => $this->passwordFile]);
    }

    protected function tearDown(): void
    {
        if (isset($this->passwordFile)) unlink($this->passwordFile);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function packet(array $changes = []): array
    {
        $now = BotStore::now();
        return array_replace(['event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch',
            'source' => 'heartbeat', 'received_at_ms' => $now, 'watch_sent_at_ms' => $now,
            'bpm' => 73, 'measured_at_ms' => $now - 1000, 'battery_percent' => 80, 'charging' => false,
            'monitoring_status' => 'active', 'wearing_state' => 'on', 'wearing_since_ms' => $this->since], $changes);
    }

    private function event(array $changes = []): void
    {
        DB::table('watch_events')->insert($this->packet($changes) + [
            'server_received_at_ms' => BotStore::now(), 'live_contact' => true, 'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function sample(int $bpm, array $changes = []): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->event(['bpm' => $bpm] + $changes);
        $this->tick();
    }

    private function tick(): void { app(TechnicalAlerts::class)->tick(); }

    private function waitWithLiveHeartbeats(int $minutes): void
    {
        for ($i = 0; $i < $minutes; $i++) {
            Carbon::setTestNow(Carbon::now()->addMinute());
            $this->event(['bpm' => null, 'measured_at_ms' => null]);
            $this->tick();
        }
    }

    public static function confirmationCounts(): array
    {
        return ['two' => [2], 'three' => [3], 'four' => [4], 'five' => [5]];
    }

    #[DataProvider('confirmationCounts')]
    public function test_sparse_fresh_samples_confirm_warning_and_recovery_for_any_count(int $count): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => $count]);
        for ($i = 1; $i <= $count; $i++) {
            if ($i > 1) $this->waitWithLiveHeartbeats(7);
            $this->sample(90);
            $this->assertSame($i === $count ? 1 : 0, $this->alerts());
            $this->tick(); $this->tick();
            $this->assertSame($i === $count ? 1 : 0, $this->alerts());
        }
        app(BotDelivery::class)->flush();
        Http::assertSentCount(1);
        $this->waitWithLiveHeartbeats(7); $this->sample(91);
        $this->assertSame(1, $this->alerts()); // Saturated counts do not spam.
        for ($i = 1; $i <= $count; $i++) {
            $this->waitWithLiveHeartbeats(7); $this->sample(73);
            $this->assertSame($i === $count ? 1 : 0, $this->alerts('pulse', false));
        }
        app(BotDelivery::class)->flush(); $this->tick();
        Http::assertSentCount(2);
        $this->assertSame(2, $this->alerts());
    }

    #[DataProvider('confirmationCounts')]
    public function test_an_in_range_sample_or_excessive_gap_restarts_any_confirmation_count(int $count): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => $count]);
        for ($i = 1; $i < $count; $i++) $this->sample(90);
        $this->sample(73);
        for ($i = 1; $i < $count; $i++) $this->sample(91);
        $this->assertSame(0, $this->alerts());
        $this->waitWithLiveHeartbeats(16);
        $this->sample(92); $this->assertSame(0, $this->alerts());
        for ($i = 1; $i < $count; $i++) $this->sample(93);
        $this->assertSame(1, $this->alerts());
    }

    public function test_exact_gap_boundary_is_inclusive_and_delayed_fresh_confirmation_uses_measurement_time(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 3]);
        $this->sample(90); $first = BotStore::now() - 1000;
        $this->waitWithLiveHeartbeats(15);
        $this->event(['bpm' => 91, 'measured_at_ms' => $first + 900_000]); $this->tick();
        $this->assertSame(0, $this->alerts());
        $this->waitWithLiveHeartbeats(16); // Last pulse is stale; live contact stays fresh.
        $this->event(['source' => 'measurement', 'live_contact' => false, 'bpm' => 92,
            'measured_at_ms' => $first + 2 * 900_000, 'received_at_ms' => BotStore::now() - 60_000,
            'watch_sent_at_ms' => BotStore::now() - 60_000]);
        $this->tick(); $this->assertSame(1, $this->alerts());
    }

    public function test_delayed_queue_cannot_complete_a_sparse_confirmation_series(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        $this->sample(90); $this->waitWithLiveHeartbeats(7);
        $this->event(['source' => 'measurement', 'live_contact' => false, 'bpm' => 91,
            'measured_at_ms' => BotStore::now() - 180_000, 'received_at_ms' => BotStore::now() - 180_000,
            'watch_sent_at_ms' => BotStore::now() - 180_000]);
        $this->tick(); $this->assertSame(0, $this->alerts());
        $this->sample(92); $this->assertSame(0, $this->alerts());
        $this->sample(93); $this->assertSame(1, $this->alerts());
    }

    public function test_restart_retains_sparse_candidate_and_heartbeat_duplicates_do_not_advance_it(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 3]);
        $this->sample(90); $measured = BotStore::now() - 1000;
        $this->waitWithLiveHeartbeats(7);
        app()->forgetInstance(TechnicalAlerts::class);
        $this->event(['bpm' => 90, 'measured_at_ms' => $measured]); $this->tick();
        $this->assertSame(0, $this->alerts());
        $this->sample(91); $this->assertSame(0, $this->alerts());
        $this->waitWithLiveHeartbeats(7); $this->sample(92);
        $this->assertSame(1, $this->alerts());
    }

    public static function interruptions(): array
    {
        return ['removed' => ['off'], 'unknown' => ['unknown'], 'stopped' => ['stopped'], 'disconnected' => ['lost']];
    }

    #[DataProvider('interruptions')]
    public function test_removal_unknown_stopped_or_lost_contact_resets_a_sparse_candidate(string $reason): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        $this->sample(90);
        if ($reason === 'lost') {
            Carbon::setTestNow(Carbon::now()->addMinutes(11)); $this->tick();
        } else {
            $this->sample(73, ['wearing_state' => in_array($reason, ['off', 'unknown']) ? $reason : 'on',
                'wearing_since_ms' => $reason === 'unknown' ? null : $this->since,
                'monitoring_status' => $reason === 'stopped' ? 'stopped' : 'active']);
        }
        $this->sample(91); $this->assertSame(0, $this->alerts());
        $this->sample(92); $this->assertSame(1, $this->alerts());
    }

    public function test_another_outside_sample_interrupts_recovery_confirmation_without_new_warning(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 3]);
        $this->sample(90); $this->sample(91); $this->sample(92); app(BotDelivery::class)->flush();
        $this->sample(73); $this->waitWithLiveHeartbeats(7); $this->sample(74);
        $this->sample(91); $this->assertSame(1, $this->alerts());
        $this->sample(73); $this->waitWithLiveHeartbeats(7); $this->sample(74);
        $this->assertSame(0, $this->alerts('pulse', false));
        $this->waitWithLiveHeartbeats(7); $this->sample(75);
        $this->assertSame(1, $this->alerts('pulse', false));
        $this->assertSame(2, $this->alerts());
    }

    public function test_new_wearing_session_resets_confirmation_even_if_removal_was_between_ticks(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        $this->sample(90); $this->waitWithLiveHeartbeats(7);
        $this->since = BotStore::now();
        $this->sample(91); $this->assertSame(0, $this->alerts());
        $this->sample(92); $this->assertSame(1, $this->alerts());
    }

    public function test_confirmation_gap_migration_preserves_rules_history_members_and_candidate(): void
    {
        $this->sample(90);
        $state = DB::table('monitoring_state')->value('state');
        $migration = require database_path('migrations/2026_10_06_000004_add_confirmation_gap_to_monitoring_settings.php');
        $migration->down(); $migration->up();
        $this->assertSame(15, app(MonitoringSettings::class)->get()->confirmation_gap_minutes);
        $this->assertTrue((bool) app(MonitoringSettings::class)->get()->pulse_enabled);
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('telegram_members', ['user_id' => 1001]);
        $this->assertSame($state, DB::table('monitoring_state')->value('state'));
    }

    public function test_settings_validate_and_persist_confirmation_gap_independently_of_freshness(): void
    {
        $this->signedIn()->get('/dashboard/monitoring')->assertOk()->assertSee('Максимальный интервал');
        foreach ([0, 121, 'bad', null] as $invalid) {
            $this->post('/dashboard/monitoring', $this->form(['confirmation_gap_minutes' => $invalid]))
                ->assertSessionHasErrors('confirmation_gap_minutes');
        }
        $this->post('/dashboard/monitoring', $this->form(['confirmation_gap_minutes' => 20, 'confirmation_samples' => 5,
            'pulse_max_age_seconds' => 300]))->assertRedirect('/dashboard/monitoring');
        $this->assertDatabaseHas('monitoring_settings', ['confirmation_gap_minutes' => 20, 'confirmation_samples' => 5,
            'pulse_max_age_seconds' => 300]);
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertSame(20, app(MonitoringSettings::class)->get()->confirmation_gap_minutes);
    }

    private function alerts(string $kind = 'pulse', ?bool $active = null): int
    {
        return DB::table('telegram_outbox')->where('alert_kind', $kind)
            ->when($active !== null, fn ($q) => $q->where('alert_active', $active))->count();
    }

    private function signedIn(): static
    {
        return $this->withSession(['dashboard_fingerprint' => hash('sha256', $this->hash), 'dashboard_last_seen' => now()->timestamp]);
    }

    private function form(array $changes = []): array
    {
        return array_replace(['pulse_enabled' => 1, 'pulse_lower' => 60, 'pulse_upper' => 85,
            'night_pulse_lower' => 60, 'night_pulse_upper' => 85, 'night_start' => '23:00', 'night_end' => '08:00',
            'profile_timezone' => 'Europe/Minsk',
            'confirmation_samples' => 1, 'pulse_max_age_seconds' => 300, 'confirmation_gap_minutes' => 15, 'wearing_enabled' => 1,
            'off_wrist_minutes' => 10, 'version' => 0], $changes);
    }

    public function test_installation_starts_with_pulse_disabled_and_user_boundaries(): void
    {
        DB::table('monitoring_settings')->delete();
        $r = app(MonitoringSettings::class)->get();
        $this->assertFalse($r->pulse_enabled); $this->assertSame(60, $r->pulse_lower); $this->assertSame(85, $r->pulse_upper);
        $this->sample(110); $this->assertSame(0, $this->alerts());
    }

    public function test_api_preserves_wearing_and_is_idempotent(): void
    {
        $p = $this->packet();
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $p)->assertCreated();
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()->assertJsonPath('wearing_state', 'on');
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_pre_upgrade_queue_hash_is_compatible_with_retry_after_migration(): void
    {
        $p = $this->packet(); unset($p['wearing_state'], $p['wearing_since_ms']);
        DB::table('watch_events')->insert($p + ['server_received_at_ms' => BotStore::now(), 'live_contact' => true,
            'payload_hash' => hash('sha256', json_encode($p, JSON_THROW_ON_ERROR))]);
        $this->withToken(self::TOKEN)->postJson('/api/v1/events', $p)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('watch_events', ['wearing_state' => 'unknown']);
    }

    public function test_api_rejects_inconsistent_wearing_and_future_transition_time(): void
    {
        foreach ([['wearing_since_ms' => null], ['wearing_state' => 'unknown'],
            ['wearing_since_ms' => BotStore::now() + 1], ['wearing_state' => 'invalid']] as $bad) {
            $this->withToken(self::TOKEN)->postJson('/api/v1/events', $this->packet($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('watch_events', 0);
    }

    public function test_wearing_without_fresh_live_heartbeat_is_unknown(): void
    {
        $this->event(['source' => 'measurement', 'live_contact' => false]);
        $this->assertSame('unknown', app(WatchStatus::class)->snapshot()['wearing_state']);
        $this->sample(73);
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $s = app(WatchStatus::class)->snapshot();
        $this->assertSame('unknown', $s['wearing_state']); $this->assertSame('on', $s['last_known_wearing_state']);
    }

    public function test_boundaries_are_included_in_range(): void
    {
        $this->sample(60); $this->sample(85);
        $this->assertSame(0, $this->alerts());
        $this->assertSame('in_range', app(WatchStatus::class)->snapshot()['pulse_control_status']);
        $this->sample(59); $this->assertSame(1, $this->alerts());
    }

    public function test_high_pulse_also_triggers_warning(): void
    {
        $this->sample(86); $this->assertSame(1, $this->alerts());
        $this->assertStringContainsString('86 уд/мин', DB::table('telegram_outbox')->where('alert_kind', 'pulse')->value('body'));
    }

    public function test_heartbeat_copies_and_worker_ticks_do_not_confirm_another_measurement(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        $this->sample(90); $measured = BotStore::now() - 1000;
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(Carbon::now()->addSeconds(2));
            $this->event(['bpm' => 90, 'measured_at_ms' => $measured]); $this->tick();
        }
        $this->assertSame(0, $this->alerts());
        $this->sample(91); $this->assertSame(1, $this->alerts());
    }

    public function test_two_confirmations_must_be_consecutive_and_within_configured_gap(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2, 'confirmation_gap_minutes' => 5]);
        $this->sample(90); $this->sample(73); $this->sample(90);
        $this->assertSame(0, $this->alerts());
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->sample(91); $this->assertSame(0, $this->alerts());
        $this->sample(92); $this->assertSame(1, $this->alerts());
    }

    public function test_same_episode_not_spammed_and_recovery_goes_only_to_warned_people(): void
    {
        $this->sample(90); $this->tick(); $this->sample(100);
        $this->assertSame(1, $this->alerts());
        app(BotDelivery::class)->flush();
        $this->sample(85); $this->tick();
        $this->assertSame(2, $this->alerts());
        app(BotDelivery::class)->flush();
        $this->assertSame(2, DB::table('telegram_outbox')->where('alert_kind', 'pulse')->where('state', 'sent')->count());
        $this->sample(59); $this->assertSame(3, $this->alerts());
    }

    public function test_transition_between_low_and_high_is_one_unresolved_episode(): void
    {
        $this->sample(59); $this->sample(110);
        $this->assertSame(1, $this->alerts());
        $this->assertSame(0, $this->alerts('pulse', false));
    }

    public function test_undelivered_warning_that_resolved_is_not_sent(): void
    {
        $this->sample(90); $this->sample(73); app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertSame(0, $this->alerts('pulse', false));
    }

    public function test_two_enabled_contacts_receive_one_warning_each_and_one_recovery(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Second', 'state' => 'active',
            'alerts_allowed' => true, 'notifications_enabled' => true]);
        DB::table('telegram_members')->insert(['user_id' => 3003, 'name' => 'Viewer', 'state' => 'active']);
        $this->sample(90); $this->tick(); $this->assertSame(2, $this->alerts());
        app(BotDelivery::class)->flush(); Carbon::setTestNow(Carbon::now()->addSeconds(2)); app(BotDelivery::class)->flush();
        $this->sample(73); $this->assertSame(4, $this->alerts());
        app(BotDelivery::class)->flush(); Carbon::setTestNow(Carbon::now()->addSeconds(2)); app(BotDelivery::class)->flush();
        Http::assertSentCount(4);
        $this->assertDatabaseMissing('telegram_outbox', ['user_id' => 3003, 'alert_kind' => 'pulse']);
    }

    public function test_old_queue_and_old_sample_cannot_create_warning(): void
    {
        $this->sample(90, ['received_at_ms' => BotStore::now() - 600_000,
            'watch_sent_at_ms' => BotStore::now() - 600_000, 'live_contact' => false]);
        $this->sample(90, ['measured_at_ms' => BotStore::now() - 600_000]);
        $this->assertSame(0, $this->alerts());
    }

    public function test_recent_measurement_from_a_delayed_queue_is_also_excluded(): void
    {
        $this->event(['bpm' => null, 'measured_at_ms' => null]); $this->tick();
        $this->event(['bpm' => 90, 'source' => 'measurement', 'live_contact' => false,
            'received_at_ms' => BotStore::now() - 180_000, 'watch_sent_at_ms' => BotStore::now() - 180_000,
            'measured_at_ms' => BotStore::now() - 180_000]);
        $this->tick();
        $this->assertSame(0, $this->alerts());
    }

    public function test_removed_unknown_or_stopped_watch_does_not_raise_pulse_warning(): void
    {
        $this->sample(90, ['wearing_state' => 'off']);
        $this->sample(91, ['wearing_state' => 'unknown', 'wearing_since_ms' => null]);
        $this->sample(92, ['monitoring_status' => 'stopped']);
        $this->assertSame(0, $this->alerts());
    }

    public function test_sample_before_putting_watch_back_on_is_not_current_pulse(): void
    {
        $this->sample(90, ['wearing_since_ms' => BotStore::now() + 2000]);
        $this->assertSame(0, $this->alerts());
        $this->assertFalse(app(WatchStatus::class)->snapshot()['pulse_eligible']);
        $this->sample(90); $this->assertSame(1, $this->alerts());
    }

    public function test_lost_contact_does_not_mean_pulse_recovery(): void
    {
        $this->sample(90); app(BotDelivery::class)->flush();
        Carbon::setTestNow(Carbon::now()->addMinutes(11)); $this->tick();
        $this->assertSame(0, $this->alerts('pulse', false));
        $this->assertDatabaseHas('telegram_alerts', ['kind' => 'pulse', 'active' => true]);
    }

    public function test_pending_warning_is_rechecked_before_delivery_after_staleness_or_removal(): void
    {
        $this->sample(90); Carbon::setTestNow(Carbon::now()->addMinutes(6)); app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['alert_kind' => 'pulse', 'state' => 'cancelled']);
        $this->sample(91); $this->event(['wearing_state' => 'off', 'measured_at_ms' => null, 'bpm' => null]);
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
    }

    public function test_restarting_alert_service_does_not_repeat_warning(): void
    {
        $this->sample(90); app(BotDelivery::class)->flush();
        app()->forgetInstance(TechnicalAlerts::class); $this->tick(); $this->sample(91);
        $this->assertSame(1, $this->alerts());
    }

    public function test_off_wrist_warning_requires_continuous_live_reports_then_recovers_once(): void
    {
        DB::table('monitoring_settings')->update(['off_wrist_minutes' => 1]);
        $this->sample(73, ['wearing_state' => 'off']);
        $this->assertSame(0, $this->alerts('wearing'));
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->event(['wearing_state' => 'off']); $this->tick(); $this->tick();
        $this->assertSame(1, $this->alerts('wearing')); app(BotDelivery::class)->flush();
        $this->sample(73); $this->assertSame(2, $this->alerts('wearing'));
    }

    public function test_off_wrist_timeout_is_restarted_after_unknown_or_disconnected_state(): void
    {
        DB::table('monitoring_settings')->update(['off_wrist_minutes' => 1]);
        $this->sample(73, ['wearing_state' => 'off']);
        Carbon::setTestNow(Carbon::now()->addSeconds(45));
        $this->sample(73, ['wearing_state' => 'unknown', 'wearing_since_ms' => null]);
        $this->sample(73, ['wearing_state' => 'off']);
        Carbon::setTestNow(Carbon::now()->addSeconds(30)); $this->tick();
        $this->assertSame(0, $this->alerts('wearing'));
        Carbon::setTestNow(Carbon::now()->addMinutes(11)); $this->tick();
        $this->sample(73, ['wearing_state' => 'off']);
        $this->assertSame(0, $this->alerts('wearing'));
    }

    public function test_unknown_wearing_does_not_send_recovery_of_off_wrist_warning(): void
    {
        DB::table('monitoring_settings')->update(['off_wrist_minutes' => 1]);
        $this->sample(73, ['wearing_state' => 'off']); Carbon::setTestNow(Carbon::now()->addMinute()); $this->tick();
        app(BotDelivery::class)->flush(); $this->sample(73, ['wearing_state' => 'unknown', 'wearing_since_ms' => null]);
        $this->assertSame(0, $this->alerts('wearing', false));
    }

    public function test_settings_are_protected_and_validate_ranges_and_version(): void
    {
        $this->post('/dashboard/monitoring', $this->form())->assertRedirect('/login');
        $this->signedIn()->get('/dashboard/monitoring')->assertOk()->assertSee('Контроль показаний');
        $this->from('/dashboard/monitoring')->post('/dashboard/monitoring', $this->form(['pulse_upper' => 50]))
            ->assertSessionHasErrors('pulse_upper');
        $this->post('/dashboard/monitoring', $this->form(['confirmation_samples' => 0]))->assertSessionHasErrors('confirmation_samples');
        $this->post('/dashboard/monitoring', $this->form())->assertRedirect('/dashboard/monitoring');
        $this->post('/dashboard/monitoring', $this->form(['pulse_upper' => 120]))->assertSessionHasErrors('version');
        $this->assertSame(85, app(MonitoringSettings::class)->get()->pulse_upper);
    }

    public function test_enabling_or_changing_settings_does_not_reuse_old_measurement_or_claim_recovery(): void
    {
        $this->sample(90); app(BotDelivery::class)->flush();
        $this->signedIn()->post('/dashboard/monitoring', $this->form(['pulse_upper' => 80]))->assertRedirect();
        $this->tick(); $this->assertSame(1, $this->alerts());
        $this->assertSame(0, $this->alerts('pulse', false));
        $this->sample(90); $this->assertSame(2, $this->alerts());
    }

    public function test_history_clear_preserves_rules_and_resets_candidates_and_pending_alerts(): void
    {
        $this->sample(90);
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertDatabaseCount('monitoring_state', 0);
        $this->assertTrue((bool) app(MonitoringSettings::class)->get()->pulse_enabled);
        $this->assertDatabaseHas('telegram_outbox', ['alert_kind' => 'pulse', 'state' => 'cancelled']);
        $this->tick(); $this->assertSame(0, $this->alerts('pulse', false));
    }

    public function test_late_live_heartbeat_cannot_regress_wearing_state(): void
    {
        $this->event(['wearing_state' => 'off']);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->event(['watch_sent_at_ms' => BotStore::now() - 5000, 'wearing_state' => 'on']);
        $this->assertSame('off', app(WatchStatus::class)->snapshot()['wearing_state']);
    }

    public function test_changing_notification_opt_out_before_delivery_is_respected(): void
    {
        $this->sample(90);
        DB::table('telegram_members')->where('user_id', 1001)->update(['notifications_enabled' => false]);
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        DB::table('telegram_members')->where('user_id', 1001)->update(['notifications_enabled' => true]);
        $this->tick(); app(BotDelivery::class)->flush(); Http::assertSentCount(1);
    }

    public function test_disabling_controls_cancels_pending_messages_and_preserves_history(): void
    {
        $this->sample(90);
        $this->signedIn()->post('/dashboard/monitoring', $this->form(['pulse_enabled' => 0, 'wearing_enabled' => 0]))->assertRedirect();
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertFalse((bool) app(MonitoringSettings::class)->get()->pulse_enabled);
        $this->assertFalse((bool) app(MonitoringSettings::class)->get()->wearing_enabled);
    }

    public function test_migration_retains_existing_events_and_their_original_payload_hash(): void
    {
        $migration = require database_path('migrations/2026_10_06_000003_add_wearing_and_monitoring_settings.php');
        $migration->down();
        $p = $this->packet(); unset($p['wearing_state'], $p['wearing_since_ms']);
        $hash = hash('sha256', json_encode($p, JSON_THROW_ON_ERROR));
        DB::table('watch_events')->insert($p + ['server_received_at_ms' => BotStore::now(), 'live_contact' => true, 'payload_hash' => $hash]);
        $migration->up();
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('watch_events', ['event_id' => $p['event_id'], 'bpm' => 73, 'payload_hash' => $hash, 'wearing_state' => 'unknown']);
        $this->assertFalse((bool) app(MonitoringSettings::class)->get()->pulse_enabled);
    }

    public function test_several_samples_received_between_worker_ticks_use_latest_confirmed_state(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        $this->event(['bpm' => 90, 'measured_at_ms' => BotStore::now() - 4000]);
        $this->event(['bpm' => 91, 'measured_at_ms' => BotStore::now() - 3000]);
        $this->event(['bpm' => 73, 'measured_at_ms' => BotStore::now() - 2000]);
        $this->event(['bpm' => 74, 'measured_at_ms' => BotStore::now() - 1000]);
        $this->tick(); app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_alerts', ['kind' => 'pulse', 'active' => false]);
    }

    public function test_settings_reject_post_without_csrf_token(): void
    {
        $class = \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class;
        $this->app->instance($class, new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->signedIn()->post('/dashboard/monitoring', $this->form(['pulse_upper' => 120]))->assertStatus(419);
        $this->assertSame(85, app(MonitoringSettings::class)->get()->pulse_upper);
    }
}
