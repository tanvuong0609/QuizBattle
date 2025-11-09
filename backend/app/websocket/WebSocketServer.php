<?php
namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Rooms\RoomManager;

class WebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $roomManager;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->roomManager = new RoomManager();
        echo "🚀 Server started on port 8080\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "🔗 New connection: {$conn->resourceId}\n";
        
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Welcome to QuizBattle!',
            'connection_id' => $conn->resourceId
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        echo "📨 Message from {$from->resourceId}: {$msg}\n";
        
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) {
            return $this->sendError($from, 'Invalid message format');
        }

        switch ($data['type']) {
            case 'join_room':
                $this->handleJoinRoom($from, $data);
                break;
            case 'chat_message':
                $this->handleChatMessage($from, $data);
                break;
            default:
                $this->sendError($from, 'Unknown message type');
        }
    }

    public function onClose(ConnectionInterface $conn) {
        echo "🔌 Disconnected: {$conn->resourceId}\n";
        $this->roomManager->removePlayer($conn);
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function handleJoinRoom(ConnectionInterface $conn, $data) {
        $playerName = $data['player_name'] ?? 'Anonymous';
        echo "🎮 {$playerName} joining room...\n";
        
        $roomInfo = $this->roomManager->assignPlayerToRoom($conn, $playerName);
        
        // Gửi thông tin room cho player
        $conn->send(json_encode([
            'type' => 'room_joined',
            'room_code' => $roomInfo['room_code'],
            'player_name' => $playerName,
            'players_count' => $roomInfo['players_count']
        ]));
        
        // Thông báo cho players khác
        $this->broadcastToRoom($roomInfo['room_code'], [
            'type' => 'player_joined',
            'player_name' => $playerName,
            'players_count' => $roomInfo['players_count']
        ], $conn);
    }

    private function handleChatMessage(ConnectionInterface $from, $data) {
        $roomCode = $this->roomManager->getPlayerRoom($from);
        if ($roomCode) {
            $this->broadcastToRoom($roomCode, [
                'type' => 'chat',
                'player_name' => $data['player_name'] ?? 'Anonymous',
                'message' => $data['message'] ?? '',
                'time' => date('H:i:s')
            ]);
        }
    }

    private function broadcastToRoom($roomCode, $message, $exclude = null) {
        $players = $this->roomManager->getRoomPlayers($roomCode);
        foreach ($players as $player) {
            if (!$exclude || $player['connection'] !== $exclude) {
                $player['connection']->send(json_encode($message));
            }
        }
        echo "📢 Broadcast to {$roomCode}: {$message['type']}\n";
    }

    private function sendError(ConnectionInterface $conn, $message) {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message
        ]));
    }
}
?>