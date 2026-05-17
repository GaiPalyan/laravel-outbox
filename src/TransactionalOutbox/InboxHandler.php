<?php

declare(strict_types=1);

namespace TransactionalOutbox;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use TransactionalOutbox\Contracts\InboxHandlerInterface;
use TransactionalOutbox\Models\InboxMessage;

final readonly class InboxHandler
{
    public function __construct(private Container $container) {}

    /**
     * @throws BindingResolutionException
     */
    public function __invoke(InboxMessage $message): void
    {
        try {
            $handler = $this->container->make($message->channel);
        } catch (\Throwable $e) {
            throw new BindingResolutionException("No handler configured for inbox channel \"{$message->channel}\".", previous: $e);
        }

        if ($handler instanceof InboxHandlerInterface) {
            $handler->handle($message);
        } else {
            throw new BindingResolutionException('Handler must implement ' . InboxHandlerInterface::class);
        }
    }
}
