<?php
require_once __DIR__ . '/db.php';

set_time_limit(0);
error_reporting(E_ALL);
ob_implicit_flush();

$address = '127.0.0.1';
$port = 8080;

$clients = [];
$rooms = [];

$socket = stream_socket_server("tcp://$address:$port", $errno, $errstr);
if (!$socket) {
    die("Error: $errstr ($errno)\n");
}
echo "Server running on ws://$address:$port\n";

function handshake($client, $headers) {
    if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $headers, $match)) {
        $key = trim($match[1]);
        $accept = base64_encode(pack('H*', sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n".
                   "Upgrade: websocket\r\n".
                   "Connection: Upgrade\r\n".
                   "Sec-WebSocket-Accept: $accept\r\n\r\n";
        fwrite($client, $upgrade);
        return true;
    }
    return false;
}

function encode($message) {
    $len = strlen($message);
    if ($len <= 125) return chr(129).chr($len).$message;
    elseif ($len <= 65535) return chr(129).chr(126).pack("n", $len).$message;
    else return chr(129).chr(127).pack("xxxxN", $len).$message;
}

function decode($buffer) {
    $len = ord($buffer[1]) & 127;
    if ($len === 126) {
        $masks = substr($buffer, 4, 4);
        $data = substr($buffer, 8);
    } elseif ($len === 127) {
        $masks = substr($buffer, 10, 4);
        $data = substr($buffer, 14);
    } else {
        $masks = substr($buffer, 2, 4);
        $data = substr($buffer, 6);
    }
    $decoded = '';
    for ($i = 0; $i < strlen($data); ++$i) {
        $decoded .= $data[$i] ^ $masks[$i % 4];
    }
    return $decoded;
}

function broadcastRoom($room, $msg) {
    global $clients;
    $data = encode(json_encode($msg));
    foreach ($room['players'] as $p) {
        fwrite($clients[$p['id']], $data);
    }
}

function findOrCreateRoom(&$rooms, &$clients, $client, $name, $pdo) {
    foreach ($rooms as &$room) {
        if (count($room['players']) < 4) {
            $id = (int)$client;
            $room['players'][] = ['id' => $id, 'name' => $name];

            $stmt = $pdo->prepare("INSERT INTO players (name, room_code) VALUES (?, ?)");
            $stmt->execute([$name, $room['id']]);

            return $room;
        }
    }

    $roomId = "room_" . uniqid();
    $rooms[$roomId] = [
        'id' => $roomId,
        'players' => [
            ['id' => (int)$client, 'name' => $name]
        ]
    ];

    $pdo->prepare("INSERT INTO rooms (room_code) VALUES (?)")->execute([$roomId]);
    $pdo->prepare("INSERT INTO players (name, room_code) VALUES (?, ?)")->execute([$name, $roomId]);

    return $rooms[$roomId];
}

while (true) {
    $read = $clients;
    $read[] = $socket;
    $write = $except = null;
    stream_select($read, $write, $except, null);

    if (in_array($socket, $read)) {
        $client = stream_socket_accept($socket);
        $clients[(int)$client] = $client;
        $headers = fread($client, 1500);
        handshake($client, $headers);
        fwrite($client, encode(json_encode(["type" => "connected", "message" => "Welcome!"])));
        unset($read[array_search($socket, $read)]);
    }

    foreach ($read as $client) {
        $data = @fread($client, 1500);
        if (!$data) {
            fclose($client);
            unset($clients[(int)$client]);
            continue;
        }

        $decoded = json_decode(decode($data), true);
        if (!$decoded) continue;

        if ($decoded['action'] === 'join') {
            $name = trim($decoded['name']);
            if ($name === '') continue;

            $room = findOrCreateRoom($rooms, $clients, $client, $name, $pdo);

            broadcastRoom($room, [
                "type" => "player_joined",
                "room" => $room['id'],
                "players" => array_map(fn($p) => $p['name'], $room['players']),
                "count" => count($room['players'])
            ]);

            if (count($room['players']) >= 4) {
                $pdo->prepare("UPDATE rooms SET status='playing' WHERE room_code=?")->execute([$room['id']]);
                broadcastRoom($room, [
                    "type" => "start_game",
                    "message" => "Game starting!"
                ]);
            }
        }
    }
}
