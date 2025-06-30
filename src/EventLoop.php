<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

use SplPriorityQueue;
use Tourze\QUIC\Transport\Exception\TransportException;

/**
 * 事件循环
 *
 * 提供异步I/O事件处理和定时器功能
 */
class EventLoop
{
    private bool $isRunning = false;
    private array $readStreams = [];
    private array $writeStreams = [];
    private array $readCallbacks = [];
    private array $writeCallbacks = [];
    private SplPriorityQueue $timers;
    private int $nextTimerId = 1;

    public function __construct()
    {
        $this->timers = new SplPriorityQueue();
    }

    /**
     * 添加读取监听
     */
    public function addReadStream($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    /**
     * 添加写入监听
     */
    public function addWriteStream($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    /**
     * 移除读取监听
     */
    public function removeReadStream($stream): void
    {
        $id = (int)$stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    /**
     * 移除写入监听
     */
    public function removeWriteStream($stream): void
    {
        $id = (int)$stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    /**
     * 添加定时器
     *
     * @param float $interval 间隔时间（秒）
     * @param callable $callback 回调函数
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
     * @param float $interval 间隔时间（秒）
     * @param callable $callback 回调函数
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
        $newTimers = new SplPriorityQueue();

        while (!$this->timers->isEmpty()) {
            $timer = $this->timers->extract();
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
        $readyTimers = [];

        // 收集到期的定时器
        while (!$this->timers->isEmpty()) {
            $timer = $this->timers->top();
            if ($timer['executeAt'] <= $now) {
                $readyTimers[] = $this->timers->extract();
            } else {
                break;
            }
        }

        // 执行定时器回调
        foreach ($readyTimers as $timer) {
            try {
                call_user_func($timer['callback']);
            } catch (\Throwable $e) {
                // 记录错误但不中断事件循环
                error_log("定时器回调错误: " . $e->getMessage());
            }

            // 如果是循环定时器，重新添加
            if ($timer['interval'] !== null) {
                $timer['executeAt'] = $now + $timer['interval'];
                $this->timers->insert($timer, -$timer['executeAt']);
            }
        }
    }

    /**
     * 处理I/O流事件
     */
    private function processStreams(): void
    {
        if (empty($this->readStreams) && empty($this->writeStreams)) {
            // 没有流需要监听，短暂休眠避免CPU空转
            usleep(1000);
            return;
        }

        $read = $this->readStreams;
        $write = $this->writeStreams;
        $except = [];

        // 计算超时时间（基于最近的定时器）
        $timeout = $this->getNextTimerTimeout();

        $result = stream_select($read, $write, $except, $timeout['sec'], $timeout['usec']);

        if ($result === false) {
            throw new TransportException('stream_select 失败');
        }

        // 处理可读流
        foreach ($read as $stream) {
            $id = (int)$stream;
            if (isset($this->readCallbacks[$id])) {
                try {
                    call_user_func($this->readCallbacks[$id], $stream);
                } catch (\Throwable $e) {
                    error_log("读取回调错误: " . $e->getMessage());
                }
            }
        }

        // 处理可写流
        foreach ($write as $stream) {
            $id = (int)$stream;
            if (isset($this->writeCallbacks[$id])) {
                try {
                    call_user_func($this->writeCallbacks[$id], $stream);
                } catch (\Throwable $e) {
                    error_log("写入回调错误: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * 获取下一个定时器的超时时间
     */
    private function getNextTimerTimeout(): array
    {
        if ($this->timers->isEmpty()) {
            return ['sec' => 1, 'usec' => 0]; // 默认1秒超时
        }

        $timer = $this->timers->top();
        $timeout = max(0, $timer['executeAt'] - microtime(true));

        return [
            'sec' => (int)$timeout,
            'usec' => (int)(($timeout - (int)$timeout) * 1000000)
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
