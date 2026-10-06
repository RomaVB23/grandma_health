<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Docker exports its environment into $_SERVER. PHPUnit's force="true"
        // does not replace those existing values; Laravel reads them first.
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        putenv('DB_URL');
        unset($_ENV['DB_URL'], $_SERVER['DB_URL']);

        $app = parent::createApplication();

        // Check BEFORE RefreshDatabase can run migrate:fresh.
        if ($app->configurationIsCached()
            || !$app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || !empty($app['config']->get('database.connections.sqlite.url'))
        ) {
            throw new RuntimeException('Tests may only use an uncached SQLite :memory: database.');
        }

        return $app;
    }
}
