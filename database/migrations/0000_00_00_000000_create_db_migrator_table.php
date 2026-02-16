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

        $tableNames = config('db-migrator.tables');
        throw_if(empty($tableNames), new Exception('Error: config/db-migrator.php not loaded. Run [php artisan config:clear] and try again.'));

        
        Schema::create($tableNames['db_migrators'], function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('migrate')->index();
            $table->string('status')->index();
            $table->integer('batch')->default(1)->index();
            $table->integer('total_migrated')->nullable()->comment('Total number of records migrated upon success only.');
            $table->text('message')->nullable()->comment('Optional message info for this migration. May be long.');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create($tableNames['migrator_histories'], function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_name');
            $table->bigInteger('source_id');
            $table->bigInteger('target_id')->nullable()->index();
            $table->string('migrator_name')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_name', 'source_id', 'migrator_name'], 'mreycode_md_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('db_migrator');
        Schema::dropIfExists('migration_history');
    }
};