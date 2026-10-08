<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Current phone state is independent of watch events and pulse history.
        Schema::create('phone_status', function (Blueprint $table): void {
            $table->string('device_id')->primary();
            $table->unsignedTinyInteger('battery_percent');
            $table->boolean('charging');
            $table->unsignedBigInteger('snapshot_at_ms');
            $table->unsignedBigInteger('server_received_at_ms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_status');
    }
};
