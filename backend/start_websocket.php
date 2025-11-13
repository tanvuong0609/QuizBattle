<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\WebSocketServer;
use App\Room\RoomManager;

// Hiển thị thông tin startup
echo "========================================\n";
echo "🎯 QUIZBATTLE WEBSOCKET SERVER\n";
echo "========================================\n";
echo "Starting server on port 8080...\n";
echo "Press Ctrl+C to stop the server\n";
echo "========================================\n";

// Tạo WebSocket server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new WebSocketServer()
        )
    ),
    8080 // Port
);

// Chạy server
$server->run();
?>