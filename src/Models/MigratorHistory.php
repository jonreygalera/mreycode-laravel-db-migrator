<?php
namespace Mreycode\DbMigrator\Models;

use Illuminate\Database\Eloquent\Model;

class MigratorHistory extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'id';

    protected $fillable = [
        'source_name',
        'source_id',
        'target_id',
        'migrator_name',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('db-migrator.tables.migrator_history', 'migrator_histories');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }


}
