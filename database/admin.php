<?php
// admin.php - Trang quản lý câu hỏi
require_once __DIR__ . '/src/db.php';

// Xử lý các hành động
$action = $_POST['action'] ?? '';
$id = $_POST['id'] ?? 0;

if ($action === 'add' || $action === 'edit') {
    $question_text = trim($_POST['question_text'] ?? '');
    $options_json = json_encode([
        trim($_POST['option_0'] ?? ''),
        trim($_POST['option_1'] ?? ''),
        trim($_POST['option_2'] ?? '')
    ], JSON_UNESCAPED_UNICODE);
    $correct_index = intval($_POST['correct_index'] ?? 0);
    $time_limit = intval($_POST['time_limit'] ?? 10);
    
    if ($question_text && $_POST['option_0'] && $_POST['option_1'] && $_POST['option_2']) {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO questions (question_text, options_json, correct_index, time_limit) VALUES (?, ?, ?, ?)");
            $stmt->execute([$question_text, $options_json, $correct_index, $time_limit]);
            $message = "Thêm câu hỏi thành công!";
        } else {
            $stmt = $pdo->prepare("UPDATE questions SET question_text=?, options_json=?, correct_index=?, time_limit=? WHERE id=?");
            $stmt->execute([$question_text, $options_json, $correct_index, $time_limit, $id]);
            $message = "Cập nhật câu hỏi thành công!";
        }
    } else {
        $error = "Vui lòng điền đầy đủ thông tin!";
    }
} elseif ($action === 'delete') {
    $stmt = $pdo->prepare("DELETE FROM questions WHERE id=?");
    $stmt->execute([$id]);
    $message = "Xóa câu hỏi thành công!";
}

// Lấy danh sách câu hỏi
$questions = $pdo->query("SELECT * FROM questions ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Câu hỏi Quiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .admin-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .admin-header {
            background: linear-gradient(90deg, #4e54c8, #8f94fb);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .question-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        .question-card:hover {
            transform: translateY(-5px);
        }
        .btn-action {
            margin: 0 3px;
        }
        .form-container {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #4e54c8;
        }
        .option-badge {
            display: inline-block;
            padding: 5px 10px;
            margin: 3px;
            border-radius: 20px;
            background: #e9ecef;
        }
        .correct-option {
            background: #d4edda;
            color: #155724;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <div class="admin-header">
                <h1><i class="fas fa-tasks me-2"></i>Quản lý Câu hỏi Quiz</h1>
                <p class="mb-0">Thêm, sửa, xóa câu hỏi cho trò chơi</p>
            </div>

            <div class="container-fluid py-4">
                <!-- Thông báo -->
                <?php if (isset($message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Thống kê -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?= count($questions) ?></div>
                            <div>Tổng câu hỏi</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?= $questions ? max(array_column($questions, 'id')) : 0 ?></div>
                            <div>ID cao nhất</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number">10</div>
                            <div>Thời gian mặc định (s)</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number">3</div>
                            <div>Lựa chọn mỗi câu</div>
                        </div>
                    </div>
                </div>

                <!-- Form thêm/sửa câu hỏi -->
                <div class="form-container">
                    <h4><i class="fas fa-plus-circle me-2"></i><?= isset($_POST['edit_id']) ? 'Sửa Câu hỏi' : 'Thêm Câu hỏi Mới' ?></h4>
                    
                    <?php
                    $editing_question = null;
                    if (isset($_POST['edit_id'])) {
                        $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
                        $stmt->execute([$_POST['edit_id']]);
                        $editing_question = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    ?>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="<?= $editing_question ? 'edit' : 'add' ?>">
                        <?php if ($editing_question): ?>
                            <input type="hidden" name="id" value="<?= $editing_question['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Câu hỏi:</label>
                            <textarea class="form-control" name="question_text" rows="3" required><?= $editing_question ? htmlspecialchars($editing_question['question_text']) : '' ?></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Lựa chọn 1:</label>
                                <input type="text" class="form-control" name="option_0" 
                                       value="<?= $editing_question ? htmlspecialchars(json_decode($editing_question['options_json'])[0]) : '' ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Lựa chọn 2:</label>
                                <input type="text" class="form-control" name="option_1" 
                                       value="<?= $editing_question ? htmlspecialchars(json_decode($editing_question['options_json'])[1]) : '' ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Lựa chọn 3:</label>
                                <input type="text" class="form-control" name="option_2" 
                                       value="<?= $editing_question ? htmlspecialchars(json_decode($editing_question['options_json'])[2]) : '' ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Đáp án đúng:</label>
                                <select class="form-select" name="correct_index" required>
                                    <option value="0" <?= $editing_question && $editing_question['correct_index'] == 0 ? 'selected' : '' ?>>Lựa chọn 1</option>
                                    <option value="1" <?= $editing_question && $editing_question['correct_index'] == 1 ? 'selected' : '' ?>>Lựa chọn 2</option>
                                    <option value="2" <?= $editing_question && $editing_question['correct_index'] == 2 ? 'selected' : '' ?>>Lựa chọn 3</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Thời gian (giây):</label>
                                <input type="number" class="form-control" name="time_limit" min="5" max="60" 
                                       value="<?= $editing_question ? $editing_question['time_limit'] : 10 ?>" required>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <?php if ($editing_question): ?>
                                <a href="admin.php" class="btn btn-secondary me-md-2">Hủy</a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>
                                <?= $editing_question ? 'Cập nhật' : 'Thêm Câu hỏi' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Danh sách câu hỏi -->
                <h4><i class="fas fa-list me-2"></i>Danh sách Câu hỏi</h4>
                
                <?php if (empty($questions)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle me-2"></i>Chưa có câu hỏi nào. Hãy thêm câu hỏi đầu tiên!
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($questions as $q): 
                            $options = json_decode($q['options_json'], true);
                        ?>
                            <div class="col-lg-6">
                                <div class="card question-card">
                                    <div class="card-body">
                                        <h5 class="card-title">#<?= $q['id'] ?>: <?= htmlspecialchars($q['question_text']) ?></h5>
                                        <p class="card-text">
                                            <?php foreach ($options as $i => $option): ?>
                                                <span class="option-badge <?= $i == $q['correct_index'] ? 'correct-option' : '' ?>">
                                                    <?= htmlspecialchars($option) ?>
                                                    <?php if ($i == $q['correct_index']): ?>
                                                        <i class="fas fa-check ms-1"></i>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </p>
                                        <p class="text-muted">
                                            <small>
                                                <i class="fas fa-clock me-1"></i>Thời gian: <?= $q['time_limit'] ?> giây
                                            </small>
                                        </p>
                                        <div class="d-flex justify-content-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="edit_id" value="<?= $q['id'] ?>">
                                                <button type="submit" class="btn btn-warning btn-sm btn-action">
                                                    <i class="fas fa-edit"></i> Sửa
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa câu hỏi này?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm btn-action">
                                                    <i class="fas fa-trash"></i> Xóa
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>