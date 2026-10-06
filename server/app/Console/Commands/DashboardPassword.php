<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class DashboardPassword extends Command
{
    protected $signature = 'dashboard:password';
    protected $description = 'Set or replace the dashboard password without exposing it in command history';

    public function handle(): int
    {
        $password = $this->secret('Новый пароль веб-интерфейса (от 12 до 72 байт)');
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->error('Пароль должен занимать от 12 до 72 байт.');
            return self::FAILURE;
        }
        if ($password !== $this->secret('Повторите пароль')) {
            $this->error('Пароли не совпадают.');
            return self::FAILURE;
        }
        $temporary = null;
        try {
            $path = config('dashboard.password_file');
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Cannot create password directory.');
            }
            $temporary = tempnam(dirname($path), '.dashboard-password-');
            if ($temporary === false || !chmod($temporary, 0600)
                || file_put_contents($temporary, password_hash($password, PASSWORD_BCRYPT)."\n", LOCK_EX) === false
                || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot save password.');
            }
            $temporary = null;
            $this->info('Пароль сохранён. Старые сеансы входа больше не действуют.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Не удалось сохранить пароль. Проверьте доступ контейнера к папке data.');
            return self::FAILURE;
        } finally {
            if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
        }
    }
}
