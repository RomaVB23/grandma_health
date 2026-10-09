<?php

namespace Tests\Feature;

use App\Services\PulseChartData;
use App\Services\Telegram\BotDelivery;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\BotStore;
use App\Services\Telegram\ChartImage;
use App\Services\Telegram\ChartReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09T10:00:00Z');
        config(['telegram.owner_id' => '1001', 'telegram.token' => '123:local-test',
            'telegram.timezone' => 'Europe/Minsk', 'telegram.charts_enabled' => true,
            'telemetry.device_id' => 'grandma-watch']);
        Http::preventStrayRequests();
        app(BotStore::class)->ensureOwner();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); parent::tearDown();
    }

    private function update(int $id = 1, int $user = 1001, string $text = '/graph', string $type = 'private'): array
    {
        return ['update_id' => $id, 'message' => ['text' => $text,
            'from' => ['id' => $user, 'is_bot' => false, 'first_name' => 'User'],
            'chat' => ['type' => $type, 'id' => $user]]];
    }

    private function sample(int $at, int $bpm = 72, string $source = 'measurement'): void
    {
        DB::table('watch_events')->insert(['event_id' => (string) Str::uuid(), 'device_id' => 'grandma-watch',
            'source' => $source, 'received_at_ms' => BotStore::now(), 'server_received_at_ms' => BotStore::now(),
            'watch_sent_at_ms' => BotStore::now(), 'measured_at_ms' => $at, 'bpm' => $bpm,
            'battery_percent' => 85, 'charging' => false, 'monitoring_status' => 'active',
            'live_contact' => false, 'payload_hash' => str_repeat('a', 64)]);
    }

    public function test_manual_request_has_a_fixed_six_hour_window_and_does_not_measure(): void
    {
        app(BotHandler::class)->handle($this->update());
        $row = DB::table('telegram_outbox')->where('purpose', 'chart')->sole();
        $spec = json_decode($row->chart, true);
        $this->assertSame(BotStore::now(), $spec['to_ms']);
        $this->assertSame(6 * 3_600_000, $spec['to_ms'] - $spec['from_ms']);
        $this->assertSame('Europe/Minsk', $spec['timezone']);
        $this->assertSame(0, DB::table('measurement_requests')->count());
        $this->assertStringContainsString('График за 6 часов', json_encode(app(BotHandler::class)->menu(1001), JSON_UNESCAPED_UNICODE));
    }

    public function test_replays_and_rapid_clicks_cannot_queue_multiple_images(): void
    {
        $handler = app(BotHandler::class); $update = $this->update();
        $handler->handle($update); $handler->handle($update); $handler->handle($this->update(2));
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'chart')->count());
        Carbon::setTestNow(now()->addSeconds(30)); $handler->handle($this->update(3));
        $this->assertSame(2, DB::table('telegram_outbox')->where('purpose', 'chart')->count());
    }

    public function test_unknown_users_and_groups_cannot_request_charts(): void
    {
        app(BotHandler::class)->handle($this->update(user: 2002));
        app(BotHandler::class)->handle($this->update(2, 1001, type: 'group'));
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'chart')->count());
    }

    public function test_viewer_can_request_a_chart_with_notifications_disabled(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active',
            'alerts_allowed' => false, 'notifications_enabled' => false]);
        app(BotHandler::class)->handle($this->update(user: 2002));
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'chart')->count());
    }

    public function test_chart_uses_measurement_time_and_deduplicates_heartbeat_copies(): void
    {
        $end = BotStore::now(); $start = $end - 6 * 3_600_000;
        $this->sample($start - 1, 55); $this->sample($start, 70); $this->sample($start, 70, 'heartbeat');
        $this->sample($end - 1, 80); $this->sample($end, 99);
        $chart = app(ChartReports::class)->build($start, $end, 'Europe/Minsk', 6);
        $this->assertSame([[$start, 70], [$end - 1, 80]], $chart['points']);
        $this->assertSame(2, $chart['report']['pulse']['count']);
        $web = app(PulseChartData::class)->build($start, $end - 1, 'Europe/Minsk');
        $this->assertSame($web['points'], $chart['points']);
    }

    public function test_schedule_starts_at_next_boundary_and_is_restart_safe(): void
    {
        $reports = app(ChartReports::class); $reports->tick();
        $this->assertSame(0, DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->count());
        Carbon::setTestNow('2026-10-09T17:00:01Z'); $reports->tick(); $reports->tick();
        $spec = json_decode(DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->sole()->chart, true);
        $this->assertSame(Carbon::parse('2026-10-09T08:00:00+03:00')->getTimestampMs(), $spec['from_ms']);
        $this->assertSame(Carbon::parse('2026-10-09T20:00:00+03:00')->getTimestampMs(), $spec['to_ms']);
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->count());
        Carbon::setTestNow('2026-10-10T05:00:02Z'); $reports->tick();
        $rows = DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->orderBy('id')->get();
        $this->assertSame('cancelled', $rows[0]->state);
        $next = json_decode($rows[1]->chart, true); $this->assertSame($spec['to_ms'], $next['from_ms']);
        $this->assertSame(12 * 3_600_000, $next['to_ms'] - $next['from_ms']);
    }

    public function test_after_downtime_only_the_latest_completed_window_is_queued(): void
    {
        $reports = app(ChartReports::class); $reports->tick();
        Carbon::setTestNow('2026-10-12T07:00:00Z'); $reports->tick();
        $row = DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->sole();
        $this->assertSame(Carbon::parse('2026-10-12T08:00:00+03:00')->getTimestampMs(), json_decode($row->chart, true)['to_ms']);
    }

    public function test_auto_reports_respect_notification_permissions_and_delivery_rechecks_them(): void
    {
        DB::table('telegram_members')->insert(['user_id' => 2002, 'name' => 'Viewer', 'state' => 'active',
            'alerts_allowed' => false, 'notifications_enabled' => true]);
        app(ChartReports::class)->tick(); Carbon::setTestNow('2026-10-09T17:00:01Z'); app(ChartReports::class)->tick();
        $this->assertSame(1, DB::table('telegram_outbox')->where('purpose', 'chart_scheduled')->count());
        DB::table('telegram_members')->where('user_id', 1001)->update(['notifications_enabled' => false]);
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart_scheduled', 'state' => 'cancelled']);
    }

    public function test_revoked_access_cancels_a_previously_requested_photo(): void
    {
        app(BotHandler::class)->handle($this->update());
        DB::table('telegram_members')->where('user_id', 1001)->update(['state' => 'revoked']);
        app(BotDelivery::class)->flush(); Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart', 'state' => 'cancelled']);
    }

    public function test_photo_is_a_real_png_and_uploaded_as_multipart(): void
    {
        $this->sample(BotStore::now() - 60_000);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        app(BotHandler::class)->handle($this->update()); app(BotDelivery::class)->flush();
        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/sendPhoto') && $request->hasFile('photo')
                && str_contains($request->body(), "\x89PNG\r\n\x1a\n")
                && str_contains($request->body(), 'Уникальных замеров: 1');
        });
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart', 'state' => 'sent']);
    }

    public function test_png_breaks_the_line_across_a_long_gap_and_supports_empty_history(): void
    {
        $end = BotStore::now(); $start = $end - 6 * 3_600_000;
        $this->sample($start, 75); $this->sample($end - 1, 75);
        $chart = app(ChartReports::class)->build($start, $end, 'Europe/Minsk', 6);
        $png = app(ChartImage::class)->render($chart); $image = imagecreatefromstring($png);
        $this->assertSame([1280, 740], [imagesx($image), imagesy($image)]);
        $this->assertSame(0xe6ebf3, imagecolorat($image, 660, 424)); // grid, not a purple bridge through the pause
        imagedestroy($image);
        DB::table('watch_events')->delete(); $empty = app(ChartReports::class)->build($start, $end, 'Europe/Minsk', 6);
        $this->assertStringContainsString('нет полученных замеров', app(ChartReports::class)->caption($empty));
        $this->assertSame("\x89PNG\r\n\x1a\n", substr(app(ChartImage::class)->render($empty), 0, 8));
    }

    public function test_rate_limit_retains_the_same_period_and_allows_a_later_retry(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 5]], 429)
            ->push(['ok' => true, 'result' => []])]);
        app(BotHandler::class)->handle($this->update()); $original = DB::table('telegram_outbox')->where('purpose', 'chart')->sole()->chart;
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart', 'state' => 'pending', 'attempts' => 1, 'chart' => $original]);
        Carbon::setTestNow(now()->addSeconds(5));
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart', 'state' => 'sent', 'chart' => $original]);
    }

    public function test_preview_saves_an_image_without_network_or_outbox_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'grandma-chart-');
        try {
            $this->artisan('telegram:graph-preview', ['--scheduled' => true, '--at' => '2026-10-09T08:00:00+03:00', '--output' => $path])
                ->assertSuccessful();
            $this->assertSame("\x89PNG\r\n\x1a\n", substr(file_get_contents($path), 0, 8));
            $this->assertSame(0, DB::table('telegram_outbox')->count());
            $this->assertDatabaseMissing('telegram_state', ['key' => 'chart_schedule_slot']);
            Http::assertNothingSent();
        } finally { unlink($path); }
    }

    public function test_render_failure_does_not_hold_up_other_messages(): void
    {
        $image = \Mockery::mock(ChartImage::class);
        $image->shouldReceive('render')->andThrow(new \RuntimeException('fixture renderer failure'));
        app()->instance(ChartImage::class, $image);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(BotHandler::class)->handle($this->update()); app(BotStore::class)->enqueue(1001, 'Следующее сообщение');
        app(BotDelivery::class)->flush();
        $this->assertDatabaseHas('telegram_outbox', ['purpose' => 'chart', 'state' => 'failed']);
        $this->assertDatabaseHas('telegram_outbox', ['body' => 'Следующее сообщение', 'state' => 'sent']);
        Http::assertSentCount(1);
    }
}
