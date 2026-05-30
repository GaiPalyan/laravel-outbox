<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TransactionalOutbox\Enums\OutboxStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')
                ->primary()
                ->comment('Message UUID');

            $table->string('channel')
                ->comment('Logical channel/destination; mapped to broker subject/topic/queue by the publisher');

            $table->json('headers')
                ->nullable()
                ->comment('Transport-layer headers passed through to the broker (e.g. Content-Type, X-Correlation-Id)');

            $table->text('payload')
                ->comment('Raw message body delivered to the broker as-is');

            $table->unsignedSmallInteger('status_id')
                ->default(OutboxStatus::Pending->id())
                ->comment('Processing status: 1=PENDING, 2=IN_PROGRESS, 3=SENT, 4=FAILED');

            $table->unsignedInteger('attempts')
                ->default(0)
                ->comment('Number of publish attempts performed');

            $table->timestamp('next_retry_at')
                ->comment('Time of the next publish attempt (exponential backoff target)');

            $table->timestamp('in_progress_deadline_at')
                ->nullable()
                ->comment('Deadline for IN_PROGRESS state; if exceeded the worker is considered stuck and the row returns to PENDING');

            $table->string('deduplication_key')
                ->unique()
                ->comment('Caller-supplied idempotency key for the logical message; unique to prevent duplicate outbox rows for the same event');

            $table->text('last_error_text')
                ->nullable()
                ->comment('Last publish error message');

            $table->timestamps();

            $table->index(['status_id', 'next_retry_at'], 'idx_outbox_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
