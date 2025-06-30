<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Transport\Exception\TransportException;

class TransportExceptionTest extends TestCase
{
    public function testConstructor(): void
    {
        $message = 'Transport error occurred';
        $code = 100;
        $previous = new \Exception('Previous exception');
        
        $exception = new TransportException($message, $code, $previous);
        
        self::assertSame($message, $exception->getMessage());
        self::assertSame($code, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
    
    public function testInheritance(): void
    {
        $exception = new TransportException();
        
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertInstanceOf(\Throwable::class, $exception);
    }
    
    public function testDefaultConstructor(): void
    {
        $exception = new TransportException();
        
        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }
}