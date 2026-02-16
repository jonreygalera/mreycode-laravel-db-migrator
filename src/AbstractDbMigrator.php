<?php

namespace Mreycode\DbMigrator;

use Mreycode\DbMigrator\Console\DbMigrator as DbMigratorCommand;
use Illuminate\Support\Facades\DB;
use Mreycode\DbMigrator\Enums\MigratorStatus;
use Mreycode\DbMigrator\Models\DbMigrator as DbMigratorModel;
use Mreycode\DbMigrator\Concerns\HasMigratorHistory;
use RuntimeException;
use Throwable;

abstract class AbstractDbMigrator
{
    use HasMigratorHistory;

    protected $groupName = null;
    protected $cacheStats = true;
    protected $queueIndex = null;
    // This will identify whether to keep this migration on the stack as pending,
    // even if there is no source data from the db app. This allows new data
    // to be migrated from db to new later on.
    protected $keepOnRunning = false;
    // Property to determine if migration should keep running until a certain totalSize is reached
    protected $keepOnUntilTotalSize = false;
    protected $dbConnection = 'flai';

    private static $sourceConnection = null;
    private $migrationOptions;
    private $batch = 1;
    private $currentClass;
    private $activeMigration;

    private $params;

    /**
     * Get the source data from the source database.
     *
     * @return mixed
     */
    abstract public function sourceData();

    /**
     * Handle the migration process.
     *
     * @return void
     */
    abstract public function handle($params = null);

    public function __construct()
    {
        $this->loadOptions();
        
        $connection = $this->dbConnection;
        self::$sourceConnection = blank($connection) ? null : DB::connection($connection);

        if(strtolower($this->groupName) === strtolower(DbMigratorCommand::GROUP_SPECIAL_KEYWORD)) {
            throw new RuntimeException("Group name cannot be set to the special keyword '" . DbMigratorCommand::GROUP_SPECIAL_KEYWORD . "' in a migrator.");
        }
    }

    /**
     * Execute the migration for a specific DbMigration record.
     *
     * @param DbMigratorModel $dbMigrator
     * @return void
     */
    public function run(DbMigratorModel $dbMigrator)
    {
        try {
            DB::beginTransaction();
            $this->printMigrationStatus("Migration started.");

            $result = $this->process($dbMigrator);

            if($result['size'] == 0 && $this->shouldKeepOnUntilTotalSize()) {
                $this->markAsSuccess($dbMigrator, $result->toArray());
                $this->newPendingMigration($dbMigrator);
                $this->printMigrationStatus("No data to migrate, new pending migration.");
            } else {
                if($result['size'] == 0) {
                    if($this->shouldKeepOnRunning()) {
                        $dbMigrator->status = MigratorStatus::PENDING->value;
                        $dbMigrator->save();
                        $this->printMigrationStatus("No data to migrate, keeping migration pending.");
                    } else {
                        $this->markAsDone($dbMigrator);
                        $this->printMigrationStatus("No data to migrate.");
                    }
                } else {
                    $this->markAsSuccess($dbMigrator, $result->toArray());
                    $this->newPendingMigration($dbMigrator);
                    $this->printMigrationStatus("Migration succeeded.");
                }
            }

            DB::commit();
        } catch (Throwable $throwable) {
            DB::rollBack();
            $this->printMigrationStatus("Migration error: " . $throwable->getMessage());
            $this->printMigrationStatus("Trace: " . $throwable->getTraceAsString());

            $this->markAsFailed($dbMigrator, $throwable->getMessage() . "\n" . $throwable->getTraceAsString());
            throw $throwable;
        }

        $this->printMigrationStatus("Migration saved.");
    }

