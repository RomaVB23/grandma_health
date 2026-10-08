<?php

namespace Tests\Feature;

use App\Services\MonitoringSettings;
use App\Services\TelemetryEpoch;
use App\Services\WatchStatus;
use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\StatusText;
use App\Services\Telegram\TechnicalAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NightModeTest extends TestCase
{
    use RefreshDatabase;
    private string $passwordFile;
    private string $hash;
    private int $update = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T12:00:00Z'); // 15:00 Minsk
        config(['telegram.owner_id' => '1001', 'telegram.token' => 'test:fake', 'telegram.timezone' => 'Europe/Minsk']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        app(BotStore::class)->ensureOwner();
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active']);
        // Synthetic test limits, never a clinical recommendation or production configuration.
        DB::table('monitoring_settings')->insert(['device_id' => 'grandma-watch', 'pulse_enabled' => true,
            'pulse_lower' => 60, 'pulse_upper' => 85, 'night_pulse_lower' => 45, 'night_pulse_upper' => 100]);
        $this->passwordFile = tempnam(sys_get_temp_dir(), 'night-mode-test-');
        $this->hash = password_hash('night-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        file_put_contents($this->passwordFile, $this->hash);
        config(['dashboard.password_file' => $this->passwordFile]);
    }

    protected function tearDown(): void
    {
        if (isset($this->passwordFile)) unlink($this->passwordFile);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signedIn(): static
    {
        return $this->withSession(['dashboard_fingerprint' => hash('sha256', $this->hash), 'dashboard_last_seen' => now()->timestamp]);
    }

    private function mode(string $mode, ?int $version = null): void
    {
        app(MonitoringSettings::class)->setMode($mode, app(TelemetryEpoch::class),
            $version ?? app(MonitoringSettings::class)->get()->changed_at_ms);
    }

    private function press(string $action, int $user = 1001): void
    {
        app(BotHandler::class)->handle(['update_id' => $this->update++, 'callback_query' => ['id' => 'test',
            'from' => ['id' => $user, 'is_bot' => false, 'first_name' => 'Tester'],
            'message' => ['chat' => ['id' => $user, 'type' => 'private']], 'data' => $action]]);
    }

    private function sample(?int $bpm, ?int $measured = null, string $wearing = 'on'): void
    {
        $now = BotStore::now();
        DB::table('watch_events')->insert(['event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch',
            'source' => 'heartbeat', 'received_at_ms' => $now, 'watch_sent_at_ms' => $now,
            'server_received_at_ms' => $now, 'bpm' => $bpm, 'measured_at_ms' => $bpm === null ? null : ($measured ?? $now - 1000),
            'battery_percent' => 80, 'charging' => false, 'monitoring_status' => 'active',
            'wearing_state' => $wearing, 'wearing_since_ms' => $now - 86400000,
            'live_contact' => true, 'payload_hash' => str_repeat('a', 64)]);
        app(TechnicalAlerts::class)->tick();
    }

    private function alerts(?bool $active = null): int
    {
        return DB::table('telegram_outbox')->where('alert_kind', 'pulse')
            ->when($active !== null, fn ($q) => $q->where('alert_active', $active))->count();
    }

    private function form(array $changes = []): array
    {
        return array_replace(['pulse_enabled' => 1, 'wearing_enabled' => 1, 'pulse_lower' => 60, 'pulse_upper' => 85,
            'night_pulse_lower' => 45, 'night_pulse_upper' => 100, 'night_start' => '23:00', 'night_end' => '08:00',
            'profile_timezone' => 'Europe/Minsk', 'confirmation_samples' => 2, 'pulse_max_age_seconds' => 300,
            'confirmation_gap_minutes' => 15, 'off_wrist_minutes' => 10,
            'version' => app(MonitoringSettings::class)->get()->changed_at_ms], $changes);
    }

    public static function scheduleCases(): array
    {
        return [
            'before night' => ['2026-10-08T19:59:59Z', 'day', '2026-10-08T20:00:00Z'],
            'night begins' => ['2026-10-08T20:00:00Z', 'night', '2026-10-09T05:00:00Z'],
            'after midnight' => ['2026-10-09T00:00:00Z', 'night', '2026-10-09T05:00:00Z'],
            'before morning' => ['2026-10-09T04:59:59Z', 'night', '2026-10-09T05:00:00Z'],
            'morning begins' => ['2026-10-09T05:00:00Z', 'day', '2026-10-09T20:00:00Z'],
        ];
    }

    #[DataProvider('scheduleCases')]
    public function test_schedule_crosses_midnight_with_exact_local_boundaries(string $now, string $profile, string $next): void
    {
        Carbon::setTestNow($now);
        $r = app(MonitoringSettings::class)->effective();
        $this->assertSame($profile, $r->effective_profile);
        $this->assertSame('auto', $r->effective_mode);
        $this->assertSame(Carbon::parse($next)->getTimestampMs(), $r->profile_until_ms);
        $this->assertSame($profile === 'night' ? 45 : 60, $r->pulse_lower);
    }

    public function test_patient_timezone_is_independent_of_server_timezone_and_daytime_interval_works(): void
    {
        DB::table('monitoring_settings')->update(['profile_timezone' => 'Europe/Kaliningrad', 'night_start' => '08:00', 'night_end' => '20:00']);
        Carbon::setTestNow('2026-10-08T06:00:00Z'); // 08:00 Kaliningrad, 09:00 Minsk
        $this->assertSame('night', app(MonitoringSettings::class)->effective()->effective_profile);
        $this->assertSame(Carbon::parse('2026-10-08T18:00:00Z')->getTimestampMs(), app(MonitoringSettings::class)->effective()->profile_until_ms);
        Carbon::setTestNow('2026-10-08T18:00:00Z');
        $this->assertSame('day', app(MonitoringSettings::class)->effective()->effective_profile);
    }

    public function test_manual_night_expires_at_next_boundary_and_survives_service_restart(): void
    {
        $this->mode('night');
        $until = Carbon::parse('2026-10-08T20:00:00Z')->getTimestampMs();
        $this->assertSame($until, app(MonitoringSettings::class)->effective()->effective_override_until_ms);
        app()->forgetInstance(MonitoringSettings::class);
        $this->assertSame('night', app(MonitoringSettings::class)->effective()->effective_mode);
        Carbon::setTestNow('2026-10-08T20:00:00Z');
        $r = app(MonitoringSettings::class)->effective();
        $this->assertSame('auto', $r->effective_mode);
        $this->assertSame('night', $r->effective_profile);
        $this->assertNull($r->effective_override_until_ms);
    }

    public function test_manual_day_during_night_expires_in_morning_and_auto_button_returns_immediately(): void
    {
        Carbon::setTestNow('2026-10-08T21:00:00Z');
        $this->mode('day');
        $this->assertSame(60, app(MonitoringSettings::class)->effective()->pulse_lower);
        $this->assertSame(Carbon::parse('2026-10-09T05:00:00Z')->getTimestampMs(), app(MonitoringSettings::class)->effective()->effective_override_until_ms);
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->mode('auto');
        $this->assertSame(45, app(MonitoringSettings::class)->effective()->pulse_lower);
        $this->assertNull(app(MonitoringSettings::class)->get()->profile_override_until_ms);
    }

    public function test_telegram_switch_is_visible_in_web_and_web_switch_is_visible_in_status(): void
    {
        $this->press('mode:night:0');
        $html = $this->signedIn()->get('/dashboard/monitoring')->assertOk()->getContent();
        $this->assertStringContainsString('Ночной · вручную', $html);
        $this->assertStringContainsString('45–100', $html);
        $version = app(MonitoringSettings::class)->get()->changed_at_ms;
        Carbon::setTestNow(Carbon::now()->addSecond());
        $this->signedIn()->post('/dashboard/monitoring/mode', ['mode' => 'day', 'version' => $version])->assertRedirect('/dashboard/monitoring');
        $s = app(WatchStatus::class)->snapshot();
        $this->assertSame('day', $s['pulse_mode']);
        $this->assertSame(60, $s['pulse_lower']);
        $text = app(StatusText::class)->format($s);
        $this->assertStringContainsString('Дневной · вручную', $text);
        $this->assertStringContainsString('Возврат к расписанию:', $text);
    }

    public function test_chart_reference_lines_follow_current_profile_even_for_historical_period(): void
    {
        $this->mode('night');
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=24h')->assertOk()
            ->assertJsonPath('thresholds', ['lower' => 45, 'upper' => 100, 'enabled' => true])
            ->assertJsonPath('threshold_profile', 'night')->assertJsonPath('threshold_mode', 'night');
        $this->mode('auto');
        $this->getJson('/dashboard/pulse-chart?period=24h')->assertOk()
            ->assertJsonPath('thresholds.lower', 60)->assertJsonPath('threshold_profile', 'day');
    }

    public function test_schedule_follows_local_time_across_daylight_saving_changes(): void
    {
        DB::table('monitoring_settings')->update(['profile_timezone' => 'Europe/Berlin']);
        foreach ([['2026-03-28T23:00:00Z', '2026-03-29T06:00:00Z'],
            ['2026-10-24T23:00:00Z', '2026-10-25T07:00:00Z']] as [$now, $end]) {
            Carbon::setTestNow($now);
            $r = app(MonitoringSettings::class)->effective();
            $this->assertSame('night', $r->effective_profile);
            $this->assertSame(Carbon::parse($end)->getTimestampMs(), $r->profile_until_ms);
            Carbon::setTestNow($end);
            $this->assertSame('day', app(MonitoringSettings::class)->effective()->effective_profile);
        }
    }

    public function test_viewers_unknown_users_groups_and_stale_buttons_cannot_change_mode(): void
    {
        $this->press('mode:night:0', 2002);
        $this->press('mode:night:0', 3003);
        $this->assertSame('auto', app(MonitoringSettings::class)->get()->profile_mode);
        $this->press('mode:night:0');
        $version = app(MonitoringSettings::class)->get()->changed_at_ms;
        $this->press('mode:day:0');
        $this->assertSame($version, app(MonitoringSettings::class)->get()->changed_at_ms);
        $this->assertSame('night', app(MonitoringSettings::class)->effective()->effective_profile);
        $this->assertDatabaseHas('telegram_outbox', ['user_id' => 1001, 'state' => 'pending']);
        app(BotHandler::class)->handle(['update_id' => $this->update++, 'callback_query' => ['id' => 'group',
            'from' => ['id' => 1001, 'is_bot' => false], 'message' => ['chat' => ['id' => -1, 'type' => 'group']],
            'data' => 'mode:day:'.$version]]);
        $this->assertSame($version, app(MonitoringSettings::class)->get()->changed_at_ms);
    }

    public function test_web_mode_requires_dashboard_login_valid_mode_version_and_csrf(): void
    {
        $this->post('/dashboard/monitoring/mode', ['mode' => 'night', 'version' => 0])->assertRedirect('/login');
        $this->signedIn()->post('/dashboard/monitoring/mode', ['mode' => 'off', 'version' => 0])->assertSessionHasErrors('mode');
        $this->post('/dashboard/monitoring/mode', ['mode' => 'night', 'version' => 1])->assertSessionHasErrors('version');
        $class = \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class;
        $this->app->instance($class, new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->post('/dashboard/monitoring/mode', ['mode' => 'night', 'version' => 0])->assertStatus(419);
        $this->assertSame('auto', app(MonitoringSettings::class)->get()->profile_mode);
    }

    public function test_web_validates_night_bounds_times_and_timezone_without_silently_changing_them(): void
    {
        $this->signedIn();
        foreach ([['night_pulse_lower' => 0], ['night_pulse_upper' => 44], ['night_start' => '25:00'],
            ['night_end' => '23:00'], ['profile_timezone' => 'Not/AZone']] as $bad) {
            $response = $this->post('/dashboard/monitoring', $this->form($bad));
            $response->assertSessionHasErrors(array_key_first($bad));
        }
        $this->post('/dashboard/monitoring', $this->form(['night_start' => '22:00', 'night_end' => '07:30']))->assertRedirect();
        $this->assertDatabaseHas('monitoring_settings', ['night_start' => '22:00', 'night_end' => '07:30', 'night_pulse_lower' => 45]);
    }

    public function test_schedule_edit_recomputes_manual_expiry_and_does_not_reactivate_expired_override(): void
    {
        $this->mode('night');
        $this->signedIn()->post('/dashboard/monitoring', $this->form(['night_start' => '22:00']))->assertRedirect();
        $this->assertSame(Carbon::parse('2026-10-08T19:00:00Z')->getTimestampMs(), app(MonitoringSettings::class)->effective()->effective_override_until_ms);
        Carbon::setTestNow('2026-10-09T10:00:00Z');
        $this->signedIn()->post('/dashboard/monitoring', $this->form(['night_start' => '22:00']))->assertRedirect();
        $this->assertSame('auto', app(MonitoringSettings::class)->effective()->effective_mode);
        $this->assertNull(app(MonitoringSettings::class)->get()->profile_override_until_ms);
    }

    public function test_night_uses_its_own_bounds_and_new_evidence_is_required_at_switch(): void
    {
        Carbon::setTestNow('2026-10-08T19:59:59Z');
        $this->sample(47); $this->assertSame(1, $this->alerts(true));
        app(BotDelivery::class)->flush();
        Carbon::setTestNow('2026-10-08T20:00:01Z');
        app(TechnicalAlerts::class)->tick();
        $this->assertFalse(app(WatchStatus::class)->snapshot()['pulse_eligible']);
        $this->assertSame(0, $this->alerts(false));
        Carbon::setTestNow('2026-10-08T20:00:03Z');
        $this->sample(47); $this->assertSame(1, $this->alerts());
        Carbon::setTestNow('2026-10-08T20:00:05Z');
        $this->sample(44); $this->assertSame(2, $this->alerts(true));
        $this->assertStringContainsString('Профиль: Ночной', DB::table('telegram_outbox')->where('alert_kind', 'pulse')->orderByDesc('id')->value('body'));
    }

    public function test_confirmations_never_mix_profiles_or_old_copies_at_boundary(): void
    {
        DB::table('monitoring_settings')->update(['confirmation_samples' => 2]);
        Carbon::setTestNow('2026-10-08T19:59:59Z');
        $this->sample(110); $old = BotStore::now() - 1000;
        Carbon::setTestNow('2026-10-08T20:00:02Z');
        $this->sample(110, $old); $this->assertSame(0, $this->alerts());
        Carbon::setTestNow('2026-10-08T20:00:04Z');
        $this->sample(110); $this->assertSame(0, $this->alerts());
        Carbon::setTestNow('2026-10-08T20:00:06Z');
        $this->sample(111); $this->assertSame(1, $this->alerts());
    }

    public function test_future_sample_in_next_profile_is_not_counted_early(): void
    {
        Carbon::setTestNow('2026-10-08T19:59:59Z');
        $this->sample(110, Carbon::parse('2026-10-08T20:00:01Z')->getTimestampMs());
        $this->assertFalse(app(WatchStatus::class)->snapshot()['pulse_eligible']);
        $this->assertSame(0, $this->alerts());
    }

    public function test_pending_day_warning_cannot_be_delivered_after_boundary_even_before_alert_worker_ticks(): void
    {
        Carbon::setTestNow('2026-10-08T19:59:59Z'); $this->sample(110);
        Carbon::setTestNow('2026-10-08T20:00:03Z');
        // Insert fresh evidence without ticking: delivery must still reject the old-profile message.
        DB::table('watch_events')->update(['measured_at_ms' => BotStore::now() - 1000,
            'watch_sent_at_ms' => BotStore::now(), 'received_at_ms' => BotStore::now(), 'server_received_at_ms' => BotStore::now()]);
        app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['alert_kind' => 'pulse', 'state' => 'cancelled']);
    }

    public function test_manual_switch_does_not_reset_wearing_timer_or_technical_alerts_or_enable_disabled_pulse(): void
    {
        $this->sample(null, wearing: 'off');
        $started = json_decode(DB::table('monitoring_state')->value('state'), true)['off_started_at'];
        DB::table('telegram_alerts')->updateOrInsert(['kind' => 'battery'], ['active' => true, 'generation' => 1]);
        $this->mode('night');
        $this->assertSame($started, json_decode(DB::table('monitoring_state')->value('state'), true)['off_started_at']);
        $this->assertDatabaseHas('telegram_alerts', ['kind' => 'battery', 'active' => true, 'generation' => 1]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10)); $this->sample(null, wearing: 'off');
        $this->assertDatabaseHas('telegram_outbox', ['alert_kind' => 'wearing']);
        DB::table('monitoring_settings')->update(['pulse_enabled' => false]);
        $this->mode('day');
        $this->assertFalse((bool) app(MonitoringSettings::class)->get()->pulse_enabled);
    }

    public function test_migration_copies_existing_day_limits_and_preserves_enablement_members_and_history_reset_keeps_profiles(): void
    {
        $migration = require database_path('migrations/2026_10_08_000002_add_monitoring_profiles.php');
        $migration->down();
        DB::table('monitoring_settings')->update(['pulse_lower' => 55, 'pulse_upper' => 95]);
        $migration->up();
        $r = app(MonitoringSettings::class)->get();
        $this->assertSame(55, $r->night_pulse_lower); $this->assertSame(95, $r->night_pulse_upper);
        $this->assertTrue((bool) $r->pulse_enabled);
        $this->mode('night');
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertSame('night', app(MonitoringSettings::class)->effective()->effective_mode);
        $this->assertSame(55, app(MonitoringSettings::class)->get()->night_pulse_lower);
        $this->assertDatabaseCount('telegram_members', 2);
    }

    public function test_menu_exposes_mode_buttons_only_to_owner(): void
    {
        $handler = app(BotHandler::class);
        $this->assertStringContainsString('Режим контроля', json_encode($handler->menu(1001), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Режим контроля', json_encode($handler->menu(2002), JSON_UNESCAPED_UNICODE));
        $this->press('mode');
        $markup = json_decode(DB::table('telegram_outbox')->where('user_id', 1001)->orderByDesc('id')->value('markup'), true);
        $this->assertSame('mode:night:0', $markup['inline_keyboard'][2][0]['callback_data']);
    }
}
