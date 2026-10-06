<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_epochs', function (Blueprint $table): void {
            $table->string('device_id', 64)->primary();
            $table->unsignedBigInteger('cleared_before_ms')->default(0);
            $table->unsignedBigInteger('generation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_epochs');
    }
};
