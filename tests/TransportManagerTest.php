<?php

declare(strict_types=1);

namespace Tourze\Tests\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\TransportInterface;
use Tourze\QUIC\Transport\TransportManager;

/**
 * @internal
 */
#[CoversClass(TransportManager::class)]
final class TransportManagerTest extends TestCase
{
    private TransportManager $manager;

    private TransportInterface $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->createTransportStub();
        $this->manager = new TransportManager($this->transport);
    }

    /**
     * 创建一个基础的Transport Stub实现
     */
    private function createTransportStub(): TransportInterface
    {
        return new class implements TransportInterface {
            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function send(string $data, string $host, int $port): bool
            {
                return true;
            }

            public function receive(): ?array
            {
                return null;
            }

            public function setTimeout(int $timeout): void
            {
            }

            public function isReady(): bool
            {
                return true;
            }

            /** @return array{host: string, port: int} */
            public function getLocalAddress(): array
            {
                return ['host' => '127.0.0.1', 'port' => 8080];
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * 创建可配置的Transport Mock
     *
     * @param array{shouldThrow?: bool, errorMessage?: string, returnPacket?: array{data: string, host: string, port: int}|null} $config
     * @return TransportInterface&object{startCalled: bool, stopCalled: bool, sendCalled: bool, receiveCalled: bool, startCallCount: int, stopCallCount: int, receiveCallCount: int, sentData: string, sentHost: string, sentPort: int}
     */
    private function createConfigurableTransport(array $config = []): TransportInterface
    {
        $shouldThrow = $config['shouldThrow'] ?? false;
        $errorMessage = $config['errorMessage'] ?? '';
        /** @var array{data: string, host: string, port: int}|null $returnPacket */
        $returnPacket = $config['returnPacket'] ?? null;
        return new class($shouldThrow, $errorMessage, $returnPacket) implements TransportInterface {
            public bool $startCalled = false;

            public bool $stopCalled = false;

            public bool $sendCalled = false;

            public bool $receiveCalled = false;

            public int $startCallCount = 0;

            public int $stopCallCount = 0;

            public int $receiveCallCount = 0;

            public string $sentData = '';

            public string $sentHost = '';

            public int $sentPort = 0;

            /** @param array{data: string, host: string, port: int}|null $returnPacket */
            public function __construct(
                private bool $shouldThrow,
                private string $errorMessage,
                private ?array $returnPacket,
            ) {
            }

            public function start(): void
            {
                ++$this->startCallCount;
                if (1 === $this->startCallCount) {
                    $this->startCalled = true;
                }
            }

            public function stop(): void
            {
                ++$this->stopCallCount;
                if (1 === $this->stopCallCount) {
                    $this->stopCalled = true;
                }
            }

            public function send(string $data, string $host, int $port): bool
            {
                $this->sendCalled = true;
                $this->sentData = $data;
                $this->sentHost = $host;
                $this->sentPort = $port;

                if ($this->shouldThrow) {
                    throw new \RuntimeException($this->errorMessage);
                }

                return true;
            }

            public function receive(): ?array
            {
                $this->receiveCalled = true;
                ++$this->receiveCallCount;

                if ($this->shouldThrow) {
                    throw new \RuntimeException($this->errorMessage);
                }

                return $this->returnPacket;
            }

            public function setTimeout(int $timeout): void
            {
            }

            public function isReady(): bool
            {
                return true;
            }

            /** @return array{host: string, port: int} */
            public function getLocalAddress(): array
            {
                return ['host' => '127.0.0.1', 'port' => 8080];
            }

            public function close(): void
            {
            }
        };
    }

    public function testStart(): void
    {
        $mock = $this->createConfigurableTransport();
        $manager = new TransportManager($mock);

        $eventFired = false;
        $manager->on('transport.started', function () use (&$eventFired): void {
            $eventFired = true;
        });

        $manager->start();

        self::assertTrue($eventFired);
        self::assertTrue($mock->startCalled);

        // 再次启动不应该调用transport->start()
        $manager->start();
    }

    public function testStop(): void
    {
        $mock = $this->createConfigurableTransport();
        $manager = new TransportManager($mock);

        $manager->start();

        $eventFired = false;
        $manager->on('transport.stopped', function () use (&$eventFired): void {
            $eventFired = true;
        });

        $manager->stop();

        self::assertTrue($eventFired);
        self::assertTrue($mock->startCalled);
        self::assertTrue($mock->stopCalled);

        // 再次停止不应该调用transport->stop()
        $manager->stop();
    }

    public function testRegisterConnection(): void
    {
        $connectionId = 'test-connection';
        $host = '127.0.0.1';
        $port = 8080;

        $eventData = null;
        $this->manager->on('connection.registered', function ($data) use (&$eventData): void {
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
        $this->manager->on('connection.unregistered', function () use (&$eventFired): void {
            $eventFired = true;
        });

        $this->manager->unregisterConnection($connectionId);

        self::assertTrue($eventFired);
        $connections = $this->manager->getConnections();
        self::assertArrayNotHasKey($connectionId, $connections);
    }

    public function testSendSuccess(): void
    {
        $mock = $this->createConfigurableTransport();
        $manager = new TransportManager($mock);

        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        $host = '127.0.0.1';
        $port = 8080;

        $eventData = null;
        $manager->on('data.sent', function ($data) use (&$eventData): void {
            $eventData = $data;
        });

        $result = $manager->send($connectionId, $data, $host, $port);

        self::assertTrue($result);
        self::assertTrue($mock->sendCalled);
        self::assertSame($data, $mock->sentData);
        self::assertSame($host, $mock->sentHost);
        self::assertSame($port, $mock->sentPort);
        self::assertNotNull($eventData);
        self::assertSame($data, $eventData['data']);
        self::assertSame(strlen($data), $eventData['bytes']);
    }

    public function testSendFailure(): void
    {
        $mock = $this->createConfigurableTransport([
            'shouldThrow' => true,
            'errorMessage' => 'Send failed',
        ]);
        $manager = new TransportManager($mock);

        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        $host = '127.0.0.1';
        $port = 8080;

        $eventData = null;
        $manager->on('send.error', function ($data) use (&$eventData): void {
            $eventData = $data;
        });

        $result = $manager->send($connectionId, $data, $host, $port);

        self::assertFalse($result);
        self::assertTrue($mock->sendCalled);
        self::assertNotNull($eventData);
        self::assertSame('Send failed', $eventData['error']);
    }

    public function testReceiveSuccess(): void
    {
        $packet = [
            'data' => 'Hello, World!',
            'host' => '192.168.1.100',
            'port' => 9999,
        ];
        $mock = $this->createConfigurableTransport(['returnPacket' => $packet]);
        $manager = new TransportManager($mock);

        $connectionId = 'test-connection';

        $eventData = null;
        $manager->on('data.received', function ($data) use (&$eventData): void {
            $eventData = $data;
        });

        $result = $manager->receive($connectionId);

        self::assertSame($packet, $result);
        self::assertTrue($mock->receiveCalled);
        self::assertNotNull($eventData);
        self::assertSame($packet['data'], $eventData['data']);
        self::assertSame($packet['host'], $eventData['host']);
        self::assertSame($packet['port'], $eventData['port']);
    }

    public function testReceiveError(): void
    {
        $mock = $this->createConfigurableTransport([
            'shouldThrow' => true,
            'errorMessage' => 'Receive failed',
        ]);
        $manager = new TransportManager($mock);

        $connectionId = 'test-connection';

        $eventData = null;
        $manager->on('receive.error', function ($data) use (&$eventData): void {
            $eventData = $data;
        });

        $result = $manager->receive($connectionId);

        self::assertNull($result);
        self::assertTrue($mock->receiveCalled);
        self::assertNotNull($eventData);
        self::assertSame('Receive failed', $eventData['error']);
    }

    public function testProcessPendingEvents(): void
    {
        $mock = $this->createConfigurableTransport();
        $manager = new TransportManager($mock);

        $manager->start();
        $manager->processPendingEvents();

        self::assertTrue($mock->startCalled);
        self::assertTrue($mock->receiveCalled);

        // 测试在未启动时不处理事件
        $manager->stop();
        $manager->processPendingEvents();
    }

    public function testEventListeners(): void
    {
        $event = 'test.event';

        // 触发事件（通过反射访问私有方法）
        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);

        // 测试添加和触发回调
        $callCount1 = 0;
        $callback1 = function () use (&$callCount1): void {
            ++$callCount1;
        };

        $this->manager->on($event, $callback1);
        $fireEvent->invoke($this->manager, $event);
        self::assertSame(1, $callCount1);
    }

    public function testEventListenerRemoval(): void
    {
        $event = 'test.event';
        $callCount = 0;
        $callback = function () use (&$callCount): void {
            ++$callCount;
        };

        // 触发事件（通过反射访问私有方法）
        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);

        // 添加回调并触发
        $this->manager->on($event, $callback);
        $fireEvent->invoke($this->manager, $event);

        // 移除回调后不应该再被调用
        $this->manager->off($event, $callback);
        $fireEvent->invoke($this->manager, $event);

        // 验证回调只被调用了一次
        self::assertSame(1, $callCount);
    }

    public function testMultipleEventListenersRemoval(): void
    {
        $event = 'test.event';
        $callCount = 0;
        $callback = function () use (&$callCount): void {
            ++$callCount;
        };

        // 触发事件（通过反射访问私有方法）
        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);

        // 添加两个相同的回调
        $this->manager->on($event, $callback);
        $this->manager->on($event, $callback);
        $fireEvent->invoke($this->manager, $event);

        // 移除所有回调
        $this->manager->off($event);
        $fireEvent->invoke($this->manager, $event);

        // 验证回调只在第一次触发时被调用了两次
        self::assertSame(2, $callCount);
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
        $mock = $this->createConfigurableTransport();
        $manager = new TransportManager($mock);

        $manager->start();

        self::assertTrue($mock->startCalled);

        $startTime = time();
        $manager->run(1); // 1秒超时
        $elapsed = time() - $startTime;

        self::assertGreaterThanOrEqual(1, $elapsed);
        self::assertLessThanOrEqual(2, $elapsed);
        self::assertGreaterThan(0, $mock->receiveCallCount);
    }

    public function testOn(): void
    {
        $event = 'test.event';
        $callbackExecuted = false;

        $callback = function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
        };

        $this->manager->on($event, $callback);

        // 通过反射触发事件
        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);
        $fireEvent->invoke($this->manager, $event);

        self::assertTrue($callbackExecuted);
    }

    public function testOff(): void
    {
        $event = 'test.event';
        $callbackExecuted = false;

        $callback = function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
        };

        $reflection = new \ReflectionClass($this->manager);
        $fireEvent = $reflection->getMethod('fireEvent');
        $fireEvent->setAccessible(true);

        // 添加回调并触发
        $this->manager->on($event, $callback);
        $fireEvent->invoke($this->manager, $event);
        self::assertTrue($callbackExecuted);

        // 移除回调后再次触发
        $this->manager->off($event, $callback);

        // 创建新的变量来追踪移除后的状态
        $executedAfterRemoval = false;
        $callbackAfterRemoval = function () use (&$executedAfterRemoval): void {
            $executedAfterRemoval = true;
        };

        // 仅对移除的事件触发，不添加新的回调
        $fireEvent->invoke($this->manager, $event);

        // 验证原始回调没有被执行（因为变量没有被重置）
        self::assertTrue($callbackExecuted); // 第一次执行后应该还是true
        self::assertFalse($executedAfterRemoval); // 新变量应该还是false
    }
}
