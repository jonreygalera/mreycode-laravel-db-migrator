<?php
namespace Mreycode\DbMigrator\Models;

use Illuminate\Database\Eloquent\Model;

class DbMigrator extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'migrate',
        'status',
        'batch',
        'total_migrated',
        'message',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('db-migrator.tables.db_migrator', 'db_migrators');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getConnectionName()
    {
        return config('db-migrator.model_connection', config('database.default')); 
    }
}
