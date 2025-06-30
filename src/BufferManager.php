<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

use Tourze\QUIC\Transport\Exception\TransportException;

/**
 * 缓冲区管理器
 *
 * 管理QUIC传输层的接收和发送缓冲区
 */
class BufferManager
{
    private array $receiveBuffers = [];
    private array $sendBuffers = [];
    private int $maxBufferSize;
    private int $totalBufferSize = 0;

    public function __construct(int $maxBufferSize = 1048576) // 默认1MB
    {
        $this->maxBufferSize = $maxBufferSize;
    }

    /**
     * 创建接收缓冲区
     */
    public function createReceiveBuffer(string $connectionId): void
    {
        if (isset($this->receiveBuffers[$connectionId])) {
            return;
        }

        $this->receiveBuffers[$connectionId] = [
            'data' => '',
            'size' => 0,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * 创建发送缓冲区
     */
    public function createSendBuffer(string $connectionId): void
    {
        if (isset($this->sendBuffers[$connectionId])) {
            return;
        }

        $this->sendBuffers[$connectionId] = [
            'data' => '',
            'size' => 0,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * 向接收缓冲区写入数据
     */
    public function writeToReceiveBuffer(string $connectionId, string $data): bool
    {
        if (!isset($this->receiveBuffers[$connectionId])) {
            $this->createReceiveBuffer($connectionId);
        }

        $dataSize = strlen($data);
        
        // 检查缓冲区大小限制
        if ($this->totalBufferSize + $dataSize > $this->maxBufferSize) {
            return false;
        }

        $this->receiveBuffers[$connectionId]['data'] .= $data;
        $this->receiveBuffers[$connectionId]['size'] += $dataSize;
        $this->receiveBuffers[$connectionId]['timestamp'] = microtime(true);
        $this->totalBufferSize += $dataSize;

        return true;
    }

    /**
     * 从接收缓冲区读取数据
     */
    public function readFromReceiveBuffer(string $connectionId, int $length = 0): string
    {
        if (!isset($this->receiveBuffers[$connectionId])) {
            return '';
        }

        $buffer = &$this->receiveBuffers[$connectionId];
        
        if ($length === 0 || $length >= $buffer['size']) {
            // 读取全部数据
            $data = $buffer['data'];
            $this->totalBufferSize -= $buffer['size'];
            $buffer['data'] = '';
            $buffer['size'] = 0;
            return $data;
        }

        // 读取指定长度的数据
        $data = substr($buffer['data'], 0, $length);
        $buffer['data'] = substr($buffer['data'], $length);
        $buffer['size'] -= $length;
        $this->totalBufferSize -= $length;

        return $data;
    }

    /**
     * 向发送缓冲区写入数据
     */
    public function writeToSendBuffer(string $connectionId, string $data): bool
    {
        if (!isset($this->sendBuffers[$connectionId])) {
            $this->createSendBuffer($connectionId);
        }

        $dataSize = strlen($data);
        
        // 检查缓冲区大小限制
        if ($this->totalBufferSize + $dataSize > $this->maxBufferSize) {
            return false;
        }

        $this->sendBuffers[$connectionId]['data'] .= $data;
        $this->sendBuffers[$connectionId]['size'] += $dataSize;
        $this->sendBuffers[$connectionId]['timestamp'] = microtime(true);
        $this->totalBufferSize += $dataSize;

        return true;
    }

    /**
     * 从发送缓冲区读取数据
     */
    public function readFromSendBuffer(string $connectionId, int $length = 0): string
    {
        if (!isset($this->sendBuffers[$connectionId])) {
            return '';
        }

        $buffer = &$this->sendBuffers[$connectionId];
        
        if ($length === 0 || $length >= $buffer['size']) {
            // 读取全部数据
            $data = $buffer['data'];
            $this->totalBufferSize -= $buffer['size'];
            $buffer['data'] = '';
            $buffer['size'] = 0;
            return $data;
        }

        // 读取指定长度的数据
        $data = substr($buffer['data'], 0, $length);
        $buffer['data'] = substr($buffer['data'], $length);
        $buffer['size'] -= $length;
        $this->totalBufferSize -= $length;

        return $data;
    }

    /**
     * 获取接收缓冲区大小
     */
    public function getReceiveBufferSize(string $connectionId): int
    {
        return $this->receiveBuffers[$connectionId]['size'] ?? 0;
    }

    /**
     * 获取发送缓冲区大小
     */
    public function getSendBufferSize(string $connectionId): int
    {
        return $this->sendBuffers[$connectionId]['size'] ?? 0;
    }

    /**
     * 清空接收缓冲区
     */
    public function clearReceiveBuffer(string $connectionId): void
    {
        if (isset($this->receiveBuffers[$connectionId])) {
            $this->totalBufferSize -= $this->receiveBuffers[$connectionId]['size'];
            unset($this->receiveBuffers[$connectionId]);
        }
    }

    /**
     * 清空发送缓冲区
     */
    public function clearSendBuffer(string $connectionId): void
    {
        if (isset($this->sendBuffers[$connectionId])) {
            $this->totalBufferSize -= $this->sendBuffers[$connectionId]['size'];
            unset($this->sendBuffers[$connectionId]);
        }
    }

    /**
     * 清空所有缓冲区
     */
    public function clearAllBuffers(string $connectionId): void
    {
        $this->clearReceiveBuffer($connectionId);
        $this->clearSendBuffer($connectionId);
    }

    /**
     * 检查接收缓冲区是否为空
     */
    public function isReceiveBufferEmpty(string $connectionId): bool
    {
        return !isset($this->receiveBuffers[$connectionId]) || 
               $this->receiveBuffers[$connectionId]['size'] === 0;
    }

    /**
     * 检查发送缓冲区是否为空
     */
    public function isSendBufferEmpty(string $connectionId): bool
    {
        return !isset($this->sendBuffers[$connectionId]) || 
               $this->sendBuffers[$connectionId]['size'] === 0;
    }

    /**
     * 获取总缓冲区大小
     */
    public function getTotalBufferSize(): int
    {
        return $this->totalBufferSize;
    }

    /**
     * 获取最大缓冲区大小
     */
    public function getMaxBufferSize(): int
    {
        return $this->maxBufferSize;
    }

    /**
     * 设置最大缓冲区大小
     */
    public function setMaxBufferSize(int $size): void
    {
        if ($size < 0) {
            throw new TransportException('缓冲区大小不能为负数');
        }
        $this->maxBufferSize = $size;
    }

    /**
     * 获取所有连接的缓冲区统计信息
     */
    public function getBufferStats(): array
    {
        $stats = [
            'total_buffer_size' => $this->totalBufferSize,
            'max_buffer_size' => $this->maxBufferSize,
            'connections' => [],
        ];

        // 接收缓冲区统计
        foreach ($this->receiveBuffers as $connectionId => $buffer) {
            $stats['connections'][$connectionId]['receive'] = [
                'size' => $buffer['size'],
                'timestamp' => $buffer['timestamp']
            ];
        }

        // 发送缓冲区统计
        foreach ($this->sendBuffers as $connectionId => $buffer) {
            $stats['connections'][$connectionId]['send'] = [
                'size' => $buffer['size'],
                'timestamp' => $buffer['timestamp']
            ];
        }

        return $stats;
    }

    /**
     * 清理过期的缓冲区
     */
    public function cleanupExpiredBuffers(float $maxAge = 300.0): int
    {
        $now = microtime(true);
        $cleaned = 0;

        // 清理过期的接收缓冲区
        foreach ($this->receiveBuffers as $connectionId => $buffer) {
            if ($now - $buffer['timestamp'] > $maxAge) {
                $this->clearReceiveBuffer($connectionId);
                $cleaned++;
            }
        }

        // 清理过期的发送缓冲区
        foreach ($this->sendBuffers as $connectionId => $buffer) {
            if ($now - $buffer['timestamp'] > $maxAge) {
                $this->clearSendBuffer($connectionId);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * 清理过期缓冲区 (别名方法，用于兼容)
     */
    public function cleanExpiredBuffers(float $maxAge = 300.0): int
    {
        return $this->cleanupExpiredBuffers($maxAge);
    }

    /**
     * 获取统计信息 (别名方法，用于兼容)
     */
    public function getStatistics(): array
    {
        return $this->getBufferStats();
    }
} 