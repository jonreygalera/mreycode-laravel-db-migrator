<?php

namespace Mreycode\DbMigrator;

use Illuminate\Support\ServiceProvider;
use Mreycode\DbMigrator\Console\DbMigrator;
use Mreycode\DbMigrator\Console\DbMigratorWorker;
use Mreycode\DbMigrator\Console\MakeDbMigrator;

class DbMigratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/db-migrator.php',
            'db-migrator'
        );
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Register commands
        $this->commands([
            DbMigrator::class,
            DbMigratorWorker::class,
            MakeDbMigrator::class
        ]);

        // Publish migrations
        $this->publishesMigrations([
            __DIR__ . '/../database/migrations/' => database_path('migrations')
        ], 'db-migrator-migrations');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/db-migrator.php' => config_path('db-migrator.php'),
        ], 'db-migrator-config');
    }
}
