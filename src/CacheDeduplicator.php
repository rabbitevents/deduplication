<?php

declare(strict_types=1);

namespace RabbitEvents\Deduplication;

use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Contracts\Deduplicator;
use Throwable;

/**
 * Remembers handled messages per queue in a cache store shared by the workers, so a message
 * delivered again to the same queue (e.g. its acknowledgement was lost) is skipped.
 */
class CacheDeduplicator implements Deduplicator
{
    public function __construct(
        private readonly Repository $cache,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 86400
    ) {
    }

    public function isDuplicate(Message $message, string $queue): bool
    {
        if (!$key = $this->key($message, $queue)) {
            return false;
        }

        try {
            return $this->cache->has($key);
        } catch (Throwable $e) {
            // Handling a message twice is better than losing or blocking it.
            $this->warn('Cannot check if the message was already handled, handling it', $message, $queue, $e);

            return false;
        }
    }

    public function markHandled(Message $message, string $queue): void
    {
        if (!$key = $this->key($message, $queue)) {
            return;
        }

        try {
            $this->cache->put($key, true, $this->ttl);
        } catch (Throwable $e) {
            $this->warn('Cannot remember the handled message, a redelivery will be handled again', $message, $queue, $e);
        }
    }

    private function key(Message $message, string $queue): ?string
    {
        // Messages published by RabbitEvents 9.x have no ID and can't be deduplicated.
        return $message->id() ? "rabbitevents:handled:{$queue}:{$message->id()}" : null;
    }

    private function warn(string $problem, Message $message, string $queue, Throwable $e): void
    {
        $this->logger->warning("RabbitEvents deduplication: {$problem}.", [
            'message_id' => $message->id(),
            'queue' => $queue,
            'exception' => $e,
        ]);
    }
}
