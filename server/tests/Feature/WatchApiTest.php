<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WatchApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['telemetry.token' => self::TOKEN, 'telemetry.device_id' => 'grandma-watch']);
    }

    private function payload(array $changes = []): array
    {
        $now = (int) floor(microtime(true) * 1000);

        return array_replace([
            'event_id' => (string) Str::uuid(),
            'device_id' => 'grandma-watch',
            'source' => 'heartbeat',
            'received_at_ms' => $now,
            'watch_sent_at_ms' => $now,
            'bpm' => 73,
            'measured_at_ms' => $now - 1000,
            'battery_percent' => 81,
            'charging' => false,
            'monitoring_status' => 'active',
        ], $changes);
    }

    private function send(array $payload)
    {
        return $this->withToken(self::TOKEN)->postJson('/api/v1/events', $payload);
    }

    public function test_every_data_route_requires_a_token(): void
    {
        $this->getJson('/api/v1/status')->assertUnauthorized();
        $this->getJson('/api/v1/history')->assertUnauthorized();
        $this->postJson('/api/v1/events', $this->payload())->assertUnauthorized();
        $this->withToken('wrong-token')->getJson('/api/v1/status')->assertUnauthorized();
    }

    public function test_database_is_in_memory_before_any_api_request(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
    }

    public function test_missing_server_token_fails_closed(): void
    {
        config(['telemetry.token' => '']);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertStatus(503);
    }

    public function test_empty_status_does_not_claim_fresh_data(): void
    {
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('bpm', null)->assertJsonPath('contact_recent', false)
            ->assertJsonPath('measurement_stale', true);
    }

    public function test_heartbeat_and_pulse_are_stored_and_reported(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertCreated()->assertJsonPath('duplicate', false);
        $this->assertDatabaseHas('watch_events', ['event_id' => $payload['event_id'], 'bpm' => 73]);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('bpm', 73)->assertJsonPath('battery_percent', 81)
            ->assertJsonPath('contact_recent', true)->assertJsonPath('measurement_stale', false);
    }

    public function test_retry_does_not_duplicate_or_refresh_contact(): void
    {
        $payload = $this->payload();
        $first = $this->send($payload)->assertCreated()->json();
        $retry = $this->send($payload)->assertOk()->assertJsonPath('duplicate', true)->json();
        $this->assertSame($first['server_received_at_ms'], $retry['server_received_at_ms']);
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertSame($first['id'], $retry['id']);
    }

    public function test_reusing_an_id_for_different_data_returns_conflict(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertCreated();
        $this->send(array_replace($payload, ['bpm' => 90]))->assertStatus(409);
        $this->assertDatabaseCount('watch_events', 1);
        $this->assertDatabaseHas('watch_events', ['bpm' => 73]);
    }

    public function test_offline_history_does_not_become_live_contact(): void
    {
        $old = (int) floor(microtime(true) * 1000) - 1_200_000;
        $this->send($this->payload([
            'received_at_ms' => $old, 'watch_sent_at_ms' => $old, 'measured_at_ms' => $old - 1000,
        ]))->assertCreated()->assertJsonPath('live_contact', false);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('contact_recent', false)->assertJsonPath('measurement_stale', true);
        $this->assertDatabaseCount('watch_events', 1);
    }

    public function test_measurement_event_never_refreshes_watch_contact(): void
    {
        $this->send($this->payload(['source' => 'measurement']))->assertCreated()
            ->assertJsonPath('live_contact', false);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('bpm', 73)->assertJsonPath('contact_recent', false);
    }

    public function test_fresh_contact_does_not_refresh_old_pulse(): void
    {
        $old = (int) floor(microtime(true) * 1000) - 600_000;
        $this->send($this->payload(['measured_at_ms' => $old]))->assertCreated();
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('contact_recent', true)->assertJsonPath('measurement_stale', true);
    }

    public function test_heartbeat_without_pulse_is_valid(): void
    {
        $this->send($this->payload(['bpm' => null, 'measured_at_ms' => null]))->assertCreated();
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('contact_recent', true)->assertJsonPath('bpm', null)
            ->assertJsonPath('measurement_stale', true);
    }

    public function test_old_packet_cannot_regress_latest_pulse_or_battery(): void
    {
        $this->send($this->payload())->assertCreated();
        $old = (int) floor(microtime(true) * 1000) - 600_000;
        $this->send($this->payload([
            'received_at_ms' => $old, 'watch_sent_at_ms' => $old, 'measured_at_ms' => $old - 1000,
            'bpm' => 100, 'battery_percent' => 90, 'monitoring_status' => 'stopped',
        ]))->assertCreated();
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('bpm', 73)->assertJsonPath('battery_percent', 81)
            ->assertJsonPath('monitoring_status', 'active');
    }

    public function test_invalid_and_future_data_are_rejected(): void
    {
        $this->send($this->payload(['battery_percent' => 101]))->assertUnprocessable();
        $this->send($this->payload(['bpm' => 0]))->assertUnprocessable();
        $this->send($this->payload(['measured_at_ms' => null]))->assertUnprocessable();
        $this->send($this->payload(['device_id' => 'another-watch']))->assertUnprocessable();
        $this->send($this->payload(['watch_sent_at_ms' => (int) floor(microtime(true) * 1000) + 180_000]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('watch_events', 0);
    }

    public function test_contact_expires_even_without_new_packets(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertCreated();
        DB::table('watch_events')->update([
            'received_at_ms' => (int) floor(microtime(true) * 1000) - 600_001,
        ]);
        $this->withToken(self::TOKEN)->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('contact_recent', false);
    }

    public function test_history_has_bounded_pagination_and_no_internal_hash(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->send($this->payload())->assertCreated();
        }
        $page = $this->withToken(self::TOKEN)->getJson('/api/v1/history?limit=2')
            ->assertOk()->assertJsonCount(2, 'events')->json();
        $this->assertArrayNotHasKey('payload_hash', $page['events'][0]);
        $this->withToken(self::TOKEN)->getJson('/api/v1/history?limit=2&before_id='.$page['next_before_id'])
            ->assertOk()->assertJsonCount(1, 'events');
        $this->withToken(self::TOKEN)->getJson('/api/v1/history?limit=1000')->assertUnprocessable();
    }

    public function test_health_check_queries_the_database(): void
    {
        $this->getJson('/up')->assertOk();
        DB::statement('DROP TABLE watch_events');
        $this->getJson('/up')->assertStatus(500);
    }
}
