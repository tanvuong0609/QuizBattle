<?php
namespace App\Services;

error_reporting(0);
ini_set('display_errors', 0);

/**
 * QuestionManager - Quản lý và phân phối câu hỏi
 */
class QuestionManager {
    private $questionsFile;
    private $questions = [];
    private $roomQuestionsFile;
    private $roomQuestions = []; // Track questions per room
    
    /**
     * Constructor
     */
    public function __construct($questionsFile = 'questions.json') {
        $this->questionsFile = $questionsFile ?: __DIR__ . '/questions.json';
        $this->roomQuestionsFile = __DIR__ . '/../room_questions.json';
        
        echo "📁 QuestionManager files:\n";
        echo "  - Questions: {$this->questionsFile}\n";
        echo "  - Room questions: {$this->roomQuestionsFile}\n";
        $this->loadQuestions();
        $this->loadRoomQuestions();
    }
    
    /**
     * Load questions from JSON file
     */
    private function loadQuestions() {
        if (!file_exists($this->questionsFile)) {
            throw new \Exception("Questions file not found: {$this->questionsFile}");
        }
        
        $json = file_get_contents($this->questionsFile);
        $data = json_decode($json, true);
        
        if (!$data || !isset($data['categories'])) {
            throw new \Exception("Invalid questions file format");
        }
        
        // Flatten all questions from all categories
        $this->questions = [];
        foreach ($data['categories'] as $category) {
            if (isset($category['questions'])) {
                foreach ($category['questions'] as $question) {
                    $question['category'] = $category['id'];
                    $question['category_name'] = $category['name'];
                    $this->questions[] = $question;
                }
            }
        }
        
        echo "✅ Loaded " . count($this->questions) . " questions\n";
    }
    
    /**
     * Load room questions state
     */
    private function loadRoomQuestions() {
        if (file_exists($this->roomQuestionsFile)) {
            $json = file_get_contents($this->roomQuestionsFile);
            $this->roomQuestions = json_decode($json, true) ?? [];
        }
    }
    
    /**
     * Save room questions state
     */
    private function saveRoomQuestions() {
        file_put_contents($this->roomQuestionsFile, json_encode($this->roomQuestions, JSON_PRETTY_PRINT));
    }
    
    /**
     * Initialize question sequence for a room
     * 
     * @param string $roomId
     * @param int $totalQuestions Number of questions for the game
     * @param string $mode 'random' or 'sequential'
     * @return array Initialized game data
     */
    public function initializeGameQuestions($roomId, $totalQuestions = 10, $mode = 'random') {
        // Get available questions
        $availableQuestions = $this->questions;
        
        if (count($availableQuestions) < $totalQuestions) {
            throw new \Exception("Not enough questions available");
        }
        
        // Select questions based on mode
        if ($mode === 'random') {
            // Shuffle and take first N questions
            shuffle($availableQuestions);
            $selectedQuestions = array_slice($availableQuestions, 0, $totalQuestions);
        } else {
            // Sequential mode
            $selectedQuestions = array_slice($availableQuestions, 0, $totalQuestions);
        }
        
        // Store question IDs (not full questions) for this room
        $questionIds = array_map(function($q) {
            return $q['id'];
        }, $selectedQuestions);
        
        $this->roomQuestions[$roomId] = [
            'question_ids' => $questionIds,
            'current_index' => 0,
            'total_questions' => $totalQuestions,
            'mode' => $mode,
            'started_at' => time(),
            'completed' => false
        ];
        
        $this->saveRoomQuestions();
        
        echo "🎮 Initialized {$totalQuestions} questions for room {$roomId} (mode: {$mode})\n";
        
        return $this->roomQuestions[$roomId];
    }
    
    /**
     * Get next question for a room
     * 
     * @param string $roomId
     * @return array|null Question data or null if no more questions
     */
    public function getNextQuestion($roomId) {
        if (!isset($this->roomQuestions[$roomId])) {
            throw new \Exception("Room {$roomId} not initialized");
        }
        
        $roomData = &$this->roomQuestions[$roomId];
        
        // Check if all questions completed
        if ($roomData['current_index'] >= $roomData['total_questions']) {
            $roomData['completed'] = true;
            $this->saveRoomQuestions();
            return null;
        }
        
        // Get current question ID
        $questionId = $roomData['question_ids'][$roomData['current_index']];
        
        // Find full question data
        $question = $this->getQuestionById($questionId);
        
        if (!$question) {
            throw new \Exception("Question {$questionId} not found");
        }
        
        // Add metadata
        $question['question_number'] = $roomData['current_index'] + 1;
        $question['total_questions'] = $roomData['total_questions'];
        
        // Increment index for next time
        $roomData['current_index']++;
        $this->saveRoomQuestions();
        
        echo "❓ Sending question {$question['question_number']}/{$roomData['total_questions']} to room {$roomId}\n";
        
        return $this->sanitizeQuestion($question);
    }
    
