<?php
error_reporting(0);
ini_set('display_errors', 0);


class QuizAnswerHandler {
    private $playersFile = 'quiz_players.json';
    private $resultsFile = 'quiz_results.json';
    
    // Scoring configuration
    private $baseScore = 100;              // Điểm cơ bản khi trả lời đúng
    private $timeBonus = 50;               // Bonus tối đa (trả lời nhanh)
    private $questionTimeLimit = 30;       // Giới hạn thời gian (giây)
    private $perfectTimeThreshold = 0.3;   // 30% đầu tiên = full bonus
    
    // Validation limits
    private $maxTimeSpent = 1000;          // Thời gian tối đa cho phép (giây)
    private $maxPlayerIdLength = 50;       // Độ dài tối đa playerId
    private $maxAnswerLength = 500;        // Độ dài tối đa câu trả lời
    
    private $players = [];
    private $results = [];
    
    /**
     * Constructor - Load data from files
     */
    public function __construct() {
        $this->loadData();
    }
    
    /**
     * Load data from JSON files
     */
    private function loadData() {
        // Load players
        if (file_exists($this->playersFile)) {
            $json = file_get_contents($this->playersFile);
            $this->players = json_decode($json, true) ?? [];
        }
        
        // Load results
        if (file_exists($this->resultsFile)) {
            $json = file_get_contents($this->resultsFile);
            $this->results = json_decode($json, true) ?? [];
        }
    }
    
    /**
     * Save data to JSON files
     */
    private function saveData() {
        // Save players
        file_put_contents($this->playersFile, json_encode($this->players, JSON_PRETTY_PRINT));
        
        // Save results
        file_put_contents($this->resultsFile, json_encode($this->results, JSON_PRETTY_PRINT));
    }
    
