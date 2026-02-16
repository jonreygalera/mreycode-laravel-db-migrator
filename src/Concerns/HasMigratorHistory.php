<?php

namespace Mreycode\DbMigrator\Concerns;

use Mreycode\DbMigrator\Models\MigrationDb;

trait HasMigratorHistory
{
    public $migrationDbSourceName = null;
    public $migrationDbMigrationTableName = null;
    
    public function migrationDb($sourceData, $sourceId, $pk = 'id')
    {
        $sourceName = $this->getMigrationDbSourceName();
        $migrationTableName = $this->getMigrationDbMigrationTableName();

        $forMigrationDb = $sourceData->map(function($item) use($sourceName, $migrationTableName, $sourceId, $pk) {
            return [
                'source_name' => $sourceName,
                'source_id' => $item[$sourceId],
                'pk_id' => $item[$pk],
                'migration_table_name' => $migrationTableName,
            ];
        });

        MigrationDb::upsert($forMigrationDb->toArray(), ['source_name', 'migration_table_name', 'source_id'], ['pk_id']);

        return $sourceData->map(function($item) use($sourceId) {
            return collect($item)->except([$sourceId])->toArray();
        });
    }

    public function getMigrationDbSource()
    {
        $sourceName = $this->getMigrationDbSourceName();
        $migrationTableName = $this->getMigrationDbMigrationTableName();

        return MigrationDb::where('source_name', $sourceName)
            ->where('migration_table_name', $migrationTableName);
    }

    public function getMigrationDb(array $sourceIds)
    {
        return $this->getMigrationDbSource()
            ->whereIn('source_id', $sourceIds)
            ->get();
    }

    public function countMigrated()
    {
        return $this->getMigrationDbSource()->count();
    }

    public function getMigrationDbSourceName()
    {
        return $this->migrationDbSourceName ?? static::class;
    }

    public function getMigrationDbMigrationTableName()
    {
        return $this->migrationDbMigrationTableName ?? static::class;
    }
}