<?php
namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Rooms\RoomManager;
use App\Services\QuestionManager;
use App\Services\GameManager;

class WebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $roomManager;
    protected $questionManager;
    protected $gameManager;
    protected $pingTimers;
    protected $lastPingTime;

    public function __construct() {
        // DANH SÁCH FILE CẦN XÓA KHI SERVER KHỞI ĐỘNG
        $filesToClean = [
            __DIR__ . '/../../game_data.json',
            __DIR__ . '/../services/game_states.json',
            __DIR__ . '/../services/room_questions.json'
        ];

        echo "🧹 Cleaning old data files...\n";
        foreach ($filesToClean as $file) {
            $absolutePath = realpath($file);
            echo "Checking: $file\n";
            
            if (file_exists($file)) {
                unlink($file);
                echo "✅ DELETED: $file\n";
            } else {
                echo "⚠️ NOT FOUND: $file\n";
            }
        }

        $this->clients = new \SplObjectStorage;
        $this->roomManager = new RoomManager();
        $this->questionManager = new QuestionManager(__DIR__ . '/../services/questions.json');
        $this->gameManager = new GameManager();
        $this->pingTimers = [];
        $this->lastPingTime = [];
        
        echo "🚀 WebSocket Server started on port 8080\n";
        echo "📁 RoomManager initialized\n";
        echo "❓ QuestionManager initialized\n";
        echo "🎮 GameManager initialized\n";
        echo "🔄 Auto-reconnection support enabled\n";
        echo "========================================\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        $this->lastPingTime[$conn->resourceId] = time();
        
        echo "🔗 New connection: {$conn->resourceId}\n";
        echo "📊 Total connections: " . count($this->clients) . "\n";
        
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Welcome to QuizBattle!',
            'connection_id' => $conn->resourceId,
            'timestamp' => time(),
            'server_version' => '1.0.0'
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $this->lastPingTime[$from->resourceId] = time();
        
        try {
            $data = json_decode($msg, true);
            
            if (!$data) {
                throw new \Exception('Invalid JSON format');
            }
            
            if (!isset($data['type'])) {
                throw new \Exception('Missing message type');
            }

            if ($data['type'] !== 'ping') {
                echo "📨 Message from {$from->resourceId}: {$data['type']}\n";
            }

            switch ($data['type']) {
                case 'ping':
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
                    
                case 'player_ready':
                    $this->handlePlayerReady($from, $data);
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

        $room = $this->roomManager->getRoom($roomId);
        if (!$room) {
            echo "❌ Room {$roomId} not found\n";
            return $this->sendError($conn, 'Room not found or expired. Please join a new room.');
        }

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

        $result = $this->roomManager->updatePlayerConnection($playerId, $conn->resourceId);
        
        if ($result['success']) {
            $response = [
                'type' => 'room_joined',
                'room' => $result['room'],
                'player' => $player,
                'message' => 'Successfully rejoined room',
                'is_recovery' => true,
                'timestamp' => time()
            ];
            
            $conn->send(json_encode($response));

            $this->broadcastToRoom($roomId, [
                'type' => 'player_rejoined',
                'player' => $player,
                'message' => "{$playerName} has reconnected",
                'room' => $result['room'],
                'timestamp' => time()
            ], $conn);

            echo "✅ {$playerName} successfully rejoined room {$roomId}\n";

            if ($room['status'] === 'playing') {
                $this->sendGameState($conn, $roomId);
            }
        } else {
            echo "❌ Failed to update player connection: " . $result['message'] . "\n";
            $this->sendError($conn, $result['message']);
        }
    }

    private function handlePlayerReady(ConnectionInterface $from, $data) {
        try {
            $playerId = 'player_' . $from->resourceId;
            
            echo "📨 handlePlayerReady called for player: {$playerId}\n";
            echo "📊 Data received: " . json_encode($data) . "\n";
            
            $room = $this->roomManager->getRoomByResourceId($from->resourceId);
            
            if (!$room) {
                echo "❌ Player {$playerId} not in any room\n";
                return $this->sendError($from, 'You are not in a room');
            }
            
            echo "📍 Player found in room: {$room['id']}, status: {$room['status']}\n";
            
            if ($room['status'] !== 'waiting') {
                echo "❌ Room status is {$room['status']}, not 'waiting'\n";
                return $this->sendError($from, 'Cannot change ready status at this time');
            }
            
            $isReady = $data['is_ready'] ?? true;
            
            echo "🎮 Player {$playerId} setting ready to: " . ($isReady ? 'READY' : 'NOT READY') . "\n";
            
            // Update ready state in RoomManager
            $result = $this->roomManager->setPlayerReady($playerId, $isReady);
            
            if (!$result['success']) {
                echo "❌ Failed to set player ready: {$result['message']}\n";
                return $this->sendError($from, $result['message']);
            }
            
            echo "✅ Successfully updated ready state for {$playerId}\n";
            
            // Get fresh room data (already an array from toArray())
            $updatedRoom = $result['room'];
            
            // Get ready count
            $readyStatus = $this->roomManager->getReadyCount($room['id']);
            echo "📊 Ready status: {$readyStatus['ready']}/{$readyStatus['total']}\n";
            
            // Prepare broadcast message
            $broadcastMessage = [
                'type' => 'player_ready_update',
                'player_id' => $playerId,
                'is_ready' => $isReady,
                'room' => $updatedRoom,
                'ready_count' => $readyStatus,
                'timestamp' => time()
            ];
            
            echo "📡 Broadcasting player_ready_update to room {$room['id']}\n";
            
            // Broadcast to ALL players (including sender)
            $this->broadcastToRoom($room['id'], $broadcastMessage);
            
            echo "✅ Broadcast completed\n";
            
            // Check if all players are ready
            if ($this->checkAllPlayersReady($updatedRoom)) {
                echo "🎉 All players ready in room {$room['id']}!\n";
                
                $this->broadcastToRoom($room['id'], [
                    'type' => 'all_players_ready',
                    'countdown' => 5,
                    'message' => 'All players ready! Game can start now.',
                    'timestamp' => time()
                ]);
            } else {
                echo "⏳ Waiting for more players to ready ({$readyStatus['ready']}/{$readyStatus['total']})\n";
            }
            
        } catch (\Exception $e) {
            echo "❌❌❌ EXCEPTION in handlePlayerReady: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            
            try {
                $this->sendError($from, 'Server error: ' . $e->getMessage());
            } catch (\Exception $e2) {
                echo "❌ Failed to send error message: " . $e2->getMessage() . "\n";
            }
        }
    }

    private function checkAllPlayersReady($room) {
        try {
            if (empty($room['playerDetails'])) {
                return false;
            }
            
            // Need at least 2 players
            if (count($room['playerDetails']) < 2) {
                return false;
            }
            
            // Check if all players are ready
            foreach ($room['playerDetails'] as $player) {
                if (!isset($player['ready']) || $player['ready'] !== true) {
                    return false;
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            echo "❌ Error in checkAllPlayersReady: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function sendGameState(ConnectionInterface $conn, $roomId) {
        $room = $this->roomManager->getRoom($roomId);
        
        if (!$room) {
            echo "❌ Room {$roomId} not found\n";
            return;
        }
        
        if ($room['status'] === 'playing') {
            // Lấy câu hỏi hiện tại từ QuestionManager
            $currentQuestion = $this->questionManager->getCurrentQuestion($roomId);
            
            if ($currentQuestion) {
                $conn->send(json_encode([
                    'type' => 'new_question',
                    'question' => $currentQuestion,
                    'time_limit' => $currentQuestion['time_limit'],
                    'is_recovery' => true,
                    'timestamp' => time()
                ]));
                
                echo "🎮 Sent current question to {$conn->resourceId} in room {$roomId}\n";
            }
            
            // Gửi scores hiện tại
            $this->sendScoresToPlayer($conn, $roomId);
        }
    }
    
    private function sendScoresToPlayer(ConnectionInterface $conn, $roomId) {
        $leaderboard = $this->gameManager->getLeaderboard($roomId);
        
        if ($leaderboard) {
            $conn->send(json_encode([
                'type' => 'scores_update',
                'scores' => $leaderboard,
                'timestamp' => time()
            ]));
        }
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
            try {
                $totalQuestions = 10;
                $this->questionManager->initializeGameQuestions($room['id'], $totalQuestions, 'random');
                
                $this->gameManager->initializeGame($room['id'], $room['playerDetails']);
                
                $this->broadcastToRoom($room['id'], [
                    'type' => 'game_starting',
                    'countdown' => 3,
                    'total_questions' => $totalQuestions,
                    'message' => 'Game is starting in 3 seconds...',
                    'timestamp' => time()
                ]);

                // Sử dụng timer thay vì sleep để không block server
                $roomId = $room['id'];
                $server = $this;
                
                // Tạo timer để gửi câu hỏi đầu tiên sau 3 giây
                $timer = new \React\EventLoop\Timer\Timer(3, function() use ($server, $roomId) {
                    $server->sendNextQuestion($roomId);
                });
                
            } catch (\Exception $e) {
                echo "❌ Error starting game: " . $e->getMessage() . "\n";
                $this->sendError($from, 'Failed to start game: ' . $e->getMessage());
            }
        } else {
            $this->sendError($from, $result['message']);
        }
    }

    private function sendNextQuestion($roomId) {
        try {
            $question = $this->questionManager->getNextQuestion($roomId);
            
            if (!$question) {
                echo "🏁 No more questions, ending game for room {$roomId}\n";
                $this->endGame($roomId);
                return;
            }
            
            $this->gameManager->setCurrentQuestion($roomId, $question['id']);
            
            $clientQuestion = $question;
            unset($clientQuestion['_correct_answer']);
            
            $this->broadcastToRoom($roomId, [
                'type' => 'new_question',
                'question' => $clientQuestion,
                'time_limit' => $question['time_limit'],
                'question_number' => $question['question_number'],
                'total_questions' => $question['total_questions'],
                'timestamp' => time()
            ]);
            
            echo "❓ Sent question {$question['question_number']}/{$question['total_questions']} to room {$roomId}\n";
            
            // Tự động chuyển câu hỏi tiếp theo sau khi hết thời gian
            $this->scheduleNextQuestion($roomId, $question['time_limit']);
            
        } catch (\Exception $e) {
            echo "❌ Error sending question: " . $e->getMessage() . "\n";
        }
    }
    
    private function scheduleNextQuestion($roomId, $timeLimit) {
        $server = $this;
        
        // Tạo timer để tự động chuyển câu hỏi sau khi hết giờ + thời gian chờ kết quả
        $totalWaitTime = $timeLimit + 5; // Thời gian làm bài + 5 giây xem kết quả
        
        $timer = new \React\EventLoop\Timer\Timer($totalWaitTime, function() use ($server, $roomId) {
            if ($this->questionManager->hasMoreQuestions($roomId)) {
                $server->sendNextQuestion($roomId);
            } else {
                $server->endGame($roomId);
            }
        });
    }
    
    private function endGame($roomId) {
        try {
            $leaderboard = $this->gameManager->finishGame($roomId);
            
            $this->broadcastToRoom($roomId, [
                'type' => 'game_finished',
                'scores' => $leaderboard,
                'timestamp' => time()
            ]);
            
            $this->questionManager->resetRoom($roomId);
            $this->gameManager->resetGame($roomId);
            
            // Cập nhật trạng thái phòng về waiting
            $this->roomManager->updateRoomStatus($roomId, \App\Rooms\Room::STATUS_WAITING);
            
            echo "🏁 Game ended for room {$roomId}\n";
            
        } catch (\Exception $e) {
            echo "❌ Error ending game: " . $e->getMessage() . "\n";
        }
    }

    /**
     * SỬA LỖI QUAN TRỌNG: Xử lý submit answer với logic thực tế
     */
    private function handleSubmitAnswer(ConnectionInterface $from, $data) {
        $playerId = 'player_' . $from->resourceId;
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        
        if (!$room) {
            echo "❌ Player {$playerId} not found in any room\n";
            return $this->sendError($from, 'You are not in a room');
        }
        
        $roomId = $room['id'];
        $questionId = $data['question_id'] ?? null;
        $answerId = $data['answer_id'] ?? null;
        
        echo "📝 Player {$playerId} (in room {$roomId}) submitted answer: {$answerId} for question {$questionId}\n";
        
        // Kiểm tra câu hỏi hiện tại
        $gameState = $this->gameManager->getGameState($roomId);
        if (!$gameState || $gameState['current_question_id'] !== $questionId) {
            return $this->sendError($from, 'Invalid question or question not active');
        }
        
        // Kiểm tra đã trả lời chưa
        if ($this->gameManager->hasAnswered($roomId, $playerId, $questionId)) {
            return $this->sendError($from, 'Already answered this question');
        }
        
        // Tính thời gian đã trải qua
        $timeSpent = time() - $gameState['question_start_time'];
        
        // Kiểm tra đáp án với QuestionManager
        $isCorrect = $this->questionManager->verifyAnswer($questionId, $answerId);
        
        // Ghi nhận kết quả
        $result = $this->gameManager->submitAnswer($roomId, $playerId, $questionId, $answerId, $isCorrect, $timeSpent);
        
        // Gửi kết quả cho player
        $from->send(json_encode([
            'type' => 'answer_result',
            'question_id' => $questionId,
            'correct' => $isCorrect,
            'correct_answer' => $this->questionManager->getCorrectAnswer($questionId),
            'score' => $result['score'],
            'total_score' => $result['total_score'],
            'timestamp' => time()
        ]));
        
        // Broadcast cập nhật điểm
        $this->broadcastScores($roomId);
        
        echo "✅ Answer processed: " . ($isCorrect ? 'Correct' : 'Wrong') . " (+{$result['score']} points)\n";
        
        // Kiểm tra nếu tất cả player đã trả lời thì chuyển câu hỏi sớm
        if ($this->gameManager->allPlayersAnswered($roomId, $questionId)) {
            echo "🎉 All players answered in room {$roomId}. Moving to next question...\n";
            
            $this->broadcastToRoom($roomId, [
                'type' => 'all_answered',
                'message' => 'All players have answered! Moving to next question...',
                'timestamp' => time()
            ]);
            
            // Chờ 3 giây rồi chuyển câu hỏi
            $server = $this;
            $timer = new \React\EventLoop\Timer\Timer(3, function() use ($server, $roomId) {
                $server->sendNextQuestion($roomId);
            });
        }
    }

    /**
     * SỬA LỖI: Broadcast scores thực tế từ GameManager
     */
    private function broadcastScores($roomId) {
        $leaderboard = $this->gameManager->getLeaderboard($roomId);
        
        if ($leaderboard) {
            $this->broadcastToRoom($roomId, [
                'type' => 'scores_update',
                'scores' => $leaderboard,
                'timestamp' => time()
            ]);
        }
    }

    /**
     * SỬA LỖI: Xử lý time up thực tế
     */
    private function handleTimeUp(ConnectionInterface $from, $data) {
        $room = $this->roomManager->getRoomByResourceId($from->resourceId);
        if (!$room) return;
        
        $roomId = $room['id'];
        $questionId = $data['question_id'] ?? null;
        
        echo "⏰ Time up for room {$roomId} (question: {$questionId})\n";
        
        // Ghi nhận tất cả player chưa trả lời là sai
        $gameState = $this->gameManager->getGameState($roomId);
        if ($gameState) {
            foreach ($gameState['players'] as $playerId => $player) {
                if (!$this->gameManager->hasAnswered($roomId, $playerId, $questionId)) {
                    // Ghi nhận không trả lời (score = 0)
                    $this->gameManager->submitAnswer($roomId, $playerId, $questionId, null, false, $gameState['question_start_time']);
                }
            }
        }
        
        // Broadcast time up
        $this->broadcastToRoom($roomId, [
            'type' => 'time_up',
            'message' => 'Time is up!',
            'timestamp' => time()
        ]);
        
        // Broadcast scores cập nhật
        $this->broadcastScores($roomId);
        
        // Chuyển câu hỏi tiếp theo sau 5 giây
        $server = $this;
        $timer = new \React\EventLoop\Timer\Timer(5, function() use ($server, $roomId) {
            $server->sendNextQuestion($roomId);
        });
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
        
        // Đánh dấu player disconnected nhưng không xóa khỏi room (cho phép rejoin)
        $result = $this->roomManager->markPlayerDisconnected($conn->resourceId);
        if ($result['success']) {
            echo "⏸️ Player {$conn->resourceId} marked as disconnected (can rejoin)\n";
            
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

    public function checkStaleConnections() {
        $now = time();
        $timeout = 60;
        
        foreach ($this->lastPingTime as $resourceId => $lastPing) {
            if ($now - $lastPing > $timeout) {
                echo "⚠️ Connection {$resourceId} timed out (no ping for {$timeout}s)\n";
                // TODO: Close stale connection
            }
        }
    }
}
?>