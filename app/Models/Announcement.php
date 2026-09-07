<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A notice shown to everyone on the dashboard.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property bool $is_pinned
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $author
 */
#[Fillable(['user_id', 'title', 'body', 'is_pinned', 'published_at', 'expires_at', 'notified_at'])]
class Announcement extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_pinned' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Published, and not yet expired. An unpublished notice is a draft only
     * its author sees; an expired one comes down on its own.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = Carbon::now();

        $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', $now));
    }

    /**
     * Pinned first, then newest. Used everywhere the notices are listed so
     * the dashboard and the admin page agree on the order.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeInReadingOrder(Builder $query): void
    {
        $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Whether the company still has to be told about this one. A notice
     * published, pulled back and published again has already been sent, and
     * is not sent twice.
     */
    public function awaitsNotifying(): bool
    {
        return $this->isLive() && $this->notified_at === null;
    }

    /**
     * The opening of the body, for the dashboard panel and the push payload.
     */
    public function excerpt(int $characters = 140): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $this->body) ?? ''), $characters);
    }

    public function isLive(): bool
    {
        if ($this->published_at === null || $this->published_at->isFuture()) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Where this notice stands, for the pill on the admin page.
     */
    public function state(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }

        if ($this->published_at->isFuture()) {
            return 'scheduled';
        }

        return $this->expires_at !== null && $this->expires_at->isPast()
            ? 'expired'
            : 'live';
    }
}
