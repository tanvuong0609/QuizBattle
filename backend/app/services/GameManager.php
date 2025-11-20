<?php
namespace App\Services;

error_reporting(0);
ini_set('display_errors', 0);

/**
 * GameManager - Quản lý trạng thái game và điểm số
 */
class GameManager {
    private $gameStateFile;
    private $gameStates = []; // roomId => gameState
    
    // Scoring configuration
    private $baseScore = 100;              // Điểm cơ bản khi trả lời đúng
    private $timeBonus = 50;               // Bonus tối đa (trả lời nhanh)
    private $questionTimeLimit = 20;       // Giới hạn thời gian (giây)
    private $perfectTimeThreshold = 0.3;   // 30% đầu tiên = full bonus
    
    public function __construct() {
        $this->gameStateFile = __DIR__ . '/../game_states.json';
        echo "GameManager state file: {$this->gameStatesFile}\n";
        $this->loadGameStates();
    }
    
    /**
     * Load game states from file
     */
    private function loadGameStates() {
        if (file_exists($this->gameStateFile)) {
            $json = file_get_contents($this->gameStateFile);
            $this->gameStates = json_decode($json, true) ?? [];
        }
    }
    
    /**
     * Save game states to file
     */
    private function saveGameStates() {
        file_put_contents($this->gameStateFile, json_encode($this->gameStates, JSON_PRETTY_PRINT));
    }
    
    /**
     * Initialize game for a room
     */
    public function initializeGame($roomId, $players) {
        $playerScores = [];
        
        foreach ($players as $player) {
            $playerScores[$player['id']] = [
                'player_id' => $player['id'],
                'player_name' => $player['name'],
                'score' => 0,
                'correct_answers' => 0,
                'wrong_answers' => 0,
                'total_time' => 0,
                'answers' => [] // questionId => answer data
            ];
        }
        
        $this->gameStates[$roomId] = [
            'room_id' => $roomId,
            'status' => 'playing',
            'players' => $playerScores,
            'current_question_id' => null,
            'question_start_time' => null,
            'started_at' => time(),
            'finished_at' => null
        ];
        
        $this->saveGameStates();
        
        echo "🎮 Initialized game for room {$roomId} with " . count($players) . " players\n";
        
        return $this->gameStates[$roomId];
    }
    
    /**
     * Set current question
     */
    public function setCurrentQuestion($roomId, $questionId) {
        if (!isset($this->gameStates[$roomId])) {
            throw new \Exception("Game not initialized for room {$roomId}");
        }
        
        $this->gameStates[$roomId]['current_question_id'] = $questionId;
        $this->gameStates[$roomId]['question_start_time'] = time();
        
        $this->saveGameStates();
    }
    
    /**
     * Submit answer
     */
    public function submitAnswer($roomId, $playerId, $questionId, $answerId, $isCorrect, $timeSpent) {
        if (!isset($this->gameStates[$roomId])) {
            throw new \Exception("Game not found for room {$roomId}");
        }
        
        if (!isset($this->gameStates[$roomId]['players'][$playerId])) {
            throw new \Exception("Player {$playerId} not found in game");
        }
        
        $player = &$this->gameStates[$roomId]['players'][$playerId];
        
        // Check if already answered this question
        if (isset($player['answers'][$questionId])) {
            throw new \Exception("Already answered this question");
        }
        
        // Calculate score
        $score = $this->calculateScore($isCorrect, $timeSpent);
        
        // Update player stats
        $player['score'] += $score;
        $player['total_time'] += $timeSpent;
        
        if ($isCorrect) {
            $player['correct_answers']++;
        } else {
            $player['wrong_answers']++;
        }
        
        // Store answer
        $player['answers'][$questionId] = [
            'answer_id' => $answerId,
            'is_correct' => $isCorrect,
            'score' => $score,
            'time_spent' => $timeSpent,
            'timestamp' => time()
        ];
        
        $this->saveGameStates();
        
        echo "📝 Player {$playerId} answered: " . ($isCorrect ? 'Correct' : 'Wrong') . " (+{$score} points)\n";
        
        return [
            'player_id' => $playerId,
            'question_id' => $questionId,
            'is_correct' => $isCorrect,
            'score' => $score,
            'total_score' => $player['score']
        ];
    }
    
    /**
     * Calculate score with time-based bonus
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
            
            $score += max(0, round($bonus));
        }
        
        return $score;
    }
    
    /**
     * Get leaderboard for a room
     */
    public function getLeaderboard($roomId) {
        if (!isset($this->gameStates[$roomId])) {
            return [];
        }
        
        $players = array_values($this->gameStates[$roomId]['players']);
        
        // Sort by score (highest first), then by total time (fastest first)
        usort($players, function($a, $b) {
            if ($b['score'] == $a['score']) {
                return $a['total_time'] - $b['total_time'];
            }
            return $b['score'] - $a['score'];
        });
        
        return $players;
    }
    
    /**
     * Finish game
     */
    public function finishGame($roomId) {
        if (!isset($this->gameStates[$roomId])) {
            throw new \Exception("Game not found");
        }
        
        $this->gameStates[$roomId]['status'] = 'finished';
        $this->gameStates[$roomId]['finished_at'] = time();
        
        $this->saveGameStates();
        
        echo "🏁 Game finished for room {$roomId}\n";
        
        return $this->getLeaderboard($roomId);
    }
    
    /**
     * Get game state
     */
    public function getGameState($roomId) {
        return $this->gameStates[$roomId] ?? null;
    }
    
    /**
     * Check if player has answered current question
     */
    public function hasAnswered($roomId, $playerId, $questionId) {
        if (!isset($this->gameStates[$roomId]['players'][$playerId])) {
            return false;
        }
        
        $player = $this->gameStates[$roomId]['players'][$playerId];
        return isset($player['answers'][$questionId]);
    }
    
    /**
     * Get all players who answered current question
     */
    public function getAnsweredPlayers($roomId, $questionId) {
        if (!isset($this->gameStates[$roomId])) {
            return [];
        }
        
        $answered = [];
        foreach ($this->gameStates[$roomId]['players'] as $player) {
            if (isset($player['answers'][$questionId])) {
                $answered[] = $player['player_id'];
            }
        }
        
        return $answered;
    }
    
    /**
     * Check if all players answered
     */
    public function allPlayersAnswered($roomId, $questionId) {
        if (!isset($this->gameStates[$roomId])) {
            return false;
        }
        
        $totalPlayers = count($this->gameStates[$roomId]['players']);
        $answeredPlayers = count($this->getAnsweredPlayers($roomId, $questionId));
        
        return $totalPlayers === $answeredPlayers;
    }
    
    /**
     * Reset game for a room
     */
    public function resetGame($roomId) {
        if (isset($this->gameStates[$roomId])) {
            unset($this->gameStates[$roomId]);
            $this->saveGameStates();
            echo "🔄 Reset game for room {$roomId}\n";
        }
    }
    
    /**
     * Get player stats
     */
    public function getPlayerStats($roomId, $playerId) {
        if (!isset($this->gameStates[$roomId]['players'][$playerId])) {
            return null;
        }
        
        return $this->gameStates[$roomId]['players'][$playerId];
    }
}
?>