<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_outbox', fn (Blueprint $table) => $table->text('chart')->nullable());
    }

    public function down(): void
    {
        Schema::table('telegram_outbox', fn (Blueprint $table) => $table->dropColumn('chart'));
    }
};
