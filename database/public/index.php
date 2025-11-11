<?php
// index.php – Giao diện game Quiz Nhanh Tay
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Game Nhanh Tay Trả Lời</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f4f7fc;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
}
#game {
    background: white;
    padding: 25px;
    border-radius: 15px;
    width: 400px;
    box-shadow: 0 0 15px rgba(0,0,0,0.1);
    text-align: center;
}
button {
    background: #007bff;
    color: white;
    border: none;
    padding: 10px 15px;
    margin-top: 10px;
    border-radius: 8px;
    cursor: pointer;
}
button:hover { background: #0056b3; }
.option {
    display: block;
    background: #eee;
    padding: 10px;
    margin: 6px 0;
    border-radius: 8px;
    cursor: pointer;
}
.option:hover { background: #ddd; }
.hidden { display: none; }
#leaderboard { text-align: left; }
</style>
</head>
<body>

<div id="game">
    <h2>🎮 Quiz Nhanh Tay</h2>

    <!-- Màn hình vào chơi -->
    <div id="join">
        <input type="text" id="name" placeholder="Nhập tên của bạn" />
        <button id="joinBtn">Vào chơi</button>
    </div>

    <!-- Màn hình chờ -->
    <div id="waiting" class="hidden">
        <p>⏳ Đang chờ đủ người chơi...</p>
        <p id="players"></p>
    </div>

    <!-- Màn hình câu hỏi -->
    <div id="questionBox" class="hidden">
        <h3 id="question"></h3>
        <div id="options"></div>
        <p id="timer"></p>
    </div>

    <!-- Màn hình kết quả từng câu -->
    <div id="resultBox" class="hidden">
        <h3>Kết quả</h3>
        <div id="results"></div>
    </div>

    <!-- Màn hình kết thúc game -->
    <div id="endBox" class="hidden">
        <h3>🎉 Trò chơi kết thúc!</h3>
        <div id="leaderboard"></div>
        <button id="replayBtn">🔁 Chơi lại</button>
    </div>
</div>

<script>
let ws;
let playerName = "";
let timerInterval;
let timeLeft = 0;

function show(id) {
    document.querySelectorAll('#game > div').forEach(div => div.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
}

// ✅ Cho phép nhấn Enter để vào chơi
document.getElementById('name').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('joinBtn').click();
});

document.getElementById('joinBtn').onclick = () => {
    playerName = document.getElementById('name').value.trim();
    if (!playerName) return alert("Hãy nhập tên!");
    connectWS();
};

function connectWS() {
    // ⚠️ Nếu Fen chạy server ở máy khác, sửa IP ở đây
    ws = new WebSocket("ws://127.0.0.1:8080");

    ws.onopen = () => {
        console.log("✅ Đã kết nối server.");
        ws.send(JSON.stringify({ action: "join", name: playerName }));
    };

    ws.onmessage = (event) => {
        let msg = JSON.parse(event.data);
        console.log("📩 Nhận:", msg);

        if (msg.type === "connected") {
            console.log("Server:", msg.message);
        }
        else if (msg.type === "player_joined") {
            show("waiting");
            document.getElementById("players").innerHTML =
                "Người chơi: " + msg.players.join(", ");
        }
        else if (msg.type === "start_game") {
            show("questionBox");
        }
        else if (msg.type === "question") {
            show("questionBox");
            showQuestion(msg);
        }
        else if (msg.type === "question_result") {
            show("resultBox");
            showResults(msg);
        }
        else if (msg.type === "game_ended") {
            show("endBox");
            showLeaderboard(msg.leaderboard);
        }
    };

    ws.onclose = () => alert("❌ Mất kết nối với server!");
}

function showQuestion(q) {
    document.getElementById("question").innerText = q.question;
    const optionsDiv = document.getElementById("options");
    optionsDiv.innerHTML = "";
    q.options.forEach((opt, idx) => {
        const div = document.createElement("div");
        div.className = "option";
        div.innerText = opt;
        div.onclick = () => {
            ws.send(JSON.stringify({ action: "answer", choice: idx }));
            document.querySelectorAll(".option").forEach(o => o.style.pointerEvents = "none");
            div.style.background = "#d1e7dd";
        };
        optionsDiv.appendChild(div);
    });

    clearInterval(timerInterval);
    timeLeft = q.time;
    document.getElementById("timer").innerText = `⏰ Thời gian: ${timeLeft}s`;
    timerInterval = setInterval(() => {
        timeLeft--;
        document.getElementById("timer").innerText = `⏰ Thời gian: ${timeLeft}s`;
        if (timeLeft <= 0) clearInterval(timerInterval);
    }, 1000);
}

function showResults(msg) {
    const resDiv = document.getElementById("results");
    resDiv.innerHTML = `<p>Đáp án đúng: <b>${msg.correct}</b></p>`;
    msg.results.forEach(r => {
        resDiv.innerHTML += `<p>${r.name} — ${r.correct ? "✅" : "❌"} (${r.score} điểm)</p>`;
    });
}

function showLeaderboard(lb) {
    const div = document.getElementById("leaderboard");
    div.innerHTML = "<ol>" + lb.map(p => `<li>${p.name}: ${p.score} điểm</li>`).join("") + "</ol>";
}

// ✅ Nút chơi lại
document.getElementById('replayBtn').onclick = () => {
    location.reload();
};
</script>

</body>
</html>
