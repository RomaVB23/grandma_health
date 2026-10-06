<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

class BotHandler
{
    public function __construct(private BotStore $store) {}

    public function handle(array $update): void
    {
        $id = $update['update_id'] ?? null;
        if (!is_int($id) || $id < 0) {
            return;
        }
        // Cursor and effects commit together: Telegram retries cannot create another invite.
        DB::transaction(function () use ($update, $id): void {
            if ($id <= (int) $this->store->value('update_id', '-1')) {
                return;
            }
            $this->dispatch($update);
            $this->store->put('update_id', (string) $id);
        });
    }

    private function dispatch(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        $message = $callback['message'] ?? $update['message'] ?? null;
        $from = $callback['from'] ?? $message['from'] ?? null;
        $user = $from['id'] ?? null;
        if (!is_int($user) || $user <= 0 || ($from['is_bot'] ?? true)
            || ($message['chat']['type'] ?? '') !== 'private' || ($message['chat']['id'] ?? null) !== $user) {
            return; // Never reveal health data in groups, forwarded identities or inline chats.
        }
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        $name = mb_substr(preg_replace('/[\p{C}]/u', '', $name) ?: 'Участник', 0, 80);
        if (!empty($from['username'])) {
            $name .= ' (@'.mb_substr($from['username'], 0, 32).')';
        }
        $member = DB::table('telegram_members')->where('user_id', $user)->first();
        if ($member) {
            DB::table('telegram_members')->where('user_id', $user)->update(['name' => $name]);
        }
        $text = trim((string) ($message['text'] ?? ''));
        if (!$callback && preg_match('/^\/start join_([A-Za-z0-9_-]{43})$/D', $text, $match)) {
            $this->join($user, $name, $match[1], $member);
            return;
        }
        if (!$member || $member->state !== 'active') {
            $this->store->enqueue($user, $member?->state === 'pending'
                ? 'Заявка ожидает подтверждения администратора.' : 'Доступ закрыт. Попросите администратора прислать новое приглашение.', null, 'access');
            return;
        }
        $action = $callback ? (string) ($callback['data'] ?? '') : match ($text) {
            '📊 Состояние бабушки', '🔄 Обновить', '/status' => 'status',
            '🔔 Мои уведомления' => 'notifications', '👥 Участники' => 'members:0',
            '➕ Добавить участника' => 'invite', '❓ Помощь', '/help' => 'help', default => 'menu',
        };
        if ($action === 'status') {
            $this->store->enqueue($user, '', $this->inline([['🔄 Обновить', 'status'], ['⬅️ Меню', 'menu']]), 'status');
        } elseif ($action === 'help') {
            $this->store->enqueue($user, 'Кнопка состояния показывает последние данные сервера и их возраст. Свежая связь не означает, что пульс только что измерен.'
                ."\n\nТехнические оповещения сообщают о потере связи, низком заряде и длительном снятии часов. Контроль пульса включается отдельно в веб-интерфейсе: границы и подтверждение задаёт администратор."
                ."\n\nУведомления о пульсе основаны на показаниях часов и не являются диагнозом. Неизвестное ношение и устаревший пульс не означают возвращение в диапазон."
                ."\n\nЕсли выключен компьютер или пропал его интернет, этот бот не сможет прислать сообщение до восстановления работы.", $this->menu($user));
        } elseif ($action === 'notifications' || $action === 'notifications:toggle') {
            if ($action === 'notifications:toggle' && $member->alerts_allowed) {
                DB::table('telegram_members')->where('user_id', $user)->update([
                    'notifications_enabled' => !$member->notifications_enabled, 'version' => DB::raw('version + 1'),
                ]);
                $member = DB::table('telegram_members')->where('user_id', $user)->first();
            }
            $this->store->enqueue($user, !$member->alerts_allowed ? 'Вам доступен просмотр. Оповещения может разрешить администратор.'
                : 'Ваши уведомления: '.($member->notifications_enabled ? 'включены' : 'выключены'),
                $this->inline($member->alerts_allowed ? [['🔔 Включить / 🔕 Выключить', 'notifications:toggle'], ['⬅️ Меню', 'menu']] : [['⬅️ Меню', 'menu']]));
        } elseif ($this->store->isOwner($user) && $action === 'invite') {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            DB::table('telegram_invites')->insert(['hash' => hash('sha256', $token),
                'expires_at_ms' => BotStore::now() + config('telegram.invite_ttl_ms')]);
            $link = 'https://t.me/'.config('telegram.username').'?start=join_'.$token;
            $this->store->enqueue($user, "➕ Отправьте эту ссылку одному человеку:\n$link\n\n"
                .'Она действует 24 часа и используется один раз. После подключения вы подтвердите доступ.');
        } elseif ($this->store->isOwner($user) && preg_match('/^members:(\d{1,6})$/D', $action, $m)) {
            $this->members($user, (int) $m[1]);
        } elseif ($this->store->isOwner($user) && preg_match('/^member:(\d{1,16})$/D', $action, $m)) {
            $this->card($user, (int) $m[1]);
        } elseif ($this->store->isOwner($user) && preg_match('/^(approve|alerts|remove|revoke):(\d{1,16}):(\d{1,10})$/D', $action, $m)) {
            $this->manage($user, $m[1], (int) $m[2], (int) $m[3]);
        } else {
            $this->store->enqueue($user, 'Выберите действие кнопкой ниже.', $this->menu($user));
        }
    }

