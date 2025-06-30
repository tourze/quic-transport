<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

/**
 * QUIC传输管理器
 *
 * 协调传输层、事件循环和缓冲区管理
 */
class TransportManager
{
    private TransportInterface $transport;
    private EventLoop $eventLoop;
    private BufferManager $bufferManager;
    private bool $running = false;
    private array $eventCallbacks = [];
    private array $connections = [];

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
        $this->eventLoop = new EventLoop();
        $this->bufferManager = new BufferManager();
    }

    /**
     * 启动传输管理器
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->transport->start();
        $this->running = true;
        
        $this->fireEvent('transport.started');
    }

    /**
     * 停止传输管理器
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->transport->stop();
        $this->running = false;
        
        $this->fireEvent('transport.stopped');
    }

    /**
     * 注册连接
     */
    public function registerConnection(string $connectionId, string $host, int $port): void
    {
        $this->connections[$connectionId] = [
            'host' => $host,
            'port' => $port,
            'registered_at' => time(),
        ];
        
        $this->fireEvent('connection.registered', [
            'connection_id' => $connectionId,
            'host' => $host,
            'port' => $port,
        ]);
    }

    /**
     * 注销连接
     */
    public function unregisterConnection(string $connectionId): void
    {
        if (isset($this->connections[$connectionId])) {
            unset($this->connections[$connectionId]);
            
            $this->fireEvent('connection.unregistered', [
                'connection_id' => $connectionId,
            ]);
        }
    }

    /**
     * 发送数据
     */
    public function send(string $connectionId, string $data, string $host, int $port): bool
    {
        try {
            $success = $this->transport->send($data, $host, $port);
            
            if ($success) {
                $this->fireEvent('data.sent', [
                    'connection_id' => $connectionId,
                    'data' => $data,
                    'host' => $host,
                    'port' => $port,
                    'bytes' => strlen($data),
                ]);
            }
            
            return $success;
        } catch (\Exception $e) {
            $this->fireEvent('send.error', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
                'host' => $host,
                'port' => $port,
            ]);
            
            return false;
        }
    }

    /**
     * 接收数据
     * 
     * @return array{data: string, host: string, port: int}|null
     */
    public function receive(string $connectionId): ?array
    {
        try {
            $data = $this->transport->receive();
            
            if ($data !== null) {
                $this->fireEvent('data.received', [
                    'connection_id' => $connectionId,
                    'data' => $data['data'],
                    'host' => $data['host'],
                    'port' => $data['port'],
                ]);
            }
            
            return $data;
        } catch (\Exception $e) {
            $this->fireEvent('receive.error', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * 处理待处理的事件
     */
    public function processPendingEvents(): void
    {
        if (!$this->running) {
            return;
        }

        // 处理传输层事件
        $this->processTransportEvents();
        
        // 处理事件循环
        $this->eventLoop->tick();
        
        // 处理缓冲区
        $this->bufferManager->cleanExpiredBuffers();
    }

    /**
     * 注册事件监听器
     */
    public function on(string $event, callable $callback): void
    {
        if (!isset($this->eventCallbacks[$event])) {
            $this->eventCallbacks[$event] = [];
        }
        
        $this->eventCallbacks[$event][] = $callback;
    }

    /**
     * 移除事件监听器
     */
    public function off(string $event, ?callable $callback = null): void
    {
        if (!isset($this->eventCallbacks[$event])) {
            return;
        }
        
        if ($callback === null) {
            unset($this->eventCallbacks[$event]);
        } else {
            $this->eventCallbacks[$event] = array_filter(
                $this->eventCallbacks[$event],
                fn($cb) => $cb !== $callback
            );
        }
    }

    /**
     * 运行传输循环
     */
    public function run(int $timeoutSeconds = 0): void
    {
        $startTime = time();
        
        while ($this->running) {
            $this->processPendingEvents();
            
            // 检查超时
            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                break;
            }
            
            usleep(10000); // 10ms
        }
    }

    /**
     * 获取连接列表
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return [
            'running' => $this->running,
            'connections_count' => count($this->connections),
            'buffer_stats' => $this->bufferManager->getStatistics(),
            'event_loop_stats' => $this->eventLoop->getStatistics(),
        ];
    }

    /**
     * 处理传输层事件
     */
    private function processTransportEvents(): void
    {
        // 检查是否有新的数据到达
        while (($packet = $this->transport->receive()) !== null) {
            $this->fireEvent('transport.data_received', [
                'data' => $packet['data'],
                'host' => $packet['host'],
                'port' => $packet['port'],
                'timestamp' => microtime(true),
            ]);
        }
    }

    /**
     * 触发事件
     */
    private function fireEvent(string $event, array $data = []): void
    {
        if (!isset($this->eventCallbacks[$event])) {
            return;
        }
        
        foreach ($this->eventCallbacks[$event] as $callback) {
            try {
                $callback($data);
            } catch (\Exception $e) {
                // 记录事件处理错误，但不中断其他事件处理
                error_log("Event callback error for {$event}: " . $e->getMessage());
            }
        }
    }
}