<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('name');
            $table->string('state'); // pending, active, revoked
            $table->boolean('is_owner')->default(false);
            $table->boolean('alerts_allowed')->default(false);
            $table->boolean('notifications_enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('telegram_invites', function (Blueprint $table): void {
            $table->string('hash', 64)->primary();
            $table->unsignedBigInteger('expires_at_ms');
            $table->unsignedBigInteger('used_by')->nullable();
        });
        Schema::create('telegram_state', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
        });
        Schema::create('telegram_alerts', function (Blueprint $table): void {
            $table->string('kind')->primary();
            $table->boolean('active')->default(false);
            $table->unsignedInteger('generation')->default(0);
        });
        Schema::create('telegram_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key')->nullable()->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('purpose'); // access, normal, status, alert
            $table->text('body');
            $table->text('markup')->nullable();
            $table->string('state')->default('pending');
            $table->unsignedBigInteger('available_at_ms')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sent_at_ms')->nullable();
            $table->string('alert_kind')->nullable();
            $table->unsignedInteger('alert_generation')->nullable();
            $table->boolean('alert_active')->nullable();
            $table->index(['state', 'available_at_ms']);
        });
    }

    public function down(): void
    {
        foreach (['telegram_outbox', 'telegram_alerts', 'telegram_state', 'telegram_invites', 'telegram_members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
