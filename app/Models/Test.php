<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model representing a Playwright Test generation request.
 * Tracks the repository coordinates, configuration credentials, 
 * and the chronological lifecycle state of the generation process.
 */
class Test extends Model
{
    /** Lifecycle state constants */
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
        'test_email',
        'test_password',
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

    /**
     * Provides a mapping of internal statuses to human-readable labels.
     *
     * @return array<string, string>
     */
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

    /**
     * Checks if the test is currently in the active generation phase.
     */
    public function isGenerating(): bool
    {
        return $this->status === self::STATUS_GENERATING;
    }

    /**
     * Checks if the test generation process encountered an unrecoverable error.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Checks if the test generation process finished successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Checks if the test generation was manually aborted.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
