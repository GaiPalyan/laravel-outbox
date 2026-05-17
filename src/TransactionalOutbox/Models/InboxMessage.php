<?php

declare(strict_types=1);

namespace TransactionalOutbox\Models;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use TransactionalOutbox\Enums\Database\InboxMessageColumn;
use TransactionalOutbox\Enums\InboxStatus;
use TransactionalOutbox\Models\Builders\InboxMessageBuilder;

/**
 * @property string $id
 * @property string $channel
 * @property array<string, mixed>|null $headers
 * @property string $payload
 * @property int $status_id
 * @property int $attempts
 * @property Carbon $next_retry_at
 * @property Carbon|null $in_progress_deadline_at
 * @property string $deduplication_key
 * @property string|null $last_error_text
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read InboxStatus $status_enum
 *
 * @method static InboxMessageBuilder inPending()
 * @method static InboxMessageBuilder inProgress()
 * @method static InboxMessageBuilder inProcessed()
 * @method static InboxMessageBuilder inFailed()
 * @method static InboxMessageBuilder inReadyToProcess()
 * @method static InboxMessageBuilder inStuck()
 * @method static InboxMessageBuilder finishedOlderThan(Carbon $cutoffTime)
 */
#[UseEloquentBuilder(InboxMessageBuilder::class)]
class InboxMessage extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = InboxMessageColumn::TABLE->value;

    protected $fillable = [
        InboxMessageColumn::CHANNEL->value,
        InboxMessageColumn::HEADERS->value,
        InboxMessageColumn::PAYLOAD->value,
        InboxMessageColumn::STATUS->value,
        InboxMessageColumn::ATTEMPTS->value,
        InboxMessageColumn::NEXT_RETRY_AT->value,
        InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value,
        InboxMessageColumn::DEDUPLICATION_KEY->value,
        InboxMessageColumn::LAST_ERROR_TEXT->value,
    ];

    protected $casts = [
        InboxMessageColumn::HEADERS->value => 'array',
        InboxMessageColumn::NEXT_RETRY_AT->value => 'datetime',
        InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => 'datetime',
    ];

    /**
     * @return Attribute<InboxStatus, never>
     */
    protected function statusEnum(): Attribute
    {
        return Attribute::make(
            get: fn () => InboxStatus::fromId($this->status_id),
        )->withoutObjectCaching();
    }

    // -------------------------------------------------------------------------
    // Static factory / action methods
    // -------------------------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model) {
            $model->deduplication_key ??= self::dedup($model->payload);
            $model->status_id ??= InboxStatus::Pending->id();
            $model->attempts ??= 0;
            $model->next_retry_at ??= Carbon::now();
        });
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function store(string $channel, string $payload, array $headers = []): self
    {
        /** @var self $message */
        $message = self::firstOrCreate(
            [InboxMessageColumn::DEDUPLICATION_KEY->value => self::dedup($payload)],
            [
                InboxMessageColumn::CHANNEL->value => $channel,
                InboxMessageColumn::PAYLOAD->value => $payload,
                InboxMessageColumn::HEADERS->value => $headers ?: null,
            ],
        );

        return $message;
    }

    public static function resetStuck(): int
    {
        return self::inStuck()->update([
            InboxMessageColumn::STATUS->value => InboxStatus::Pending->id(),
            InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
        ]);
    }

    public function prunable(): InboxMessageBuilder
    {
        $cutoff = Carbon::now()->subDays(
            config('transactional-outbox.inbox.prune_after_days', 30),
        );

        return self::finishedOlderThan($cutoff);
    }

    private static function dedup(string $payload): string
    {
        return hash('murmur3f', $payload);
    }

    // -------------------------------------------------------------------------
    // Instance state-transition methods
    // -------------------------------------------------------------------------

    /**
     * Optimistic lock & mark progress
     */
    public function markAsInProgress(Carbon $deadline): bool
    {
        return self::where(InboxMessageColumn::ID->value, $this->id)
            ->where(InboxMessageColumn::STATUS->value, InboxStatus::Pending->id())
            ->update([
                InboxMessageColumn::STATUS->value => InboxStatus::InProgress->id(),
                InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => $deadline,
            ]) === 1;
    }

    public function markAsProcessed(): bool
    {
        return $this->update([
            InboxMessageColumn::STATUS->value => InboxStatus::Processed->id(),
            InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
        ]);
    }

    public function markAsFailed(string $errorText): bool
    {
        return $this->update([
            InboxMessageColumn::STATUS->value => InboxStatus::Failed->id(),
            InboxMessageColumn::ATTEMPTS->value => $this->attempts + 1,
            InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
            InboxMessageColumn::LAST_ERROR_TEXT->value => $errorText,
        ]);
    }

    public function markAsPendingWithRetry(Carbon $nextRetryAt, string $errorText): bool
    {
        return $this->update([
            InboxMessageColumn::STATUS->value => InboxStatus::Pending->id(),
            InboxMessageColumn::ATTEMPTS->value => $this->attempts + 1,
            InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
            InboxMessageColumn::NEXT_RETRY_AT->value => $nextRetryAt,
            InboxMessageColumn::LAST_ERROR_TEXT->value => $errorText,
        ]);
    }
}