    public function shouldTerminate()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->whereIn('status', [MigratorStatus::FAILED->value, MigratorStatus::PAUSED->value, MigratorStatus::DONE->value])
            ->first();
    }

    /**
     * Show the current migration progress/status.
     * 
     * Calls displayMigrationProgress to output progress information to the user.
     */
    public function showStats(bool $truth = false)
    {
        $this->displayMigrationProgress($truth);
    }

    /**
     * Count the number of migrations by status.
     *
     * @return array
     */
    public function countStatus()
    {
        $stats = DbMigratorModel::where('migrate', $this->currentClass)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Ensure all statuses are present in the result
        return array_merge(array_fill_keys(array_map(fn($s) => $s->value, MigratorStatus::cases()), 0), $stats);
    }

    public function restart()
    {
        $this->printMigrationStatus("Restarting all migrations for this class...");
        $this->markAllAsRestart();
        $this->markFirstBatchAsPending();
        $this->displayMigrationProgress();
        return 1;
    }

    public function pause()
    {
        $this->markAsPaused();
    }

    public function resume()
    {
        // Find the most recent migration for this migrator, regardless of current status
        $lastMigration = DbMigratorModel::where('migrate', $this->currentClass)
            ->orderByDesc('batch')
            ->first();

        if ($lastMigration) {
            $this->markAsPending($lastMigration);
            $this->printMigrationStatus("Resumed migration batch {$lastMigration->batch}: status set to pending.");
        } else {
            $this->printMigrationStatus("No previous migration found to resume.");
        }
    }
    
    public function migrate()
    {   
        $currentClass = static::class;
        $datetime = now()->toDateTimeString();
        $this->printMigrationStatus("[" . $datetime . "] Processing migration: {$currentClass}");
        $this->createPendingMigration();
        $this->printMigrationStatus("[" . $datetime . "] INFO  Queued migration: {$currentClass}");
    }

    /**
     * Print a reusable migration status message with called class.
     *
     * @param string $message
     * @param string|null $color
     * @return void
     */
    protected function printMigrationStatus(string $message): void
    {
        $datetime = now()->toDateTimeString();
        $plainMessage = "[" . get_called_class() . "][$datetime] {$message}\n";
        print($plainMessage);
    }

    /**
     * Determine if this migration should remain pending ("keep on running").
     *
     * @return bool
     */
    protected function shouldKeepOnRunning(): bool
    {
        return (bool) $this->keepOnRunning;
    }

    /**
     * Determine if this migration should remain pending until the true totalSize is reached.
     *
     * @return bool
     */
    protected function shouldKeepOnUntilTotalSize(): bool
    {
        if (!$this->keepOnUntilTotalSize) {
            return false;
        }

        return $this->totalMigrated() < $this->totalSize();
    }

    protected function getMeta()
    {
        return [ 'options' => $this->getOptions() ];
    }

    protected function getOptions()
    {
        return $this->migrationOptions;
    }

    protected function buildOptions($params = [])
    {
        return [];
    }

    public function getSourceConnection()
    {
        return static::$sourceConnection ?? null;
    }

    protected function transformSourceData()
    {
        return $this->sourceData();
    }

    protected function countSourceData($data = null): int
    {
        $data ??= $this->transformSourceData();

        if (is_array($data)) {
            return count($data);
        }

        // Check for Laravel/Eloquent Collection or Arrayable instances
        if (is_object($data)) {
            // If it's a Laravel Collection
            if (method_exists($data, 'count')) {
                return $data->count();
            }
            // If it can be converted to array
            if (method_exists($data, 'toArray')) {
                return count($data->toArray());
            }
        }

        // As fallback, try to cast and count
        return is_countable($data) ? count($data) : 0;
    }

    public function markFirstBatchAsPending()
    {
        $firstMigration = DbMigratorModel::where('migrate', $this->currentClass)
            ->orderBy('batch', 'asc')
            ->first();

        if ($firstMigration) {
            $firstMigration->status = MigratorStatus::PENDING->value;
            $firstMigration->meta = $this->getMeta();
            $firstMigration->save();
            return $firstMigration;
        }

        return null;
    }

    public function markAsPending(DbMigratorModel $dbMigrator)
    {
        $dbMigrator->status = MigratorStatus::PENDING->value;
        $dbMigrator->save();
        return $dbMigrator;
    }

    public function markAsDone(DbMigratorModel $dbMigrator)
    {
        $dbMigrator->status = MigratorStatus::DONE->value;
        $dbMigrator->save();
        return $dbMigrator;
    }

    public function markAsPaused()
    {
        $firstMigration = DbMigratorModel::where('migrate', $this->currentClass)
            ->orderBy('id', 'desc')
            ->first();

        if ($firstMigration) {
            $firstMigration->status = MigratorStatus::PAUSED->value;
            $firstMigration->save();
            return $firstMigration;
        }

        return null;
    }

    protected function markAllAsRestart()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->update(['status' => MigratorStatus::RESTART->value]);
    }

    protected function markAsSuccess(DbMigratorModel $dbMigrator, array $result)
    {
        $dbMigrator->status = MigratorStatus::SUCCESS->value;
        $dbMigrator->total_migrated = $result['size'];
        $dbMigrator->save();
        return $dbMigrator;
    }

    public function markAsFailed(DbMigratorModel $dbMigrator, $message)
    {
        // Trim the message if it's too long (e.g., max 1024 chars)
        $maxMsgLen = 1024;
        $trimmedMsg = (is_string($message) && strlen($message) > $maxMsgLen)
            ? substr($message, 0, $maxMsgLen) . '...'
            : $message;

        $dbMigrator->message = $trimmedMsg;
        $dbMigrator->status = MigratorStatus::FAILED->value;

        $dbMigrator->save();
        return $dbMigrator;
    }

    protected function createPendingMigration()
    {
        $existing = $this->activeMigration;

        if($existing && in_array($existing?->status, $this->getActiveMigrationStatuses())) {
            throw new RuntimeException(sprintf(
                'Cannot create migration: found existing migration (status: %s, batch: %s) for [%s].',
                $existing->status ?? 'unknown',
                $existing->batch ?? 'n/a',
                $this->currentClass
            ));
        }

        // Get the last successful batch using this class's method.
        $lastSuccessBatch = $this->getLastBatchSuccessMigration();
        $this->batch = ($lastSuccessBatch?->batch + 0) + 1;

        $options = $lastSuccessBatch?->meta['options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }
        $this->migrationOptions = $this->buildOptions($options);
     
        $this->storeDbMigrations(
            new DbMigratorDto(
                $this->currentClass,
                MigratorStatus::PENDING,
                $this->batch,
                meta: $this->getMeta()
            )
        );
    }

    protected function newPendingMigration($dbMigrator)
    {
        $this->batch = ($dbMigrator?->batch ?? 0) + 1;

        $existingRetryDbMigration = $this->getFirstRestartMigration();

        if($existingRetryDbMigration) {
            $existingRetryDbMigration->status = MigratorStatus::PENDING->value;
            $existingRetryDbMigration->meta = $this->getMeta();
            $existingRetryDbMigration->save();
        } else {
            $this->storeDbMigrations(
                new DbMigratorDto(
                    $this->currentClass,
                    MigratorStatus::PENDING,
                    $this->batch,
                    meta: $this->getMeta()
                )
            );
        }
    }

    public function getFirstRestartMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::RESTART->value)
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Returns the total number of items available for migration.
     * 
     * By default, returns 0. Override in subclasses to provide accurate counts.
     *
     * @return int
     */
    protected function totalSize()
    {
        // NOTE: Subclasses should override this to return the actual db data size.
        return 0;
    }

    /**
     * Returns the actual number of successfully migrated items.
     *
     * By default, returns 0. Subclasses should override this for real logic.
     *
     * @return int
     */
    protected function actualMigrated()
    {
        // NOTE: Subclasses should override this to return how many items have been migrated.
        return $this->countMigrated();
    }

    /**
     * Returns the total number of items that have been migrated so far.
     *
     * By default, returns 0. Override in subclasses for actual progress tracking.
     *
     * @return int
     */
    protected function totalMigrated()
    {
        // NOTE: Subclasses should override this to return how many items have been migrated.
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::SUCCESS->value)
            ->sum('total_migrated');
    }

    public function getMigrationStats(bool $truth = false)
    {
        $cacheKey = $this->getTotalMigratedCacheKey();

        $migrationStats = function () {
            $totalSize = $this->totalSize();
            $actualMigrated = $this->actualMigrated();
            $totalMigrated = $this->totalMigrated();
            $remaining = $totalSize - ($actualMigrated == 0 ? $totalMigrated : $actualMigrated);
            $statusCounts = $this->countStatus();

            return [
                'total_size' => $totalSize,
                'actual_migrated' => $actualMigrated,
                'total_migrated' => $totalMigrated,
                'remaining' => $remaining,
                'status_counts' => $statusCounts,
            ];
        };

        if(!$this->cacheStats || $truth) return $migrationStats();

        return cache()->remember($cacheKey, config('db-migrator.monitoring.interval', DbMigratorCommand::WATCHER_INTERVAL), callback: $migrationStats);
    }

    public function getQueueIndex()
    {
        return $this->queueIndex;
    }

    public function getGroupName()
    {
        return $this->groupName;
    }

    protected function throwIfCountMismatch($sourceDataCount)
    {
        // If a closure is provided, execute it to get the stored count for comparison
        $storedCount = $this->getStoredData();

        // Only check if $storedCount is set and is numeric
        if ($storedCount !== null && is_numeric($storedCount)) {
            if ($sourceDataCount !== $storedCount) {
                $errorMessage = "Migration count mismatch: source data count ({$sourceDataCount}) does not match stored count ({$storedCount})";
                $this->findUnsavedData($errorMessage);
                throw new RuntimeException($errorMessage);
            }
        }
    }


    protected function findUnsavedData($message)
    {
        return null;
    }

    protected function getParams()
    {
        return $this->params;
    }

    protected function getStoredData()
    {
        return null;
    }

    protected function onRestart(): bool
    {
        return true;
    }

 
    private function storeDbMigrations(?DbMigratorDto $dbMigratorDto)
    {
        $payload = $dbMigratorDto->toArray();
        $migrationIdentifier = [
            'migrate' => $payload['migrate'],
            'batch' => $payload['batch']
        ];

        if ($dbMigratorDto?->id) {
            $migrationIdentifier['id'] = $dbMigratorDto->id;
        }

        return DbMigratorModel::updateOrCreate(
            $migrationIdentifier,
            $payload
        );
    }

    private function getTotalMigratedCacheKey()
    {
        return 'db_migrated_total:' . $this->currentClass;
    }

    private function displayMigrationProgress(bool $truth = false)
    {
        printf("\n%-18s: %s\n%-18s: %s\n", "Migration Class", get_class($this), "Report Date", date('Y-m-d H:i:s'));
        $stats = $this->getMigrationStats($truth);

        $divider = str_repeat('-', 39) . "\n";
        print($divider);
        print("STATS\n");
        print($divider);

        printf(
            "Recorded Migrated : %d\nActual Migrated   : %d\nTotal             : %d\nRemaining         : %d\n",
            $stats['total_migrated'],
            $stats['actual_migrated'],
            $stats['total_size'],
            $stats['remaining']
        );

        // Show memory usage
        $memUsage = memory_get_usage(true);
        $memPeak = memory_get_peak_usage(true);

        // Inline function to format bytes to human-readable string
        $formatBytes = function ($bytes) {
            if ($bytes < 1024) {
                return $bytes . ' B';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            $exp = (int) (log($bytes) / log(1024));
            return round($bytes / (1024 ** $exp), 2) . ' ' . $units[$exp];
        };

        printf("\nMemory Usage      : %s\nMemory Peak       : %s\n",
            $formatBytes($memUsage),
            $formatBytes($memPeak)
        );

        print($divider);
        print("STATUS\n");
        print($divider);

        foreach ($stats['status_counts'] as $status => $count) {
            printf("%-14s: %d\n", ucfirst($status), $count);
        }

        print($divider);
        print("Note: These numbers may not be perfectly precise due to differences in migration logic, caches, or source data updates.\n");
    }

    private function loadOptions()
    {
        $this->migrationOptions ??= $this->buildOptions();
        $this->currentClass = get_class($this);
        $this->activeMigration = $this->getActiveDbMigration();
    }

    private function process(DbMigratorModel $dbMigrator)
    {
        $meta = $dbMigrator->meta;
        $this->migrationOptions = $meta['options'];
        $params = $this->params = $this->buildHandleParams();
        if($params['size'] > 0 || $this->shouldKeepOnUntilTotalSize()) {
            $this->handle($params);
            $this->throwIfCountMismatch($params['size'] ?? 0);
            $this->migrationOptions = $this->buildOptions($meta['options']);
        }

        return $params;
    }

    /**
     * Build data to be passed into the handle() method as params.
     * You may override this in subclasses for custom behavior.
     */
    private function buildHandleParams()
    {
        $sourceData = $this->transformSourceData();
        return collect([ 
            'sourceData' => $sourceData,
            'size' => $this->countSourceData($sourceData)
        ]);
    }

    private function getActiveDbMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->whereIn('status', $this->getActiveMigrationStatuses())
            ->orderByDesc('batch')
            ->first();
    }

    private function getLastBatchSuccessMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::SUCCESS->value)
            ->orderByDesc('batch')
            ->first();
    }

    /**
     * Get all statuses that represent a migration still pending resolution.
     *
     * @return array
     */
    private function getActiveMigrationStatuses(): array
    {
        return [
            MigratorStatus::PENDING->value,
            MigratorStatus::ONGOING->value,
            MigratorStatus::FAILED->value,
        ];
    }

}