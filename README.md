# QUIC Transport Package

QUIC协议传输层抽象和事件处理包。

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

## 示例

查看 `examples/` 目录获取更多使用示例。

## 系统要求

- PHP >= 8.1
- sockets 扩展

## 许可证

MIT License
