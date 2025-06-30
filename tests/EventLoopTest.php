<?php

declare(strict_types=1);

namespace Tourze\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\EventLoop;

class EventLoopTest extends TestCase
{
    private EventLoop $eventLoop;

    protected function setUp(): void
    {
        $this->eventLoop = new EventLoop();
    }

    public function testAddAndExecuteTimer(): void
    {
        $executed = false;
        $this->eventLoop->addTimer(0.001, function () use (&$executed) {
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
        $timerId = $this->eventLoop->addPeriodicTimer(0.001, function () use (&$count) {
            $count++;
        });

        // 执行多次事件循环
        for ($i = 0; $i < 5; $i++) {
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
        $timerId = $this->eventLoop->addTimer(0.01, function () use (&$executed) {
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
        $this->eventLoop->nextTick(function () use (&$executed) {
            $executed = true;
        });

        // 执行一次事件循环
        $this->eventLoop->tick();

        self::assertTrue($executed);
    }

    public function testReadStreamCallback(): void
    {
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        
        if ($pipes === false) {
            $this->markTestSkipped('无法创建socket pair');
        }

        [$readStream, $writeStream] = $pipes;

        $data = null;
        $this->eventLoop->addReadStream($readStream, function ($stream) use (&$data) {
            $data = fread($stream, 1024);
        });

        // 写入数据
        fwrite($writeStream, 'Hello, EventLoop!');
        
        // 执行事件循环
        $this->eventLoop->tick();

        self::assertSame('Hello, EventLoop!', $data);

        // 清理
        $this->eventLoop->removeReadStream($readStream);
        fclose($readStream);
        fclose($writeStream);
    }

    public function testWriteStreamCallback(): void
    {
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        
        if ($pipes === false) {
            $this->markTestSkipped('无法创建socket pair');
        }

        [$readStream, $writeStream] = $pipes;

        $written = false;
        $this->eventLoop->addWriteStream($writeStream, function ($stream) use (&$written) {
            fwrite($stream, 'Test data');
            $written = true;
        });

        // 执行事件循环
        $this->eventLoop->tick();

        self::assertTrue($written);

        // 清理
        $this->eventLoop->removeWriteStream($writeStream);
        fclose($readStream);
        fclose($writeStream);
    }

    public function testIsRunning(): void
    {
        self::assertFalse($this->eventLoop->isRunning());

        // 在另一个进程中测试运行状态
        $timerId = $this->eventLoop->addTimer(0.001, function () {
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
}