    private function join(int $user, string $name, ?string $token, ?object $member): void
    {
        if ($this->store->isOwner($user) || in_array($member?->state, ['active', 'pending'], true)) {
            $this->store->enqueue($user, $member?->state === 'pending' ? 'Ваша заявка уже ожидает подтверждения.'
                : 'У вас уже есть доступ.', $member?->state === 'active' ? $this->menu($user) : null, 'access');
            return;
        }
        $used = DB::table('telegram_invites')->where('hash', hash('sha256', $token))
            ->whereNull('used_by')->where('expires_at_ms', '>', BotStore::now())->update(['used_by' => $user]);
        if (!$used) {
            $this->store->enqueue($user, 'Приглашение истекло или уже использовано. Попросите новую ссылку.', null, 'access');
            return;
        }
        DB::table('telegram_members')->updateOrInsert(['user_id' => $user], [
            'name' => $name, 'state' => 'pending', 'is_owner' => false, 'alerts_allowed' => false,
            'notifications_enabled' => false, 'version' => ($member?->version ?? 0) + 1,
        ]);
        $this->store->enqueue($user, 'Заявка отправлена. Дождитесь подтверждения администратора.', null, 'access');
        $this->store->enqueue($this->store->ownerId(), "👤 Заявка на доступ: $name\nTelegram ID: $user",
            $this->inline([['Открыть заявку', 'member:'.$user]]));
    }

    private function members(int $owner, int $page): void
    {
        $members = DB::table('telegram_members')->where('user_id', '!=', $this->store->ownerId())
            ->whereIn('state', ['pending', 'active'])->orderBy('user_id')->offset($page * 10)->limit(11)->get();
        $buttons = [];
        foreach ($members->take(10) as $member) {
            $buttons[] = [($member->state === 'pending' ? '⏳ ' : '👤 ').$member->name, 'member:'.$member->user_id];
        }
        if ($page > 0) { $buttons[] = ['⬅️ Предыдущие', 'members:'.($page - 1)]; }
        if ($members->count() > 10) { $buttons[] = ['➡️ Следующие', 'members:'.($page + 1)]; }
        $buttons[] = ['➕ Добавить участника', 'invite'];
        $buttons[] = ['⬅️ Меню', 'menu'];
        $this->store->enqueue($owner, $members->isEmpty() ? 'Пока других участников нет.' : '👥 Участники: выберите человека.', $this->inline($buttons));
    }

