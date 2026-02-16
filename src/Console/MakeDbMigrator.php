<?php

namespace Mreycode\DbMigrator\Console;

use Illuminate\Console\GeneratorCommand;

class MakeDbMigrator extends GeneratorCommand
{
    protected $name = 'make:db-migrator';
    protected $description = 'Create a new db migrator class';
    protected $type = 'DbMigrator';

    // Where to put generated classes
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace.'\DbMigrators';
    }

    // Stub file path
    protected function getStub()
    {
        return __DIR__.'/stubs/db-migrator.stub';
    }
}