# QUIC Transport Package

[![PHP Version](https://img.shields.io/badge/php-%5E8.0-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Build Status](https://img.shields.io/badge/build-passing-brightgreen.svg)](#)
[![Code Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)](#)

[English](README.md) | [中文](README.zh-CN.md)

QUIC协议传输层抽象和事件处理包。

## 目录

- [功能特性](#功能特性)
- [安装](#安装)
- [快速开始](#快速开始)
  - [基本使用](#基本使用)
  - [发送和接收数据](#发送和接收数据)
- [核心组件](#核心组件)
  - [TransportInterface](#transportinterface)
  - [UDPTransport](#udptransport)
  - [EventLoop](#eventloop)
  - [BufferManager](#buffermanager)
  - [TransportManager](#transportmanager)
- [事件系统](#事件系统)
- [高级用法](#高级用法)
  - [自定义事件循环配置](#自定义事件循环配置)
  - [高级传输管理器配置](#高级传输管理器配置)
  - [缓冲区管理优化](#缓冲区管理优化)
  - [自定义传输层实现](#自定义传输层实现)
  - [性能监控和统计](#性能监控和统计)
- [示例](#示例)
- [系统要求](#系统要求)
- [许可证](#许可证)

## 功能特性

- **传输层抽象**: 提供统一的传输层接口
- **UDP传输实现**: 基于UDP套接字的QUIC传输实现
- **事件循环**: 异步I/O事件处理和定时器
- **缓冲区管理**: 高效的接收和发送缓冲区管理
- **传输管理器**: 协调传输层、事件循环和缓冲区

## 安装

```bash
composer require tourze/quic-transport
```

## 快速开始

### 基本使用

```php
use Tourze\QUIC\Transport\TransportManager;
use Tourze\QUIC\Transport\UDPTransport;

// 创建传输管理器
$transport = new UDPTransport('127.0.0.1', 8443);
$manager = new TransportManager($transport);

// 注册事件监听器
$manager->on('data.received', function($data) {
    echo "接收到数据: " . $data['data'] . "\n";
});

// 启动并运行
$manager->start();
$manager->run();
```

### 发送和接收数据

```php
// 注册连接
$connectionId = 'client-001';
$manager->registerConnection($connectionId, '127.0.0.1', 9443);

// 发送数据
$success = $manager->send($connectionId, "Hello QUIC!", '127.0.0.1', 9443);

// 接收数据
$data = $manager->receive($connectionId);
```

## 核心组件

### TransportInterface

传输层接口，定义了传输层的核心方法：

- `start()` - 启动传输层
- `stop()` - 停止传输层
- `send()` - 发送数据包
- `receive()` - 接收数据包
- `setTimeout()` - 设置超时时间

### UDPTransport

基于UDP套接字的传输层实现，支持：

- IPv4/IPv6 地址绑定
- 非阻塞I/O操作
- 超时控制
- 自动端口分配

### EventLoop

事件循环管理器，提供：

- I/O流监听
- 定时器管理
- 异步回调执行
- 事件驱动架构

### BufferManager

缓冲区管理器，功能包括：

- 连接级别的缓冲区管理
- 内存使用控制
- 过期缓冲区清理
- 统计信息收集

### TransportManager

传输管理器，协调所有组件：

- 连接生命周期管理
- 事件处理
- 数据缓冲
- 统计信息

## 事件系统

支持的事件类型：

- `connection.registered` - 连接注册
- `connection.unregistered` - 连接注销
- `data.received` - 数据接收
- `data.sent` - 数据发送
- `buffers.cleaned` - 缓冲区清理

```php
$manager->on('data.received', function($data) {
    $connectionId = $data['connection_id'];
    $receivedData = $data['data'];
    $host = $data['host'];
    $port = $data['port'];
    
    // 处理接收到的数据
});
```

## 高级用法

### 自定义事件循环配置

```php
use Tourze\QUIC\Transport\EventLoop;
use Psr\Log\LoggerInterface;

// 使用自定义日志记录器
$logger = new MyCustomLogger();
$eventLoop = new EventLoop($logger);

// 添加定时器
$timerId = $eventLoop->addTimer(5.0, function() {
    echo "5秒后执行\n";
});

// 添加循环定时器
$periodicId = $eventLoop->addPeriodicTimer(1.0, function() {
    echo "每秒执行一次\n";
});

// 取消定时器
$eventLoop->cancelTimer($timerId);
```

### 高级传输管理器配置

```php
use Psr\Log\LoggerInterface;

// 使用自定义日志记录器
$logger = new MyCustomLogger();
$transport = new UDPTransport('0.0.0.0', 8443);
$manager = new TransportManager($transport, $logger);

// 设置连接超时处理
$manager->on('connection.timeout', function($data) {
    $connectionId = $data['connection_id'];
    echo "连接 {$connectionId} 超时\n";
});

// 错误处理
$manager->on('send.error', function($data) {
    $error = $data['error'];
    echo "发送错误: {$error}\n";
});

$manager->on('receive.error', function($data) {
    $error = $data['error'];
    echo "接收错误: {$error}\n";
});
```

### 缓冲区管理优化

```php
$bufferManager = new BufferManager();

// 设置缓冲区大小限制
$bufferManager->setMaxBufferSize(1024 * 1024); // 1MB

// 设置过期时间
$bufferManager->setExpirationTime(300); // 5分钟

// 获取统计信息
$stats = $bufferManager->getStatistics();
echo "总缓冲区数量: " . $stats['buffer_count'] . "\n";
echo "内存使用量: " . $stats['memory_usage'] . " bytes\n";
```

### 自定义传输层实现

```php
use Tourze\QUIC\Transport\TransportInterface;

class CustomTransport implements TransportInterface
{
    public function start(): void
    {
        // 启动自定义传输层
    }
    
    public function stop(): void
    {
        // 停止自定义传输层
    }
    
    public function send(string $data, string $host, int $port): bool
    {
        // 自定义发送逻辑
        return true;
    }
    
    public function receive(): ?array
    {
        // 自定义接收逻辑
        return null;
    }
    
    public function setTimeout(int $timeout): void
    {
        // 设置超时
    }
    
    public function isReady(): bool
    {
        return true;
    }
    
    public function getLocalAddress(): array
    {
        return ['host' => '127.0.0.1', 'port' => 8443];
    }
    
    public function close(): void
    {
        // 关闭连接
    }
}

// 使用自定义传输层
$customTransport = new CustomTransport();
$manager = new TransportManager($customTransport);
```

### 性能监控和统计

```php
// 获取详细统计信息
$statistics = $manager->getStatistics();

echo "运行状态: " . ($statistics['running'] ? '运行中' : '已停止') . "\n";
echo "连接数量: " . $statistics['connections_count'] . "\n";
echo "缓冲区统计: \n";
print_r($statistics['buffer_stats']);
echo "事件循环统计: \n";
print_r($statistics['event_loop_stats']);
```

## 示例

查看 `examples/` 目录获取更多使用示例。

## 系统要求

- PHP >= 8.1
- sockets 扩展

## 许可证

MIT License
