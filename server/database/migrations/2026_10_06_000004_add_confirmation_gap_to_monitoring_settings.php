<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('confirmation_gap_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', fn (Blueprint $table) => $table->dropColumn('confirmation_gap_minutes'));
    }
};