    /**
     * Main handler - Process incoming requests
     */
    public function handleRequest() {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
        
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'submit_answer':
                    $this->submitAnswer();
                    break;
                case 'get_scores':
                    $this->getScores();
                    break;
                case 'get_results':
                    $this->getResults();
                    break;
                case 'reset':
                    $this->resetGame();
                    break;
                default:
                    $this->sendError('Invalid action. Available: submit_answer, get_scores, get_results, reset', 400);
            }
        } catch (Exception $e) {
            $this->sendError('Server error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Submit and validate answer
     */
    private function submitAnswer() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $validation = $this->validateInput($data);
        if (!$validation['valid']) {
            $this->sendError($validation['message'], 400);
            return;
        }
        
        $playerId = $data['playerId'];
        $questionId = $data['questionId'];
        $answer = $data['answer'];
        $timeSpent = floatval($data['timeSpent']);
        $correctAnswer = $data['correctAnswer'] ?? null;
        
        // ✅ FIX 1: Kiểm tra đã trả lời chưa
        if ($this->hasAnswered($playerId, $questionId)) {
            $this->sendError('Đã trả lời câu này rồi', 400);
            return;
        }
        
        // Handle unanswered case
        if ($answer === null || $answer === '') {
            $result = $this->processUnanswered($playerId, $questionId, $timeSpent);
            $this->saveData();
            
            $broadcast = $this->broadcastResults($questionId);
            
            $this->sendSuccess([
                'result' => $result,
                'broadcast' => $broadcast,
                'message' => 'Hết giờ - không trả lời'
            ]);
            return;
        }
        
        // Validate answer format
        if (!$this->validateAnswer($answer)) {
            $this->sendError('Câu trả lời không hợp lệ (quá dài)', 400);
            return;
        }
        
        // Check correct answer
        $isCorrect = $this->checkAnswer($answer, $correctAnswer);
        
        // Calculate score with time bonus
        $score = $this->calculateScore($isCorrect, $timeSpent);
        
        // Save result
        $result = $this->saveResult($playerId, $questionId, $answer, $isCorrect, $score, $timeSpent);
        
        // Update player's total score
        $this->updatePlayerScore($playerId, $score);
        
        // Save all data
        $this->saveData();
        
        // Broadcast results to all players
        $broadcast = $this->broadcastResults($questionId);
        
        $this->sendSuccess([
            'result' => $result,
            'broadcast' => $broadcast,
            'message' => $isCorrect ? '✓ Đúng rồi!' : '✗ Sai rồi!'
        ]);
    }
    
    /**
     * Validate input data (ENHANCED)
     */
    private function validateInput($data) {
        // Check required fields
        if (!isset($data['playerId']) || 
            !isset($data['questionId']) || 
            !isset($data['timeSpent']) ||
            !array_key_exists('answer', $data)) {
            return [
                'valid' => false,
                'message' => 'Thiếu thông tin bắt buộc: playerId, questionId, answer, timeSpent'
            ];
        }
        
        // Validate playerId
        $playerId = trim($data['playerId']);
        if (empty($playerId)) {
            return [
                'valid' => false,
                'message' => 'playerId không được rỗng'
            ];
        }
        if (strlen($playerId) > $this->maxPlayerIdLength) {
            return [
                'valid' => false,
                'message' => 'playerId quá dài (max ' . $this->maxPlayerIdLength . ' ký tự)'
            ];
        }
        
        // Validate questionId
        $questionId = trim($data['questionId']);
        if (empty($questionId)) {
            return [
                'valid' => false,
                'message' => 'questionId không được rỗng'
            ];
        }
        
        // ✅ FIX 3: Validate timeSpent
        $timeSpent = floatval($data['timeSpent']);
        if ($timeSpent < 0) {
            return [
                'valid' => false,
                'message' => 'timeSpent không được âm'
            ];
        }
        if ($timeSpent > $this->maxTimeSpent) {
            return [
                'valid' => false,
                'message' => 'timeSpent quá lớn (max ' . $this->maxTimeSpent . 's)'
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * ✅ FIX 1: Kiểm tra đã trả lời chưa
     */
    private function hasAnswered($playerId, $questionId) {
        foreach ($this->results as $result) {
            if ($result['playerId'] === $playerId && 
                $result['questionId'] === $questionId) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Validate answer format
     */
    private function validateAnswer($answer) {
        // Accept string or numeric answers
        if (!is_string($answer) && !is_numeric($answer)) {
            return false;
        }
        
        // Check length
        if (is_string($answer) && strlen($answer) > $this->maxAnswerLength) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if answer is correct
     */
    private function checkAnswer($answer, $correctAnswer) {
        if ($correctAnswer === null) {
            return false; // No correct answer provided
        }
        
        // Case-insensitive comparison, trim whitespace
        return strtolower(trim($answer)) === strtolower(trim($correctAnswer));
    }
    
    /**
     * Calculate score with time-based bonus (FIXED)
     * 
     * Công thức:
     * - Sai = 0 điểm
     * - Đúng = Điểm cơ bản (100) + Bonus thời gian (0-50)
     * - Bonus: Trả lời trong 30% đầu = full 50 điểm
     * - Bonus giảm dần linear đến 0 khi hết giờ
     */
    private function calculateScore($isCorrect, $timeSpent) {
        if (!$isCorrect) {
            return 0; // Sai = 0 điểm
        }
        
        // Base score for correct answer
        $score = $this->baseScore;
        
        // Calculate time bonus (faster = more bonus)
        if ($timeSpent < $this->questionTimeLimit) {
            $timeRatio = $timeSpent / $this->questionTimeLimit;
            
            // Perfect threshold: first 30% of time gets full bonus
            if ($timeRatio <= $this->perfectTimeThreshold) {
                $bonus = $this->timeBonus;
            } else {
                // Linear decrease from max bonus to 0
                $bonus = $this->timeBonus * (1 - $timeRatio);
            }
            
            // ✅ FIX 2: Đảm bảo bonus không âm
            $score += max(0, round($bonus));
        }
        
        return $score;
    }
    
    /**
     * Process unanswered question (timeout)
     */
    private function processUnanswered($playerId, $questionId, $timeSpent) {
        $result = [
            'playerId' => $playerId,
            'questionId' => $questionId,
            'answer' => null,
            'isCorrect' => false,
            'score' => 0,
            'timeSpent' => round($timeSpent, 2),
            'timestamp' => time(),
            'status' => 'unanswered'
        ];
        
        // Save unanswered result
        $this->results[] = $result;
        
        // Update player stats (no score added)
        $this->updatePlayerScore($playerId, 0);
        
        return $result;
    }
    
    /**
     * Save result
     */
    private function saveResult($playerId, $questionId, $answer, $isCorrect, $score, $timeSpent) {
        $result = [
            'playerId' => $playerId,
            'questionId' => $questionId,
            'answer' => $answer,
            'isCorrect' => $isCorrect,
            'score' => $score,
            'timeSpent' => round($timeSpent, 2),
            'timestamp' => time(),
            'status' => 'answered'
        ];
        
        $this->results[] = $result;
        
        return $result;
    }
    
    /**
     * Update player's total score
     */
    private function updatePlayerScore($playerId, $scoreToAdd) {
        // Find player
        $playerIndex = $this->findPlayerIndex($playerId);
        
        if ($playerIndex === -1) {
            // Create new player
            $this->players[] = [
                'playerId' => $playerId,
                'totalScore' => $scoreToAdd,
                'questionsAnswered' => 1,
                'correctAnswers' => $scoreToAdd > 0 ? 1 : 0,
                'wrongAnswers' => $scoreToAdd > 0 ? 0 : 1,
                'createdAt' => time(),
                'lastUpdate' => time()
            ];
        } else {
            // Update existing player
            $this->players[$playerIndex]['totalScore'] += $scoreToAdd;
            $this->players[$playerIndex]['questionsAnswered']++;
            
            if ($scoreToAdd > 0) {
                $this->players[$playerIndex]['correctAnswers']++;
            } else {
                $this->players[$playerIndex]['wrongAnswers']++;
            }
            
            $this->players[$playerIndex]['lastUpdate'] = time();
        }
    }
    
    /**
     * Find player index in array
     */
    private function findPlayerIndex($playerId) {
        foreach ($this->players as $index => $player) {
            if ($player['playerId'] === $playerId) {
                return $index;
            }
        }
        return -1;
    }
    
    /**
     * Broadcast results to all players after each question
     */
    private function broadcastResults($questionId) {
        // Get results for this question
        $questionResults = [];
        foreach ($this->results as $result) {
            if ($result['questionId'] === $questionId) {
                $questionResults[] = $result;
            }
        }
        
        // Sort by score (highest first), then by time (fastest first)
        usort($questionResults, function($a, $b) {
            if ($b['score'] == $a['score']) {
                return $a['timeSpent'] - $b['timeSpent'];
            }
            return $b['score'] - $a['score'];
        });
        
        // Get leaderboard (all players sorted by total score)
        $leaderboard = $this->players;
        usort($leaderboard, function($a, $b) {
            if ($b['totalScore'] == $a['totalScore']) {
                // Nếu điểm bằng nhau, ưu tiên người trả lời ít câu hơn
                return $a['questionsAnswered'] - $b['questionsAnswered'];
            }
            return $b['totalScore'] - $a['totalScore'];
        });
        
        return [
            'questionId' => $questionId,
            'questionResults' => $questionResults,
            'leaderboard' => $leaderboard,
            'totalPlayers' => count($leaderboard),
            'timestamp' => time()
        ];
    }
    
    /**
     * Get current scores (leaderboard)
     */
    private function getScores() {
        $leaderboard = $this->players;
        
        // Sort by total score
        usort($leaderboard, function($a, $b) {
            if ($b['totalScore'] == $a['totalScore']) {
                return $a['questionsAnswered'] - $b['questionsAnswered'];
            }
            return $b['totalScore'] - $a['totalScore'];
        });
        
        $this->sendSuccess([
            'leaderboard' => $leaderboard,
            'totalPlayers' => count($leaderboard),
            'timestamp' => time()
        ]);
    }
    
    /**
     * Get all results
     */
    private function getResults() {
        // Sort by timestamp (newest first)
        $results = $this->results;
        usort($results, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        $this->sendSuccess([
            'results' => $results,
            'count' => count($results),
            'timestamp' => time()
        ]);
    }
    
    /**
     * Reset game data
     */
    private function resetGame() {
        $this->players = [];
        $this->results = [];
        $this->saveData();
        
        $this->sendSuccess([
            'message' => 'Đã reset game thành công',
            'timestamp' => time()
        ]);
    }
    
    /**
     * Send success response
     */
    private function sendSuccess($data) {
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit();
    }
    
    /**
     * Send error response
     */
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => time()
        ]);
        exit();
    }
}

// Initialize and handle request
$handler = new QuizAnswerHandler();
$handler->handleRequest();
?>