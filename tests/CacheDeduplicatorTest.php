<?php

declare(strict_types=1);

namespace RabbitEvents\Deduplication\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RabbitEvents\Deduplication\CacheDeduplicator;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\JsonPayload;
use RuntimeException;

class CacheDeduplicatorTest extends TestCase
{
    use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_handled_message_is_a_duplicate_for_another_worker_sharing_the_store(): void
    {
        $store = new Repository(new ArrayStore());

        $this->deduplicator($store)->markHandled($this->message('msg-1'), 'svc:order.created');

        // Another worker process (another instance) gets the redelivered message.
        self::assertTrue($this->deduplicator($store)->isDuplicate($this->message('msg-1'), 'svc:order.created'));
    }

    public function test_message_is_not_a_duplicate_before_it_was_handled(): void
    {
        self::assertFalse($this->deduplicator()->isDuplicate($this->message('msg-1'), 'q'));
    }

    public function test_same_message_is_handled_once_per_queue(): void
    {
        $deduplicator = $this->deduplicator();
        $deduplicator->markHandled($this->message('msg-1'), 'billing:order.created');

        self::assertFalse($deduplicator->isDuplicate($this->message('msg-1'), 'mailer:order.created'));
    }

    public function test_messages_without_id_are_never_duplicates(): void
    {
        $deduplicator = $this->deduplicator();

        $deduplicator->markHandled($this->message(null), 'q');

        self::assertFalse($deduplicator->isDuplicate($this->message(null), 'q'));
    }

    public function test_handled_ids_expire_after_ttl(): void
    {
        $store = new Repository(new ArrayStore());
        $this->deduplicator($store, ttl: 60)->markHandled($this->message('msg-1'), 'q');

        Carbon::setTestNow(Carbon::now()->addSeconds(61));

        self::assertFalse($this->deduplicator($store)->isDuplicate($this->message('msg-1'), 'q'));
    }

    public function test_unavailable_store_lets_the_message_be_handled_and_warns(): void
    {
        /** @var \Illuminate\Contracts\Cache\Repository&m\MockInterface $store */
        $store = m::mock(\Illuminate\Contracts\Cache\Repository::class);
        $store->shouldReceive('has')->andThrow(new RuntimeException('Connection refused'));
        $store->shouldReceive('put')->andThrow(new RuntimeException('Connection refused'));
        /** @var LoggerInterface&m\MockInterface $logger */
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->twice()->with(m::pattern('/^RabbitEvents deduplication: /'), m::type('array'));

        $deduplicator = new CacheDeduplicator($store, $logger);

        self::assertFalse($deduplicator->isDuplicate($this->message('msg-1'), 'q'));
        $deduplicator->markHandled($this->message('msg-1'), 'q');
    }

    private function deduplicator(?Repository $store = null, int $ttl = 86400): CacheDeduplicator
    {
        return new CacheDeduplicator($store ?? new Repository(new ArrayStore()), new NullLogger(), $ttl);
    }

    private function message(?string $id): Message
    {
        return new Message('order.created', new JsonPayload(['id' => 1]), $id ? ['message_id' => $id] : []);
    }
}
