<?php
// serve.php - WebSocket game server (Quick Play)
// Replace your existing serve.php with this file.

require_once __DIR__ . '/db.php'; // $pdo PDO connection

set_time_limit(0);
error_reporting(E_ALL);
ob_implicit_flush();

$address = '127.0.0.1';
$port = 8080;

// runtime structures
$socket = stream_socket_server("tcp://$address:$port", $errno, $errstr);
if (!$socket) {
    die("Error: $errstr ($errno)\n");
}

echo "Server running on ws://$address:$port\n";

$clients = [];        // [clientId => resource]
$clients_meta = [];   // [clientId => ['name'=>..., 'room'=>...]]
$rooms = [];          // [roomId => roomArray]

/* ---------------- WebSocket helpers ---------------- */

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

function encodeWS($message) {
    $message = (string)$message;
    $len = strlen($message);
    if ($len <= 125) return chr(129).chr($len).$message;
    if ($len <= 65535) return chr(129).chr(126).pack("n", $len).$message;
    return chr(129).chr(127).pack("xxxxN", $len).$message;
}

function decodeWS($buffer) {
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

function sendToClient($clientRes, $msgArr) {
    if (!$clientRes) return;
    $payload = json_encode($msgArr, JSON_UNESCAPED_UNICODE);
    @fwrite($clientRes, encodeWS($payload));
}

function broadcastRoom($room, $msgArr) {
    global $clients;
    $payload = encodeWS(json_encode($msgArr, JSON_UNESCAPED_UNICODE));
    foreach ($room['players'] as $p) {
        $id = $p['id'];
        if (isset($clients[$id])) {
            @fwrite($clients[$id], $payload);
        }
    }
}

/* ---------------- Load all questions into memory (id-indexed) ---------------- */
$global_questions = [];
try {
    $stmt = $pdo->query("SELECT id, question_text, options_json, correct_index, time_limit FROM questions ORDER BY id ASC");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $global_questions[] = [
            'id' => (int)$r['id'],
            'question' => $r['question_text'],
            'options' => json_decode($r['options_json'], true),
            'answer_index' => (int)$r['correct_index'],
            'time_limit' => (int)($r['time_limit'] ?? 10)
        ];
    }
} catch (Exception $e) {
    echo "Warning: cannot load questions from DB. Using fallback.\n";
}

if (count($global_questions) === 0) {
    // fallback sample questions
    $global_questions = [
        ['id'=>1,'question'=>'Thủ đô Việt Nam là?','options'=>['Hà Nội','Huế','Đà Nẵng'],'answer_index'=>0,'time_limit'=>10],
        ['id'=>2,'question'=>'2 + 2 = ?','options'=>['3','4','5'],'answer_index'=>1,'time_limit'=>10],
        ['id'=>3,'question'=>'Bác Hồ sinh năm nào?','options'=>['1890','1900','1911'],'answer_index'=>0,'time_limit'=>10],
        ['id'=>4,'question'=>'Màu cờ Việt Nam là?','options'=>['Đỏ','Xanh','Vàng'],'answer_index'=>0,'time_limit'=>10],
        ['id'=>5,'question'=>'Sông Hồng chảy qua thành phố nào?','options'=>['Hà Nội','Huế','Đà Nẵng'],'answer_index'=>0,'time_limit'=>10],
    ];
    echo "Using fallback questions.\n";
}

$total_questions = count($global_questions);

/* ---------------- Room & Game helpers ---------------- */

function createRoomDB($pdo, $roomId) {
    try {
        $pdo->prepare("INSERT INTO rooms (room_code, status, current_question) VALUES (?, 'waiting', 0)")
            ->execute([$roomId]);
    } catch (Exception $e) { /* ignore */ }
}

