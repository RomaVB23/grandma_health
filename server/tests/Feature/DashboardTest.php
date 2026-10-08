<?php

namespace Tests\Feature;

use App\Services\DashboardCredentials;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $passwordFile;
    private string $hash;
    private const PASSWORD = 'local-test-password';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06T14:00:00Z');
        $this->passwordFile = tempnam(sys_get_temp_dir(), 'grandma-dashboard-test-');
        $this->hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        file_put_contents($this->passwordFile, $this->hash);
        config(['dashboard.password_file' => $this->passwordFile, 'dashboard.timezone' => 'Europe/Minsk',
            'telemetry.device_id' => 'grandma-watch']);
    }

    protected function tearDown(): void
    {
        if (isset($this->passwordFile) && is_file($this->passwordFile)) { unlink($this->passwordFile); }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signedIn(): static
    {
        return $this->withSession(['dashboard_fingerprint' => hash('sha256', $this->hash),
            'dashboard_last_seen' => now()->timestamp]);
    }

    private function event(array $values = []): int
    {
        $time = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        return DB::table('watch_events')->insertGetId(array_replace([
            'event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch', 'source' => 'measurement',
            'received_at_ms' => $time + 1500, 'watch_sent_at_ms' => $time + 1000,
            'bpm' => 72, 'measured_at_ms' => $time, 'battery_percent' => 90, 'charging' => false,
            'monitoring_status' => 'active', 'server_received_at_ms' => $time + 2000,
            'live_contact' => false, 'payload_hash' => str_repeat('a', 64),
        ], $values));
    }

    public function test_measurement_button_creates_and_resumes_a_correlated_request(): void
    {
        $r = $this->signedIn()->postJson('/dashboard/measurement-requests')->assertStatus(202)->json();
        $this->signedIn()->get('/dashboard')->assertOk()->assertSee('measurement-button')->assertSee($r['id']);
        $this->signedIn()->getJson('/dashboard/measurement-requests/'.$r['id'])->assertOk()->assertJsonPath('status', 'queued');
        $again = $this->signedIn()->postJson('/dashboard/measurement-requests')->assertStatus(202)->json();
        $this->assertSame($r['id'], $again['id']);
    }

    public function test_phone_battery_card_shows_charge_charging_and_staleness_separately_from_watch(): void
    {
        $this->signedIn()->get('/dashboard')->assertOk()->assertSee('Заряд телефона')->assertSee('Ожидаем Honor');
        DB::table('phone_status')->insert(['device_id' => 'grandma-watch', 'battery_percent' => 15,
            'charging' => true, 'snapshot_at_ms' => now()->getTimestampMs(), 'server_received_at_ms' => now()->getTimestampMs()]);
        $html = $this->signedIn()->get('/dashboard')->assertOk()->getContent();
        preg_match('/<section id="phone-battery"(.*?)<\/section>/s', $html, $card);
        $this->assertStringContainsString('tone-red', $card[1]);
        $this->assertStringContainsString('Заряжается', $card[1]);
        $this->assertStringContainsString('15<small>%</small>', $card[1]);
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $html = $this->signedIn()->get('/dashboard')->assertOk()->getContent();
        preg_match('/<section id="phone-battery"(.*?)<\/section>/s', $html, $card);
        $this->assertStringContainsString('tone-neutral', $card[1]);
        $this->assertStringContainsString('Данные устарели', $card[1]);
    }

    public function test_guests_cannot_read_history_even_with_a_telemetry_bearer(): void
    {
        $this->event();
        $this->get('/dashboard')->assertRedirect('/login')->assertDontSeeText('72');
        $this->withHeader('Authorization', 'Bearer test-token')->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Пароль веб-интерфейса')->assertDontSee('test-token');
    }

    public function test_unconfigured_or_malformed_password_disables_dashboard_without_leaking_data(): void
    {
        file_put_contents($this->passwordFile, 'not-a-hash');
        $this->event();
        $this->get('/dashboard')->assertStatus(503)->assertSee('ещё не настроен')
            ->assertDontSeeText('72')->assertViewMissing('status')->assertViewMissing('events');
        $this->post('/login', ['password' => self::PASSWORD])->assertStatus(503);
    }

    public function test_login_and_logout_and_no_store_headers(): void
    {
        $this->post('/login', ['password' => 'wrong-password'])->assertStatus(422)->assertSee('Неверный пароль.');
        $this->post('/login', ['password' => self::PASSWORD])->assertRedirect('/dashboard')
            ->assertSessionHas('dashboard_fingerprint', hash('sha256', $this->hash));
        $this->get('/dashboard')->assertOk()->assertSee('Журнал показаний')->assertSee('За этот период записей нет')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('dashboard_fingerprint');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_wrong_logins_are_rate_limited_and_password_is_not_reflected_or_flashed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['password' => 'do-not-echo-this'])->assertStatus(422)
                ->assertDontSee('do-not-echo-this')->assertSessionMissing('_old_input.password');
        }
        $this->post('/login', ['password' => self::PASSWORD])->assertStatus(429)->assertHeader('Retry-After');
        Carbon::setTestNow(now()->addSeconds(61));
        $this->post('/login', ['password' => self::PASSWORD])->assertRedirect('/dashboard');
    }

    public function test_idle_timeout_and_password_rotation_invalidate_existing_sessions(): void
    {
        $this->signedIn();
        Carbon::setTestNow(now()->addMinutes(30));
        $this->get('/dashboard')->assertRedirect('/login');
        $this->signedIn();
        file_put_contents($this->passwordFile, password_hash('different-password', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_unique_history_and_statistics_do_not_count_heartbeat_pulse_copies(): void
    {
        $first = $this->event();
        $copy = $this->event(['source' => 'heartbeat', 'battery_percent' => 89]);
        $nextTime = Carbon::parse('2026-10-06T12:03:00Z')->getTimestampMs();
        $second = $this->event(['bpm' => 80, 'measured_at_ms' => $nextTime]);
        $this->event(['bpm' => null, 'measured_at_ms' => null, 'source' => 'heartbeat']);
        $this->event(['device_id' => 'other-watch', 'bpm' => 999]);
        $response = $this->signedIn()->get('/dashboard');
        $response->assertOk()->assertViewHas('events', fn ($rows) => $rows->total() === 2
            && $rows->pluck('id')->all() === [$second, $first])
            ->assertViewHas('summary', fn ($s) => $s->count === 2 && $s->min_bpm === 72
                && $s->max_bpm === 80 && (float) $s->avg_bpm === 76.0);
        $this->get('/dashboard?mode=events')->assertOk()->assertSee('Последний известный')
            ->assertViewHas('events', fn ($rows) => $rows->total() === 4 && $rows->pluck('id')->contains($copy));
    }

    public function test_pulse_first_received_in_a_heartbeat_is_not_lost(): void
    {
        $first = $this->event(['source' => 'heartbeat']);
        $this->event(['source' => 'measurement']);
        $this->signedIn()->get('/dashboard')->assertOk()
            ->assertViewHas('events', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $first);
    }

    public function test_chart_requires_dashboard_login_even_with_a_telemetry_token(): void
    {
        $this->event();
        $this->get('/dashboard/pulse-chart')->assertRedirect('/login');
        $this->withHeader('Authorization', 'Bearer test-token')->get('/dashboard/pulse-chart')->assertRedirect('/login');
    }

    public function test_chart_deduplicates_measurements_and_does_not_use_upload_times_or_table_filters(): void
    {
        $time = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        $this->event(['source' => 'heartbeat']);
        $this->event();
        $this->event(['measured_at_ms' => $time + 60_000, 'bpm' => 80]);
        $this->event(['measured_at_ms' => $time - 2 * 86_400_000, 'bpm' => 40]);
        $this->event(['measured_at_ms' => null, 'bpm' => null, 'source' => 'heartbeat']);
        $this->event(['device_id' => 'other-watch', 'bpm' => 999]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=24h&mode=events&source=measurement&page=2')
            ->assertOk()->assertJsonPath('points', [[$time, 72], [$time + 60_000, 80]])
            ->assertJsonPath('timezone', 'Europe/Minsk')->assertJsonPath('gap_ms', 600_000)
            ->assertJsonPath('thresholds', ['lower' => 60, 'upper' => 85, 'enabled' => false])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_chart_custom_period_uses_minsk_time_and_preserves_interval_endpoints(): void
    {
        $start = Carbon::parse('2026-10-06T00:00:00+03:00')->getTimestampMs();
        $end = $start + 60_000;
        $this->event(['measured_at_ms' => $start - 1]);
        $this->event(['measured_at_ms' => $start, 'bpm' => 60]);
        $this->event(['measured_at_ms' => $end, 'bpm' => 85]);
        $this->event(['measured_at_ms' => $end + 1]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T00:00&to=2026-10-06T00:01&gap_minutes=5')
            ->assertOk()->assertJsonPath('from_ms', $start)->assertJsonPath('to_ms', $end)
            ->assertJsonPath('points', [[$start, 60], [$end, 85]])->assertJsonPath('gap_ms', 300_000);
    }

    public function test_chart_hour_window_excludes_future_and_older_measurements(): void
    {
        $now = now()->getTimestampMs();
        $this->event(['measured_at_ms' => $now - 3_600_001]);
        $this->event(['measured_at_ms' => $now - 3_600_000, 'bpm' => 61]);
        $this->event(['measured_at_ms' => $now, 'bpm' => 75]);
        $this->event(['measured_at_ms' => $now + 1]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=1h')->assertOk()
            ->assertJsonPath('points', [[$now - 3_600_000, 61], [$now, 75]]);
        $this->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T16:00&to=2026-10-06T18:00')
            ->assertOk()->assertJsonPath('to_ms', $now)->assertJsonCount(2, 'points');
    }

    public function test_chart_rejects_invalid_or_unbounded_periods(): void
    {
        $this->signedIn();
        foreach (['period=all', 'gap_minutes=0', 'gap_minutes=61', 'period=custom',
            'period=custom&from=2026-02-30T12:00&to=2026-03-01T12:00',
            'period=custom&from=2026-10-06T12:00&to=2026-10-06T11:00',
            'period=custom&from=2026-01-01T00:00&to=2026-10-06T12:00',
            'period=custom&from=2026-10-07T00:00&to=2026-10-07T01:00'] as $query) {
            $this->getJson('/dashboard/pulse-chart?'.$query)->assertUnprocessable();
        }
    }

    public function test_date_filters_use_minsk_midnight_and_inclusive_end_date(): void
    {
        $start = Carbon::parse('2026-10-05T21:00:00Z')->getTimestampMs();
        $this->event(['measured_at_ms' => $start - 1]);
        $begin = $this->event(['measured_at_ms' => $start]);
        $end = $this->event(['measured_at_ms' => $start + 86_400_000 - 1]);
        $this->event(['measured_at_ms' => $start + 86_400_000]);
        $this->signedIn()->get('/dashboard?from=2026-10-06&to=2026-10-06')->assertOk()
            ->assertViewHas('events', fn ($rows) => $rows->pluck('id')->all() === [$end, $begin])
            ->assertSee('06.10.2026 00:00:00')->assertSee('06.10.2026 23:59:59');
        $this->get('/dashboard?from=&to=')->assertViewHas('events', fn ($rows) => $rows->total() === 4);
    }

    public function test_measurement_date_is_distinct_from_upload_date_and_sorts_by_measurement(): void
    {
        $yesterday = Carbon::parse('2026-10-05T12:00:00Z')->getTimestampMs();
        $new = $this->event();
        $old = $this->event(['measured_at_ms' => $yesterday]);
        $this->signedIn()->get('/dashboard')->assertViewHas('events', fn ($rows) => $rows->pluck('id')->all() === [$new]);
        $this->get('/dashboard?mode=events')->assertViewHas('events', fn ($rows) => $rows->total() === 2);
        $this->get('/dashboard?from=&to=')->assertViewHas('events', fn ($rows) => $rows->pluck('id')->all() === [$new, $old]);
    }

    public function test_event_filter_and_pagination_preserve_dates_and_mode(): void
    {
        for ($i = 0; $i < 26; $i++) { $this->event(['source' => 'heartbeat', 'measured_at_ms' => 1791288000000 + $i]); }
        $this->event(['source' => 'measurement']);
        $this->signedIn()->get('/dashboard?mode=events&source=heartbeat&per_page=25&from=&to=')
            ->assertViewHas('events', fn ($rows) => $rows->count() === 25 && $rows->total() === 26
                && str_contains($rows->nextPageUrl(), 'source=heartbeat') && str_contains($rows->nextPageUrl(), 'mode=events'));
        $this->get('/dashboard?mode=events&source=heartbeat&per_page=25&from=&to=&page=2')
            ->assertViewHas('events', fn ($rows) => $rows->count() === 1);
    }

    public function test_invalid_filters_are_rejected_without_running_an_unbounded_history_query(): void
    {
        foreach (['from=2026-02-30', 'mode=unknown', 'per_page=5000', 'from=2026-10-07&to=2026-10-06', 'page=-1'] as $query) {
            $this->signedIn()->from('/dashboard')->get('/dashboard?'.$query)->assertRedirect('/dashboard')->assertSessionHasErrors();
        }
    }

    public function test_views_escape_event_identifiers_and_do_not_expose_authentication_secrets(): void
    {
        $this->event(['event_id' => '<script>alert(1)</script>']);
        $this->signedIn()->get('/dashboard')->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee($this->hash, false)
            ->assertDontSee(self::PASSWORD);
    }

    public function test_login_and_logout_reject_missing_csrf_tokens_outside_the_test_bypass(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->post('/login', ['password' => self::PASSWORD])->assertStatus(419);
        $this->signedIn()->post('/logout')->assertStatus(419);
        $this->signedIn()->postJson('/dashboard/measurement-requests')->assertStatus(419);
        $this->assertDatabaseCount('measurement_requests', 0);
        $this->withSession(['_token' => 'test-csrf-token'])->post('/login', ['password' => self::PASSWORD, '_token' => 'test-csrf-token'])
            ->assertRedirect('/dashboard');
    }

    public function test_password_command_stores_only_a_hash_and_rejects_invalid_confirmation(): void
    {
        $original = file_get_contents($this->passwordFile);
        $this->artisan('dashboard:password')->expectsQuestion('Новый пароль веб-интерфейса (от 12 до 72 байт)', 'short')
            ->assertExitCode(1);
        $this->assertSame($original, file_get_contents($this->passwordFile));
        $this->artisan('dashboard:password')->expectsQuestion('Новый пароль веб-интерфейса (от 12 до 72 байт)', self::PASSWORD)
            ->expectsQuestion('Повторите пароль', 'different')->assertExitCode(1);
        $this->assertSame($original, file_get_contents($this->passwordFile));
        $this->artisan('dashboard:password')->expectsQuestion('Новый пароль веб-интерфейса (от 12 до 72 байт)', self::PASSWORD)
            ->expectsQuestion('Повторите пароль', self::PASSWORD)->assertExitCode(0);
        $saved = app(DashboardCredentials::class)->hash();
        $this->assertNotNull($saved);
        $this->assertTrue(password_verify(self::PASSWORD, $saved));
        $this->assertStringNotContainsString(self::PASSWORD, file_get_contents($this->passwordFile));
    }

    public function test_period_report_counts_distinct_samples_and_measures_gaps_in_measurement_time(): void
    {
        $start = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        foreach ([[1, 72], [6, 80], [20, 64]] as [$minute, $bpm]) {
            $this->event(['measured_at_ms' => $start + $minute * 60_000, 'bpm' => $bpm, 'battery_percent' => null]);
        }
        $this->event(['source' => 'heartbeat', 'measured_at_ms' => $start + 60_000, 'battery_percent' => null]);
        $this->event(['device_id' => 'other-watch', 'bpm' => 200]);
        $this->event(['measured_at_ms' => $start - 1, 'bpm' => 10, 'battery_percent' => null]);
        $this->event(['measured_at_ms' => $start + 30 * 60_000 + 1, 'bpm' => 250, 'battery_percent' => null]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T15:00&to=2026-10-06T15:30&gap_minutes=5')
            ->assertOk()->assertJsonPath('report.pulse', ['count' => 3, 'min_bpm' => 64, 'max_bpm' => 80,
                'mean_bpm' => 72, 'first_at_ms' => $start + 60_000, 'last_at_ms' => $start + 20 * 60_000])
            ->assertJsonPath('report.intervals', ['mean_ms' => 570_000, 'max_ms' => 840_000,
                'max_from_ms' => $start + 6 * 60_000, 'max_to_ms' => $start + 20 * 60_000, 'long_gap_count' => 1])
            ->assertJsonPath('report.edges', ['before_first_ms' => 60_000, 'after_last_ms' => 600_000]);
        // Changing the graph's gap threshold changes only the long-pause count.
        $this->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T15:00&to=2026-10-06T15:30&gap_minutes=15')
            ->assertOk()->assertJsonPath('report.intervals.long_gap_count', 0)->assertJsonPath('report.intervals.max_ms', 840_000);
    }

    public function test_period_report_empty_and_single_sample_do_not_invent_intervals_or_battery_consumption(): void
    {
        $url = '/dashboard/pulse-chart?period=custom&from=2026-10-06T15:00&to=2026-10-06T15:30';
        $this->signedIn()->getJson($url)->assertOk()->assertJsonPath('report.pulse.count', 0)
            ->assertJsonPath('report.pulse.mean_bpm', null)->assertJsonPath('report.intervals.mean_ms', null)
            ->assertJsonPath('report.intervals.max_ms', null)->assertJsonPath('report.edges.before_first_ms', 1_800_000)
            ->assertJsonPath('report.edges.after_last_ms', null)->assertJsonPath('report.battery.first', null)
            ->assertJsonPath('report.battery.delta_pp', null)->assertJsonPath('report.battery.charging_observed', null);
        $start = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        $this->event(['measured_at_ms' => $start + 10 * 60_000, 'watch_sent_at_ms' => $start + 11 * 60_000]);
        $this->getJson($url)->assertOk()->assertJsonPath('report.pulse.count', 1)
            ->assertJsonPath('report.intervals.mean_ms', null)->assertJsonPath('report.intervals.max_ms', null)
            ->assertJsonPath('report.intervals.long_gap_count', 0)
            ->assertJsonPath('report.edges', ['before_first_ms' => 600_000, 'after_last_ms' => 1_200_000])
            ->assertJsonPath('report.battery.snapshot_count', 1)->assertJsonPath('report.battery.delta_pp', null)
            ->assertJsonPath('report.battery.charging_observed', false);
    }

    public function test_period_report_battery_uses_snapshot_time_including_packets_without_pulse_and_charge_sessions(): void
    {
        $start = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        foreach ([[1, 90, false], [10, 100, true], [25, 82, false]] as [$minute, $percent, $charging]) {
            $this->event(['watch_sent_at_ms' => $start + $minute * 60_000, 'battery_percent' => $percent,
                'charging' => $charging, 'measured_at_ms' => null, 'bpm' => null, 'source' => 'heartbeat']);
        }
        // Snapshot duplicates do not inflate the number of observations.
        $this->event(['watch_sent_at_ms' => $start + 60_000, 'battery_percent' => 90]);
        // Earlier snapshot uploaded inside this period is not a current battery sample.
        $this->event(['watch_sent_at_ms' => $start - 1, 'battery_percent' => 5]);
        $this->event(['watch_sent_at_ms' => $start + 30 * 60_000 + 1, 'battery_percent' => 1]);
        $this->event(['device_id' => 'other-watch', 'battery_percent' => 0]);
        $this->event(['battery_percent' => null]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T15:00&to=2026-10-06T15:30')
            ->assertOk()->assertJsonPath('report.battery', ['snapshot_count' => 3,
                'first' => ['at_ms' => $start + 60_000, 'percent' => 90],
                'last' => ['at_ms' => $start + 25 * 60_000, 'percent' => 82],
                'delta_pp' => -8, 'charging_observed' => true]);
    }

    public function test_period_report_preserves_full_charge_zero_charge_and_positive_net_change(): void
    {
        $start = Carbon::parse('2026-10-06T12:00:00Z')->getTimestampMs();
        $this->event(['watch_sent_at_ms' => $start, 'battery_percent' => 0, 'charging' => true]);
        $this->event(['watch_sent_at_ms' => $start + 60_000, 'battery_percent' => 100]);
        $this->signedIn()->getJson('/dashboard/pulse-chart?period=custom&from=2026-10-06T15:00&to=2026-10-06T15:01')
            ->assertOk()->assertJsonPath('report.battery.snapshot_count', 2)
            ->assertJsonPath('report.battery.first.percent', 0)->assertJsonPath('report.battery.last.percent', 100)
            ->assertJsonPath('report.battery.delta_pp', 100)->assertJsonPath('report.battery.charging_observed', true);
    }

}
