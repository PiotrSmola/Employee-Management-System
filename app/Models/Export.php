<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Jobs\GenerateExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * A bulk export generated in the background ({@see GenerateExport}),
 * listed under "My exports". Only its owner can see, download or delete it.
 *
 * @property int $id
 * @property int $user_id
 * @property ExportFormat $format
 * @property array{selection: array<string, mixed>, columns: string[]} $params
 * @property string $status one of the STATUS_* constants
 * @property string|null $file_path path on the exports disk
 * @property string|null $file_name download name
 * @property int|null $file_size bytes
 * @property int|null $row_count
 * @property string|null $error shown to the user (details are in the log)
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class Export extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Not finished yet: never pruned, and counted against the per-user limit. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'params' => 'array',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path !== null;
    }

    public static function diskName(): string
    {
        return (string) config('data_tools.exports.disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /**
     * Delete the generated file (if any) together with the entry.
     */
    public function deleteWithFile(): void
    {
        if ($this->file_path !== null) {
            self::disk()->delete($this->file_path);
        }

        $this->delete();
    }

    public function humanFileSize(): string
    {
        return $this->file_size !== null ? Number::fileSize($this->file_size, 1) : '-';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => __('Queued'),
            self::STATUS_PROCESSING => __('Generating'),
            self::STATUS_COMPLETED => __('Ready'),
            self::STATUS_FAILED => __('Failed'),
            default => $this->status,
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'bg-success',
            self::STATUS_FAILED => 'bg-danger',
            self::STATUS_PROCESSING => 'bg-primary',
            default => 'bg-secondary',
        };
    }
}
