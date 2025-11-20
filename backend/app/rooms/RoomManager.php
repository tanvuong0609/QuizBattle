<?php
namespace App\Rooms;

error_reporting(0);
ini_set('display_errors', 0);

/**
 * Room.php - Class quản lý phòng chơi
 */
class Room {
    const STATUS_WAITING = 'waiting';
    const STATUS_PLAYING = 'playing';
    const STATUS_FINISHED = 'finished';
    
    public $id;
    public $players;
    public $status;
    public $maxPlayers;
    public $createdAt;
    
    public function __construct($id, $maxPlayers = 4) {
        $this->id = $id;
        $this->players = [];
        $this->status = self::STATUS_WAITING;
        $this->maxPlayers = $maxPlayers;
        $this->createdAt = date('Y-m-d H:i:s');
    }
    
    public function isFull() {
        return count($this->players) >= $this->maxPlayers;
    }
    
    public function isEmpty() {
        return count($this->players) === 0;
    }
    
    public function getPlayerCount() {
        return count($this->players);
    }
    
    public function toArray() {
        return [
            'id' => $this->id,
            'players' => array_column($this->players, 'id'),
            'playerDetails' => $this->players,
            'status' => $this->status,
            'maxPlayers' => $this->maxPlayers,
            'createdAt' => $this->createdAt
        ];
    }
}

/**
 * RoomManager.php - Class quản lý tất cả phòng chơi
 */
class RoomManager {
    private $rooms;
    private $playerRoomMap;
    private $playerIdMap; // Map resourceId -> playerId
    private $nextRoomId;
    private $nextPlayerId;
    private $dataFile;
    
    public function __construct() {
        $this->dataFile = __DIR__ . '/../../game_data.json';
        $this->rooms = [];
        $this->playerRoomMap = [];
        $this->playerIdMap = [];
        $this->nextRoomId = 1;
        $this->nextPlayerId = 1;
        $this->loadFromFile();

        echo "🔍 DEBUG RoomManager:\n";
        echo "  __DIR__ = " . __DIR__ . "\n";
        echo "  dataFile = " . $this->dataFile . "\n";
        echo "  realpath = " . realpath($this->dataFile) . "\n";
    }
    
