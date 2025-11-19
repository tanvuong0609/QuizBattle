<?php
namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Rooms\RoomManager;

class WebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $roomManager;
    protected $pingTimers;
    protected $lastPingTime;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->roomManager = new RoomManager();
        $this->pingTimers = [];
        $this->lastPingTime = [];
        
        echo "🚀 WebSocket Server started on port 8080\n";
        echo "📁 RoomManager initialized\n";
        echo "🔄 Auto-reconnection support enabled\n";
        echo "========================================\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        // Khởi tạo ping timer
        $this->lastPingTime[$conn->resourceId] = time();
        
        echo "🔗 New connection: {$conn->resourceId}\n";
        echo "📊 Total connections: " . count($this->clients) . "\n";
        
        // Gửi welcome message
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Welcome to QuizBattle!',
            'connection_id' => $conn->resourceId,
            'timestamp' => time(),
            'server_version' => '1.0.0'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Update last activity time
        $this->lastPingTime[$from->resourceId] = time();
        
        try {
            $data = json_decode($msg, true);
            
            if (!$data) {
                throw new \Exception('Invalid JSON format');
            }
            
            if (!isset($data['type'])) {
                throw new \Exception('Missing message type');
            }

            // Log message (trừ ping/pong để tránh spam)
            if ($data['type'] !== 'ping') {
                echo "📨 Message from {$from->resourceId}: {$data['type']}\n";
            }

            switch ($data['type']) {
                case 'ping':
                    // Phản hồi pong ngay lập tức
                    $this->handlePing($from);
                    break;
                    
                case 'join_room':
                    $this->handleJoinRoom($from, $data);
                    break;
                    
                case 'rejoin_room':
                    $this->handleRejoinRoom($from, $data);
                    break;
                    
                case 'chat_message':
                    $this->handleChatMessage($from, $data);
                    break;
                    
                case 'submit_answer':
                    $this->handleSubmitAnswer($from, $data);
                    break;
                    
                case 'start_game':
                    $this->handleStartGame($from, $data);
                    break;
                    
                case 'create_room':
                    $this->handleCreateRoom($from);
                    break;
                    
                case 'get_rooms':
                    $this->handleGetRooms($from);
                    break;
                    
                case 'get_stats':
                    $this->handleGetStats($from);
                    break;
                    
                case 'time_up':
                    $this->handleTimeUp($from, $data);
                    break;
                    
                case 'leave_room':
                    $this->handleLeaveRoom($from, $data);
                    break;
                    
                default:
                    $this->sendError($from, 'Unknown message type: ' . $data['type']);
            }
        } catch (\Exception $e) {
            echo "❌ Error processing message: " . $e->getMessage() . "\n";
            $this->sendError($from, 'Server error: ' . $e->getMessage());
        }
    }

    private function handlePing(ConnectionInterface $from) {
        // Gửi pong response
        $from->send(json_encode([
            'type' => 'pong',
            'timestamp' => time()
        ]));
    }

    private function handleJoinRoom(ConnectionInterface $conn, $data) {
        $playerName = $data['player_name'] ?? 'Anonymous';
        $isRecovery = $data['is_recovery'] ?? false;
        
        echo "🎮 {$playerName}({$conn->resourceId}) joining room...\n";
        
        $result = $this->roomManager->addPlayer($conn->resourceId, $playerName);
        
        if ($result['success']) {
            // Gửi thông tin room cho player
            $response = [
                'type' => 'room_joined',
                'room_code' => $result['room']['id'],
                'player' => $result['player'],
                'message' => $result['message'],
                'room' => $result['room'],
                'is_recovery' => $isRecovery,
                'timestamp' => time()
            ];
            
            $conn->send(json_encode($response));

            // Thông báo cho players khác
            $this->broadcastToRoom($result['room']['id'], [
                'type' => 'player_joined',
                'player' => $result['player'],
                'message' => "{$playerName} has joined the room.",
                'room' => $result['room'],
                'timestamp' => time()
            ], $conn);

            echo "✅ {$playerName} joined room {$result['room']['id']}\n";

        } else {
            $this->sendError($conn, $result['message']);
        }
    }

    private function handleRejoinRoom(ConnectionInterface $conn, $data) {
        $playerId = $data['player_id'] ?? null;
        $roomId = $data['room_id'] ?? null;
        $playerName = $data['player_name'] ?? 'Anonymous';
        
        echo "🔄 Player {$playerName} ({$playerId}) attempting to rejoin room {$roomId}\n";
        
        if (!$playerId || !$roomId) {
            return $this->sendError($conn, 'Missing player_id or room_id for rejoin');
        }

        // Kiểm tra room có tồn tại không
        $room = $this->roomManager->getRoom($roomId);
        if (!$room) {
            echo "❌ Room {$roomId} not found\n";
            return $this->sendError($conn, 'Room not found or expired. Please join a new room.');
        }

        // Tìm player trong room
        $player = null;
        foreach ($room['playerDetails'] as $p) {
            if ($p['id'] === $playerId) {
                $player = $p;
                break;
            }
        }

        if (!$player) {
            echo "❌ Player {$playerId} not found in room {$roomId}\n";
            return $this->sendError($conn, 'Player not found in room. Please join a new room.');
        }

        echo "🔍 Found player: " . $player['name'] . " in room {$roomId}\n";

        // Cập nhật resourceId của player (reconnection)
        $result = $this->roomManager->updatePlayerConnection($playerId, $conn->resourceId);
        
        if ($result['success']) {
            // Gửi trạng thái room hiện tại
            $response = [
                'type' => 'room_joined',
                'room' => $result['room'],
                'player' => $player,
                'message' => 'Successfully rejoined room',
                'is_recovery' => true,
                'timestamp' => time()
            ];
            
            $conn->send(json_encode($response));

            // Thông báo cho players khác
            $this->broadcastToRoom($roomId, [
                'type' => 'player_rejoined',
                'player' => $player,
                'message' => "{$playerName} has reconnected",
                'room' => $result['room'],
                'timestamp' => time()
            ], $conn);

            echo "✅ {$playerName} successfully rejoined room {$roomId}\n";

            // Nếu game đang chạy, gửi trạng thái game
            if ($room['status'] === 'playing') {
                $this->sendGameState($conn, $roomId);
            }
        } else {
            echo "❌ Failed to update player connection: " . $result['message'] . "\n";
            $this->sendError($conn, $result['message']);
        }
    }

    private function sendGameState(ConnectionInterface $conn, $roomId) {
        // TODO: Lấy game state thực tế từ game manager
        // Đây là demo game state
        $gameState = [
            'type' => 'game_state',
            'game' => [
                'status' => 'playing',
                'current_question' => [
                    'id' => 'q1',
                    'question' => 'What is the capital of France?',
                    'answers' => [
                        ['id' => 'a', 'text' => 'London'],
                        ['id' => 'b', 'text' => 'Paris'],
                        ['id' => 'c', 'text' => 'Berlin'],
                        ['id' => 'd', 'text' => 'Madrid']
                    ],
                    'time_limit' => 20
                ],
                'time_remaining' => 15,
                'current_question_number' => 1,
                'total_questions' => 10
            ],
            'timestamp' => time()
        ];
        
        $conn->send(json_encode($gameState));
        echo "🎮 Sent game state to {$conn->resourceId} in room {$roomId}\n";
    }

    private function handleLeaveRoom(ConnectionInterface $conn, $data) {
        $playerId = $data['player_id'] ?? 'player_' . $conn->resourceId;
        $roomId = $data['room_id'] ?? null;
        
        echo "🚪 Player {$playerId} leaving room...\n";
        
        $result = $this->roomManager->removePlayer($conn->resourceId);
        
        if ($result['success']) {
            $conn->send(json_encode([
                'type' => 'left_room',
                'message' => 'You have left the room',
                'timestamp' => time()
            ]));

            // Thông báo cho players khác
            if (isset($result['room'])) {
                $this->broadcastToRoom($result['room']['id'], [
                    'type' => 'player_left',
                    'player_id' => $playerId,
                    'message' => "A player has left the room.",
                    'room' => $result['room'],
                    'timestamp' => time()
                ], $conn);
            }

            echo "✅ Player left room\n";
        }
    }

    private function handleCreateRoom(ConnectionInterface $from) {
        $result = $this->roomManager->createRoom();
        $from->send(json_encode($result));
    }

    private function handleGetRooms(ConnectionInterface $from) {
        $rooms = $this->roomManager->getAllRooms();
        $from->send(json_encode([
            'type' => 'rooms_list',
            'rooms' => $rooms,
            'timestamp' => time()
        ]));
    }

    private function handleGetStats(ConnectionInterface $from) {
        $stats = $this->roomManager->getStatistics();
        $from->send(json_encode([
            'type' => 'stats',
            'stats' => $stats,
            'timestamp' => time()
        ]));
    }

    private function handleStartGame(ConnectionInterface $from, $data) {
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        if (!$room) {
            return $this->sendError($from, 'You are not in a room');
        }
        
        echo "🎮 Starting game in room {$room['id']}\n";
        
        $result = $this->roomManager->startGame($room['id']);
        if ($result['success']) {
            // Gửi thông báo game starting với countdown
            $this->broadcastToRoom($room['id'], [
                'type' => 'game_starting',
                'countdown' => 3,
                'message' => 'Game is starting in 3 seconds...',
                'timestamp' => time()
            ]);

            // Sau 3 giây gửi câu hỏi đầu tiên
            // NOTE: Trong production nên dùng event loop hoặc timer thực sự
            // Ở đây dùng sleep đơn giản cho demo
            sleep(3);
            
            $this->sendFirstQuestion($room['id']);

        } else {
            $this->sendError($from, $result['message']);
        }
    }

    private function sendFirstQuestion($roomId) {
        // TODO: Lấy câu hỏi từ database
        $question = [
            'id' => 'q1',
            'question' => 'What is the capital of France?',
            'answers' => [
                ['id' => 'a', 'text' => 'London'],
                ['id' => 'b', 'text' => 'Paris'],
                ['id' => 'c', 'text' => 'Berlin'],
                ['id' => 'd', 'text' => 'Madrid']
            ],
            'correct_answer' => 'b',
            'time_limit' => 20
        ];

        $this->broadcastToRoom($roomId, [
            'type' => 'new_question',
            'question' => $question,
            'time_limit' => $question['time_limit'],
            'timestamp' => time()
        ]);
        
        echo "❓ Sent first question to room {$roomId}\n";
    }

    private function handleSubmitAnswer(ConnectionInterface $from, $data) {
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        if (!$room) {
            return $this->sendError($from, 'You are not in a room');
        }
        
        $questionId = $data['question_id'] ?? null;
        $answerId = $data['answer_id'] ?? null;
        
        echo "📝 Player {$from->resourceId} submitted answer: {$answerId}\n";
        
        // TODO: Kiểm tra câu trả lời với database
        // Demo: 'b' là đáp án đúng
        $isCorrect = ($answerId === 'b');
        
        // Gửi kết quả cho player
        $from->send(json_encode([
            'type' => 'answer_result',
            'question_id' => $questionId,
            'correct' => $isCorrect,
            'correct_answer' => 'b',
            'timestamp' => time()
        ]));

        // TODO: Cập nhật scores và broadcast
        $this->broadcastScores($room['id']);

        echo "✅ Answer processed: " . ($isCorrect ? 'Correct' : 'Wrong') . "\n";
    }

    private function broadcastScores($roomId) {
        // TODO: Lấy scores thực tế từ database
        // Demo scores
        $scores = [
            ['player_id' => 'player_123', 'player_name' => 'Player 1', 'score' => 100, 'correct_answers' => 1],
            ['player_id' => 'player_456', 'player_name' => 'Player 2', 'score' => 50, 'correct_answers' => 0],
        ];

        $this->broadcastToRoom($roomId, [
            'type' => 'scores_update',
            'scores' => $scores,
            'timestamp' => time()
        ]);
    }

    private function handleTimeUp(ConnectionInterface $from, $data) {
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        if (!$room) return;
        
        echo "⏰ Time up for player {$from->resourceId} in room {$room['id']}\n";
        
        // TODO: Xử lý logic time up
    }

    private function handleChatMessage(ConnectionInterface $from, $data) {
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        if ($room) {
            $this->broadcastToRoom($room['id'], [
                'type' => 'chat',
                'player_name' => $data['player_name'] ?? 'Anonymous',
                'message' => $data['message'] ?? '',
                'time' => date('H:i:s'),
                'timestamp' => time()
            ], $from);
        }
    }

    private function broadcastToRoom($roomId, $message, $exclude = null) {
        $room = $this->roomManager->getRoom($roomId);
        if (!$room) {
            echo "❌ Room {$roomId} not found for broadcast\n";
            return;
        }

        $sentCount = 0;
        foreach ($this->clients as $client) {
            $playerId = 'player_' . $client->resourceId;
            if (in_array($playerId, $room['players'])) {
                if (!$exclude || $client !== $exclude) {
                    try {
                        $client->send(json_encode($message));
                        $sentCount++;
                    } catch (\Exception $e) {
                        echo "❌ Failed to send to client {$client->resourceId}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        
        if ($message['type'] !== 'time_update') {
            echo "📢 Broadcast to {$roomId} ({$sentCount} clients): {$message['type']}\n";
        }
    }

    private function sendError(ConnectionInterface $conn, $message) {
        try {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => $message,
                'timestamp' => time()
            ]));
            echo "❌ Error sent to {$conn->resourceId}: {$message}\n";
        } catch (\Exception $e) {
            echo "❌ Failed to send error to {$conn->resourceId}: " . $e->getMessage() . "\n";
        }
    }

    public function onClose(ConnectionInterface $conn) {
        echo "🔌 Disconnected: {$conn->resourceId}\n";
        
        // Đánh dấu player là disconnected nhưng KHÔNG xóa khỏi room
        // Cho phép họ rejoin trong vòng timeout
        $result = $this->roomManager->markPlayerDisconnected($conn->resourceId);
        if ($result['success']) {
            echo "⏸️ Player {$conn->resourceId} marked as disconnected (can rejoin)\n";
            
            // Broadcast player disconnected (không phải left)
            if (isset($result['room'])) {
                $this->broadcastToRoom($result['room']['id'], [
                    'type' => 'player_disconnected',
                    'message' => "A player has disconnected",
                    'room' => $result['room'],
                    'timestamp' => time()
                ], $conn);
            }
        }

        // Cleanup
        unset($this->lastPingTime[$conn->resourceId]);
        $this->clients->detach($conn);
        
        echo "📊 Remaining connections: " . count($this->clients) . "\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "❌ Error on connection {$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // Optional: Cleanup stale connections
    public function checkStaleConnections() {
        $now = time();
        $timeout = 60; // 60 seconds timeout
        
        foreach ($this->lastPingTime as $resourceId => $lastPing) {
            if ($now - $lastPing > $timeout) {
                echo "⚠️ Connection {$resourceId} timed out (no ping for {$timeout}s)\n";
                // TODO: Close stale connection
            }
        }
    }
}
?>