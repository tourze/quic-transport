<?php

declare(strict_types=1);

namespace Tourze\Tests\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\EventLoop;

/**
 * @internal
 */
#[CoversClass(EventLoop::class)]
final class EventLoopTest extends TestCase
{
    private EventLoop $eventLoop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventLoop = new EventLoop();
    }

    public function testAddAndExecuteTimer(): void
    {
        $executed = false;
        $this->eventLoop->addTimer(0.001, function () use (&$executed): void {
            $executed = true;
        });

        // 执行一次事件循环
        $this->eventLoop->tick();
        usleep(2000); // 等待2毫秒
        $this->eventLoop->tick();

        self::assertTrue($executed);
    }

    public function testAddPeriodicTimer(): void
    {
        $count = 0;
        $timerId = $this->eventLoop->addPeriodicTimer(0.001, function () use (&$count): void {
            ++$count;
        });

        // 执行多次事件循环
        for ($i = 0; $i < 5; ++$i) {
            usleep(2000); // 等待2毫秒
            $this->eventLoop->tick();
        }

        self::assertGreaterThanOrEqual(2, $count);

        // 取消定时器
        $this->eventLoop->cancelTimer($timerId);
    }

    public function testCancelTimer(): void
    {
        $executed = false;
        $timerId = $this->eventLoop->addTimer(0.01, function () use (&$executed): void {
            $executed = true;
        });

        // 立即取消定时器
        $this->eventLoop->cancelTimer($timerId);

        // 等待并执行事件循环
        usleep(20000); // 等待20毫秒
        $this->eventLoop->tick();

        self::assertFalse($executed);
    }

    public function testNextTick(): void
    {
        $executed = false;
        $this->eventLoop->nextTick(function () use (&$executed): void {
            $executed = true;
        });

        // 执行一次事件循环
        $this->eventLoop->tick();

        self::assertTrue($executed);
    }

    public function testReadStreamCallback(): void
    {
        $readStream = fopen('php://temp', 'r+');
        if (false === $readStream) {
            self::fail('无法创建临时流');
        }

        fwrite($readStream, 'Hello, EventLoop!');
        rewind($readStream);

        $data = null;
        $this->eventLoop->addReadStream($readStream, function ($stream) use (&$data): void {
            $data = fread($stream, 1024);
        });

        // 执行事件循环
        $this->eventLoop->tick();

        self::assertSame('Hello, EventLoop!', $data);

        // 清理
        $this->eventLoop->removeReadStream($readStream);
        fclose($readStream);
    }

    public function testWriteStreamCallback(): void
    {
        $writeStream = fopen('php://temp', 'w+');
        if (false === $writeStream) {
            self::fail('无法创建临时流');
        }

        $written = false;
        $this->eventLoop->addWriteStream($writeStream, function ($stream) use (&$written): void {
            fwrite($stream, 'Test data');
            $written = true;
        });

        // 执行事件循环
        $this->eventLoop->tick();

        self::assertTrue($written);

        // 清理
        $this->eventLoop->removeWriteStream($writeStream);
        fclose($writeStream);
    }

    public function testIsRunning(): void
    {
        self::assertFalse($this->eventLoop->isRunning());

        // 在另一个进程中测试运行状态
        $timerId = $this->eventLoop->addTimer(0.001, function (): void {
            $this->eventLoop->stop();
        });

        $this->eventLoop->run();

        self::assertFalse($this->eventLoop->isRunning());
    }

    public function testGetStatistics(): void
    {
        $stats = $this->eventLoop->getStatistics();

        self::assertArrayHasKey('is_running', $stats);
        self::assertArrayHasKey('read_streams_count', $stats);
        self::assertArrayHasKey('write_streams_count', $stats);
        self::assertArrayHasKey('timers_count', $stats);
        self::assertArrayHasKey('next_timer_id', $stats);

        self::assertFalse($stats['is_running']);
        self::assertSame(0, $stats['read_streams_count']);
        self::assertSame(0, $stats['write_streams_count']);
    }

    public function testAddReadStream(): void
    {
        $readStream = fopen('php://temp', 'r+');
        if (false === $readStream) {
            self::fail('无法创建临时流');
        }

        $callbackExecuted = false;
        $this->eventLoop->addReadStream($readStream, function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
        });

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(1, $stats['read_streams_count']);

        fclose($readStream);
    }

    public function testAddTimer(): void
    {
        $timerId = $this->eventLoop->addTimer(0.001, function (): void {});

        self::assertIsInt($timerId);
        self::assertGreaterThan(0, $timerId);

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(1, $stats['timers_count']);
    }

    public function testAddWriteStream(): void
    {
        $writeStream = fopen('php://temp', 'w+');
        if (false === $writeStream) {
            self::fail('无法创建临时流');
        }

        $this->eventLoop->addWriteStream($writeStream, function (): void {});

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(1, $stats['write_streams_count']);

        fclose($writeStream);
    }

    public function testRemoveReadStream(): void
    {
        $readStream = fopen('php://temp', 'r+');
        if (false === $readStream) {
            self::fail('无法创建临时流');
        }

        $this->eventLoop->addReadStream($readStream, function (): void {});

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(1, $stats['read_streams_count']);

        $this->eventLoop->removeReadStream($readStream);

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(0, $stats['read_streams_count']);

        fclose($readStream);
    }

    public function testRemoveWriteStream(): void
    {
        $writeStream = fopen('php://temp', 'w+');
        if (false === $writeStream) {
            self::fail('无法创建临时流');
        }

        $this->eventLoop->addWriteStream($writeStream, function (): void {});

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(1, $stats['write_streams_count']);

        $this->eventLoop->removeWriteStream($writeStream);

        $stats = $this->eventLoop->getStatistics();
        self::assertSame(0, $stats['write_streams_count']);

        fclose($writeStream);
    }

    public function testRun(): void
    {
        $callbackExecuted = false;
        $this->eventLoop->addTimer(0.001, function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
            $this->eventLoop->stop();
        });

        $this->eventLoop->run();

        self::assertTrue($callbackExecuted);
        self::assertFalse($this->eventLoop->isRunning());
    }

    public function testStop(): void
    {
        self::assertFalse($this->eventLoop->isRunning());
        $this->eventLoop->stop();
        self::assertFalse($this->eventLoop->isRunning());
    }

    public function testTick(): void
    {
        $callbackExecuted = false;
        $this->eventLoop->addTimer(0.001, function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
        });

        usleep(2000);
        $this->eventLoop->tick();

        self::assertTrue($callbackExecuted);
    }
}
