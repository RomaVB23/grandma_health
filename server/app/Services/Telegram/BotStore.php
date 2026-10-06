<?php

namespace App\Services\Telegram;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BotStore
{
    public static function now(): int
    {
        return Carbon::now()->getTimestampMs();
    }

    public function ownerId(): int
    {
        return (int) config('telegram.owner_id');
    }

    public function isOwner(int $user): bool
    {
        return $user > 0 && $user === $this->ownerId();
    }

    public function ensureOwner(): void
    {
        if ($this->ownerId() <= 0) {
            throw new \RuntimeException('TELEGRAM_OWNER_ID must be configured.');
        }
        DB::transaction(function (): void {
            DB::table('telegram_members')->where('is_owner', true)->where('user_id', '!=', $this->ownerId())
                ->update(['state' => 'revoked', 'is_owner' => false, 'alerts_allowed' => false,
                    'notifications_enabled' => false, 'version' => DB::raw('version + 1')]);
            $owner = DB::table('telegram_members')->where('user_id', $this->ownerId())->first();
            if (!$owner) {
                DB::table('telegram_members')->insert(['user_id' => $this->ownerId(), 'name' => 'Администратор',
                    'state' => 'active', 'is_owner' => true, 'alerts_allowed' => true, 'notifications_enabled' => true]);
            } elseif (!$owner->is_owner || $owner->state !== 'active') {
                DB::table('telegram_members')->where('user_id', $this->ownerId())->update([
                    'state' => 'active', 'is_owner' => true, 'alerts_allowed' => true,
                    'notifications_enabled' => true, 'version' => DB::raw('version + 1'),
                ]);
            }
        });
    }

    public function value(string $key, string $default = ''): string
    {
        return (string) (DB::table('telegram_state')->where('key', $key)->value('value') ?? $default);
    }

    public function put(string $key, string $value): void
    {
        DB::table('telegram_state')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    public function enqueue(int $user, string $body, ?array $markup = null, string $purpose = 'normal', array $extra = []): void
    {
        DB::table('telegram_outbox')->insertOrIgnore(array_merge([
            'user_id' => $user, 'body' => $body, 'purpose' => $purpose,
            'markup' => $markup === null ? null : json_encode($markup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $extra));
    }
}
