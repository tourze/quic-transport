<?php

declare(strict_types=1);

namespace Tourze\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\BufferManager;
use Tourze\QUIC\Transport\Exception\TransportException;

class BufferManagerTest extends TestCase
{
    private BufferManager $bufferManager;

    protected function setUp(): void
    {
        $this->bufferManager = new BufferManager();
    }

    public function testCreateReceiveBuffer(): void
    {
        $connectionId = 'test-connection';
        $this->bufferManager->createReceiveBuffer($connectionId);
        
        self::assertTrue($this->bufferManager->isReceiveBufferEmpty($connectionId));
        self::assertSame(0, $this->bufferManager->getReceiveBufferSize($connectionId));
    }

    public function testWriteToReceiveBuffer(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $result = $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        
        self::assertTrue($result);
        self::assertFalse($this->bufferManager->isReceiveBufferEmpty($connectionId));
        self::assertSame(strlen($data), $this->bufferManager->getReceiveBufferSize($connectionId));
    }

    public function testReadFromReceiveBuffer(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        $readData = $this->bufferManager->readFromReceiveBuffer($connectionId);
        
        self::assertSame($data, $readData);
        self::assertTrue($this->bufferManager->isReceiveBufferEmpty($connectionId));
    }

    public function testReadPartialFromReceiveBuffer(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        $readData = $this->bufferManager->readFromReceiveBuffer($connectionId, 5);
        
        self::assertSame('Hello', $readData);
        self::assertSame(8, $this->bufferManager->getReceiveBufferSize($connectionId));
    }

    public function testBufferSizeLimit(): void
    {
        $bufferManager = new BufferManager(10); // 10 bytes limit
        $connectionId = 'test-connection';
        
        $result1 = $bufferManager->writeToReceiveBuffer($connectionId, '12345');
        self::assertTrue($result1);
        
        $result2 = $bufferManager->writeToReceiveBuffer($connectionId, '123456');
        self::assertFalse($result2);
    }

    public function testClearBuffers(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        $this->bufferManager->writeToSendBuffer($connectionId, $data);
        
        $this->bufferManager->clearAllBuffers($connectionId);
        
        self::assertTrue($this->bufferManager->isReceiveBufferEmpty($connectionId));
        self::assertTrue($this->bufferManager->isSendBufferEmpty($connectionId));
    }

    public function testSetMaxBufferSizeWithNegativeValue(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('缓冲区大小不能为负数');
        
        $this->bufferManager->setMaxBufferSize(-1);
    }

    public function testGetBufferStats(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        $this->bufferManager->writeToSendBuffer($connectionId, $data);
        
        $stats = $this->bufferManager->getBufferStats();
        
        self::assertArrayHasKey('total_buffer_size', $stats);
        self::assertArrayHasKey('max_buffer_size', $stats);
        self::assertArrayHasKey('connections', $stats);
        self::assertArrayHasKey($connectionId, $stats['connections']);
    }

    public function testCleanupExpiredBuffers(): void
    {
        $connectionId = 'test-connection';
        $data = 'Hello, World!';
        
        $this->bufferManager->writeToReceiveBuffer($connectionId, $data);
        
        // 等待一小段时间
        usleep(1000); // 1毫秒
        
        // 清理过期的缓冲区（过期时间设为0秒）
        $cleaned = $this->bufferManager->cleanupExpiredBuffers(0.0);
        
        self::assertGreaterThan(0, $cleaned);
        self::assertTrue($this->bufferManager->isReceiveBufferEmpty($connectionId));
    }
}