<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\QUIC\Transport\Exception\TransportException;

/**
 * @internal
 */
#[CoversClass(TransportException::class)]
final class TransportExceptionTest extends AbstractExceptionTestCase
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

        // 测试继承层次结构
        $reflection = new \ReflectionClass($exception);
        self::assertTrue($reflection->isSubclassOf(\RuntimeException::class));
    }

    public function testDefaultConstructor(): void
    {
        $exception = new TransportException();

        self::assertSame('', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }
}
