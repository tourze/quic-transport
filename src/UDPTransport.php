<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

use Tourze\QUIC\Transport\Exception\TransportException;

/**
 * UDP传输层实现
 *
 * 基于UDP套接字的QUIC传输层实现
 */
class UDPTransport implements TransportInterface
{
    private $socket = null;
    private string $host;
    private int $port;
    private int $timeout = 1000; // 默认1秒超时
    private bool $isRunning = false;

    public function __construct(string $host = '0.0.0.0', int $port = 0)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            throw new TransportException('创建UDP套接字失败: ' . socket_strerror(socket_last_error()));
        }

        // 设置套接字选项
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => (int)($this->timeout / 1000),
            'usec' => ($this->timeout % 1000) * 1000
        ]);

        if (!socket_bind($this->socket, $this->host, $this->port)) {
            $error = socket_strerror(socket_last_error($this->socket));
            socket_close($this->socket);
            throw new TransportException("绑定套接字失败 {$this->host}:{$this->port}: {$error}");
        }

        // 获取实际绑定的端口（如果指定端口为0）
        if ($this->port === 0) {
            socket_getsockname($this->socket, $this->host, $this->port);
        }

        $this->isRunning = true;
    }

    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->close();
        $this->isRunning = false;
    }

    public function send(string $data, string $host, int $port): bool
    {
        if (!$this->isReady()) {
            throw new TransportException('传输层未启动');
        }

        $bytesWritten = socket_sendto($this->socket, $data, strlen($data), 0, $host, $port);
        return $bytesWritten !== false && $bytesWritten === strlen($data);
    }

    public function receive(): ?array
    {
        if (!$this->isReady()) {
            throw new TransportException('传输层未启动');
        }

        $buffer = '';
        $remoteHost = '';
        $remotePort = 0;

        $bytesRead = socket_recvfrom($this->socket, $buffer, 65535, 0, $remoteHost, $remotePort);

        if ($bytesRead === false) {
            $error = socket_last_error($this->socket);
            // 超时不是错误 - 检查常见的超时错误码
            if (in_array($error, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_ETIMEDOUT], true)) {
                return null;
            }
            throw new TransportException('接收数据失败: ' . socket_strerror($error));
        }

        if ($bytesRead === 0) {
            return null;
        }

        return [
            'data' => $buffer,
            'host' => $remoteHost,
            'port' => $remotePort
        ];
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;

        if ($this->socket !== null) {
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => (int)($timeout / 1000),
                'usec' => ($timeout % 1000) * 1000
            ]);
        }
    }

    public function isReady(): bool
    {
        return $this->isRunning && $this->socket !== null;
    }

    public function getLocalAddress(): array
    {
        if (!$this->isReady()) {
            return ['host' => $this->host, 'port' => $this->port];
        }

        $host = '';
        $port = 0;
        socket_getsockname($this->socket, $host, $port);

        return ['host' => $host, 'port' => $port];
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
