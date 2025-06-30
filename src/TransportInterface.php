<?php

declare(strict_types=1);

namespace Tourze\QUIC\Transport;

/**
 * QUIC传输层接口
 *
 * 定义QUIC协议传输层的核心抽象方法
 */
interface TransportInterface
{
    /**
     * 启动传输层
     */
    public function start(): void;

    /**
     * 停止传输层
     */
    public function stop(): void;

    /**
     * 发送数据包
     *
     * @param string $data 要发送的数据
     * @param string $host 目标主机
     * @param int $port 目标端口
     */
    public function send(string $data, string $host, int $port): bool;

    /**
     * 接收数据包
     *
     * @return array{data: string, host: string, port: int}|null
     */
    public function receive(): ?array;

    /**
     * 设置接收超时时间（毫秒）
     */
    public function setTimeout(int $timeout): void;

    /**
     * 检查传输层是否可用
     */
    public function isReady(): bool;

    /**
     * 获取本地绑定地址
     */
    public function getLocalAddress(): array;

    /**
     * 关闭传输层连接
     */
    public function close(): void;
}
