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
    // even if there is no source data from the source app. This allows new data
    // to be migrated from source to new later on.
    protected $keepOnRunning = false;
    // Property to determine if migration should keep running until a certain totalSize is reached
    protected $keepOnUntilTotalSize = false;
    protected $dbConnection = 'db_tests';

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
        
        $connection = $this->dbConnection ?? config('db-migrator.source_connection', 'db');
        self::$sourceConnection = DB::connection($connection);

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
        $this->printMigrationStatus("Checking if we can resume...");
        $activeMigration = $this->getActiveDbMigration();
        if ($activeMigration) {
            $this->printMigrationStatus("Active migration found with status: " . $activeMigration->status);
            $this->markAsPending($activeMigration);
            $this->printMigrationStatus("Status set to PENDING.");
        } else {
            $this->printMigrationStatus("No active migration found to resume.");
        }
        $this->displayMigrationProgress();
    }

    public function migrate()
    {
        $this->printMigrationStatus("Starting migration...");
        if (!$this->activeMigration) {
            $this->markFirstBatchAsPending();
        }
        $this->run($this->getActiveDbMigration());
    }

    /**
     * Print a reusable migration status message with called class.
     *
     * @param string $message
     * @return void
     */
    public function printMigrationStatus(string $message)
    {
        echo "[" . now() . "] [" . $this->currentClass . "] " . $message . PHP_EOL;
    }

    /**
     * Determine if this migration should remain pending ("keep on running").
     *
     * @return bool
     */
    public function shouldKeepOnRunning()
    {
        return $this->keepOnRunning;
    }

    /**
     * Determine if this migration should remain pending until the true totalSize is reached.
     *
     * @return bool
     */
    public function shouldKeepOnUntilTotalSize()
    {
        if(!$this->keepOnUntilTotalSize) return false;

        $stats = $this->getActualMigrationStats();
        if($stats['migrated'] < $stats['total']) return true;

        return false;
    }

    public function getMeta()
    {
        return ['options' => $this->migrationOptions];
    }

    public function getOptions()
    {
        return $this->migrationOptions;
    }

    protected function buildOptions($params = [])
    {
        return [];
    }

    public function getSourceConnection()
    {
        return self::$sourceConnection;
    }

    protected function transformSourceData()
    {
        return [];
    }

    protected function countSourceData($data = null)
    {
        if(is_array($data)) return count($data);

        if($data instanceof \Illuminate\Support\Collection) return $data->count();

        if (is_null($data)) return 0;

        return 0;
    }

    public function markFirstBatchAsPending()
    {
        $exist = DbMigratorModel::where('migrate', $this->currentClass)
            ->first();

        if (!$exist) {
            DbMigratorModel::create([
                'migrate' => $this->currentClass,
                'status' => MigratorStatus::PENDING->value,
                'batch' => 1,
                'meta' => $this->getMeta(),
            ]);
        }
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
        $migration = $this->getActiveDbMigration();
        if ($migration) {
            $migration->status = MigratorStatus::PAUSED->value;
            $migration->save();
            $this->printMigrationStatus("Migration paused.");
        } else {
            $this->printMigrationStatus("No active migration to pause.");
        }
    }

    public function markAllAsRestart()
    {
        DbMigratorModel::where('migrate', $this->currentClass)
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
        $dbMigrator->status = MigratorStatus::FAILED->value;
        $dbMigrator->message = $message;
        $dbMigrator->save();
        return $dbMigrator;
    }

    private function displayMigrationProgress(bool $truth = false)
    {
        $stats = $this->getActualMigrationStats($truth);
        $total = $stats['total'];
        $migrated = $stats['migrated'];
        $percent = $total > 0 ? round(($migrated / $total) * 100, 2) : 0;
        $memory = round(memory_get_usage() / 1024 / 1024, 2);

        $statusSummary = $this->countStatus();
        $statusStr = '';
        foreach ($statusSummary as $status => $count) {
            $statusStr .= "[$status: $count] ";
        }

        echo "\r[" . now() . "] [" . $this->currentClass . "] Progress: $percent% ($migrated/$total) | Status: $statusStr | Memory: {$memory}MB";
        if($truth) echo PHP_EOL;
    }

    private function getActualMigrationStats(bool $truth = false)
    {
        $cacheKey = $this->getTotalMigratedCacheKey();
        $migrationStats = function() {
            return [
                'total' => $this->totalSize(),
                'migrated' => $this->actualMigrated(),
            ];
        };

        if(!$this->cacheStats || $truth) return $migrationStats();

        return cache()->remember($cacheKey, DbMigratorCommand::WATCHER_INTERVAL, callback: $migrationStats);
    }

    public function getQueueIndex()
    {
        return $this->queueIndex;
    }

    public function getGroupName()
    {
        return $this->groupName;
    }

    public function getDescription()
    {
        return null;
    }

    protected function totalSize()
    {
        return 0;
    }

    protected function actualMigrated()
    {
        return 0;
    }

    protected function storeDbMigrations(collect $sourceData, string $sourcePk, string $destPk)
    {
        $totalSize = $sourceData->count();
        $this->printMigrationStatus("Storing $totalSize migration records.");
        // Implement logic to map and store individual records if needed.
    }

    private function buildHandleParams()
    {
        $sourceData = $this->sourceData();
        $size = $this->countSourceData($sourceData);

        return collect([
            'sourceData' => $sourceData,
            'size' => $size,
        ]);
    }

    public function getActiveDbMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->whereIn('status', $this->getActiveMigrationStatuses())
            ->first();
    }

    public function getLastBatchSuccessMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::SUCCESS->value)
            ->orderBy('batch', 'desc')
            ->first();
    }

    public function getActiveMigrationStatuses()
    {
        return [
            MigratorStatus::PENDING->value,
            MigratorStatus::ONGOING->value,
        ];
    }

    protected function newPendingMigration(DbMigratorModel $dbMigrator)
    {
        $this->batch = ($dbMigrator->batch ?? 0) + 1;

        $existingRetryMigration = $this->getFirstRestartMigration();

        if($existingRetryMigration) {
            $existingRetryMigration->status = MigratorStatus::PENDING->value;
            $existingRetryMigration->meta = $this->getMeta();
            $existingRetryMigration->save();
        } else {
            DbMigratorModel::create([
                'migrate' => $this->currentClass,
                'status' => MigratorStatus::PENDING->value,
                'batch' => $this->batch,
                'meta' => $this->getMeta(),
            ]);
        }
    }

    private function getFirstRestartMigration()
    {
        return DbMigratorModel::where('migrate', $this->currentClass)
            ->where('status', MigratorStatus::RESTART->value)
            ->orderBy('batch', 'asc')
            ->first();
    }

    private function getTotalMigratedCacheKey()
    {
        return 'db-migrator:stats:' . $this->currentClass;
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
            $this->migrationOptions = $this->buildOptions($meta['options']);
        }

        return $params;
    }
}