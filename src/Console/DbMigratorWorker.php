<?php

namespace Mreycode\DbMigrator\Console;

use Illuminate\Console\Command;
use Mreycode\DbMigrator\Enums\MigratorStatus;
use Mreycode\DbMigrator\Jobs\DbMigratorJob;
use Mreycode\DbMigrator\Models\DbMigrator as DbMigratorModel;
use Throwable;

class DbMigratorWorker extends Command
{
    protected $signature = 'mreycode:db-worker
                            {--max-jobs=0 : Maximum jobs before exiting (0 = unlimited)}
                            {--memory=512 : Memory limit in MB}';

    protected $description = 'Laravel-style long-running worker with memory and signal safety';

    /**
     * Flag used to stop the worker gracefully
     */
    protected bool $shouldQuit = false;

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->info("Shutdown signal received ({$signal}). Finishing current job...");
        $this->shouldQuit = true;

        return $previousExitCode;
    }

    /**
     * Main worker loop
     */
    public function handle(): int
    {
        $jobDelaySeconds = config('db-migrator.worker.delay', 5);
        $sleepSeconds = config('db-migrator.worker.sleep', 3);
        $processedJobs = 0;

        $maxJobs = (int) $this->option('max-jobs');
        $maxMemory = (int) $this->option('memory');

        $this->info("[Mreycode] Db Migrator Worker started");
        $this->info("Max jobs: " . ($maxJobs ?: 'unlimited'));
        $this->info("Memory limit: {$maxMemory} MB");

        while (true) {

            if ($this->shouldQuit) {
                $this->warn("Worker stopping gracefully...");
                break;
            }

            $jobMigrate = $this->fetchAndMarkPendingMigration();

            if (is_null($jobMigrate)) {
                // No jobs → sleep to avoid CPU burn
                sleep($sleepSeconds);
                continue;
            }

            /**
             * Process job
             */
            try {
                $this->info("[" . now() . "(" . $jobMigrate->migration . ")] Processing job");
                DbMigratorJob::dispatch($jobMigrate)
                    ->delay(now()
                    ->addSeconds($jobDelaySeconds));
                sleep($jobDelaySeconds); // simulate real work
                
            } catch (Throwable $throwable) {
                // Job failed, but worker stays alive
                $this->error("Job failed: " . $throwable->getMessage());
            }

            // Optimize memory usage after processing each job
            unset($job);
            $collectedCycles = gc_collect_cycles();
            $this->line("Garbage collection run: cleaned up {$collectedCycles} memory cycles.");
            $processedJobs++;

            /**
             * Max jobs check (same as --max-jobs in Laravel)
             */
            if ($maxJobs > 0 && $processedJobs >= $maxJobs) {
                $this->warn("Max jobs reached ({$processedJobs}). Exiting...");
                break;
            }

            /**
             * Memory safety check (same as --memory)
             */
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $this->line("Current memory usage: " . round($memoryUsage, 2) . " MB");
            if ($memoryUsage >= $maxMemory) {
                $this->warn("Memory limit exceeded ({$memoryUsage} MB). Exiting...");
                break;
            }
        }

        $this->info("Worker exited cleanly");
        return Command::SUCCESS;
    }

    
    protected function fetchAndMarkPendingMigration()
    {
        $exist = DbMigratorModel::where('status', MigratorStatus::PENDING->value)
            ->first();
        
        if ($exist) {
            $exist->status = MigratorStatus::ONGOING->value;
            $exist->save();

            return $exist;
        }

        return $exist;
    }
}
