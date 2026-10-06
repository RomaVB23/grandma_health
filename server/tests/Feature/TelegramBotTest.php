<?php

namespace Tests\Feature;

use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\TechnicalAlerts;
use App\Services\Telegram\TelegramApi;
use App\Services\Telegram\TelegramApiException;
use App\Services\WatchStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUpdate = 1;
    private array $snapshot;
    private const OWNER = 1001;
    private const VIEWER = 2002;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-06T11:00:00Z'));
        config(['telegram.owner_id' => (string) self::OWNER, 'telegram.token' => '123456:local-test-token',
            'telegram.username' => 'GrandmaTestBot', 'telegram.timezone' => 'Europe/Minsk']);
        Http::preventStrayRequests();
        app(BotStore::class)->ensureOwner();
        $this->snapshot = [
            'server_time_ms' => BotStore::now(), 'last_live_contact_at_ms' => null, 'contact_recent' => false,
            'bpm' => null, 'measured_at_ms' => null, 'measurement_age_ms' => null, 'measurement_stale' => true,
            'battery_percent' => null, 'charging' => null, 'snapshot_at_ms' => null, 'monitoring_status' => 'unknown',
        ];
        $status = Mockery::mock(WatchStatus::class);
        $status->shouldReceive('snapshot')->andReturnUsing(fn () => $this->snapshot);
        app()->instance(WatchStatus::class, $status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function update(int $user, string $text = '', ?string $callback = null): array
    {
        $from = ['id' => $user, 'is_bot' => false, 'first_name' => 'User '.$user];
        $message = ['from' => $from, 'chat' => ['id' => $user, 'type' => 'private'], 'text' => $text];
        return ['update_id' => $this->nextUpdate++] + ($callback === null ? ['message' => $message]
            : ['callback_query' => ['id' => 'button', 'from' => $from, 'message' => $message, 'data' => $callback]]);
    }

    private function act(int $user, string $action): void
    {
        app(BotHandler::class)->handle($this->update($user, callback: $action));
    }

    private function invite(): string
    {
        $this->act(self::OWNER, 'invite');
        $body = DB::table('telegram_outbox')->orderByDesc('id')->value('body');
        $this->assertSame(1, preg_match('/join_([A-Za-z0-9_-]{43})/', $body, $match));
        return $match[1];
    }

    private function join(string $token, int $user = self::VIEWER): void
    {
        app(BotHandler::class)->handle($this->update($user, '/start join_'.$token));
    }

    private function activate(int $user = self::VIEWER, bool $alerts = false): void
    {
        DB::table('telegram_members')->insert(['user_id' => $user, 'name' => 'Viewer', 'state' => 'active',
            'alerts_allowed' => $alerts, 'notifications_enabled' => $alerts]);
    }

    private function successApi(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_unknown_users_cannot_become_owner_or_view_health(): void
    {
        app(BotHandler::class)->handle($this->update(self::VIEWER, '/start'));
        $this->act(self::VIEWER, 'status');
        $this->assertDatabaseMissing('telegram_members', ['user_id' => self::VIEWER]);
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'status')->count());
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::OWNER, 'is_owner' => true]);
    }

    public function test_replayed_updates_do_not_duplicate_invites_or_messages(): void
    {
        $update = $this->update(self::OWNER, callback: 'invite');
        app(BotHandler::class)->handle($update);
        app(BotHandler::class)->handle($update);
        $this->assertSame(1, DB::table('telegram_invites')->count());
        $this->assertSame(1, DB::table('telegram_outbox')->count());
    }

    public function test_failed_effects_roll_back_cursor_and_invite(): void
    {
        $store = Mockery::mock(BotStore::class)->makePartial();
        $store->shouldReceive('enqueue')->andThrow(new \RuntimeException('simulated queue failure'));
        try {
            (new BotHandler($store))->handle($this->update(self::OWNER, callback: 'invite'));
            $this->fail('Expected failure');
        } catch (\RuntimeException) {
            $this->assertSame(0, DB::table('telegram_invites')->count());
            $this->assertSame('', app(BotStore::class)->value('update_id'));
        }
    }

    public function test_invitation_is_hashed_single_use_and_requires_owner_approval(): void
    {
        $token = $this->invite();
        $this->assertDatabaseHas('telegram_invites', ['hash' => hash('sha256', $token)]);
        $this->join($token);
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'pending']);
        $this->act(self::VIEWER, 'status');
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'status')->count());
        $this->join($token, 3003);
        $this->assertDatabaseMissing('telegram_members', ['user_id' => 3003]);
        $this->act(self::OWNER, 'approve:'.self::VIEWER.':1');
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'active', 'alerts_allowed' => false]);
        $this->act(self::VIEWER, 'status');
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'status')->count());
    }

    public function test_expired_invite_cannot_register_a_user(): void
    {
        $token = $this->invite();
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->join($token);
        $this->assertDatabaseMissing('telegram_members', ['user_id' => self::VIEWER]);
    }

    public function test_viewer_cannot_forge_admin_actions_or_enable_unpermitted_alerts(): void
    {
        $this->activate();
        $this->activate(3003);
        $this->act(self::VIEWER, 'revoke:3003:1');
        $this->act(self::VIEWER, 'alerts:'.self::VIEWER.':1');
        $this->act(self::VIEWER, 'invite');
        $this->act(self::VIEWER, 'notifications:toggle');
        $this->assertDatabaseHas('telegram_members', ['user_id' => 3003, 'state' => 'active']);
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'alerts_allowed' => false, 'notifications_enabled' => false]);
        $this->assertSame(0, DB::table('telegram_invites')->count());
    }

    public function test_groups_and_mismatched_chat_id_are_ignored(): void
    {
        foreach (['group', 'private'] as $type) {
            $u = $this->update(self::OWNER, callback: 'status');
            $u['callback_query']['message']['chat'] = ['id' => -555, 'type' => $type];
            app(BotHandler::class)->handle($u);
        }
        $this->assertSame(0, DB::table('telegram_outbox')->count());
    }

    public function test_removal_needs_confirmation_cancels_queued_health_and_old_buttons(): void
    {
        $this->activate();
        $this->act(self::VIEWER, 'status');
        $this->act(self::OWNER, 'remove:'.self::VIEWER.':1');
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'active']);
        $this->act(self::OWNER, 'revoke:'.self::VIEWER.':1');
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'revoked']);
        $this->assertDatabaseHas('telegram_outbox', ['user_id' => self::VIEWER, 'purpose' => 'status', 'state' => 'cancelled']);
        $this->act(self::VIEWER, 'status');
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'status')->where('state', 'pending')->count());
        $token = $this->invite();
        $this->join($token);
        $this->act(self::OWNER, 'approve:'.self::VIEWER.':1');
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'pending', 'version' => 3]);
        $this->act(self::OWNER, 'approve:'.self::VIEWER.':3');
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::VIEWER, 'state' => 'active']);
    }

    public function test_owner_cannot_remove_himself_and_restart_preserves_notification_choice(): void
    {
        $this->act(self::OWNER, 'revoke:'.self::OWNER.':1');
        $this->act(self::OWNER, 'notifications:toggle');
        app(BotStore::class)->ensureOwner();
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::OWNER, 'state' => 'active', 'notifications_enabled' => false]);
    }

    public function test_delivery_rechecks_access_even_when_message_is_already_queued(): void
    {
        $this->activate();
        $this->act(self::VIEWER, 'status');
        DB::table('telegram_members')->where('user_id', self::VIEWER)->update(['state' => 'revoked']);
        $this->successApi();
        app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'status', 'state' => 'cancelled']);
    }

    public function test_status_buttons_generate_current_data_at_delivery_time(): void
    {
        $this->act(self::OWNER, 'status');
        $this->snapshot['bpm'] = 77;
        $this->snapshot['measured_at_ms'] = BotStore::now() - 1000;
        $this->snapshot['measurement_age_ms'] = 1000;
        $this->snapshot['measurement_stale'] = false;
        $this->successApi();
        app(BotDelivery::class)->flush();
        Http::assertSent(fn ($r) => str_contains($r['text'], '77 уд/мин') && isset($r['reply_markup']['inline_keyboard']));
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'status', 'state' => 'sent']);
    }

    public function test_network_failure_retains_message_and_does_not_expose_token(): void
    {
        $this->act(self::OWNER, 'status');
        Http::fake(fn () => throw new ConnectionException('secret token: '.config('telegram.token')));
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_outbox', ['state' => 'pending', 'attempts' => 1]);
        try {
            app(TelegramApi::class)->call('getMe');
            $this->fail('Expected failure');
        } catch (TelegramApiException $e) {
            $this->assertStringNotContainsString(config('telegram.token'), (string) $e);
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_telegram_rate_limit_defers_retry_instead_of_losing_message(): void
    {
        $this->act(self::OWNER, 'status');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429,
            'parameters' => ['retry_after' => 42]], 429)]);
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_outbox', ['state' => 'pending', 'available_at_ms' => BotStore::now() + 42_000]);
    }

    public function test_contact_warning_is_deduplicated_and_recovery_is_sent_once(): void
    {
        $this->snapshot['last_live_contact_at_ms'] = BotStore::now() - 900_000;
        $this->snapshot['contact_recent'] = false;
        app(TechnicalAlerts::class)->tick();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'alert')->count());
        $this->successApi();
        app(BotDelivery::class)->flush();
        $this->snapshot['contact_recent'] = true;
        app(TechnicalAlerts::class)->tick();
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(2, DB::table('telegram_outbox')->where('purpose', 'alert')->count());
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        app(BotDelivery::class)->flush();
        $this->assertSame(2, DB::table('telegram_outbox')->where('state', 'sent')->count());
        $this->snapshot['contact_recent'] = false;
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(3, DB::table('telegram_outbox')->where('purpose', 'alert')->count());
    }

    public function test_resolved_undelivered_warning_and_recovery_do_not_reach_user(): void
    {
        $this->snapshot['last_live_contact_at_ms'] = BotStore::now() - 900_000;
        app(TechnicalAlerts::class)->tick();
        $this->snapshot['contact_recent'] = true;
        app(TechnicalAlerts::class)->tick();
        $this->successApi();
        app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('telegram_outbox')->count());
        $this->assertDatabaseHas('telegram_outbox', ['state' => 'cancelled']);
    }

    public function test_only_approved_subscribers_receive_alerts_and_dispatch_rechecks_opt_out(): void
    {
        $this->activate();
        $this->snapshot['last_live_contact_at_ms'] = BotStore::now() - 900_000;
        app(TechnicalAlerts::class)->tick();
        $this->assertDatabaseMissing('telegram_outbox', ['user_id' => self::VIEWER, 'purpose' => 'alert']);
        $this->act(self::OWNER, 'alerts:'.self::VIEWER.':1');
        app(TechnicalAlerts::class)->tick();
        $this->assertDatabaseHas('telegram_outbox', ['user_id' => self::VIEWER, 'purpose' => 'alert']);
        $this->act(self::VIEWER, 'notifications:toggle');
        $this->successApi();
        app(BotDelivery::class)->flush();
        Http::assertNotSent(fn ($r) => $r['chat_id'] === self::VIEWER && str_starts_with($r['text'], '⚠️'));
    }

    public function test_battery_hysteresis_and_stale_battery_are_handled(): void
    {
        $this->snapshot['contact_recent'] = true;
        $this->snapshot['snapshot_at_ms'] = BotStore::now() - 700_000;
        $this->snapshot['battery_percent'] = 20;
        $this->snapshot['charging'] = false;
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(0, DB::table('telegram_outbox')->count());
        $this->snapshot['snapshot_at_ms'] = BotStore::now();
        app(TechnicalAlerts::class)->tick();
        $this->successApi();
        app(BotDelivery::class)->flush();
        $this->snapshot['battery_percent'] = 21;
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(1, DB::table('telegram_outbox')->count());
        $this->snapshot['battery_percent'] = 25;
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(2, DB::table('telegram_outbox')->count());
    }

    public function test_reenabling_notifications_restores_a_previously_cancelled_unsent_warning(): void
    {
        $this->snapshot['last_live_contact_at_ms'] = BotStore::now() - 900_000;
        app(TechnicalAlerts::class)->tick();
        DB::table('telegram_members')->where('user_id', self::OWNER)->update(['notifications_enabled' => false]);
        $this->successApi();
        app(BotDelivery::class)->flush();
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'alert', 'state' => 'cancelled']);
        DB::table('telegram_members')->where('user_id', self::OWNER)->update(['notifications_enabled' => true]);
        app(TechnicalAlerts::class)->tick();
        app(BotDelivery::class)->flush();
        $this->assertSame(1, DB::table('telegram_outbox')->where('state', 'sent')->count());
    }

    public function test_blocked_bot_disables_notifications_without_repeated_delivery_attempts(): void
    {
        $this->act(self::OWNER, 'status');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403], 403)]);
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_members', ['user_id' => self::OWNER, 'notifications_enabled' => false]);
        $this->assertDatabaseHas('telegram_outbox', ['state' => 'failed']);
        app(BotDelivery::class)->flush();
        Http::assertSentCount(1);
    }

    public function test_empty_installation_has_initial_grace_before_contact_warning(): void
    {
        app(TechnicalAlerts::class)->tick();
        $this->assertSame(0, DB::table('telegram_outbox')->count());
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        app(TechnicalAlerts::class)->tick();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'alert', 'alert_kind' => 'contact']);
    }

    public function test_worker_requires_explicit_owner_configuration_before_network_calls(): void
    {
        config(['telegram.owner_id' => '']);
        $this->artisan('telegram:run')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_worker_health_expires_when_the_loop_stops_progressing(): void
    {
        app(BotStore::class)->put('worker_tick_at', (string) BotStore::now());
        $this->artisan('telegram:health')->assertExitCode(0);
        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->artisan('telegram:health')->assertExitCode(1);
    }
}
