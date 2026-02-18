<p align="center"><a href="https://jonreygalera.vercel.app" target="_blank"><img src="https://raw.githubusercontent.com/jonreygalera/mreydocs/refs/heads/main/assets/logo.png" alt="Mreycode Logo"></a></p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)
[![Total Downloads](https://img.shields.io/packagist/dt/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)
[![PHP Version](https://img.shields.io/packagist/php-v/mreycode/laravel-db-migrator.svg?style=flat-square)](https://packagist.org/packages/mreycode/laravel-db-migrator)

# Laravel Db Migrator
A production-grade Laravel package for complex, large-scale database migrations with resumable, monitored, and batch-aware processing.

## Documentation

For full documentation, please visit [**Mreycode Docs - Laravel Db Migrator**](https://github.com/jonreygalera/mreydocs/blob/main/laravel-db-migrator/README.md).

## Introduction

Laravel Db Migrator transforms the often-chaotic process of data ETL (Extract, Transform, Load) into a manageable, monitored, and resumable workflow. Unlike standard schema migrations, this package is designed specifically for high-volume data movement between connections.

## Installation

You can install the package via composer:

```bash
composer require mreycode/laravel-db-migrator
```

You should publish the configuration and system migrations using:

```bash
php artisan vendor:publish --tag=db-migrator-config
php artisan vendor:publish --tag=db-migrator-migrations
php artisan migrate
```

## Quick Start

Generate a new migrator class:

```bash
php artisan make:db-migrator UserMigrator
```

Implement your migration logic:

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

    public function sourceData()
    {
        return $this->getSourceConnection()
            ->table('legacy_users')
            ->offset($this->getOptions()['offset'])
            ->limit($this->getOptions()['limit'])
            ->get();
    }

    public function handle($params = null)
    {
        foreach ($params['sourceData'] as $row) {
            User::create([
                'name'  => $row->full_name,
                'email' => $row->email,
            ]);
        }
    }
}
```

Run your migration via the interactive dashboard:

```bash
php artisan mreycode:db-migrate -i
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email jonreygalera@gmail.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
