<?php

namespace Mreycode\DbMigrator;

use Illuminate\Contracts\Support\Arrayable;
use Mreycode\DbMigrator\Enums\MigratorStatus;

class DbMigratorDto implements Arrayable
{
    public string $migration;
    public MigratorStatus $status;
    public int $batch;
    public ?string $message;
    public array $meta;
    public ?string $createdAt;
    public ?string $updatedAt;

    public ?int $id = null;

    public function __construct(
        string $migration,
        MigratorStatus $status,
        int $batch,
        ?string $message = null,
        array $meta = [],
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?int $id = null
    ) {
        $this->migration = $migration;
        $this->status = $status;
        $this->batch = $batch;
        $this->message = $message;
        $this->meta = $meta;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->id = $id;
    }

    /**
     * Convert DTO to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'migration' => $this->migration,
            'status' => $this->status->value,
            'batch' => $this->batch,
            'message' => $this->message,
            'meta' => $this->meta,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
