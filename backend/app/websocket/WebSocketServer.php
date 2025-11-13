<?php
namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Rooms\RoomManager;

class WebSocketServer implements MessageComponentInterface {
    protected $clients;
    protected $roomManager;
    protected $questionTimers = [];
    // protected $loop;


    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->roomManager = new RoomManager();
        $this->questionTimers = [];
        // $this->loop = $loop;
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
            case 'time_up': // Xử lý khi client báo time up
                $this->handleTimeUp($from, $data);
                break;
            default:
                $this->sendError($from, 'Unknown message type');
        }
    }

    public function onClose(ConnectionInterface $conn) {
        echo "🔌 Disconnected: {$conn->resourceId}\n";

        $result = $this->roomManager->removePlayer($conn->resourceId);

        if ($result['success']) {
            echo "❌ Player removed {$conn->resourceId} from room\n";
        }

        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function handleJoinRoom(ConnectionInterface $conn, $data) {
        $playerName = $data['player_name'] ?? 'Anonymous';
        echo "🎮 {$playerName}({$conn->resourceId}) joining room...\n";
        
        $result = $this->roomManager->addPlayer($conn->resourceId, $playerName);
        
        if ($result['success']) {

             // Gửi thông tin room cho player
            $conn->send(json_encode([
                'type' => 'room_joined',
                'room_code' => $result['room_code'],
                'player' => $result['player'],
                'message' => $result['message'],
                'players_count' => $result['players_count']
            ]));

            // Thông báo cho players khác
            $this->broadcastToRoom($result['room_code'], [
                'type' => 'player_joined',
                'player' => $result['player'],
                'players_count' => $result['room']['players_count'] ?? count($result['room']['players']),
                'message' => "{$playerName} has joined the room."
            ], $conn);

            echo "✅ {$playerName} joined room {$result['room']['id']}\n";

        } else {
            $this->sendError($conn, $result['message']);
        }
    }

    private function handleCreateRoom(ConnectionInterface $from){
        $result = $this->roomManager->createRoom();
        $from->send(json_encode($result));
    }

    private function handleGetRooms(ConnectionInterface $from) {
        $rooms = $this->roomManager->getAllRooms();
        $from->send(json_encode([
            'type' => 'rooms_list',
            'rooms' => $rooms
        ]));
    }

    private function handleGetStats(ConnectionInterface $from) {
        $stats = $this->roomManager->getStatistics();
        $from->send(json_encode([
            'type' => 'stats',
            'stats' => $stats
        ]));
    }

    private function handleStartGame(ConnectionInterface $from, $data) {
        $roomCode = $this->roomManager->getPlayerRoom($from);
        if (!$roomCode) {
            $this->sendError($from, 'You are not in a room');
            return;
        }
        
        $question = $this->roomManager->startGame($roomCode);
        if ($question) {
            $this->sendQuestionToRoom($roomCode, $question);
        } else {
            $this->sendError($from, 'Failed to start game - no questions available');
        }
    }

    private function handleSubmitAnswer(ConnectionInterface $from, $data) {
        $roomCode = $this->roomManager->getPlayerRoom($from);
        $playerId = $from->resourceId;
        $questionId = $data['question_id'] ?? null;
        $answerIndex = $data['answer_index'] ?? null;
        
        if (!$roomCode || $questionId === null || $answerIndex === null) {
            return $this->sendError($from, 'Invalid answer data');
        }
        
        $isCorrect = $this->roomManager->submitAnswer($roomCode, $playerId, $questionId, $answerIndex);
        
        // Gửi kết quả cho player
        $from->send(json_encode([
            'type' => 'answer_result',
            'question_id' => $questionId,
            'correct' => $isCorrect,
            'correct_answer' => $this->roomManager->getCurrentQuestion($roomCode)['correct_answer'] ?? null
        ]));
        
        // Kiểm tra nếu tất cả players đã trả lời
        if ($this->allPlayersAnswered($roomCode, $questionId)) {
            $this->nextQuestionOrFinish($roomCode);
        }
    }

    private function sendQuestionToRoom($roomCode, $question) {
        $this->broadcastToRoom($roomCode, [
            'type' => 'new_question',
            'question' => $question,
            'time_limit' => $question['time_limit'],
            'server_time' => time() // Gửi thời gian server để client sync
        ]);
        
        echo "❓ Sent question to room {$roomCode}: {$question['question']}\n";
        
        // Đặt timer cho câu hỏi
        $this->setAutoNextTimer($roomCode, $question['time_limit']);
    }

    private function setAutoNextTimer($roomCode, $duration) {
        // Hủy timer cũ nếu có
        if (isset($this->questionTimers[$roomCode])) {
            return;
        }
        
        // Đánh dấu có timer đang chạy
        $this->questionTimers[$roomCode] = true;
        
        // Chạy timer trong background (đơn giản)
        $this->runSimpleTimer($roomCode, $duration);
    }

    private function runSimpleTimer($roomCode, $duration) {
        // Thêm 2 giây buffer để đảm bảo client có thời gian xử lý
        $waitTime = $duration + 2;
        
        // Sử dụng shell_exec để chạy background process
        $script = __DIR__ . "/../timer_script.php";
        $command = "php \"$script\" \"$roomCode\" \"$waitTime\"";
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $command, "r"));
        } else {
            exec($command . " > /dev/null 2>&1 &");
        }
        
        echo "⏰ Auto-next timer set for {$waitTime}s in room {$roomCode}\n";
    }

    private function handleTimeUp(ConnectionInterface $from, $data) {
        $roomCode = $this->roomManager->getPlayerRoom($from);
        if (!$roomCode) return;
        
        echo "⏰ Client reported time up in room {$roomCode}\n";
        $this->nextQuestionOrFinish($roomCode);
    }

    // private function setQuestionTimer($roomCode, $duration) {
    //     // Hủy timer cũ nếu có
    //     $this->cancelQuestionTimer($roomCode);
        
    //     $remaining = $duration;

    //     // Timer cập nhật mỗi giây
    //     $intervalTimer = $this->loop->addPeriodicTimer(1, function() use (&$remaining, $roomCode) {
    //         if ($remaining > 0) {
    //             $this->broadcastToRoom($roomCode, [
    //                 'type' => 'time_update',
    //                 'remaining_time' => $remaining
    //             ]);
    //             echo "⏰ Room {$roomCode}: {$remaining}s remaining\n";
    //             $remaining--;
    //         }
    //     });

    //     // Timer kết thúc
    //     $mainTimer = $this->loop->addTimer($duration, function() use ($roomCode, $intervalTimer) {
    //         $this->loop->cancelTimer($intervalTimer);
    //         $this->broadcastToRoom($roomCode, [
    //             'type' => 'time_up',
    //             'message' => 'Time is up!'
    //         ]);
    //         echo "⏰ Time's up in room {$roomCode}\n";
    //         $this->nextQuestionOrFinish($roomCode);
    //     });

    //     $this->questionTimers[$roomCode] = [
    //         'main_timer' => $mainTimer,
    //         'interval_timer' => $intervalTimer
    //     ];
        
    //     echo "⏰ Timer set for {$duration}s in room {$roomCode}\n";
    // }

    // private function cancelQuestionTimer($roomCode) {
    //     if (isset($this->questionTimers[$roomCode])) {
    //         if (isset($this->questionTimers[$roomCode]['main_timer'])) {
    //             $this->loop->cancelTimer($this->questionTimers[$roomCode]['main_timer']);
    //         }
    //         if (isset($this->questionTimers[$roomCode]['interval_timer'])) {
    //             $this->loop->cancelTimer($this->questionTimers[$roomCode]['interval_timer']);
    //         }
    //         unset($this->questionTimers[$roomCode]);
    //         echo "⏰ Timer cancelled for room {$roomCode}\n";
    //     }
    // }

    private function nextQuestionOrFinish($roomCode) {

        unset($this->questionTimers[$roomCode]);

        if ($this->roomManager->isGameFinished($roomCode)) {
            // Kết thúc game
            $scores = $this->roomManager->getScores($roomCode);
            $this->broadcastToRoom($roomCode, [
                'type' => 'game_finished',
                'scores' => $scores,
                'message' => 'Game finished!'
            ]);
            echo "🏁 Game finished in room {$roomCode}\n";
        } else {
            // Chuyển câu hỏi tiếp theo sau 2 giây
               $nextQuestion = $this->roomManager->nextQuestion($roomCode);
                if ($nextQuestion) {
                    sleep(2); // Đợi 2 giây trước khi gửi câu hỏi tiếp theo
                    $this->sendQuestionToRoom($roomCode, $nextQuestion);
                }
            
        }
    }

    private function allPlayersAnswered($roomCode, $questionId) {
        $players = $this->roomManager->getRoomPlayers($roomCode);
        foreach ($players as $player) {
            $answered = false;
            foreach ($player['answers'] ?? [] as $answer) {
                if ($answer['question_id'] == $questionId) {
                    $answered = true;
                    break;
                }
            }
            if (!$answered) return false;
        }
        return true;
    }

    // private function getCorrectAnswer($roomCode, $questionId) {
    //     $players = $this->roomManager->getRoomPlayers($roomCode);
    //     foreach ($players as $player) {
    //         foreach ($player['answers'] ?? [] as $answer) {
    //             if ($answer['question_id'] == $questionId && $answer['correct']) {
    //                 return $answer['answer'];
    //             }
    //         }
    //     }
    //     return null;
    // }

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