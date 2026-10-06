<?php

namespace Tests\Feature;

use App\Services\DashboardCredentials;
use App\Services\TelemetryEpoch;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\TechnicalAlerts;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoryClearTest extends TestCase
{
    use RefreshDatabase;

    private string $passwordFile;
    private string $hash;
    private int $now;
    private const PASSWORD = 'clear-test-password';
    private const TOKEN = 'history-clear-test-token-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (int) floor(microtime(true) * 1000);
        Carbon::setTestNow(Carbon::createFromTimestampMs($this->now));
        $this->passwordFile = tempnam(sys_get_temp_dir(), 'grandma-clear-test-');
        $this->hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        file_put_contents($this->passwordFile, $this->hash);
        config(['dashboard.password_file' => $this->passwordFile, 'dashboard.timezone' => 'Europe/Minsk',
            'telemetry.token' => self::TOKEN, 'telemetry.device_id' => 'grandma-watch']);
        Http::preventStrayRequests();
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

    private function packet(array $overrides = []): array
    {
        return array_replace([
            'event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch', 'source' => 'measurement',
            'received_at_ms' => $this->now - 1000, 'watch_sent_at_ms' => $this->now - 1500,
            'bpm' => 79, 'measured_at_ms' => $this->now - 2000,
            'battery_percent' => 89, 'charging' => false, 'monitoring_status' => 'active',
        ], $overrides);
    }

    private function upload(array $packet)
    {
        return $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->postJson('/api/v1/events', $packet);
    }

    private function confirm(array $overrides = [])
    {
        return $this->signedIn()->from('/dashboard/clear')->post('/dashboard/clear', array_replace([
            'confirmation' => 'ОЧИСТИТЬ', 'password' => self::PASSWORD, 'generation' => 0,
        ], $overrides));
    }

    public function test_get_only_shows_confirmation_and_never_clears_records(): void
    {
        $this->upload($this->packet())->assertCreated();
        $this->signedIn()->get('/dashboard')->assertOk()->assertSee('Очистить историю');
        $this->get('/dashboard/clear')->assertOk()->assertSee('Удалить все показания')->assertSee('ОЧИСТИТЬ');
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_guest_and_telemetry_token_cannot_clear_history(): void
    {
        $this->upload($this->packet())->assertCreated();
        $this->post('/dashboard/clear', ['confirmation' => 'ОЧИСТИТЬ', 'password' => self::PASSWORD, 'generation' => 0])
            ->assertRedirect('/login');
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_confirmation_word_and_password_are_required_before_any_deletion(): void
    {
        $this->upload($this->packet());
        $this->confirm(['confirmation' => 'да'])->assertRedirect('/dashboard/clear')->assertSessionHasErrors('confirmation');
        $this->confirm(['password' => 'wrong-password'])->assertRedirect('/dashboard/clear')->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('telemetry_epochs', ['generation' => 0, 'cleared_before_ms' => 0]);
    }

    public function test_clear_removes_all_dates_but_preserves_password_members_invites_cursor_and_preferences(): void
    {
        $this->upload($this->packet());
        $this->upload($this->packet(['measured_at_ms' => $this->now - 86_400_000]));
        DB::table('telegram_members')->insert(['user_id' => 1001, 'name' => 'Owner', 'state' => 'active',
            'is_owner' => true, 'alerts_allowed' => true, 'notifications_enabled' => false]);
        DB::table('telegram_invites')->insert(['hash' => str_repeat('a', 64), 'expires_at_ms' => $this->now + 10000]);
        app(BotStore::class)->put('update_id', '42');
        $this->confirm()->assertRedirect('/dashboard')->assertSessionHas('history_cleared.count', 2);
        $this->assertDatabaseCount('watch_events', 0);
        $this->assertDatabaseHas('telemetry_epochs', ['device_id' => 'grandma-watch', 'cleared_before_ms' => $this->now, 'generation' => 1]);
        $this->assertDatabaseHas('telegram_members', ['user_id' => 1001, 'notifications_enabled' => false, 'is_owner' => true]);
        $this->assertDatabaseCount('telegram_invites', 1);
        $this->assertSame('42', app(BotStore::class)->value('update_id'));
        $this->assertSame($this->hash, app(DashboardCredentials::class)->hash());
    }

    public function test_delayed_old_queue_packets_receive_compatible_ack_without_restoring_rows(): void
    {
        $packet = $this->packet();
        $this->upload($packet)->assertCreated();
        $this->confirm();
        foreach ([$packet, $this->packet(['source' => 'heartbeat'])] as $old) {
            $this->upload($old)->assertOk()->assertJsonPath('event_id', $old['event_id'])
                ->assertJsonPath('ignored_before_reset', true)->assertJsonPath('live_contact', false)
                ->assertJsonStructure(['server_received_at_ms']);
        }
        $this->assertDatabaseCount('watch_events', 0);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/v1/status')
            ->assertOk()->assertJsonPath('bpm', null)->assertJsonPath('last_live_contact_at_ms', null);
    }

    public function test_new_heartbeat_keeps_connection_and_battery_without_reintroducing_old_pulse(): void
    {
        $this->confirm();
        $packet = $this->packet(['source' => 'heartbeat', 'watch_sent_at_ms' => $this->now + 1000,
            'received_at_ms' => $this->now + 1100]);
        $this->upload($packet)->assertCreated()->assertJsonPath('live_contact', true);
        $this->assertDatabaseHas('watch_events', ['event_id' => $packet['event_id'], 'bpm' => null,
            'measured_at_ms' => null, 'battery_percent' => 89, 'live_contact' => true]);
        $this->upload($packet)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('watch_events', 1);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/v1/status')->assertJsonPath('bpm', null)
            ->assertJsonPath('contact_recent', true)->assertJsonPath('battery_percent', 89);
    }

    public function test_new_measurements_are_saved_and_normal_retry_conflicts_still_work(): void
    {
        $this->confirm();
        $packet = $this->packet(['measured_at_ms' => $this->now + 1000, 'watch_sent_at_ms' => $this->now + 1100,
            'received_at_ms' => $this->now + 1200, 'bpm' => 67]);
        $this->upload($packet)->assertCreated();
        $this->upload($packet)->assertOk()->assertJsonPath('duplicate', true);
        $this->upload(array_replace($packet, ['bpm' => 68]))->assertStatus(409);
        $this->assertDatabaseHas('watch_events', ['event_id' => $packet['event_id'], 'bpm' => 67]);
    }

    public function test_submitting_an_old_confirmation_twice_cannot_erase_new_data(): void
    {
        $this->confirm();
        $packet = $this->packet(['measured_at_ms' => $this->now + 1000, 'watch_sent_at_ms' => $this->now + 1100,
            'received_at_ms' => $this->now + 1200]);
        $this->upload($packet)->assertCreated();
        $this->confirm()->assertRedirect('/dashboard/clear')->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('telemetry_epochs', ['generation' => 1]);
    }

    public function test_clear_cancels_old_technical_alerts_without_resetting_bot_access_or_polling(): void
    {
        DB::table('telegram_alerts')->insert(['kind' => 'battery', 'active' => true, 'generation' => 4]);
        app(BotStore::class)->enqueue(1001, 'old battery warning', null, 'alert', [
            'alert_kind' => 'battery', 'alert_generation' => 4, 'alert_active' => true,
        ]);
        app(BotStore::class)->enqueue(1001, '', null, 'status');
        app(BotStore::class)->enqueue(1001, 'menu', null, 'normal');
        app(BotStore::class)->put('update_id', '500');
        $this->confirm();
        $this->assertDatabaseHas('telegram_alerts', ['kind' => 'battery', 'active' => false, 'generation' => 5]);
        $this->assertSame(2, DB::table('telegram_outbox')->where('state', 'cancelled')->count());
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'normal', 'state' => 'pending']);
        $this->assertSame('500', app(BotStore::class)->value('update_id'));
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(0, DB::table('telegram_outbox')->where('state', 'pending')->where('purpose', 'alert')->count());
    }

    public function test_clearing_does_not_remove_records_for_another_device(): void
    {
        DB::table('watch_events')->insert($this->packet(['device_id' => 'another-device']) + [
            'server_received_at_ms' => $this->now, 'live_contact' => false, 'payload_hash' => str_repeat('a', 64),
        ]);
        $this->confirm();
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_clear_rejects_missing_csrf_even_with_correct_password_and_word(): void
    {
        $this->upload($this->packet());
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        });
        $this->confirm()->assertStatus(419);
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_clear_rolls_back_the_boundary_and_deleted_records_if_a_later_database_step_fails(): void
    {
        $this->upload($this->packet())->assertCreated();
        // Deliberate failure is confined to RefreshDatabase's guarded :memory: DB.
        \Illuminate\Support\Facades\Schema::drop('telegram_outbox');
        $this->confirm()->assertStatus(500);
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('telemetry_epochs', ['generation' => 0, 'cleared_before_ms' => 0]);
    }

    public function test_password_attempts_are_limited_without_erasing_history(): void
    {
        $this->upload($this->packet());
        for ($i = 0; $i < 5; $i++) {
            $this->confirm(['password' => 'wrong-password'])->assertSessionHasErrors('password');
        }
        $response = $this->confirm();
        $response->assertSessionHasErrors('password');
        $this->assertStringContainsString('Слишком много попыток', session('errors')->first('password'));
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_the_exact_reset_boundary_is_excluded_for_packets_and_cached_measurements(): void
    {
        $this->confirm();
        $this->upload($this->packet(['watch_sent_at_ms' => $this->now, 'received_at_ms' => $this->now]))
            ->assertOk()->assertJsonPath('ignored_before_reset', true);
        $packet = $this->packet(['source' => 'heartbeat', 'measured_at_ms' => $this->now,
            'watch_sent_at_ms' => $this->now + 1, 'received_at_ms' => $this->now + 2]);
        $this->upload($packet)->assertCreated();
        $this->assertDatabaseHas('watch_events', ['event_id' => $packet['event_id'], 'bpm' => null, 'measured_at_ms' => null]);
    }
}
