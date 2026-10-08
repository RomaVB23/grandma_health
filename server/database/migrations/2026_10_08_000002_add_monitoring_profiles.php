<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('night_pulse_lower')->default(60);
            $table->unsignedSmallInteger('night_pulse_upper')->default(85);
            $table->string('night_start', 5)->default('23:00');
            $table->string('night_end', 5)->default('08:00');
            $table->string('profile_timezone', 64)->default('Europe/Minsk');
            $table->string('profile_mode', 8)->default('auto');
            $table->unsignedBigInteger('profile_override_until_ms')->nullable();
        });
        // Preserve the user's existing alarm limits; no medical defaults are invented.
        DB::table('monitoring_settings')->update([
            'night_pulse_lower' => DB::raw('pulse_lower'), 'night_pulse_upper' => DB::raw('pulse_upper'),
            'profile_timezone' => config('telegram.timezone', 'Europe/Minsk'),
        ]);
    }

    public function down(): void
    {
        Schema::table('monitoring_settings', fn (Blueprint $table) => $table->dropColumn([
            'night_pulse_lower', 'night_pulse_upper', 'night_start', 'night_end', 'profile_timezone',
            'profile_mode', 'profile_override_until_ms',
        ]));
    }
};