    public function createRoom() {
        $roomId = 'room_' . $this->nextRoomId++;
        $room = new Room($roomId);
        $this->rooms[$roomId] = $room;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã tạo phòng ' . $roomId,
            'room' => $room->toArray()
        ];
    }
    
    public function deleteRoom($roomId) {
        if (!isset($this->rooms[$roomId])) {
            return ['success' => false, 'message' => 'Phòng không tồn tại'];
        }
        
        $room = $this->rooms[$roomId];
        
        if (!$room->isEmpty()) {
            return ['success' => false, 'message' => 'Không thể xóa phòng có người chơi'];
        }
        
        foreach ($room->players as $player) {
            unset($this->playerRoomMap[$player['id']]);
            unset($this->playerIdMap[$player['resourceId']]);
        }
        
        unset($this->rooms[$roomId]);
        $this->saveToFile();
        
        return ['success' => true, 'message' => 'Đã xóa phòng thành công'];
    }
    
    private function autoCreateRoomIfNeeded() {
        foreach ($this->rooms as $room) {
            if ($room->status === Room::STATUS_WAITING && !$room->isFull()) {
                return $room;
            }
        }
        return $this->createRoomInternal();
    }
    
    private function createRoomInternal() {
        $roomId = 'room_' . $this->nextRoomId++;
        $room = new Room($roomId);
        $this->rooms[$roomId] = $room;
        return $room;
    }
    
    public function markPlayerDisconnected($resourceId) {
        // Tìm playerId từ resourceId
        $playerId = $this->getPlayerIdFromResource($resourceId);
        
        if (!$playerId || !isset($this->playerRoomMap[$playerId])) {
            return ['success' => false, 'message' => 'Player not in any room'];
        }
        
        $roomId = $this->playerRoomMap[$playerId];
        
        if (!isset($this->rooms[$roomId])) {
            unset($this->playerRoomMap[$playerId]);
            unset($this->playerIdMap[$resourceId]);
            return ['success' => false, 'message' => 'Room not found'];
        }
        
        $room = $this->rooms[$roomId];
        
        // Đánh dấu player là disconnected
        foreach ($room->players as &$player) {
            if ($player['id'] === $playerId) {
                $player['connected'] = false;
                $player['lastSeen'] = date('Y-m-d H:i:s');
                break;
            }
        }
        
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Player marked as disconnected',
            'room' => $room->toArray()
        ];
    }

    public function updatePlayerConnection($playerId, $newResourceId) {
        echo "🔄 Updating connection for player {$playerId} to resource {$newResourceId}\n";
        
        // Tìm player trong tất cả các rooms
        foreach ($this->rooms as $room) {
            foreach ($room->players as &$player) {
                if ($player['id'] === $playerId) {
                    // Cập nhật resourceId và đánh dấu connected
                    $oldResourceId = $player['resourceId'];
                    $player['resourceId'] = $newResourceId;
                    $player['connected'] = true;
                    $player['lastReconnect'] = date('Y-m-d H:i:s');
                    
                    // Cập nhật maps
                    unset($this->playerIdMap[$oldResourceId]);
                    $this->playerIdMap[$newResourceId] = $playerId;
                    $this->playerRoomMap[$playerId] = $room->id;
                    
                    $this->saveToFile();
                    
                    echo "✅ Updated player connection: {$playerId} from {$oldResourceId} to {$newResourceId}\n";
                    
                    return [
                        'success' => true,
                        'message' => 'Player connection updated',
                        'room' => $room->toArray()
                    ];
                }
            }
        }
        
        echo "❌ Player not found: {$playerId}\n";
        return ['success' => false, 'message' => 'Player not found in any room'];
    }

    public function getRoomByPlayerId($playerId) {
        if (!isset($this->playerRoomMap[$playerId])) {
            return null;
        }
        
        $roomId = $this->playerRoomMap[$playerId];
        return $this->getRoom($roomId);
    }

    public function addPlayer($resourceId, $playerName) {
        if (empty(trim($playerName))) {
            return ['success' => false, 'message' => 'Tên người chơi không được rỗng'];
        }
        
        $playerId = 'player_' . $resourceId;
        
        // Kiểm tra nếu player đã có trong một room
        if (isset($this->playerRoomMap[$playerId])) {
            $currentRoomId = $this->playerRoomMap[$playerId];
            $currentRoom = $this->getRoom($currentRoomId);
            
            if ($currentRoom && $currentRoom['status'] === 'waiting') {
                // Player đã có trong room waiting, cập nhật connection
                return $this->updatePlayerConnection($playerId, $resourceId);
            }
        }
    
        $room = $this->autoCreateRoomIfNeeded();
        
        $player = [
            'id' => $playerId,
            'resourceId' => $resourceId,
            'name' => trim($playerName),
            'joinedAt' => date('Y-m-d H:i:s'),
            'connected' => true,
            'ready' => false  
        ];
        
        $room->players[] = $player;
        $this->playerRoomMap[$playerId] = $room->id;
        $this->playerIdMap[$resourceId] = $playerId;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã thêm ' . $player['name'] . ' vào ' . $room->id,
            'room' => $room->toArray(),
            'player' => $player
        ];
    }
    
    public function removePlayer($resourceId) {
        $playerId = $this->getPlayerIdFromResource($resourceId);

        if (!$player['name'] || !isset($this->playerRoomMap[$player['name']])) {
            return ['success' => false, 'message' => 'Người chơi không ở trong phòng nào'];
        }
        
        $roomId = $this->playerRoomMap[$playerId];
        $room = $this->rooms[$roomId];
        
        $room->players = array_values(array_filter($room->players, function($player) use ($playerId) {
            return $player['id'] !== $playerId;
        }));
        
        unset($this->playerRoomMap[$playerId]);
        unset($this->playerIdMap[$resourceId]);
        
        if ($room->isEmpty() && $room->status !== Room::STATUS_PLAYING) {
            $this->deleteRoom($roomId);
            return [
                'success' => true,
                'message' => 'Đã xóa người chơi và xóa phòng trống',
                'roomDeleted' => true
            ];
        }
        
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã xóa người chơi khỏi phòng',
            'room' => $room->toArray()
        ];
    }

    public function getRoomByResourceId($resourceId) {
        $playerId = $this->getPlayerIdFromResource($resourceId);

        if (!$playerId || !isset($this->playerRoomMap[$playerId])) {
            return null;
        }
        
        $roomId = $this->playerRoomMap[$playerId];
        return $this->getRoom($roomId);
    }
    
    private function getPlayerIdFromResource($resourceId) {
        if (isset($this->playerIdMap[$resourceId])) {
            return $this->playerIdMap[$resourceId];
        }
        
        // Fallback: tìm trong rooms
        foreach ($this->rooms as $room) {
            foreach ($room->players as $player) {
                if ($player['resourceId'] == $resourceId) {
                    $this->playerIdMap[$resourceId] = $player['id'];
                    return $player['id'];
                }
            }
        }
        
        return null;
    }
    
    public function getRoom($roomId) {
        if (!isset($this->rooms[$roomId])) {
            return null;
        }
        return $this->rooms[$roomId]->toArray();
    }
    
    public function getAllRooms() {
        $roomsArray = [];
        foreach ($this->rooms as $room) {
            $roomsArray[] = $room->toArray();
        }
        return $roomsArray;
    }
    
    public function getPlayerRoom($playerId) {
        if (!isset($this->playerRoomMap[$playerId])) {
            return null;
        }
        $roomId = $this->playerRoomMap[$playerId];
        return $this->getRoom($roomId);
    }
    
    public function updateRoomStatus($roomId, $status) {
        if (!isset($this->rooms[$roomId])) {
            return ['success' => false, 'message' => 'Phòng không tồn tại'];
        }
        
        $room = $this->rooms[$roomId];
        $room->status = $status;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã cập nhật trạng thái phòng',
            'room' => $room->toArray()
        ];
    }
    
    public function startGame($roomId) {
        if (!isset($this->rooms[$roomId])) {
            return ['success' => false, 'message' => 'Phòng không tồn tại'];
        }
        
        $room = $this->rooms[$roomId];
        
        if ($room->getPlayerCount() < 2) {
            return ['success' => false, 'message' => 'Cần ít nhất 2 người chơi'];
        }
        
        $room->status = Room::STATUS_PLAYING;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã bắt đầu game',
            'room' => $room->toArray()
        ];
    }
    
    public function setPlayerReady($playerId, $ready) {
        try {
            echo "🔍 Setting ready for player: {$playerId}, ready: " . ($ready ? 'true' : 'false') . "\n";
            
            // ✅ FIX: Iterate qua Room objects đúng cách
            foreach ($this->rooms as $roomId => $room) {
                // Kiểm tra playerId có trong room không
                $playerExists = false;
                foreach ($room->players as $player) {
                    if ($player['id'] === $playerId) {
                        $playerExists = true;
                        break;
                    }
                }
                
                if (!$playerExists) {
                    continue; // Skip room này
                }
                
                echo "📍 Found player in room: {$roomId}\n";
                
                // Update player ready status
                $playerFound = false;
                foreach ($room->players as $index => &$player) {
                    if ($player['id'] === $playerId) {
                        echo "✏️ Updating player {$playerId} ready status from " . 
                            ($player['ready'] ?? 'null') . " to " . ($ready ? 'true' : 'false') . "\n";
                        
                        $player['ready'] = $ready;
                        $playerFound = true;
                        
                        // Save to file
                        $this->saveToFile();
                        
                        echo "💾 Room data saved. Current players ready status:\n";
                        foreach ($room->players as $p) {
                            echo "  - {$p['name']} ({$p['id']}): ready=" . ($p['ready'] ?? false ? 'YES' : 'NO') . "\n";
                        }
                        
                        return [
                            'success' => true,
                            'room' => $room->toArray(), // ✅ Convert to array
                            'player' => $player,
                            'message' => 'Player ready status updated'
                        ];
                    }
                }
                
                if (!$playerFound) {
                    echo "❌ Player {$playerId} found in room but NOT in players array!\n";
                    return [
                        'success' => false,
                        'message' => 'Player not found in room players'
                    ];
                }
            }
            
            echo "❌ Player {$playerId} not found in any room\n";
            return [
                'success' => false,
                'message' => 'Player not found in any room'
            ];
            
        } catch (\Exception $e) {
            echo "❌ Error in setPlayerReady: " . $e->getMessage() . "\n";
            echo "Stack trace: " . $e->getTraceAsString() . "\n";
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get ready count for a room
     */
    public function getReadyCount($roomId) {
        if (!isset($this->rooms[$roomId])) {
            return ['ready' => 0, 'total' => 0];
        }
        
        $room = $this->rooms[$roomId]; // ✅ Room object
        
        $readyCount = 0;
        $totalPlayers = count($room->players);
        
        foreach ($room->players as $player) {
            if (isset($player['ready']) && $player['ready'] === true) {
                $readyCount++;
            }
        }
        
        return [
            'ready' => $readyCount,
            'total' => $totalPlayers
        ];
    }
    
    public function getStatistics() {
        $stats = [
            'totalRooms' => count($this->rooms),
            'waitingRooms' => 0,
            'playingRooms' => 0,
            'connectedPlayers' => count($this->playerRoomMap),
            'totalPlayers' => count($this->playerRoomMap),
            'availableSlots' => 0
        ];
        
        foreach ($this->rooms as $room) {
            if ($room->status === Room::STATUS_WAITING) {
                $stats['waitingRooms']++;
                $stats['availableSlots'] += ($room->maxPlayers - $room->getPlayerCount());
            } elseif ($room->status === Room::STATUS_PLAYING) {
                $stats['playingRooms']++;
            }
        }
        
        return $stats;
    }
    
    public function getConnectedPlayers() {
        $players = [];
        foreach ($this->rooms as $room) {
            foreach ($room->players as $player) {
                if ($player['connected']) {
                    $players[] = $player;
                }
            }
        }
        return $players;
    }
    
    public function reset() {
        $this->rooms = [];
        $this->playerRoomMap = [];
        $this->playerIdMap = [];
        $this->nextRoomId = 1;
        $this->nextPlayerId = 1;
        $this->saveToFile();
        
        return ['success' => true, 'message' => 'Đã reset hệ thống'];
    }
    
    private function saveToFile() {
        $roomsData = [];
        foreach ($this->rooms as $roomId => $room) {
            $roomsData[$roomId] = [
                'id' => $room->id,
                'players' => $room->players,
                'status' => $room->status,
                'maxPlayers' => $room->maxPlayers,
                'createdAt' => $room->createdAt
            ];
        }
        
        $data = [
            'rooms' => $roomsData,
            'playerRoomMap' => $this->playerRoomMap,
            'playerIdMap' => $this->playerIdMap,
            'nextRoomId' => $this->nextRoomId,
            'nextPlayerId' => $this->nextPlayerId
        ];
        
        file_put_contents($this->dataFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    private function loadFromFile() {
        if (file_exists($this->dataFile)) {
            $json = file_get_contents($this->dataFile);
            $data = json_decode($json, true);
            
            if ($data) {
                foreach ($data['rooms'] ?? [] as $roomId => $roomData) {
                    $room = new Room($roomData['id'], $roomData['maxPlayers']);
                    $room->players = $roomData['players'];
                    $room->status = $roomData['status'];
                    $room->createdAt = $roomData['createdAt'];
                    $this->rooms[$roomId] = $room;
                }
                
                $this->playerRoomMap = $data['playerRoomMap'] ?? [];
                $this->playerIdMap = $data['playerIdMap'] ?? [];
                $this->nextRoomId = $data['nextRoomId'] ?? 1;
                $this->nextPlayerId = $data['nextPlayerId'] ?? 1;
            }
        }
    }
}

?>