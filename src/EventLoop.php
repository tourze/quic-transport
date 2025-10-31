<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tourze\QUIC\Transport\Exception\TransportException;

/**
 * 事件循环
 *
 * 提供异步I/O事件处理和定时器功能
 */
class EventLoop
{
    private bool $isRunning = false;

    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, callable> */
    private array $readCallbacks = [];

    /** @var array<int, callable> */
    private array $writeCallbacks = [];

    /** @var \SplPriorityQueue<float, array{id: int, callback: callable, executeAt: float, interval: float|null}> */
    private \SplPriorityQueue $timers;

    private int $nextTimerId = 1;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->timers = new \SplPriorityQueue();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 添加读取监听
     * @param resource $stream
     */
    public function addReadStream($stream, callable $callback): void
    {
        assert(is_resource($stream));
        $id = (int) $stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    /**
     * 添加写入监听
     * @param resource $stream
     */
    public function addWriteStream($stream, callable $callback): void
    {
        assert(is_resource($stream));
        $id = (int) $stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    /**
     * 移除读取监听
     * @param resource $stream
     */
    public function removeReadStream($stream): void
    {
        assert(is_resource($stream));
        $id = (int) $stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    /**
     * 移除写入监听
     * @param resource $stream
     */
    public function removeWriteStream($stream): void
    {
        assert(is_resource($stream));
        $id = (int) $stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    /**
     * 添加定时器
     *
     * @param float    $interval 间隔时间（秒）
     * @param callable $callback 回调函数
     *
     * @return int 定时器ID
     */
    public function addTimer(float $interval, callable $callback): int
    {
        $timerId = $this->nextTimerId++;
        $executeAt = microtime(true) + $interval;

        $this->timers->insert([
            'id' => $timerId,
            'callback' => $callback,
            'executeAt' => $executeAt,
            'interval' => null, // 单次执行
        ], -$executeAt); // 负值让最早执行的排在前面

        return $timerId;
    }

    /**
     * 添加循环定时器
     *
     * @param float    $interval 间隔时间（秒）
     * @param callable $callback 回调函数
     *
     * @return int 定时器ID
     */
    public function addPeriodicTimer(float $interval, callable $callback): int
    {
        $timerId = $this->nextTimerId++;
        $executeAt = microtime(true) + $interval;

        $this->timers->insert([
            'id' => $timerId,
            'callback' => $callback,
            'executeAt' => $executeAt,
            'interval' => $interval, // 循环执行
        ], -$executeAt);

        return $timerId;
    }

    /**
     * 取消定时器
     */
    public function cancelTimer(int $timerId): void
    {
        // 重构定时器队列，移除指定ID的定时器
        /** @var \SplPriorityQueue<float, array{id: int, callback: callable(): mixed, executeAt: float, interval: float|null}> $newTimers */
        $newTimers = new \SplPriorityQueue();

        while (!$this->timers->isEmpty()) {
            $timer = $this->timers->extract();
            assert(is_array($timer) && isset($timer['id'], $timer['executeAt']));
            if ($timer['id'] !== $timerId) {
                $newTimers->insert($timer, -$timer['executeAt']);
            }
        }

        $this->timers = $newTimers;
    }

    /**
     * 延迟执行
     */
    public function nextTick(callable $callback): void
    {
        $this->addTimer(0, $callback);
    }

    /**
     * 运行事件循环
     */
    public function run(): void
    {
        $this->isRunning = true;

        while ($this->isRunning) {
            $this->tick();
        }
    }

    /**
     * 停止事件循环
     */
    public function stop(): void
    {
        $this->isRunning = false;
    }

    /**
     * 执行一次事件循环迭代
     */
    public function tick(): void
    {
        // 处理定时器
        $this->processTimers();

        // 处理I/O事件
        $this->processStreams();
    }

    /**
     * 处理定时器
     */
    private function processTimers(): void
    {
        $now = microtime(true);
        $readyTimers = $this->collectExpiredTimers($now);
        $this->executeTimers($readyTimers, $now);
    }

    /**
     * 收集到期的定时器
     *
     * @return array<array{id: int, callback: callable, executeAt: float, interval: float|null}>
     */
    private function collectExpiredTimers(float $now): array
    {
        /** @var array<array{id: int, callback: callable, executeAt: float, interval: float|null}> $readyTimers */
        $readyTimers = [];

        while (!$this->timers->isEmpty()) {
            $timer = $this->timers->top();
            assert(is_array($timer) && isset($timer['executeAt']));
            if ($timer['executeAt'] <= $now) {
                $extractedTimer = $this->timers->extract();
                assert(is_array($extractedTimer) && isset($extractedTimer['id'], $extractedTimer['callback'], $extractedTimer['executeAt']) && array_key_exists('interval', $extractedTimer));
                $readyTimers[] = $extractedTimer;
            } else {
                break;
            }
        }

        return $readyTimers;
    }

    /**
     * 执行定时器回调
     *
     * @param array<array{id: int, callback: callable, executeAt: float, interval: float|null}> $timers
     */
    private function executeTimers(array $timers, float $now): void
    {
        foreach ($timers as $timer) {
            assert(is_array($timer) && isset($timer['callback']) && array_key_exists('interval', $timer) && isset($timer['executeAt']));

            $this->executeTimerCallback($timer);
            $this->reschedulePeriodicTimer($timer, $now);
        }
    }

    /**
     * 执行定时器回调
     *
     * @param array{id: int, callback: callable, executeAt: float, interval: float|null} $timer
     */
    private function executeTimerCallback(array $timer): void
    {
        try {
            call_user_func($timer['callback']);
        } catch (\Throwable $e) {
            $this->logger->error('定时器回调错误', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * 重新调度循环定时器
     *
     * @param array{id: int, callback: callable, executeAt: float, interval: float|null} $timer
     */
    private function reschedulePeriodicTimer(array $timer, float $now): void
    {
        if (null !== $timer['interval']) {
            $timer['executeAt'] = $now + $timer['interval'];
            $this->timers->insert($timer, -$timer['executeAt']);
        }
    }

    /**
     * 处理I/O流事件
     */
    private function processStreams(): void
    {
        if (0 === count($this->readStreams) && 0 === count($this->writeStreams)) {
            // 没有流需要监听，短暂休眠避免CPU空转
            usleep(1000);

            return;
        }

        $selectedStreams = $this->selectStreams();
        $this->processReadableStreams($selectedStreams['read']);
        $this->processWritableStreams($selectedStreams['write']);
    }

    /**
     * 选择就绪的流
     *
     * @return array{read: array<resource>, write: array<resource>}
     */
    private function selectStreams(): array
    {
        $read = $this->readStreams;
        $write = $this->writeStreams;
        $except = [];

        // 计算超时时间（基于最近的定时器）
        $timeout = $this->getNextTimerTimeout();

        $result = stream_select($read, $write, $except, $timeout['sec'], $timeout['usec']);

        if (false === $result) {
            throw new TransportException('stream_select 失败');
        }

        /** @var array<resource> $read */
        /** @var array<resource> $write */
        return ['read' => $read, 'write' => $write];
    }

    /**
     * 处理可读流
     *
     * @param array<resource> $readableStreams
     */
    private function processReadableStreams(array $readableStreams): void
    {
        foreach ($readableStreams as $stream) {
            $this->processStreamCallback($stream, $this->readCallbacks, '读取回调错误');
        }
    }

    /**
     * 处理可写流
     *
     * @param array<resource> $writableStreams
     */
    private function processWritableStreams(array $writableStreams): void
    {
        foreach ($writableStreams as $stream) {
            $this->processStreamCallback($stream, $this->writeCallbacks, '写入回调错误');
        }
    }

    /**
     * 处理流回调
     *
     * @param resource $stream
     * @param array<int, callable> $callbacks
     * @param string $errorMessage
     */
    private function processStreamCallback($stream, array $callbacks, string $errorMessage): void
    {
        assert(is_resource($stream));
        $id = (int) $stream;
        if (!isset($callbacks[$id])) {
            return;
        }

        try {
            call_user_func($callbacks[$id], $stream);
        } catch (\Throwable $e) {
            $this->logger->error($errorMessage, ['exception' => $e->getMessage()]);
        }
    }

    /**
     * 获取下一个定时器的超时时间
     *
     * @return array{sec: int, usec: int}
     */
    private function getNextTimerTimeout(): array
    {
        if ($this->timers->isEmpty()) {
            return ['sec' => 1, 'usec' => 0]; // 默认1秒超时
        }

        $timer = $this->timers->top();
        assert(is_array($timer) && isset($timer['executeAt']));
        $timeout = max(0, $timer['executeAt'] - microtime(true));

        return [
            'sec' => (int) $timeout,
            'usec' => (int) (($timeout - (int) $timeout) * 1000000),
        ];
    }

    /**
     * 检查事件循环是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * 获取统计信息
     *
     * @return array{is_running: bool, read_streams_count: int, write_streams_count: int, timers_count: int, next_timer_id: int}
     */
    public function getStatistics(): array
    {
        return [
            'is_running' => $this->isRunning,
            'read_streams_count' => count($this->readStreams),
            'write_streams_count' => count($this->writeStreams),
            'timers_count' => $this->timers->count(),
            'next_timer_id' => $this->nextTimerId,
        ];
    }
}