    /**
     * Get current question for a room (for recovery)
     * 
     * @param string $roomId
     * @return array|null Current question
     */
    public function getCurrentQuestion($roomId) {
        if (!isset($this->roomQuestions[$roomId])) {
            return null;
        }
        
        $roomData = $this->roomQuestions[$roomId];
        
        // Get previous question index (current - 1)
        $index = max(0, $roomData['current_index'] - 1);
        
        if ($index >= count($roomData['question_ids'])) {
            return null;
        }
        
        $questionId = $roomData['question_ids'][$index];
        $question = $this->getQuestionById($questionId);
        
        if ($question) {
            $question['question_number'] = $index + 1;
            $question['total_questions'] = $roomData['total_questions'];
            return $this->sanitizeQuestion($question);
        }
        
        return null;
    }
    
    /**
     * Get question by ID
     * 
     * @param string $questionId
     * @return array|null Question data
     */
    public function getQuestionById($questionId) {
        foreach ($this->questions as $question) {
            if ($question['id'] === $questionId) {
                return $question;
            }
        }
        return null;
    }
    
    /**
     * Sanitize question (remove correct answer for client)
     * 
     * @param array $question
     * @return array Sanitized question
     */
    private function sanitizeQuestion($question) {
        // Create a copy
        $sanitized = $question;
        
        // Store correct answer separately (for server validation)
        $sanitized['_correct_answer'] = $question['correct_answer'];
        
        // Remove correct answer from client version
        unset($sanitized['correct_answer']);
        
        return $sanitized;
    }
    
    /**
     * Verify answer
     * 
     * @param string $questionId
     * @param string $answerId
     * @return bool True if correct
     */
    public function verifyAnswer($questionId, $answerId) {
        $question = $this->getQuestionById($questionId);
        
        if (!$question) {
            return false;
        }
        
        return $question['correct_answer'] === $answerId;
    }
    
    /**
     * Get correct answer for a question
     * 
     * @param string $questionId
     * @return string|null Correct answer ID
     */
    public function getCorrectAnswer($questionId) {
        $question = $this->getQuestionById($questionId);
        return $question ? $question['correct_answer'] : null;
    }
    
    /**
     * Check if room has more questions
     * 
     * @param string $roomId
     * @return bool
     */
    public function hasMoreQuestions($roomId) {
        if (!isset($this->roomQuestions[$roomId])) {
            return false;
        }
        
        $roomData = $this->roomQuestions[$roomId];
        return $roomData['current_index'] < $roomData['total_questions'];
    }
    
    /**
     * Get room progress
     * 
     * @param string $roomId
     * @return array Progress data
     */
    public function getRoomProgress($roomId) {
        if (!isset($this->roomQuestions[$roomId])) {
            return null;
        }
        
        $roomData = $this->roomQuestions[$roomId];
        
        return [
            'current_question' => $roomData['current_index'],
            'total_questions' => $roomData['total_questions'],
            'completed' => $roomData['completed'],
            'started_at' => $roomData['started_at']
        ];
    }
    
    /**
     * Reset room questions
     * 
     * @param string $roomId
     */
    public function resetRoom($roomId) {
        if (isset($this->roomQuestions[$roomId])) {
            unset($this->roomQuestions[$roomId]);
            $this->saveRoomQuestions();
            echo "🔄 Reset questions for room {$roomId}\n";
        }
    }
    
    /**
     * Get all questions (for admin/debug)
     * 
     * @return array All questions
     */
    public function getAllQuestions() {
        return $this->questions;
    }
    
    /**
     * Get questions by category
     * 
     * @param string $categoryId
     * @return array Questions in category
     */
    public function getQuestionsByCategory($categoryId) {
        return array_filter($this->questions, function($q) use ($categoryId) {
            return $q['category'] === $categoryId;
        });
    }
    
    /**
     * Get questions by difficulty
     * 
     * @param string $difficulty 'easy', 'medium', 'hard'
     * @return array Filtered questions
     */
    public function getQuestionsByDifficulty($difficulty) {
        return array_filter($this->questions, function($q) use ($difficulty) {
            return isset($q['difficulty']) && $q['difficulty'] === $difficulty;
        });
    }
    
    /**
     * Get statistics
     * 
     * @return array Statistics
     */
    public function getStatistics() {
        $stats = [
            'total_questions' => count($this->questions),
            'active_rooms' => count($this->roomQuestions),
            'categories' => []
        ];
        
        // Count by category
        foreach ($this->questions as $question) {
            $cat = $question['category'];
            if (!isset($stats['categories'][$cat])) {
                $stats['categories'][$cat] = [
                    'name' => $question['category_name'],
                    'count' => 0
                ];
            }
            $stats['categories'][$cat]['count']++;
        }
        
        return $stats;
    }
}
?>