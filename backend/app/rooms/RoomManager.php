<?php
namespace App\Rooms;

use Ratchet\ConnectionInterface;

class RoomManager {
    private $rooms = [];
    private $players = [];

    public function assignPlayerToRoom(ConnectionInterface $conn, $playerName) {
        $roomCode = $this->findAvailableRoom() ?? $this->createRoom();
        
        $this->addPlayerToRoom($roomCode, $conn, $playerName);
        
        return [
            'room_code' => $roomCode,
            'players_count' => count($this->rooms[$roomCode]['players'])
        ];
    }

    private function findAvailableRoom() {
        foreach ($this->rooms as $code => $room) {
            if (count($room['players']) < 4) {
                return $code;
            }
        }
        return null;
    }

    private function createRoom() {
        $code = $this->generateRoomCode();
        $this->rooms[$code] = [
            'code' => $code,
            'players' => [],
            'max_players' => 4
        ];
        echo "🆕 New room: {$code}\n";
        return $code;
    }

    private function addPlayerToRoom($roomCode, $conn, $playerName) {
        $playerId = $conn->resourceId;
        
        $this->players[$playerId] = [
            'connection' => $conn,
            'player_name' => $playerName,
            'room_code' => $roomCode
        ];

        $this->rooms[$roomCode]['players'][$playerId] = [
            'connection' => $conn,
            'player_name' => $playerName
        ];

        echo "➕ {$playerName} joined {$roomCode}\n";
        
        // Tự động start game nếu đủ 4 players
        if (count($this->rooms[$roomCode]['players']) === 4) {
            $this->startGame($roomCode);
        }
    }

    public function removePlayer(ConnectionInterface $conn) {
        $playerId = $conn->resourceId;
        
        if (!isset($this->players[$playerId])) return null;
        
        $player = $this->players[$playerId];
        $roomCode = $player['room_code'];
        
        // Xóa player khỏi room
        unset($this->rooms[$roomCode]['players'][$playerId]);
        unset($this->players[$playerId]);
        
        // Xóa room nếu trống
        if (empty($this->rooms[$roomCode]['players'])) {
            unset($this->rooms[$roomCode]);
            echo "🗑️ Room {$roomCode} deleted\n";
        }
        
        return $player;
    }

    public function getRoomPlayers($roomCode) {
        return $this->rooms[$roomCode]['players'] ?? [];
    }

    public function getPlayerRoom(ConnectionInterface $conn) {
        return $this->players[$conn->resourceId]['room_code'] ?? null;
    }

    private function generateRoomCode() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $code;
    }

    private function startGame($roomCode) {
        echo "🎮 Starting game in {$roomCode}\n";
        // Logic start game sẽ thêm sau
    }
}
?>