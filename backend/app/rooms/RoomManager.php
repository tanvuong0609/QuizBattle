<?php
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
    private $nextRoomId;
    private $nextPlayerId;
    private $dataFile = 'game_data.json';
    
    public function __construct() {
        $this->rooms = [];
        $this->playerRoomMap = [];
        $this->nextRoomId = 1;
        $this->nextPlayerId = 1;
        $this->loadFromFile();
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
    
    public function addPlayer($playerName) {
        if (empty(trim($playerName))) {
            return ['success' => false, 'message' => 'Tên người chơi không được rỗng'];
        }
        
        $playerId = 'player_' . $this->nextPlayerId++;
        
        $room = $this->autoCreateRoomIfNeeded();
        
        $player = [
            'id' => $playerId,
            'name' => trim($playerName),
            'joinedAt' => date('Y-m-d H:i:s'),
            'connected' => true
        ];
        
        $room->players[] = $player;
        $this->playerRoomMap[$playerId] = $room->id;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã thêm ' . $player['name'] . ' vào ' . $room->id,
            'room' => $room->toArray(),
            'player' => $player
        ];
    }
    
    public function assignPlayer($playerId, $playerName, $roomId = null) {
        if (isset($this->playerRoomMap[$playerId])) {
            $currentRoomId = $this->playerRoomMap[$playerId];
            return [
                'success' => false, 
                'message' => 'Người chơi đã ở trong phòng ' . $currentRoomId
            ];
        }
        
        if ($roomId === null) {
            $room = $this->autoCreateRoomIfNeeded();
        } else {
            if (!isset($this->rooms[$roomId])) {
                return ['success' => false, 'message' => 'Phòng không tồn tại'];
            }
            $room = $this->rooms[$roomId];
        }
        
        if ($room->isFull()) {
            return ['success' => false, 'message' => 'Phòng đã đầy'];
        }
        
        if ($room->status !== Room::STATUS_WAITING) {
            return ['success' => false, 'message' => 'Phòng đang không nhận người chơi mới'];
        }
        
        $player = [
            'id' => $playerId,
            'name' => $playerName,
            'joinedAt' => date('Y-m-d H:i:s'),
            'connected' => true
        ];
        
        $room->players[] = $player;
        $this->playerRoomMap[$playerId] = $room->id;
        $this->saveToFile();
        
        return [
            'success' => true,
            'message' => 'Đã thêm người chơi vào phòng thành công',
            'room' => $room->toArray(),
            'player' => $player
        ];
    }
    
    public function removePlayer($playerId) {
        if (!isset($this->playerRoomMap[$playerId])) {
            return ['success' => false, 'message' => 'Người chơi không ở trong phòng nào'];
        }
        
        $roomId = $this->playerRoomMap[$playerId];
        $room = $this->rooms[$roomId];
        
        $room->players = array_values(array_filter($room->players, function($player) use ($playerId) {
            return $player['id'] !== $playerId;
        }));
        
        unset($this->playerRoomMap[$playerId]);
        
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
                $this->nextRoomId = $data['nextRoomId'] ?? 1;
                $this->nextPlayerId = $data['nextPlayerId'] ?? 1;
            }
        }
    }
}

// ============================================
// API ENDPOINTS
// ============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$roomManager = new RoomManager();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'addPlayer':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $roomManager->addPlayer($data['name'] ?? '');
                echo json_encode($result);
            }
            break;
            
        case 'removePlayer':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $roomManager->removePlayer($data['playerId'] ?? '');
                echo json_encode($result);
            }
            break;
            
        case 'createRoom':
            $result = $roomManager->createRoom();
            echo json_encode($result);
            break;
            
        case 'deleteRoom':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $roomManager->deleteRoom($data['roomId'] ?? '');
                echo json_encode($result);
            }
            break;
            
        case 'startGame':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $result = $roomManager->startGame($data['roomId'] ?? '');
                echo json_encode($result);
            }
            break;
            
        case 'getRooms':
            $rooms = $roomManager->getAllRooms();
            echo json_encode(['success' => true, 'rooms' => $rooms]);
            break;
            
        case 'getRoom':
            $roomId = $_GET['roomId'] ?? '';
            $room = $roomManager->getRoom($roomId);
            echo json_encode(['success' => true, 'room' => $room]);
            break;
            
        case 'getPlayers':
            $players = $roomManager->getConnectedPlayers();
            echo json_encode(['success' => true, 'players' => $players]);
            break;
            
        case 'getStats':
            $stats = $roomManager->getStatistics();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        case 'reset':
            $result = $roomManager->reset();
            echo json_encode($result);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Action không hợp lệ',
                'availableActions' => [
                    'addPlayer', 'removePlayer', 'createRoom', 'deleteRoom',
                    'startGame', 'getRooms', 'getRoom', 'getPlayers', 
                    'getStats', 'reset'
                ]
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Lỗi server: ' . $e->getMessage()
    ]);
}
?>