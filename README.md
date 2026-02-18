# 🚀 Laravel Db Migrator

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)
[![Total Downloads](https://img.shields.io/packagist/dt/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)
[![PHP Version](https://img.shields.io/packagist/php-v/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)

**Laravel Db Migrator** is a high-performance, production-grade framework designed for complex, high-volume database migrations. It transforms the often-chaotic process of data ETL (Extract, Transform, Load) into a manageable, monitored, and resumable workflow.

---

## 🏗️ Architecture & Philosophy

Standard Laravel migrations handle schema. **Db Migrator** handles _data_. It is built on three pillars:

1.  **Atomicity:** Each migration batch is wrapped in a database transaction. We ensure data integrity by either succeeding completely or failing gracefully.
2.  **Observability:** Migration isn't a "black box." Real-time stats provide visibility into throughput, memory usage, and failure points.
3.  **Resilience:** Failures are inevitable in large datasets. Our state-aware architecture allows for instant resume, retry, or restart at any level.

---

## 🛠️ Getting Started

### Installation

```bash
composer require mreycode/laravel-db-migrator
```

### Setup

Publish the required assets to your application:

```bash
# Publish configuration
php artisan vendor:publish --tag=db-migrator-config

# Publish system migrations
php artisan vendor:publish --tag=db-migrator-migrations

# Run system migrations
php artisan migrate
```

---

## 📖 The Migrator Lifecycle

Each migration class progresses through a defined state machine:

| Status        | Description                                                                          |
| :------------ | :----------------------------------------------------------------------------------- |
| **`PENDING`** | The batch is queued and waiting for processing.                                      |
| **`ONGOING`** | The batch is currently being processed by a worker or CLI command.                   |
| **`SUCCESS`** | The batch completed successfully. A new `PENDING` batch is usually created.          |
| **`FAILED`**  | An error occurred. The transaction was rolled back, and the error stored for review. |
| **`DONE`**    | No more data was found. The migrator has completed its entire task.                  |
| **`PAUSED`**  | Execution was manually stopped after the current batch finished.                     |

---

## 🚀 Creating Your First Migrator

### 1. Generate the Scaffold

```bash
php artisan make:db-migrator UserMigrator
```

### 2. Implementation Guide

```php
namespace App\DbMigrators;

use Mreycode\DbMigrator\AbstractDbMigrator;
use App\Models\User;

class UserMigrator extends AbstractDbMigrator
{
    /** @var string The column name representing the unique ID in the source data */
    public $migratorSourceId = 'source_id';

    /** @var string The column name representing the unique ID in the target table */
    public $migratorTargetId = 'id';

    /** @var bool Set to true to store the source-to-target ID mapping in the history table */
    public bool $recordMigratorHistory = false;

    /**
     * Define the data to be migrated in this batch.
     * Uses the 'source_connection' defined in config or $dbConnection property.
     */
    public function sourceData()
    {
        return $this->getSourceConnection()
            ->table('legacy_users')
            ->offset($this->getOptions()['offset'])
            ->limit($this->getOptions()['limit'])
            ->get();
    }

    /**
     * Core transformation logic.
     * @param \Illuminate\Support\Collection $params Contains 'sourceData' and 'size'
     */
    public function handle($params = null)
    {
        foreach ($params['sourceData'] as $row) {
            // Business Logic & Transformation
            User::create([
                'name'  => "{$row->first_name} {$row->last_name}",
                'email' => $row->email_address,
                'legacy_id' => $row->id,
            ]);
        }
    }

    /**
     * Advanced: Customize batch pagination logic.
     */
    protected function buildOptions($params = [])
    {
        $limit = $params['limit'] ?? 1000;
        $offset = isset($params['offset']) ? ($params['offset'] + $limit) : 0;

        return [
            'limit' => $limit,
            'offset' => $offset
        ];
    }
}
```

---

## 🖥️ Command Line Orchestration

### Interactive Dashboard

```bash
php artisan mreycode:db-migrate -i
```

The recommended way to manage migrations. Select classes, view stats, and trigger actions through a robust CLI menu.

### Power User Flags

| Flag | Full Option         | Use Case                                                                  |
| :--- | :------------------ | :------------------------------------------------------------------------ |
| `-C` | `--continues-stats` | **Live Monitor:** Refreshes the terminal every 30s with progress metrics. |
| `-u` | `--resume`          | **Safety:** Picks up exactly where the last batch stopped.                |
| `-r` | `--retry`           | **Recovery:** Globally resets all `FAILED` migrations back to `PENDING`.  |
| `-R` | `--restart`         | **Reset:** Wipes all progress for a class to start from batch #1.         |
| `-p` | `--pause`           | **Control:** Signals workers to stop gracefully after the current batch.  |
| `-g` | `--group=...`       | **Bulk:** Run a specific set of migrators (e.g., `users`, `billing`).     |

---

## 📦 Background Processing & Scaling

For enterprise-scale datasets, use the specialized background worker:

```bash
php artisan mreycode:db-worker --max-jobs=500 --memory=1024
```

- **Signal Awareness:** Responds to `SIGINT` and `SIGTERM` for graceful shutdowns.
- **Resource Management:** Automatically recycles workers when `--memory` limits are reached to prevent OOM errors.
- **Queue Integration:** Efficiently polls and dispatches migration jobs without blocking.

---

## ⚙️ Configuration Reference (`db-migrator.php`)

| Key                   | Default | Description                                                                  |
| :-------------------- | :------ | :--------------------------------------------------------------------------- |
| `sequence`            | `[]`    | Critical: Defines the order of execution to satisfy foreign key constraints. |
| `monitoring.interval` | `30`    | Seconds between refreshes for the continuous stats watcher.                  |
| `worker.delay`        | `5`     | Seconds to wait between job dispatches.                                      |
| `worker.sleep`        | `3`     | Seconds to sleep when no pending migrations are found.                       |
| `tables`              | `[...]` | Customize the table names used for tracking and history.                     |

---

## 🧩 Advanced Features

### 🚦 Smart Sequence

Ensure `UserMigrator` runs before `OrderMigrator` by listing them in the `sequence` array in `config/db-migrator.php`. Discovered classes not in the list will run afterward in alphabetical order.

### 🔄 Delta Migrations (`keepOnRunning`)

Perfect for live migration windows. Setting `protected $keepOnRunning = true;` prevents the migrator from entering the `DONE` state, allowing it to pick up new records as they appear in the legacy database.

---

## 🤝 Community & Support

- **Found a bug?** Open an [issue](https://github.com/mreycode/laravel-db-migrator/issues).
- **Want to contribute?** Pull requests are welcome!
- **Feedback?** Contact Jon Rey Galera at [jonreygalera@gmail.com](mailto:jonreygalera@gmail.com).

---

<p align="center">
  <i>"Migration is not just about moving data; it's about moving history with integrity."</i>
</p>
