<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Test extends Model
{
    public const STATUS_NONE = 'none';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'tests';

    protected $fillable = [
        'name',
        'repo_name',
        'repo_url',
        'source_branch',
        'test_branch',
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
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }
}
