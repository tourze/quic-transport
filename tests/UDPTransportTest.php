<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\UDPTransport;

/**
 * UDP传输层测试
 */
class UDPTransportTest extends TestCase
{
    private UDPTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new UDPTransport('127.0.0.1', 0);
    }

    protected function tearDown(): void
    {
        $this->transport->close();
    }

    public function testTransportStartAndStop(): void
    {
        $this->assertFalse($this->transport->isReady());
        
        $this->transport->start();
        $this->assertTrue($this->transport->isReady());
        
        $this->transport->stop();
        $this->assertFalse($this->transport->isReady());
    }

    public function testGetLocalAddress(): void
    {
        $this->transport->start();
        
        $address = $this->transport->getLocalAddress();
        $this->assertIsArray($address);
        $this->assertArrayHasKey('host', $address);
        $this->assertArrayHasKey('port', $address);
        $this->assertEquals('127.0.0.1', $address['host']);
        $this->assertGreaterThan(0, $address['port']);
    }

    public function testSetTimeout(): void
    {
        $this->transport->setTimeout(5000);
        $this->transport->start();
        
        // 测试接收超时
        $startTime = microtime(true);
        $result = $this->transport->receive();
        $endTime = microtime(true);
        
        $this->assertNull($result);
        $this->assertLessThan(6.0, $endTime - $startTime); // 应该在6秒内超时
    }

    public function testSendAndReceive(): void
    {
        // 创建两个传输实例进行通信测试
        $transport1 = new UDPTransport('127.0.0.1', 0);
        $transport2 = new UDPTransport('127.0.0.1', 0);
        
        try {
            $transport1->start();
            $transport2->start();
            
            $addr1 = $transport1->getLocalAddress();
            $addr2 = $transport2->getLocalAddress();
            
            $testData = 'Hello, QUIC Transport!';
            
            // transport1 发送数据到 transport2
            $result = $transport1->send($testData, $addr2['host'], $addr2['port']);
            $this->assertTrue($result);
            
            // transport2 接收数据
            $transport2->setTimeout(1000);
            $received = $transport2->receive();
            
            $this->assertNotNull($received);
            $this->assertEquals($testData, $received['data']);
            $this->assertEquals($addr1['host'], $received['host']);
            $this->assertEquals($addr1['port'], $received['port']);
            
        } finally {
            $transport1->close();
            $transport2->close();
        }
    }
} 