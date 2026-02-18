<?php

namespace Mreycode\DbMigrator\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mreycode\DbMigrator\Models\MigratorHistory;

trait HasMigratorHistory
{
    public $migratorSourceName = null;
    public $dbMigratorName = null;
    public $migratorSourceId = 'source_id';
    public $migratorTargetId = 'id';
    public bool $recordMigratorHistory = false;

    public function recordMigrationMapping($sourceData)
    {
        if(!$this->recordMigratorHistory) return collect([]);

        $sourceId = $this->migratorSourceId;
        $targetId = $this->migratorTargetId;
        
        $sourceData = collect($sourceData);

        $sourceName = $this->getMigratorSourceName();
        $migratorName = $this->getMigratorName();

        $forMigrationDb = $sourceData->map(function($item) use($sourceName, $migratorName, $sourceId, $targetId) {
            return [
                'id' => Str::uuid(),
                'source_name' => $sourceName,
                'source_id' => $item->{$sourceId} ?? $item[$sourceId],
                'target_id' => $item->{$targetId} ?? $item[$targetId],
                'migrator_name' => $migratorName,
            ];
        });

       $this->storeMigratorHistory($forMigrationDb);

        return $sourceData->map(function($item) use($sourceId) {
            return collect($item)->except([$sourceId])->toArray();
        });
    }

    public function storeMigratorHistory(Collection|array $migratorData): void
    {
        collect($migratorData)->chunk(1000)->each(fn (Collection $chunk) => 
            MigratorHistory::upsert(
                $chunk->toArray(),
                ['source_name', 'migrator_name', 'source_id'],
                ['target_id']
            )
        );
    }

    public function getMigratorDbSource()
    {
        $sourceName = $this->getMigratorSourceName();
        $migratorName = $this->getMigratorName();

        return MigratorHistory::where('source_name', $sourceName)
            ->where('migrator_name', $migratorName);
    }

    public function getMigratorHistoryBySource(array $sourceIds)
    {
        return $this->getMigratorDbSource()
            ->whereIn('source_id', $sourceIds)
            ->get();
    }

    public function countMigrated()
    {
        return $this->getMigratorDbSource()->count();
    }

    public function getMigratorSourceName()
    {
        return $this->migratorSourceName ?? static::class;
    }

    public function getMigratorName()
    {
        return $this->dbMigratorName ?? static::class;
    }
}