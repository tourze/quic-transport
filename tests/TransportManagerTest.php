<?php

declare(strict_types=1);

namespace Tourze\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\TransportInterface;
use Tourze\QUIC\Transport\TransportManager;

class TransportManagerTest extends TestCase
{
    private TransportManager $manager;
    private TransportInterface $transport;

    protected function setUp(): void
    {
        $this->transport = $this->createMock(TransportInterface::class);
        $this->manager = new TransportManager($this->transport);
    }

    public function testStart(): void
    {
        $this->transport->expects($this->once())
            ->method('start');

        $eventFired = false;
        $this->manager->on('transport.started', function () use (&$eventFired) {
            $eventFired = true;
        });

        $this->manager->start();
        
        self::assertTrue($eventFired);
        
        // 再次启动不应该调用transport->start()
        $this->manager->start();
    }

    public function testStop(): void
    {
        $this->transport->expects($this->once())
            ->method('start');
        $this->transport->expects($this->once())
            ->method('stop');

        $this->manager->start();

        $eventFired = false;
        $this->manager->on('transport.stopped', function () use (&$eventFired) {
            $eventFired = true;
        });

        $this->manager->stop();
        
        self::assertTrue($eventFired);
        
        // 再次停止不应该调用transport->stop()
        $this->manager->stop();
    }

    public function testRegisterConnection(): void
    {
        $connectionId = 'test-connection';
        $host = '127.0.0.1';
        $port = 8080;

        $eventData = null;
        $this->manager->on('connection.registered', function ($data) use (&$eventData) {
            $eventData = $data;
        });

        $this->manager->registerConnection($connectionId, $host, $port);

        self::assertNotNull($eventData);
        self::assertSame($connectionId, $eventData['connection_id']);
        self::assertSame($host, $eventData['host']);
        self::assertSame($port, $eventData['port']);

        $connections = $this->manager->getConnections();
        self::assertArrayHasKey($connectionId, $connections);
    }

    public function testUnregisterConnection(): void
    {
        $connectionId = 'test-connection';
        $this->manager->registerConnection($connectionId, '127.0.0.1', 8080);

        $eventFired = false;
        $this->manager->on('connection.unregistered', function () use (&$eventFired) {
            $eventFired = true;
        });

        $this->manager->unregisterConnection($connectionId);

        self::assertTrue($eventFired);
        $connections = $this->manager->getConnections();
        self::assertArrayNotHasKey($connectionId, $connections);
    }

    public function testSendSuccess(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        $host = '127.0.0.1';
        $port = 8080;

        $this->transport->expects($this->once())
            ->method('send')
            ->with($data, $host, $port)
            ->willReturn(true);

        $eventData = null;
        $this->manager->on('data.sent', function ($data) use (&$eventData) {
            $eventData = $data;
        });

        $result = $this->manager->send($connectionId, $data, $host, $port);

        self::assertTrue($result);
        self::assertNotNull($eventData);
        self::assertSame($data, $eventData['data']);
        self::assertSame(strlen($data), $eventData['bytes']);
    }

    public function testSendFailure(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        $host = '127.0.0.1';
        $port = 8080;

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('Send failed'));

        $eventData = null;
        $this->manager->on('send.error', function ($data) use (&$eventData) {
            $eventData = $data;
        });

        $result = $this->manager->send($connectionId, $data, $host, $port);

        self::assertFalse($result);
        self::assertNotNull($eventData);
        self::assertSame('Send failed', $eventData['error']);
    }

    public function testReceiveSuccess(): void
    {
        $connectionId = 'test-connection';
        $packet = [
            'data' => 'Hello, World!',
            'host' => '192.168.1.100',
            'port' => 9999,
        ];

        $this->transport->expects($this->once())
            ->method('receive')
            ->willReturn($packet);

        $eventData = null;
        $this->manager->on('data.received', function ($data) use (&$eventData) {
            $eventData = $data;
        });

        $result = $this->manager->receive($connectionId);

        self::assertSame($packet, $result);
        self::assertNotNull($eventData);
        self::assertSame($packet['data'], $eventData['data']);
        self::assertSame($packet['host'], $eventData['host']);
        self::assertSame($packet['port'], $eventData['port']);
    }

    public function testReceiveError(): void
    {
        $connectionId = 'test-connection';

        $this->transport->expects($this->once())
            ->method('receive')
            ->willThrowException(new \RuntimeException('Receive failed'));

        $eventData = null;
        $this->manager->on('receive.error', function ($data) use (&$eventData) {
            $eventData = $data;
        });

        $result = $this->manager->receive($connectionId);

        self::assertNull($result);
        self::assertNotNull($eventData);
        self::assertSame('Receive failed', $eventData['error']);
    }

    public function testProcessPendingEvents(): void
    {
        $this->transport->expects($this->once())
            ->method('start');
        $this->transport->expects($this->once())
            ->method('receive')
            ->willReturn(null);

        $this->manager->start();
        $this->manager->processPendingEvents();

        // 测试在未启动时不处理事件
        $this->manager->stop();
        $this->manager->processPendingEvents();
    }

    public function testEventListeners(): void
    {
        $event = 'test.event';
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
        };

        $this->manager->on($event, $callback);
        
        // 触发事件（通过反射访问私有方法）
        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);
        $fireEvent->invoke($this->manager, $event);

        self::assertSame(1, $callCount);

        // 移除特定回调
        $this->manager->off($event, $callback);
        $fireEvent->invoke($this->manager, $event);
        self::assertSame(1, $callCount);

        // 添加多个回调并移除所有
        $this->manager->on($event, $callback);
        $this->manager->on($event, $callback);
        $this->manager->off($event);
        $fireEvent->invoke($this->manager, $event);
        self::assertSame(1, $callCount);
    }

    public function testGetStatistics(): void
    {
        $stats = $this->manager->getStatistics();

        self::assertArrayHasKey('running', $stats);
        self::assertArrayHasKey('connections_count', $stats);
        self::assertArrayHasKey('buffer_stats', $stats);
        self::assertArrayHasKey('event_loop_stats', $stats);
        
        self::assertFalse($stats['running']);
        self::assertSame(0, $stats['connections_count']);
    }

    public function testRunWithTimeout(): void
    {
        $this->transport->expects($this->once())
            ->method('start');
        $this->transport->expects($this->any())
            ->method('receive')
            ->willReturn(null);

        $this->manager->start();
        
        $startTime = time();
        $this->manager->run(1); // 1秒超时
        $elapsed = time() - $startTime;

        self::assertGreaterThanOrEqual(1, $elapsed);
        self::assertLessThanOrEqual(2, $elapsed);
    }
}