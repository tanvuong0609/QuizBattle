import { useState, useEffect, useRef } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'

function GamePage() {
  const navigate = useNavigate()
  const location = useLocation()
  const username = location.state?.username || 'Player'
  const players = location.state?.players || []
  
  // Game State
  const [currentQuestion, setCurrentQuestion] = useState(null)
  const [selectedAnswer, setSelectedAnswer] = useState(null)
  const [timeRemaining, setTimeRemaining] = useState(20)
  const [questionNumber, setQuestionNumber] = useState(1)
  const [totalQuestions] = useState(10)
  const [gamePhase, setGamePhase] = useState('playing') // playing, waiting, result, finished
  const [correctAnswerId, setCorrectAnswerId] = useState(null)
  const [hasAnswered, setHasAnswered] = useState(false)
  
  // Scores
  const [scores, setScores] = useState(
    players.map(p => ({ username: p.username, score: 0, answeredCount: 0 }))
  )
  
  // WebSocket
  const wsRef = useRef(null)
  const timerRef = useRef(null)

  // Demo question data
  const demoQuestions = [
    {
      id: 'q1',
      question: 'What is the capital of France?',
      answers: [
        { id: 'a', text: 'London' },
        { id: 'b', text: 'Paris' },
        { id: 'c', text: 'Berlin' },
        { id: 'd', text: 'Madrid' }
      ],
      correctAnswerId: 'b',
      timeLimit: 20
    },
    {
      id: 'q2',
      question: 'Which planet is known as the Red Planet?',
      answers: [
        { id: 'a', text: 'Venus' },
        { id: 'b', text: 'Jupiter' },
        { id: 'c', text: 'Mars' },
        { id: 'd', text: 'Saturn' }
      ],
      correctAnswerId: 'c',
      timeLimit: 20
    },
    {
      id: 'q3',
      question: 'What is 15 + 27?',
      answers: [
        { id: 'a', text: '40' },
        { id: 'b', text: '42' },
        { id: 'c', text: '44' },
        { id: 'd', text: '46' }
      ],
      correctAnswerId: 'b',
      timeLimit: 20
    }
  ]

  // Initialize game
  useEffect(() => {
    loadQuestion(0)
    
    return () => {
      if (timerRef.current) clearInterval(timerRef.current)
    }
  }, [])

  // Timer countdown
  useEffect(() => {
    if (gamePhase !== 'playing') return

    timerRef.current = setInterval(() => {
      setTimeRemaining(prev => {
        if (prev <= 1) {
          handleTimeUp()
          return 0
        }
        return prev - 1
      })
    }, 1000)

    return () => {
      if (timerRef.current) clearInterval(timerRef.current)
    }
  }, [gamePhase, questionNumber])

  const loadQuestion = (index) => {
    if (index >= demoQuestions.length) {
      // Game finished
      setGamePhase('finished')
      setTimeout(() => {
        navigate('/result', { state: { scores, username } })
      }, 2000)
      return
    }

    const question = demoQuestions[index]
    setCurrentQuestion(question)
    setSelectedAnswer(null)
    setCorrectAnswerId(null)
    setTimeRemaining(question.timeLimit)
    setQuestionNumber(index + 1)
    setGamePhase('playing')
    setHasAnswered(false)
  }

  const handleAnswerSelect = (answerId) => {
    if (gamePhase !== 'playing') return
    setSelectedAnswer(answerId)
  }

  const handleSubmitAnswer = () => {
    if (!selectedAnswer || hasAnswered) return

    setHasAnswered(true)
    
    // Calculate score based on time remaining
    const timeBonus = Math.floor(timeRemaining * 5)
    const baseScore = 100
    const earnedScore = selectedAnswer === currentQuestion.correctAnswerId 
      ? baseScore + timeBonus 
      : 0

    // Simulate sending to WebSocket
    console.log('Submitting answer:', {
      questionId: currentQuestion.id,
      answerId: selectedAnswer,
      timeSpent: currentQuestion.timeLimit - timeRemaining,
      score: earnedScore
    })

    // Simulate waiting for all players
    setGamePhase('waiting')
    
    setTimeout(() => {
      showResults(earnedScore)
    }, 2000)
  }

  const showResults = (earnedScore) => {
    setGamePhase('result')
    setCorrectAnswerId(currentQuestion.correctAnswerId)
    
    // Update scores
    setScores(prev => prev.map(player => {
      if (player.username === username) {
        return {
          ...player,
          score: player.score + earnedScore,
          answeredCount: player.answeredCount + 1
        }
      }
      // Simulate other players' scores (random)
      const otherPlayerScore = Math.floor(Math.random() * 150) + 50
      return {
        ...player,
        score: player.score + otherPlayerScore,
        answeredCount: player.answeredCount + 1
      }
    }))

    // Next question after 3 seconds
    setTimeout(() => {
      loadQuestion(questionNumber)
    }, 3000)
  }

  const handleTimeUp = () => {
    if (hasAnswered) return
    
    setGamePhase('waiting')
    setHasAnswered(true)
    
    console.log('Time up! No answer submitted.')
    
    setTimeout(() => {
      showResults(0) // No score for timeout
    }, 1500)
  }

  const getTimerColor = () => {
    const percentage = (timeRemaining / currentQuestion?.timeLimit) * 100
    if (percentage > 60) return '#10b981' // Green
    if (percentage > 30) return '#f59e0b' // Yellow
    return '#ef4444' // Red
  }

  const getAnswerClass = (answerId) => {
    const baseClass = "w-full p-4 rounded-xl font-bold text-lg transition-all duration-300 border-2 "
    
    if (gamePhase === 'result') {
      if (answerId === correctAnswerId) {
        return baseClass + "bg-green-500 text-white border-green-600 scale-105 shadow-lg"
      }
      if (answerId === selectedAnswer && answerId !== correctAnswerId) {
        return baseClass + "bg-red-500 text-white border-red-600"
      }
      return baseClass + "bg-gray-200 text-gray-600 border-gray-300"
    }
    
    if (selectedAnswer === answerId) {
      return baseClass + "bg-purple-500 text-white border-purple-600 scale-105 shadow-lg"
    }
    
    return baseClass + "bg-white text-gray-800 border-gray-300 hover:border-purple-400 hover:bg-purple-50 hover:scale-105 active:scale-95"
  }

  const sortedScores = [...scores].sort((a, b) => b.score - a.score)

  if (!currentQuestion) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-purple-600 to-pink-600">
        <div className="text-white text-2xl font-bold">Loading question...</div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-600 to-pink-600 p-4">
      <div className="max-w-7xl mx-auto">
        
        {/* Header */}
        <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-4 mb-4 shadow-lg">
          <div className="flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-4">
              <div className="text-2xl font-black text-purple-600">
                Question {questionNumber}/{totalQuestions}
              </div>
              {gamePhase === 'waiting' && (
                <div className="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-sm font-bold animate-pulse">
                  ⏳ Waiting for players...
                </div>
              )}
              {gamePhase === 'result' && (
                <div className="bg-green-100 text-green-700 px-3 py-1 rounded-full text-sm font-bold">
                  ✓ Results
                </div>
              )}
            </div>
            
            <div className="flex items-center gap-4">
              <div className="text-sm text-gray-600">
                Your Score: <span className="text-2xl font-black text-purple-600">{scores.find(s => s.username === username)?.score || 0}</span>
              </div>
            </div>
          </div>
        </div>

        {/* Main Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
          
          {/* Main Game Area */}
          <div className="lg:col-span-3 space-y-4">
            
            {/* Timer Circle */}
            <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-8 shadow-lg">
              <div className="flex items-center justify-center mb-6">
                <div className="relative w-32 h-32">
                  {/* Circle SVG */}
                  <svg className="transform -rotate-90 w-32 h-32">
                    <circle
                      cx="64"
                      cy="64"
                      r="56"
                      stroke="#e5e7eb"
                      strokeWidth="8"
                      fill="none"
                    />
                    <circle
                      cx="64"
                      cy="64"
                      r="56"
                      stroke={getTimerColor()}
                      strokeWidth="8"
                      fill="none"
                      strokeDasharray={`${2 * Math.PI * 56}`}
                      strokeDashoffset={`${2 * Math.PI * 56 * (1 - timeRemaining / currentQuestion.timeLimit)}`}
                      strokeLinecap="round"
                      className="transition-all duration-1000"
                    />
                  </svg>
                  <div className="absolute inset-0 flex items-center justify-center">
                    <span className="text-4xl font-black" style={{ color: getTimerColor() }}>
                      {timeRemaining}
                    </span>
                  </div>
                </div>
              </div>

              {/* Question */}
              <div className="text-center mb-8">
                <h2 className="text-3xl font-black text-gray-800 mb-2">
                  {currentQuestion.question}
                </h2>
                {gamePhase === 'result' && selectedAnswer === correctAnswerId && (
                  <div className="text-green-600 font-bold text-xl animate-bounce">
                    🎉 Correct! +{scores.find(s => s.username === username)?.score - (scores.find(s => s.username === username)?.score - (100 + timeRemaining * 5)) || 0} points
                  </div>
                )}
                {gamePhase === 'result' && selectedAnswer !== correctAnswerId && selectedAnswer && (
                  <div className="text-red-600 font-bold text-xl">
                    ❌ Wrong answer
                  </div>
                )}
              </div>

              {/* Answer Buttons */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                {currentQuestion.answers.map((answer, index) => (
                  <button
                    key={answer.id}
                    onClick={() => handleAnswerSelect(answer.id)}
                    disabled={gamePhase !== 'playing'}
                    className={getAnswerClass(answer.id)}
                  >
                    <div className="flex items-center gap-3">
                      <span className="w-8 h-8 rounded-full bg-purple-600 text-white flex items-center justify-center font-black flex-shrink-0">
                        {['A', 'B', 'C', 'D'][index]}
                      </span>
                      <span className="text-left flex-1">{answer.text}</span>
                      {gamePhase === 'result' && answer.id === correctAnswerId && (
                        <span className="text-2xl">✓</span>
                      )}
                    </div>
                  </button>
                ))}
              </div>

              {/* Submit Button */}
              {gamePhase === 'playing' && (
                <button
                  onClick={handleSubmitAnswer}
                  disabled={!selectedAnswer || hasAnswered}
                  className="w-full py-4 px-6 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-black text-xl rounded-xl hover:shadow-2xl transition-all duration-300 hover:scale-105 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:scale-100"
                >
                  {hasAnswered ? '✓ Submitted' : '✨ Submit Answer'}
                </button>
              )}
            </div>
          </div>

          {/* Leaderboard Sidebar */}
          <div className="lg:col-span-1">
            <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-6 shadow-lg sticky top-4">
              <h3 className="text-xl font-black text-gray-800 mb-4 flex items-center gap-2">
                🏆 Leaderboard
              </h3>
              
              <div className="space-y-3">
                {sortedScores.map((player, index) => (
                  <div
                    key={player.username}
                    className={`p-3 rounded-xl transition-all duration-300 ${
                      player.username === username
                        ? 'bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-400'
                        : 'bg-gray-50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      {/* Rank */}
                      <div className={`w-8 h-8 rounded-full flex items-center justify-center font-black text-sm flex-shrink-0 ${
                        index === 0 ? 'bg-yellow-400 text-yellow-900' :
                        index === 1 ? 'bg-gray-300 text-gray-700' :
                        index === 2 ? 'bg-orange-400 text-orange-900' :
                        'bg-gray-200 text-gray-600'
                      }`}>
                        {index + 1}
                      </div>
                      
                      {/* Info */}
                      <div className="flex-1 min-w-0">
                        <div className="font-bold text-gray-800 truncate text-sm">
                          {player.username}
                          {player.username === username && (
                            <span className="ml-1 text-xs text-purple-600">(You)</span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
                          {player.answeredCount}/{totalQuestions} answered
                        </div>
                      </div>
                      
                      {/* Score */}
                      <div className="text-right">
                        <div className="text-lg font-black text-purple-600">
                          {player.score}
                        </div>
                        <div className="text-xs text-gray-500">pts</div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {/* Progress */}
              <div className="mt-6 pt-6 border-t border-gray-200">
                <div className="text-sm text-gray-600 mb-2">Progress</div>
                <div className="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                  <div
                    className="bg-gradient-to-r from-purple-600 to-pink-600 h-3 rounded-full transition-all duration-500"
                    style={{ width: `${(questionNumber / totalQuestions) * 100}%` }}
                  ></div>
                </div>
                <div className="text-xs text-gray-500 mt-1 text-right">
                  {questionNumber}/{totalQuestions} questions
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default GamePage