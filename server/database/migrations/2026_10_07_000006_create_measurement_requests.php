<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measurement_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('device_id')->index();
            $table->string('state');
            $table->unsignedBigInteger('created_at_ms');
            $table->unsignedBigInteger('dispatch_before_ms');
            $table->unsignedBigInteger('expires_at_ms');
            $table->unsignedBigInteger('finished_at_ms')->nullable();
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->unsignedBigInteger('measured_at_ms')->nullable();
            $table->text('result_hash')->nullable();
        });
        Schema::create('measurement_subscribers', function (Blueprint $table): void {
            $table->uuid('request_id');
            $table->unsignedBigInteger('user_id');
            $table->unique(['request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measurement_subscribers');
        Schema::dropIfExists('measurement_requests');
    }
};
