<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WebSocketServer;

// Hiển thị thông tin startup
echo "========================================\n";
echo "🎯 QUIZBATTLE WEBSOCKET SERVER\n";
echo "========================================\n";
echo "Starting server on port 8080...\n";
echo "Server IP: " . (getHostByName(getHostName()) ?: '127.0.0.1') . "\n";
echo "Local URL: ws://localhost:8080\n";
echo "Press Ctrl+C to stop the server\n";
echo "========================================\n";

// Tăng memory limit và execution time
ini_set('memory_limit', '256M');
set_time_limit(0);

// Tạo WebSocket server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new WebSocketServer()
        )
    ),
    8080, // Port
    '0.0.0.0' // Listen on all interfaces
);

// Chạy server
try {
    $server->run();
} catch (Exception $e) {
    echo "❌ Server error: " . $e->getMessage() . "\n";
    echo "🔄 Restarting server...\n";
    // Có thể thêm logic restart ở đây
}
?>