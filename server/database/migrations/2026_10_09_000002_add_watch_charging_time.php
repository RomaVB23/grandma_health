<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('watch_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('charging_since_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('watch_events', function (Blueprint $table): void {
            $table->dropColumn('charging_since_ms');
        });
    }
};
