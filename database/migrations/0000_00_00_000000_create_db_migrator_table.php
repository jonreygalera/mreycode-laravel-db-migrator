<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        $tableNames = config('db-migrator.tables', []);
        $dbMigratorTable = $tableNames['db_migrator'] ?? $tableNames['db_migrators'] ?? 'db_migrators';
        $historyTable = $tableNames['migrator_history'] ?? $tableNames['migrator_histories'] ?? 'migrator_histories';

        Schema::create($dbMigratorTable, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('migrate');
            $table->string('status');
            $table->integer('batch')->default(1);
            $table->integer('total_migrated')->nullable()->comment('Total number of records migrated upon success only.');
            $table->text('message')->nullable()->comment('Optional message info for this migration. May be long.');
            $table->json('meta')->nullable();
            $table->timestamps();

            // Optimized for common query patterns
            $table->index(['migrate', 'status'], 'db_migrators_migrate_status_index');
            $table->index(['migrate', 'batch'], 'db_migrators_migrate_batch_index');
            $table->index('created_at');
        });

        Schema::create($historyTable, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_name');
            $table->bigInteger('source_id');
            $table->bigInteger('target_id')->nullable()->index();
            $table->string('migrator_name');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_name', 'migrator_name', 'source_id'], 'migrator_histories_unique');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('db-migrator.tables', []);
        $dbMigratorTable = $tableNames['db_migrator'] ?? $tableNames['db_migrators'] ?? 'db_migrators';
        $historyTable = $tableNames['migrator_history'] ?? $tableNames['migrator_histories'] ?? 'migrator_histories';
        
        if ($tableNames) {
            Schema::dropIfExists($historyTable);
            Schema::dropIfExists($dbMigratorTable);
        }
    }
};