function addPlayerDB($pdo, $name, $roomId) {
    try {
        $stmt = $pdo->prepare("INSERT INTO players (name, room_code, score) VALUES (?, ?, 0)");
        $stmt->execute([$name, $roomId]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

function updatePlayerScoreDB($pdo, $playerName, $score) {
    try {
        $pdo->prepare("UPDATE players SET score = ? WHERE name = ?")
            ->execute([$score, $playerName]);
    } catch (Exception $e) {}
}

function findOrCreateRoom(&$rooms, &$clients, $clientRes, $name, $pdo) {
    // ensure unique name in room (prevent duplicates across rooms? we check per chosen target room)
    // Try to find first room that is waiting and has <4 players and name not duplicated
    foreach ($rooms as $roomId => &$room) {
        if ($room['state'] === 'waiting' && count($room['players']) < 4) {
            // check duplicate name inside this room
            $exists = false;
            foreach ($room['players'] as $p) if ($p['name'] === $name) { $exists = true; break; }
            if ($exists) continue;
            $id = (int)$clientRes;
            $room['players'][] = ['id'=>$id, 'name'=>$name];
            $room['scores'][$id] = 0;
            // add to DB players table
            addPlayerDB($pdo, $name, $room['id']);
            return $room;
        }
    }

    // create new room
    $roomId = "room_" . uniqid();
    $id = (int)$clientRes;
    $rooms[$roomId] = [
        'id' => $roomId,
        'state' => 'waiting', // waiting|playing|ended
        'players' => [
            ['id'=>$id, 'name'=>$name]
        ],
        'scores' => [$id => 0], // clientId => score
        'current_q' => 0, // index in global_questions (0-based)
        'total_q' => $GLOBALS['total_questions'],
        'questions' => $GLOBALS['global_questions'],
        'answers' => [], // answers[qIndex][clientId] = chosenIndex
        'question_started_at' => null,
        'time_per_question' => 10,
    ];
    createRoomDB($pdo, $roomId);
    addPlayerDB($pdo, $name, $roomId);
    return $rooms[$roomId];
}

function startGame(&$room, $pdo) {
    $room['state'] = 'playing';
    $room['current_q'] = 0;
    $room['question_started_at'] = time();
    // set time_per_question from first question if exists
    if (isset($room['questions'][0]['time_limit'])) $room['time_per_question'] = $room['questions'][0]['time_limit'];

    try {
        $pdo->prepare("UPDATE rooms SET status='playing', current_question=0 WHERE room_code=?")
            ->execute([$room['id']]);
    } catch (Exception $e) {}

    broadcastRoom($room, [
        "type"=>"start_game",
        "message"=>"Game starting!",
        "total_questions"=>$room['total_q']
    ]);

    // send first question
    sendQuestion($room);
}

function sendQuestion(&$room) {
    if ($room['current_q'] >= $room['total_q']) {
        // no more questions -> end
        endGame($room);
        return;
    }
    $qIndex = $room['current_q'];
    $q = $room['questions'][$qIndex];
    $room['question_started_at'] = time();
    $room['time_per_question'] = $q['time_limit'] ?? $room['time_per_question'];

    broadcastRoom($room, [
        "type"=>"question",
        "index"=>$qIndex,
        "question"=>$q['question'],
        "options"=>$q['options'],
        "time"=>$room['time_per_question']
    ]);
}

function evaluateQuestion(&$room, $reason = 'timeout') {
    global $clients_meta, $clients;
    $qIndex = $room['current_q'];
    if (!isset($room['questions'][$qIndex])) return;
    $correctIndex = $room['questions'][$qIndex]['answer_index'];

    $results = [];
    foreach ($room['players'] as $p) {
        $pid = $p['id'];
        $chosen = $room['answers'][$qIndex][$pid] ?? null;
        $isCorrect = ($chosen !== null && $chosen === $correctIndex);
        if ($isCorrect) {
            if (!isset($room['scores'][$pid])) $room['scores'][$pid] = 0;
            $room['scores'][$pid] += 1;
        }
        // prepare readable name and score
        $name = $p['name'];
        $score = $room['scores'][$pid] ?? 0;
        $results[] = [
            "player_id"=>$pid,
            "name"=>$name,
            "choice"=>$chosen,
            "correct"=>$isCorrect,
            "score"=>$score
        ];
        // update DB players.score (optionally)
        try {
            $stmt = $GLOBALS['pdo']->prepare("UPDATE players SET score = ? WHERE name = ? AND room_code = ?");
            $stmt->execute([$score, $name, $room['id']]);
        } catch (Exception $e) {}
    }

    broadcastRoom($room, [
        "type"=>"question_result",
        "index"=>$qIndex,
        "correct"=>$correctIndex,
        "results"=>$results,
        "reason"=>$reason
    ]);

    // advance to next question
    $room['current_q']++;
    // clear answers for next q handled naturally when adding answers indexed by qIndex
}

function endGame(&$room) {
    $room['state'] = 'ended';
    // compile leaderboard
    $lb = [];
    foreach ($room['players'] as $p) {
        $pid = $p['id'];
        $lb[] = ['player_id'=>$pid, 'name'=>$p['name'], 'score'=>$room['scores'][$pid] ?? 0];
    }
    usort($lb, function($a,$b){ return $b['score'] <=> $a['score']; });

    try {
        $GLOBALS['pdo']->prepare("UPDATE rooms SET status='ended' WHERE room_code=?")->execute([$room['id']]);
    } catch (Exception $e) {}

    broadcastRoom($room, [
        "type"=>"game_ended",
        "message"=>"Game ended!",
        "leaderboard"=>$lb
    ]);

    // Optionally, persist final scores into players table (already updated after each question)
}

/* ---------------- MAIN LOOP ---------------- */

while (true) {
    $read = $clients;
    $read[] = $socket;
    $write = $except = null;

    // stream_select with small timeout so we can run timers frequently
    $tv_sec = 0;
    $tv_usec = 200000; // 200ms
    stream_select($read, $write, $except, $tv_sec, $tv_usec);

    // new connection
    if (in_array($socket, $read)) {
        $client = stream_socket_accept($socket);
        $clientId = (int)$client;
        $clients[$clientId] = $client;

        $headers = fread($client, 1500);
        handshake($client, $headers);
        // send welcome
        sendToClient($client, ["type"=>"connected", "message"=>"Welcome!"]);
        // remove server socket from read list
        unset($read[array_search($socket, $read)]);
    }

    // read incoming messages
    foreach ($read as $client) {
        $clientId = (int)$client;
        $data = @fread($client, 1500);
        if (!$data) {
            // client disconnected
            if (isset($clients[$clientId])) {
                fclose($clients[$clientId]);
                unset($clients[$clientId]);
            }
            // remove from any room
            foreach ($rooms as &$room) {
                foreach ($room['players'] as $idx => $p) {
                    if ($p['id'] === $clientId) {
                        // remove player
                        array_splice($room['players'], $idx, 1);
                        unset($room['scores'][$clientId]);
                        broadcastRoom($room, ["type"=>"player_left", "player_id"=>$clientId, "player_name"=>$p['name'], "count"=>count($room['players'])]);
                        break;
                    }
                }
            }
            continue;
        }

        $decodedTxt = decodeWS($data);
        $decoded = @json_decode($decodedTxt, true);
        if (!$decoded) continue;

        // handle actions
        $action = $decoded['action'] ?? null;

        if ($action === 'join') {
            $name = trim($decoded['name'] ?? '');
            if ($name === '') {
                sendToClient($client, ["type"=>"error", "message"=>"Empty name"]);
                continue;
            }
            // assign to room
            $room = &findOrCreateRoom($rooms, $clients, $client, $name, $pdo);
            // store client meta
            $clients_meta[$clientId] = ['name'=>$name, 'room'=>$room['id']];
            // notify room
            broadcastRoom($room, [
                "type"=>"player_joined",
                "room"=>$room['id'],
                "players"=>array_map(fn($p)=>$p['name'],$room['players']),
                "count"=>count($room['players'])
            ]);
            // if enough players -> start
            if (count($room['players']) >= 4 && $room['state'] === 'waiting') {
                startGame($room, $pdo);
            }
        }
        elseif ($action === 'answer') {
            // expect: {action:'answer', choice: int}
            $choice = isset($decoded['choice']) ? intval($decoded['choice']) : null;
            if (!isset($clients_meta[$clientId]['room'])) continue;
            $roomId = $clients_meta[$clientId]['room'];
            if (!isset($rooms[$roomId])) continue;
            $room = &$rooms[$roomId];
            if ($room['state'] !== 'playing') continue;
            $qIndex = $room['current_q'];
            if (!isset($room['answers'][$qIndex])) $room['answers'][$qIndex] = [];
            // ignore duplicate answers
            if (isset($room['answers'][$qIndex][$clientId])) {
                sendToClient($client, ["type"=>"answer_ack", "status"=>"already_answered", "choice"=>$choice, "qindex"=>$qIndex]);
                continue;
            }
            $room['answers'][$qIndex][$clientId] = $choice;
            // ack
            sendToClient($client, ["type"=>"answer_ack", "status"=>"received", "choice"=>$choice, "qindex"=>$qIndex]);

            // persist last_answer into DB for this player (optional)
            try {
                $name = $clients_meta[$clientId]['name'];
                $stmt = $pdo->prepare("UPDATE players SET last_answer = ? WHERE name = ? AND room_code = ?");
                $stmt->execute([$choice, $name, $roomId]);
            } catch (Exception $e) {}

            // if all players answered -> evaluate immediately
            $playersCount = count($room['players']);
            $answeredCount = count($room['answers'][$qIndex]);
            if ($answeredCount >= $playersCount) {
                evaluateQuestion($room, 'all_answered');
                // after evaluate, check if more questions
                if ($room['current_q'] >= $room['total_q']) {
                    endGame($room);
                } else {
                    // send next question after short delay (non-blocking handled by timers below)
                    $room['question_started_at'] = time();
                    sendQuestion($room);
                }
            }
        }
        // you may add actions like 'ping', 'ready', etc.
    }

    // ---------------- Timer checks: for each playing room, check question timeout ----------------
    $now = time();
    foreach ($rooms as &$room) {
        if ($room['state'] !== 'playing') continue;
        $qIndex = $room['current_q'];
        if (!isset($room['questions'][$qIndex])) {
            // no more questions -> end
            endGame($room);
            continue;
        }
        $started = $room['question_started_at'];
        if ($started === null) continue;
        $limit = $room['questions'][$qIndex]['time_limit'] ?? $room['time_per_question'];
        if (($now - $started) >= $limit) {
            // evaluate if not already evaluated
            // ensure not double-evaluate: after evaluate, current_q increments
            evaluateQuestion($room, 'timeout');

            // after evaluation, check whether ended
            if ($room['current_q'] >= $room['total_q']) {
                endGame($room);
            } else {
                // send next question
                // set question_started_at before sending to reset timer
                $room['question_started_at'] = time();
                sendQuestion($room);
            }
        }
    }

    // small sleep to reduce CPU
    usleep(10000);
}
