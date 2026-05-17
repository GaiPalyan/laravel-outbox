Laravel package implementing the **Transactional Outbox / Inbox** patterns. Broker-agnostic: ship messages to any transport (NATS, Kafka, RabbitMQ, SQS, HTTP webhook — anything you can call `publish()` on).

## Contents

- [Delivery semantics](#delivery-semantics)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Outbox — publishing messages](#outbox--publishing-messages)
  - [1. Write to the outbox inside your DB transaction](#1-write-to-the-outbox-inside-your-db-transaction)
  - [2. Implement `OutboxPublisherInterface` for your broker](#2-implement-outboxpublisherinterface-for-your-broker)
  - [3. Bind it in the service container](#3-bind-it-in-the-service-container)
  - [4. Schedule the publisher worker](#4-schedule-the-publisher-worker)
- [Inbox — receiving messages](#inbox--receiving-messages)
  - [1. From your broker subscriber, fire `MessageConsumed`](#1-from-your-broker-subscriber-fire-messageconsumed)
  - [2. Implement a handler per channel](#2-implement-a-handler-per-channel)
  - [3. Bind channel → handler in the container](#3-bind-channel--handler-in-the-container)
  - [4. Schedule the inbox worker](#4-schedule-the-inbox-worker)
- [Pruning old rows](#pruning-old-rows)
- [How it works](#how-it-works)
- [Public surface](#public-surface)
- [Client responsibilities](#client-responsibilities)

## Delivery semantics

The package gives you **at-least-once delivery to the broker** plus **idempotent storage on both sides via a payload hash** (`murmur3f`). Composed together, this is what is commonly called **effectively-exactly-once**:

- **Outbox side.** `OutboxMessage::store()` is a `firstOrCreate` keyed on `deduplication_key = hash(payload)`. Calling it twice with the same payload inside a retried HTTP request or a retried job stores one row, not two.
- **Publisher worker.** Keeps retrying with exponential backoff until your `OutboxPublisherInterface::publish()` returns without throwing. If the broker accepts the message but the ack is lost, the worker will publish again — downstream **must** be idempotent. This is fundamental to any outbox; the package does not paper over it.
- **Inbox side.** `InboxMessage::store()` is also `firstOrCreate` by payload hash. Re-deliveries from the broker for the same payload land on the same row, the handler runs once.

True exactly-once delivery to a remote broker is impossible (FLP, two-generals); don't promise it. What you can promise to downstream consumers is **effectively-exactly-once processing of each unique payload**, which is what this package implements.

> **Caveat.** Dedup is by payload **bytes**. If your payload embeds a timestamp, a random ID, or any non-deterministic field, two logical duplicates will hash differently and **will not** dedup. Make payloads canonical per business event, or fold your own idempotency key into the payload (e.g. `{"id": "order-42-created", ...}`).

## Requirements

- PHP 8.4+
- Laravel 12 / 13

## Installation

```bash
composer require gpalyan/laravel-outbox
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=transactional-outbox-config
php artisan vendor:publish --tag=transactional-outbox-migrations
php artisan migrate
```

## Configuration

| Variable | Default | Description |
|---|---|---|
| `OUTBOX__RETRY_BACKOFF` | `2` | Exponential backoff multiplier |
| `OUTBOX__RETRY_JITTER` | `0.2` | Random jitter factor (0–1) |
| `OUTBOX__RETRY_MAX_DELAY` | `86400` | Max delay between publish retries (seconds) |
| `OUTBOX__IN_PROGRESS_DEADLINE` | `60` | Seconds before an in-progress outbox row is considered stuck |
| `OUTBOX__PRUNE_AFTER_DAYS` | `30` | Days to keep sent messages before pruning |
| `INBOX__MAX_ATTEMPTS` | `5` | Max handler invocations before permanent failure |
| `INBOX__RETRY_DELAY_SECONDS` | `15` | Initial retry delay |
| `INBOX__MAX_DELAY_SECONDS` | `3600` | Max delay between handler retries |
| `INBOX__IN_PROGRESS_DEADLINE` | `300` | Seconds before an in-progress inbox row is considered stuck |
| `INBOX__PRUNE_AFTER_DAYS` | `30` | Days to keep processed messages before pruning |

## Outbox — publishing messages

### 1. Write to the outbox inside your DB transaction

```php
use TransactionalOutbox\Models\OutboxMessage;

DB::transaction(function () use ($order) {
    $order->save();

    OutboxMessage::store(
        channel: 'orders.created',
        payload: json_encode($order),
        headers: ['X-Type' => 'OrderCreated'], // optional
    );
});
```

Batch insert (skips Eloquent events, useful for bulk producers):

```php
OutboxMessage::storeBatch([
    ['channel' => 'orders.created', 'payload' => json_encode($order1)],
    ['channel' => 'orders.updated', 'payload' => json_encode($order2)],
]);
```

### 2. Implement `OutboxPublisherInterface` for your broker

Below is a complete working example using RabbitMQ via `php-amqplib/php-amqplib`. Replace with your broker SDK; the contract is the same: take an `OutboxMessage`, publish it, throw on failure.

```php
namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use TransactionalOutbox\Contracts\OutboxPublisherInterface;
use TransactionalOutbox\Models\OutboxMessage;

final class RabbitMqOutboxPublisher implements OutboxPublisherInterface
{
    public function __construct(private AMQPStreamConnection $connection) {}

    public function publish(OutboxMessage $message): void
    {
        $route = config("rabbitmq.routes.{$message->channel}");

        if (! $route) {
            throw new RuntimeException("No RabbitMQ route configured for channel '{$message->channel}'");
        }

        $amqpMessage = new AMQPMessage(
            $message->payload,
            [
                'content_type' => 'application/octet-stream',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $message->id,
                'application_headers' => new AMQPTable($message->headers ?? []),
            ],
        );

        $channel = $this->connection->channel();
        $channel->basic_publish(
            msg: $amqpMessage,
            exchange: $route['exchange'],
            routing_key: $route['routing_key'],
        );
        // Any AMQP exception here propagates up; the worker catches it and reschedules with backoff.
    }
}
```

Route table lives in your own config:

```php
// config/rabbitmq.php
return [
    'routes' => [
        'orders.created' => ['exchange' => 'orders', 'routing_key' => 'orders.created'],
        'orders.updated' => ['exchange' => 'orders', 'routing_key' => 'orders.updated'],
        'payments.processed' => ['exchange' => 'payments', 'routing_key' => 'payments.processed'],
    ],
];
```

### 3. Bind it in the service container

```php
// AppServiceProvider::register()
$this->app->singleton(AMQPStreamConnection::class, fn () => new AMQPStreamConnection(
    host: config('rabbitmq.host'),
    port: config('rabbitmq.port'),
    user: config('rabbitmq.user'),
    password: config('rabbitmq.password'),
));

$this->app->bind(
    \TransactionalOutbox\Contracts\OutboxPublisherInterface::class,
    \App\Messaging\RabbitMqOutboxPublisher::class,
);
```

### 4. Schedule the publisher worker

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('transactional-outbox:process outbox')
    ->everyMinute()
    ->withoutOverlapping();
```

## Inbox — receiving messages

### 1. From your broker subscriber, fire `MessageConsumed`

The package does **not** subscribe to any broker. You bring your own subscriber (a long-running artisan command, a daemon, a worker subscribed to push notifications — whatever fits your broker). For each message received, fire the package event.

A complete RabbitMQ subscriber as an artisan command:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use TransactionalOutbox\Events\MessageConsumed;

final class RabbitMqListenCommand extends Command
{
    protected $signature = 'app:rabbitmq-listen {queue} {channel}';

    public function handle(AMQPStreamConnection $connection): int
    {
        $amqpChannel = $connection->channel();
        $amqpChannel->basic_qos(prefetch_size: 0, prefetch_count: 10, a_global: false);

        $amqpChannel->basic_consume(
            queue: $this->argument('queue'),
            consumer_tag: '',
            no_local: false,
            no_ack: false,             // <- manual ack; critical for at-least-once
            exclusive: false,
            nowait: false,
            callback: function (AMQPMessage $msg) {
                try {
                    event(new MessageConsumed(
                        channel: $this->argument('channel'),
                        payload: $msg->getBody(),
                        headers: $this->extractHeaders($msg),
                    ));

                    $msg->ack();
                } catch (Throwable $e) {
                    // event() failed (DB unavailable, etc.) — return the message to the broker.
                    $msg->reject(requeue: true);
                    report($e);
                }
            },
        );

        while ($amqpChannel->is_consuming()) {
            $amqpChannel->wait();
        }

        return self::SUCCESS;
    }

    private function extractHeaders(AMQPMessage $msg): array
    {
        $headers = $msg->get_properties()['application_headers'] ?? null;

        return $headers?->getNativeData() ?? [];
    }
}
```

Run one process per (queue, channel) pair under supervisor / systemd:

```
php artisan app:rabbitmq-listen orders.queue orders.created
```

The package registers `OnMessageConsumed` to this event automatically. The listener stores the message in `inbox_messages` (idempotent by payload hash).

**Synchronicity contract — important.**

`event(new MessageConsumed(...))` invokes the listener **synchronously**, in the same PHP process and call stack as your subscriber. The listener performs a DB write before `event()` returns. Implications:

- **Ack the broker only after `event()` returns.** If you ack first and `event()` then throws (DB unavailable, constraint failure, etc.), the inbox row is not persisted but the broker considers the message delivered — silent loss. The correct order is: receive → fire `event()` → on success, ack; on exception, nack/let the broker redeliver. This is what makes the system at-least-once end-to-end.
- **Do not wrap the listener in `Event::fake()` in tests** unless you re-fake selectively. `Event::fake()` with no arguments swallows all events, including `MessageConsumed`, and your inbox table stays empty. Use `Event::fake([SomeUnrelatedEvent::class])` to fake specific events only, or assert against the inbox row directly.
- **Do not queue the listener.** If you ever make `OnMessageConsumed` implement `ShouldQueue`, the listener becomes async — `event()` returns before the DB write, you can ack the broker and then lose the message if the queue or DB fails. The default registration is intentionally synchronous.

### 2. Implement a handler per channel

```php
use TransactionalOutbox\Contracts\InboxHandlerInterface;
use TransactionalOutbox\Models\InboxMessage;

final class PaymentProcessedHandler implements InboxHandlerInterface
{
    public function handle(InboxMessage $message): void
    {
        $data = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
        // process $data... throw on failure — the job will retry automatically
    }
}
```

### 3. Bind channel → handler in the container

```php
// AppServiceProvider::register()
$this->app->bind('payments.processed', PaymentProcessedHandler::class);
```

The key passed to `bind()` must match the `channel` you put into `MessageConsumed`.

### 4. Schedule the inbox worker

```php
// routes/console.php
Schedule::command('transactional-outbox:process inbox')
    ->everyMinute()
    ->withoutOverlapping();
```

## Pruning old rows

```php
// routes/console.php
use TransactionalOutbox\Models\OutboxMessage;
use TransactionalOutbox\Models\InboxMessage;

Schedule::command('model:prune', ['--model' => [OutboxMessage::class, InboxMessage::class]])
    ->daily();
```

`prune_after_days` in config controls the cutoff. **Only successfully terminated rows are pruned** — `SENT` on the outbox side, `PROCESSED` on the inbox side. `FAILED` rows are kept indefinitely.

### Why `FAILED` rows are never auto-pruned

A `FAILED` row means the package exhausted retries and gave up. Two cases:

- **Outbox `FAILED`** — the publisher could not hand the message off to the broker at all (broker unavailable, route misconfigured, payload rejected). The message **never reached** any broker DLQ — this is your application-side dead letter.
- **Inbox `FAILED`** — your handler kept throwing past `max_attempts`. The message was received from the broker but cannot be processed locally. Again, a dead letter at the application boundary.

In both cases the row is the **only surviving evidence** of a lost business event. Auto-pruning it silently erases problems that need human eyes — broken routes, bad payloads, handler bugs. So the package keeps them.

## How it works

```
Write side (Outbox)                          Read side (Inbox)
──────────────────────────────────────       ──────────────────────────────────────
App calls OutboxMessage::store()             Your broker subscriber receives a msg
  └─ saved in DB (same transaction)            └─ fires event(new MessageConsumed(...))
                                                  └─ OnMessageConsumed listener (built in)
transactional-outbox:process outbox                  └─ InboxMessage::store() (idempotent)
  └─ picks pending outbox rows
  └─ dispatches ProcessOutboxMessageJob       transactional-outbox:process inbox
       └─ resolves OutboxPublisherInterface    └─ picks pending inbox rows
       └─ publisher->publish($message)         └─ dispatches ProcessInboxMessageJob
       └─ marks as sent                            └─ resolves handler from container by channel
                                                   └─ handler->handle($message)
                                                   └─ marks as processed
```

Both sides use exponential backoff on failure with configurable max attempts. Stuck in-progress rows (worker crashed mid-flight) are returned to pending after the configured deadline.

## Public surface

| Symbol | Purpose |
|---|---|
| `TransactionalOutbox\Models\OutboxMessage` | Store outgoing messages inside your DB transaction |
| `TransactionalOutbox\Models\InboxMessage` | (read-only from your code) Inbound message rows |
| `TransactionalOutbox\Contracts\OutboxPublisherInterface` | Implement to publish to your broker |
| `TransactionalOutbox\Contracts\InboxHandlerInterface` | Implement per channel to process inbound messages |
| `TransactionalOutbox\Events\MessageConsumed` | Fire from your broker subscriber to push into the inbox |
| `TransactionalOutbox\Events\OutboxMessageSent` | Dispatched after successful publish |
| `TransactionalOutbox\Events\OutboxMessageFailed` | Dispatched after permanent publish failure |
| `TransactionalOutbox\Events\InboxMessageProcessed` | Dispatched after successful handler invocation |
| `TransactionalOutbox\Events\InboxMessageFailed` | Dispatched after permanent handler failure |
| Artisan: `transactional-outbox:process outbox\|inbox` | The worker entrypoint |

## Client responsibilities

| Responsibility | Who |
|---|---|
| Provision broker (streams, topics, queues, subscriptions) | You |
| Call `OutboxMessage::store()` inside DB transactions | You |
| Implement and bind `OutboxPublisherInterface` | You |
| Implement and bind `InboxHandlerInterface` per channel | You |
| Run a broker subscriber that fires `MessageConsumed` | You |
| Schedule `transactional-outbox:process outbox` and `... inbox` | You |
| Storage, retries, dedup, backoff, pruning, transitions | Package |
