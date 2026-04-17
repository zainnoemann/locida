<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Test extends Model
{
    public const STATUS_NONE = 'none';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'tests';

    protected $fillable = [
        'name',
        'repo_name',
        'repo_url',
        'app_url',
        'status',
        'error',
        'started_at',
        'failed_at',
        'generated_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'failed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NONE => 'Not Started',
            self::STATUS_GENERATING => 'Generating',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
        ];
    }
}
