<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TransactionalOutbox\Enums\InboxStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->uuid('id')
                ->primary()
                ->comment('Message UUID');

            $table->string('channel')
                ->comment('Logical channel/source the message was received on (e.g. Kafka topic, RabbitMQ queue, NATS subject)');

            $table->json('headers')
                ->nullable()
                ->comment('Headers received from the broker');

            $table->text('payload')
                ->comment('Raw message body received from the broker');

            $table->unsignedSmallInteger('status_id')
                ->default(InboxStatus::Pending->id())
                ->comment('Processing status: 1=PENDING, 2=IN_PROGRESS, 3=PROCESSED, 4=FAILED');

            $table->unsignedInteger('attempts')
                ->default(0)
                ->comment('Number of handler invocation attempts performed');

            $table->unsignedSmallInteger('max_attempts')
                ->default(5)
                ->comment('Per-row override of max handler attempts before transitioning to terminal FAILED');

            $table->timestamp('next_retry_at')
                ->comment('Time of the next processing attempt (exponential backoff target)');

            $table->timestamp('in_progress_deadline_at')
                ->nullable()
                ->comment('Deadline for IN_PROGRESS state; if exceeded the worker is considered stuck and the row returns to PENDING');

            $table->string('deduplication_key')
                ->unique()
                ->comment('Idempotency key (payload hash); prevents reprocessing duplicate messages');

            $table->text('last_error_text')
                ->nullable()
                ->comment('Last handler error message');

            $table->timestamp('failed_at')
                ->nullable()
                ->comment('Transition time to terminal FAILED status (attempts >= max_attempts)');

            $table->timestamp('processed_at')
                ->nullable()
                ->comment('Time of successful processing; used to compute end-to-end latency');

            $table->timestamps();

            $table->index(['status_id', 'next_retry_at'], 'idx_inbox_pending');
            $table->index(['channel', 'status_id', 'next_retry_at'], 'idx_inbox_channel_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