    private function card(int $owner, int $user): void
    {
        $member = DB::table('telegram_members')->where('user_id', $user)->first();
        if (!$member || $this->store->isOwner($user) || $member->state === 'revoked') {
            $this->store->enqueue($owner, 'Участник недоступен.', $this->inline([['⬅️ Участники', 'members:0']]));
            return;
        }
        $suffix = $user.':'.$member->version;
        $buttons = $member->state === 'pending' ? [['✅ Разрешить доступ', 'approve:'.$suffix]]
            : [[($member->alerts_allowed ? '🔕 Запретить оповещения' : '🔔 Разрешить оповещения'), 'alerts:'.$suffix]];
        $buttons[] = ['🗑 Удалить доступ', 'remove:'.$suffix];
        $buttons[] = ['⬅️ Участники', 'members:0'];
        $this->store->enqueue($owner, "👤 {$member->name}\nTelegram ID: $user\n"
            .($member->state === 'pending' ? 'Ожидает подтверждения' : 'Доступ разрешён')
            ."\nОповещения: ".(!$member->alerts_allowed ? 'не разрешены' : ($member->notifications_enabled ? 'включены' : 'выключены самим участником')), $this->inline($buttons));
    }

    private function manage(int $owner, string $action, int $user, int $version): void
    {
        $member = DB::table('telegram_members')->where('user_id', $user)->first();
        if (!$member || $this->store->isOwner($user) || $member->version !== $version || $member->state === 'revoked') {
            $this->store->enqueue($owner, 'Карточка устарела. Откройте участника заново.', $this->inline([['⬅️ Участники', 'members:0']]));
            return;
        }
        if ($action === 'remove') {
            $this->store->enqueue($owner, 'Удалить доступ у '.$member->name.'?', $this->inline([
                ['🗑 Да, удалить', 'revoke:'.$user.':'.$version], ['Отмена', 'member:'.$user],
            ]));
            return;
        }
        $changes = match (true) {
            $action === 'approve' && $member->state === 'pending' => ['state' => 'active'],
            $action === 'alerts' && $member->state === 'active' => ['alerts_allowed' => !$member->alerts_allowed,
                'notifications_enabled' => !$member->alerts_allowed],
            $action === 'revoke' => ['state' => 'revoked', 'alerts_allowed' => false, 'notifications_enabled' => false],
            default => [],
        };
        if (!$changes) { $this->card($owner, $user); return; }
        DB::table('telegram_members')->where('user_id', $user)->where('version', $version)
            ->update($changes + ['version' => $version + 1]);
        if ($action === 'revoke') {
            DB::table('telegram_outbox')->where('user_id', $user)->where('state', 'pending')->update(['state' => 'cancelled']);
            $this->store->enqueue($user, 'Администратор закрыл доступ.', null, 'access');
            $this->store->enqueue($owner, 'Доступ удалён.', $this->inline([['⬅️ Участники', 'members:0']]));
        } else {
            if ($action === 'approve') {
                $this->store->enqueue($user, 'Доступ разрешён. Пользуйтесь кнопками ниже.', $this->menu($user));
            } else {
                $this->store->enqueue($user, $changes['alerts_allowed'] ? 'Администратор включил вам оповещения.' : 'Администратор выключил вам оповещения.');
            }
            $this->card($owner, $user);
        }
    }

    public function menu(int $user): array
    {
        $rows = [[['text' => '📊 Состояние бабушки'], ['text' => '🔄 Обновить']],
            [['text' => '🔔 Мои уведомления'], ['text' => '❓ Помощь']]];
        if ($this->store->isOwner($user)) {
            $rows[] = [['text' => '👥 Участники'], ['text' => '➕ Добавить участника']];
        }
        return ['keyboard' => $rows, 'resize_keyboard' => true, 'is_persistent' => true];
    }

    public function inline(array $buttons): array
    {
        return ['inline_keyboard' => array_map(fn (array $button) => [['text' => $button[0], 'callback_data' => $button[1]]], $buttons)];
    }
}
