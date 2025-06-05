<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tourze\QUIC\Transport\TransportManager;
use Tourze\QUIC\Transport\UDPTransport;

echo "QUIC Transport Package 基本使用示例\n";
echo "================================\n\n";

// 创建传输管理器
$transport = new UDPTransport('127.0.0.1', 8443);
$manager = new TransportManager($transport);

// 注册事件监听器
$manager->on('connection.registered', function($data) {
    echo "连接已注册: {$data['connection_id']} ({$data['host']}:{$data['port']})\n";
});

$manager->on('data.received', function($data) {
    echo "接收到数据: {$data['connection_id']} -> " . strlen($data['data']) . " 字节\n";
    echo "数据内容: " . $data['data'] . "\n";
});

$manager->on('data.sent', function($data) {
    $status = $data['success'] ? '成功' : '失败';
    echo "发送数据 {$status}: {$data['connection_id']} -> " . strlen($data['data']) . " 字节\n";
});

try {
    // 启动传输管理器
    echo "启动传输管理器...\n";
    $manager->start();
    
    $stats = $manager->getStats();
    echo "监听地址: {$stats['transport_address']['host']}:{$stats['transport_address']['port']}\n\n";
    
    // 模拟注册一个连接
    $connectionId = 'client-001';
    $manager->registerConnection($connectionId, '127.0.0.1', 9443);
    
    // 模拟发送数据
    echo "发送测试数据...\n";
    $testData = "Hello from QUIC Transport Package!";
    $success = $manager->send($connectionId, $testData, '127.0.0.1', 9443);
    echo "发送结果: " . ($success ? '成功' : '失败') . "\n\n";
    
    // 运行一小段时间来处理事件
    echo "运行事件循环 5 秒...\n";
    $startTime = time();
    
    while (time() - $startTime < 5) {
        $manager->getEventLoop()->tick();
        usleep(10000); // 10ms
    }
    
    // 显示统计信息
    echo "\n传输管理器统计信息:\n";
    $stats = $manager->getStats();
    echo "- 运行状态: " . ($stats['is_running'] ? '运行中' : '已停止') . "\n";
    echo "- 连接数量: " . $stats['connections_count'] . "\n";
    echo "- 缓冲区统计: 总大小 " . $stats['buffer_stats']['total_buffer_size'] . " 字节\n";
    
    foreach ($stats['connections'] as $connId => $conn) {
        echo "  连接 {$connId}: {$conn['host']}:{$conn['port']}\n";
        echo "    创建时间: " . date('Y-m-d H:i:s', (int)$conn['created_at']) . "\n";
        echo "    最后活动: " . date('Y-m-d H:i:s', (int)$conn['last_activity']) . "\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
} finally {
    // 清理资源
    echo "\n停止传输管理器...\n";
    $manager->stop();
}

echo "示例运行完毕\n"; 