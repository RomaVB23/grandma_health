<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watch_events', function (Blueprint $table): void {
            $table->string('wearing_state')->default('unknown');
            $table->unsignedBigInteger('wearing_since_ms')->nullable();
        });
        Schema::create('monitoring_settings', function (Blueprint $table): void {
            $table->string('device_id', 64)->primary();
            $table->boolean('pulse_enabled')->default(false);
            $table->unsignedSmallInteger('pulse_lower')->default(60);
            $table->unsignedSmallInteger('pulse_upper')->default(85);
            $table->unsignedSmallInteger('confirmation_samples')->default(1);
            $table->unsignedSmallInteger('pulse_max_age_seconds')->default(300);
            $table->boolean('wearing_enabled')->default(true);
            $table->unsignedSmallInteger('off_wrist_minutes')->default(10);
            $table->unsignedBigInteger('changed_at_ms')->default(0);
        });
        Schema::create('monitoring_state', function (Blueprint $table): void {
            $table->string('device_id', 64)->primary();
            $table->text('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_state');
        Schema::dropIfExists('monitoring_settings');
        Schema::table('watch_events', fn (Blueprint $table) => $table->dropColumn(['wearing_state', 'wearing_since_ms']));
    }
};
