<?php

namespace Tests\Feature;

use App\Services\MeasurementRequests;
use App\Services\TelemetryEpoch;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\BotDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeasurementRequestTest extends TestCase
{
    use RefreshDatabase;
    private string $passwordFile;
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07T12:00:00Z');
        config(['telemetry.token' => self::TOKEN, 'telemetry.device_id' => 'grandma-watch',
            'telegram.owner_id' => '1001', 'telegram.token' => '123456:test-token']);
        $this->passwordFile = tempnam(sys_get_temp_dir(), 'measurement-auth-test-');
        file_put_contents($this->passwordFile, password_hash('local-test-password', PASSWORD_BCRYPT, ['cost' => 4]));
        config(['dashboard.password_file' => $this->passwordFile]);
        app(BotStore::class)->ensureOwner();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void { unlink($this->passwordFile); Carbon::setTestNow(); parent::tearDown(); }
    private function requests(): MeasurementRequests { return app(MeasurementRequests::class); }
    private function claim() { return $this->postJson('/api/v1/measurement-requests/claim', [], ['Authorization' => 'Bearer '.self::TOKEN]); }
    private function postResult(string $id, array $data) { return $this->postJson('/api/v1/measurement-requests/'.$id.'/result', $data, ['Authorization' => 'Bearer '.self::TOKEN]); }
    private function success(): array { return ['status' => 'success', 'bpm' => 74, 'measured_at_ms' => BotStore::now(), 'sample_after_request_ms' => 1800]; }
    private function bot(int $user = 1001, int $update = 1, string $action = 'measure', string $type = 'private'): void
    {
        app(BotHandler::class)->handle(['update_id' => $update, 'callback_query' => ['id' => 'test',
            'from' => ['id' => $user, 'is_bot' => false, 'first_name' => 'Test'],
            'message' => ['chat' => ['id' => $user, 'type' => $type]], 'data' => $action]]);
    }

    public function test_claim_and_result_require_token_and_dashboard_requires_its_own_login(): void
    {
        $r = $this->requests()->create();
        $this->postJson('/api/v1/measurement-requests/claim')->assertUnauthorized();
        $this->postJson('/api/v1/measurement-requests/'.$r['id'].'/result', $this->success())->assertUnauthorized();
        $this->postJson('/dashboard/measurement-requests', [], ['Authorization' => 'Bearer '.self::TOKEN])->assertRedirect('/login');
        $this->getJson('/dashboard/measurement-requests/'.$r['id'], ['Authorization' => 'Bearer '.self::TOKEN])->assertRedirect('/login');
    }

    public function test_multiple_clicks_join_one_request_and_each_user_gets_one_result(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active']);
        $r = $this->requests()->create(1001);
        $this->assertSame($r['id'], $this->requests()->create(1001)['id']);
        $this->assertSame($r['id'], $this->requests()->create(2002)['id']);
        $this->assertDatabaseCount('measurement_requests', 1);
        $this->assertDatabaseCount('measurement_subscribers', 2);
        $this->claim()->assertOk()->assertJsonPath('request.id', $r['id']);
        $this->postResult($r['id'], $this->success())->assertOk()->assertJsonPath('status', 'success');
        $this->requests()->notify(); $this->requests()->notify();
        $this->assertSame(2, DB::table('telegram_outbox')->where('purpose', 'measurement')->count());
        $this->assertDatabaseCount('measurement_subscribers', 0);
    }

    public function test_a_claimed_command_is_not_replayed(): void
    {
        $r = $this->requests()->create();
        $this->claim()->assertJsonPath('request.id', $r['id']);
        $this->claim()->assertJsonPath('request', null);
    }

    public function test_queued_command_expires_and_cannot_start_after_reconnection(): void
    {
        $r = $this->requests()->create(1001);
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        $this->claim()->assertJsonPath('request', null);
        $this->assertSame('phone_unavailable', $this->requests()->find($r['id'])['status']);
        $this->requests()->notify();
        $this->assertStringContainsString('Телефон не получил', DB::table('telegram_outbox')->value('body'));
    }

    public function test_late_success_is_acknowledged_as_timeout_and_never_resurrects_it(): void
    {
        $r = $this->requests()->create(); $this->claim();
        Carbon::setTestNow(Carbon::now()->addSeconds(100));
        $this->postResult($r['id'], $this->success())->assertOk()->assertJsonPath('status', 'timeout')->assertJsonPath('bpm', null);
    }

    public function test_result_retry_is_idempotent_but_a_conflicting_result_is_rejected(): void
    {
        $r = $this->requests()->create(); $this->claim(); $data = $this->success();
        $this->postResult($r['id'], $data)->assertOk();
        $this->postResult($r['id'], $data)->assertOk();
        $this->postResult($r['id'], array_replace($data, ['bpm' => 90]))->assertConflict();
        $this->assertSame(74, $this->requests()->find($r['id'])['bpm']);
    }

    public function test_unknown_and_unclaimed_request_ids_cannot_accept_success(): void
    {
        $r = $this->requests()->create();
        $this->postResult($r['id'], $this->success())->assertConflict();
        $this->postResult('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $this->success())->assertNotFound();
    }

    public function test_success_needs_new_sample_evidence_and_valid_pulse(): void
    {
        $r = $this->requests()->create(); $this->claim();
        foreach ([['sample_after_request_ms' => 0], ['sample_after_request_ms' => 60_001], ['bpm' => 0], ['bpm' => 301],
            ['measured_at_ms' => BotStore::now() - 120_001], ['measured_at_ms' => BotStore::now() + 120_001]] as $bad) {
            $this->postResult($r['id'], array_replace($this->success(), $bad))->assertUnprocessable();
        }
        $this->postResult($r['id'], ['status' => 'success'])->assertUnprocessable();
        $this->assertSame('dispatched', $this->requests()->find($r['id'])['status']);
    }

    public function test_off_body_failure_contains_no_cached_pulse_and_notifies_without_alert_permission(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active']);
        $r = $this->requests()->create(2002); $this->claim();
        $this->postResult($r['id'], ['status' => 'off_body', 'bpm' => 74])->assertUnprocessable();
        $this->postResult($r['id'], ['status' => 'off_body'])->assertOk()->assertJsonPath('bpm', null);
        $this->requests()->notify();
        $this->assertSame('Часы сняты: замер не выполнен.', DB::table('telegram_outbox')->value('body'));
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        app(BotDelivery::class)->flush();
        Http::assertSentCount(1);
    }

    public function test_removed_members_cannot_start_or_receive_a_measurement(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active']);
        $r = $this->requests()->create(2002); $this->claim();
        DB::table('telegram_members')->where('user_id', 2002)->update(['state' => 'revoked']);
        $this->bot(2002);
        $this->postResult($r['id'], ['status' => 'off_body']); $this->requests()->notify();
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'measurement')->count());
        $this->assertDatabaseCount('measurement_requests', 1);
    }

    public function test_groups_and_strangers_cannot_trigger_sensor_commands(): void
    {
        $this->bot(9999); $this->bot(1001, 2, type: 'group');
        $this->assertDatabaseCount('measurement_requests', 0);
    }

    public function test_telegram_retry_does_not_create_a_second_request_or_acknowledgement(): void
    {
        $this->bot(); $this->bot();
        $this->assertDatabaseCount('measurement_requests', 1);
        $this->assertDatabaseCount('telegram_outbox', 1);
        $this->assertStringContainsString('Измерить сейчас', json_encode(app(BotHandler::class)->menu(1001), JSON_UNESCAPED_UNICODE));
    }

    public function test_cooldown_after_a_fast_result_rejects_new_requests(): void
    {
        $r = $this->requests()->create(); $this->claim();
        $this->postResult($r['id'], ['status' => 'off_body']);
        $this->bot();
        $this->assertDatabaseCount('measurement_requests', 1);
        $this->assertStringContainsString('10 секунд', DB::table('telegram_outbox')->value('body'));
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $this->assertNotSame($r['id'], $this->requests()->create()['id']);
    }

    public function test_history_reset_cancels_requests_and_removes_requested_pulse_but_keeps_members(): void
    {
        $done = $this->requests()->create(); $this->claim(); $this->postResult($done['id'], $this->success());
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $pending = $this->requests()->create(1001);
        app(TelemetryEpoch::class)->clear('grandma-watch', 0);
        $this->assertSame('cancelled', $this->requests()->find($pending['id'])['status']);
        $this->assertNull($this->requests()->find($done['id'])['bpm']);
        $this->assertSame('cancelled', $this->requests()->latest()['status']);
        $this->assertDatabaseCount('measurement_subscribers', 0);
        $this->assertDatabaseCount('telegram_members', 1);
        $this->claim()->assertJsonPath('request', null);
    }
}
