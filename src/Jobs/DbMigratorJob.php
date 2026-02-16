<?php

namespace Mreycode\DbMigrator\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Mreycode\DbMigrator\Models\DbMigrator as DbMigratorModel;
use ReflectionClass;
use Throwable;

class DbMigratorJob implements ShouldQueue
{
    use Queueable;

    protected $tries = 5;
    protected $timeout = 1000000;
    protected ?DbMigratorModel $dbMigration;

    /**
     * Create a new job instance.
     */
    public function __construct(DbMigratorModel $dbMigration)
    {
        $this->dbMigration = $dbMigration;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $dbMigration = $this->dbMigration;
        $migrateClass = new ReflectionClass($dbMigration->migration);
        $migrator = $migrateClass->newInstance();

        try {
            $migrator->run($dbMigration);
        } catch (Throwable $throwable) {
            // Check if jobs can be retried
            if (method_exists($this, 'attempts') && $this->attempts() >= $this->tries) {
                $message = "[DbMigrator failed]: {$throwable->getMessage()}\nTrace:\n{$throwable->getTraceAsString()}";
                $migrator->markAsFailed($dbMigration, $message);
            } else {
                if (method_exists($this, 'release')) {
                    $this->release(5);
                }
            }
        }
    }
}
