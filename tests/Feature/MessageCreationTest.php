<?php

declare(strict_types=1);

use TransactionalOutbox\Enums\InboxStatus;
use TransactionalOutbox\Enums\OutboxStatus;
use TransactionalOutbox\Models\InboxMessage;
use TransactionalOutbox\Models\OutboxMessage;

describe('outboxCreate', function () {
    it('creates a message with correct fields', function () {
        $message = OutboxMessage::store(channel: 'orders', payload: '{"id":1}');

        expect($message)
            ->channel->toBe('orders')
            ->payload->toBe('{"id":1}')
            ->status_id->toBe(OutboxStatus::Pending->id())
            ->attempts->toBe(0)
            ->headers->toBeNull()
            ->id->not->toBeNull()
            ->next_retry_at->not->toBeNull()
            ->and(OutboxMessage::count())
            ->toBe(1);
    });

    it('stores headers', function () {
        $headers = ['Content-Type' => 'application/protobuf', 'X-Message-Type' => 'OrderCreated'];

        $message = OutboxMessage::store(
            channel: 'orders',
            payload: '{"id":1}',
            headers: $headers,
        );

        expect($message->headers)->toBe($headers);
    });

    it('deduplicates by payload', function () {
        $first = OutboxMessage::store(channel: 'orders', payload: '{"id":1}');
        $second = OutboxMessage::store(channel: 'orders', payload: '{"id":1}');

        expect(OutboxMessage::count())->toBe(1)
            ->and($first->id)->toBe($second->id);
    });

    it('creates separate records for different payloads', function () {
        OutboxMessage::store(channel: 'orders', payload: '{"id":1}');
        OutboxMessage::store(channel: 'orders', payload: '{"id":2}');

        expect(OutboxMessage::count())->toBe(2);
    });
});

describe('outboxCreateBatch', function () {
    it('creates all messages in one call', function (int $count) {
        $messages = array_map(
            static fn (int $i) => ['channel' => 'orders', 'payload' => json_encode(['id' => $i])],
            range(1, $count),
        );

        OutboxMessage::storeBatch($messages);

        expect(OutboxMessage::count())->toBe($count);
    })->with([
        'single' => [1],
        'small' => [5],
        'large' => [50],
    ]);

    it('stores headers per message', function () {
        OutboxMessage::storeBatch([
            [
                'channel' => 'orders',
                'payload' => '{"id":1}',
                'headers' => ['X-Message-Type' => 'OrderCreated'],
            ],
            [
                'channel' => 'payments',
                'payload' => '{"id":2}',
                'headers' => ['X-Message-Type' => 'PaymentProcessed'],
            ],
        ]);

        expect(OutboxMessage::where('channel', 'orders')->first()->headers)
            ->toBe(['X-Message-Type' => 'OrderCreated'])
            ->and(OutboxMessage::where('channel', 'payments')->first()->headers)
            ->toBe(['X-Message-Type' => 'PaymentProcessed']);
    });

    it('skips duplicate payloads silently', function () {
        OutboxMessage::storeBatch([
            ['channel' => 'orders', 'payload' => '{"id":1}'],
            ['channel' => 'orders', 'payload' => '{"id":1}'],
            ['channel' => 'orders', 'payload' => '{"id":2}'],
        ]);

        expect(OutboxMessage::count())->toBe(2);
    });

    it('does nothing on empty array', function () {
        OutboxMessage::storeBatch([]);

        expect(OutboxMessage::count())->toBe(0);
    });

    it('sets pending status for all messages', function () {
        OutboxMessage::storeBatch([
            ['channel' => 'orders', 'payload' => '{"id":1}'],
            ['channel' => 'orders', 'payload' => '{"id":2}'],
        ]);

        expect(OutboxMessage::where('status_id', OutboxStatus::Pending->id())->count())->toBe(2);
    });
});

describe('inboxStore', function () {
    it('creates a message with correct fields', function () {
        $message = InboxMessage::store(channel: 'orders', payload: '{"id":1}');

        expect($message)
            ->channel->toBe('orders')
            ->payload->toBe('{"id":1}')
            ->status_id->toBe(InboxStatus::Pending->id())
            ->attempts->toBe(0)
            ->headers->toBeNull()
            ->id->not->toBeNull();
    });

    it('persists to database', function () {
        InboxMessage::store(channel: 'orders', payload: '{"id":1}');

        expect(InboxMessage::count())->toBe(1);
    });

    it('stores headers', function () {
        $headers = ['Content-Type' => 'application/protobuf', 'X-Correlation-ID' => 'abc-123'];

        $message = InboxMessage::store(
            channel: 'orders',
            payload: '{"id":1}',
            headers: $headers,
        );

        expect($message->headers)->toBe($headers);
    });

    it('deduplicates by payload', function () {
        $first = InboxMessage::store(channel: 'orders', payload: '{"id":1}');
        $second = InboxMessage::store(channel: 'orders', payload: '{"id":1}');

        expect(InboxMessage::count())->toBe(1)
            ->and($first->id)->toBe($second->id);
    });

    it('creates separate records for different payloads', function () {
        InboxMessage::store(channel: 'orders', payload: '{"id":1}');
        InboxMessage::store(channel: 'orders', payload: '{"id":2}');

        expect(InboxMessage::count())->toBe(2);
    });
});
