<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Relaticle\ActivityLog\ActivityLogServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider as SpatieActivitylogServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $class): string => 'Relaticle\\ActivityLog\\Tests\\Fixtures\\database\\factories\\'.class_basename($class).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SpatieActivitylogServiceProvider::class,
            ActivityLogServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('activitylog.database_connection', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');
        $this->loadLaravelMigrations();
        $this->artisan('migrate', ['--database' => 'testing']);
    }
}
