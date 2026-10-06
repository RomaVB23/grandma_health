<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('device_id', 64);
            $table->string('source', 16);
            $table->bigInteger('received_at_ms');
            $table->bigInteger('watch_sent_at_ms');
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->bigInteger('measured_at_ms')->nullable();
            $table->unsignedTinyInteger('battery_percent')->nullable();
            $table->boolean('charging');
            $table->string('monitoring_status', 32);
            $table->bigInteger('server_received_at_ms');
            $table->boolean('live_contact');
            $table->char('payload_hash', 64);
            $table->index(['device_id', 'measured_at_ms']);
            $table->index(['device_id', 'watch_sent_at_ms']);
            $table->index(['device_id', 'received_at_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_events');
    }
};
