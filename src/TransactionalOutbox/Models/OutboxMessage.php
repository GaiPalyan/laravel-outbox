<?php

declare(strict_types=1);

namespace TransactionalOutbox\Models;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use TransactionalOutbox\Data\OutboxDraft;
use TransactionalOutbox\Enums\Database\OutboxMessageColumn;
use TransactionalOutbox\Enums\OutboxStatus;
use TransactionalOutbox\Models\Builders\OutboxMessageBuilder;

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
 * @property-read OutboxStatus $status_enum
 *
 * @method static OutboxMessageBuilder inPending()
 * @method static OutboxMessageBuilder inProgress()
 * @method static OutboxMessageBuilder inSent()
 * @method static OutboxMessageBuilder inFailed()
 * @method static OutboxMessageBuilder inReadyToProcess()
 * @method static OutboxMessageBuilder inStuck()
 * @method static OutboxMessageBuilder finishedOlderThan(Carbon $cutoffTime)
 */
#[UseEloquentBuilder(OutboxMessageBuilder::class)]
class OutboxMessage extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = OutboxMessageColumn::TABLE->value;

    protected $fillable = [
        OutboxMessageColumn::CHANNEL->value,
        OutboxMessageColumn::HEADERS->value,
        OutboxMessageColumn::PAYLOAD->value,
        OutboxMessageColumn::STATUS->value,
        OutboxMessageColumn::ATTEMPTS->value,
        OutboxMessageColumn::NEXT_RETRY_AT->value,
        OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value,
        OutboxMessageColumn::DEDUPLICATION_KEY->value,
        OutboxMessageColumn::LAST_ERROR_TEXT->value,
    ];

    protected $casts = [
        OutboxMessageColumn::HEADERS->value => 'array',
        OutboxMessageColumn::NEXT_RETRY_AT->value => 'datetime',
        OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => 'datetime',
    ];

    /**
     * @return Attribute<OutboxStatus, never>
     */
    protected function statusEnum(): Attribute
    {
        return Attribute::make(
            get: fn () => OutboxStatus::fromId($this->status_id),
        )->withoutObjectCaching();
    }

    // -------------------------------------------------------------------------
    // Static factory / action methods
    // -------------------------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            $model->status_id ??= OutboxStatus::Pending->id();
            $model->attempts ??= 0;
            $model->next_retry_at ??= Carbon::now();
        });
    }

    /**
     * Bulk-insert messages, ignoring rows whose deduplication_key already exists
     * (including duplicates within the batch — the unique index handles those).
     *
     * @param  list<OutboxDraft>  $drafts
     */
    public static function storeBatch(array $drafts): void
    {
        $now = Carbon::now();

        $rows = array_map(static fn (OutboxDraft $draft): array => [
            OutboxMessageColumn::ID->value => Str::uuid()->toString(),
            OutboxMessageColumn::CHANNEL->value => $draft->channel,
            OutboxMessageColumn::HEADERS->value => $draft->headers !== []
                ? json_encode($draft->headers, JSON_THROW_ON_ERROR)
                : null,
            OutboxMessageColumn::PAYLOAD->value => $draft->payload,
            OutboxMessageColumn::STATUS->value => OutboxStatus::Pending->id(),
            OutboxMessageColumn::ATTEMPTS->value => 0,
            OutboxMessageColumn::NEXT_RETRY_AT->value => $now,
            OutboxMessageColumn::DEDUPLICATION_KEY->value => $draft->deduplicationKey,
            OutboxMessageColumn::CREATED_AT->value => $now,
            OutboxMessageColumn::UPDATED_AT->value => $now,
        ], $drafts);

        self::insertOrIgnore($rows);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public static function store(string $channel, string $payload, string $deduplicationKey, array $headers = []): self
    {
        /** @var self $message */
        $message = self::firstOrCreate(
            [OutboxMessageColumn::DEDUPLICATION_KEY->value => $deduplicationKey],
            [
                OutboxMessageColumn::CHANNEL->value => $channel,
                OutboxMessageColumn::PAYLOAD->value => $payload,
                OutboxMessageColumn::HEADERS->value => $headers ?: null,
            ],
        );

        return $message;
    }

    public static function resetStuck(): int
    {
        return self::inStuck()->update([
            OutboxMessageColumn::STATUS->value => OutboxStatus::Pending->id(),
            OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
        ]);
    }

    public function prunable(): OutboxMessageBuilder
    {
        $cutoff = Carbon::now()->subDays(
            config('transactional-outbox.outbox.prune_after_days', 30),
        );

        return self::finishedOlderThan($cutoff);
    }

    /**
     * Content-addressed deduplication key. Opt-in helper for callers who want
     * dedup by payload bytes — pass it explicitly as the deduplication key.
     * Note: only stable if the payload is canonical per logical event (no
     * timestamps / random fields), otherwise duplicates will not dedup.
     */
    public static function hashPayload(string $payload): string
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
        return self::where(OutboxMessageColumn::ID->value, $this->id)
            ->where(OutboxMessageColumn::STATUS->value, OutboxStatus::Pending->id())
            ->update([
                OutboxMessageColumn::STATUS->value => OutboxStatus::InProgress->id(),
                OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => $deadline,
            ]) === 1;
    }

    public function markAsSent(): bool
    {
        return $this->update([
            OutboxMessageColumn::STATUS->value => OutboxStatus::Sent->id(),
            OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
        ]);
    }

    public function markAsFailed(string $errorText): bool
    {
        return $this->update([
            OutboxMessageColumn::STATUS->value => OutboxStatus::Failed->id(),
            OutboxMessageColumn::ATTEMPTS->value => $this->attempts + 1,
            OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
            OutboxMessageColumn::LAST_ERROR_TEXT->value => $errorText,
        ]);
    }

    public function markAsPendingWithRetry(Carbon $nextRetryAt, string $errorText): bool
    {
        return $this->update([
            OutboxMessageColumn::STATUS->value => OutboxStatus::Pending->id(),
            OutboxMessageColumn::ATTEMPTS->value => $this->attempts + 1,
            OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value => null,
            OutboxMessageColumn::NEXT_RETRY_AT->value => $nextRetryAt,
            OutboxMessageColumn::LAST_ERROR_TEXT->value => $errorText,
        ]);
    }
}
