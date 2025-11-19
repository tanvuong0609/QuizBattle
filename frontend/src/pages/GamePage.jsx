import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { webSocketService } from '../services/WebSocketService'
import { gameStateService } from '../services/GameStateService'

function GamePage() {
  const navigate = useNavigate()
  const user = gameStateService.getUser()
  const room = gameStateService.getRoom()
  const storedQuestion = gameStateService.getCurrentQuestion()
  
  const [currentQuestion, setCurrentQuestion] = useState(storedQuestion)
  const [selectedAnswer, setSelectedAnswer] = useState(null)
  const [timeRemaining, setTimeRemaining] = useState(storedQuestion?.time_limit || 20)
  const [questionNumber, setQuestionNumber] = useState(1)
  const [totalQuestions] = useState(10)
  const [gamePhase, setGamePhase] = useState(storedQuestion ? 'playing' : 'waiting')
  const [correctAnswerId, setCorrectAnswerId] = useState(null)
  const [hasAnswered, setHasAnswered] = useState(false)
  const [connectionStatus, setConnectionStatus] = useState('checking')
  
  // Scores
  const [scores, setScores] = useState([])
  
  const timerRef = useRef(null)

  useEffect(() => {
    // Kiểm tra user và room
    if (!user || !room) {
      console.warn('⚠️ No user or room data, redirecting to home')
      navigate('/')
      return
    }

    // Kiểm tra WebSocket connection
    if (!webSocketService.isConnected()) {
      console.log('🔌 WebSocket not connected in game, attempting to reconnect...')
      setConnectionStatus('reconnecting')
      
      webSocketService.connect()
        .then(() => {
          console.log('✅ Reconnected in game')
          setConnectionStatus('connected')
          setupWebSocketHandlers()
        })
        .catch(error => {
          console.error('❌ Failed to reconnect in game:', error)
          setConnectionStatus('error')
        })
    } else {
      setConnectionStatus('connected')
      setupWebSocketHandlers()
    }

    return () => {
      webSocketService.offMessage('new_question')
      webSocketService.offMessage('answer_result')
      webSocketService.offMessage('game_finished')
      webSocketService.offMessage('time_update')
      webSocketService.offMessage('scores_update')
      webSocketService.offMessage('connection_lost')
      webSocketService.offMessage('connection_established')
      
      if (timerRef.current) clearInterval(timerRef.current)
    }
  }, [navigate, user, room])

  const setupWebSocketHandlers = () => {
    webSocketService.onMessage('new_question', (data) => {
      console.log('❓ New question received')
      setCurrentQuestion(data.question)
      setTimeRemaining(data.question.time_limit)
      setQuestionNumber(prev => prev + 1)
      setGamePhase('playing')
      setSelectedAnswer(null)
      setCorrectAnswerId(null)
      setHasAnswered(false)
      
      gameStateService.setCurrentQuestion(data.question)
      
      // Update stored state
      const currentState = webSocketService.getStoredState()
      webSocketService.setStoredState({
        ...currentState,
        currentQuestion: data.question,
        gameStatus: 'playing'
      })
    })

    webSocketService.onMessage('answer_result', (data) => {
      console.log('✅ Answer result received')
      setCorrectAnswerId(data.correct_answer)
      setGamePhase('result')
    })

    webSocketService.onMessage('game_finished', (data) => {
      console.log('🏁 Game finished')
      
      // Clear game state nhưng giữ connection
      gameStateService.clearState()
      webSocketService.clearStoredState()
      
      navigate('/result', { state: { scores: data.scores } })
    })

    webSocketService.onMessage('time_update', (data) => {
      setTimeRemaining(data.time_remaining)
    })

    webSocketService.onMessage('scores_update', (data) => {
      console.log('📊 Scores updated')
      setScores(data.scores)
    })

    webSocketService.onMessage('connection_lost', () => {
      console.warn('⚠️ Connection lost in game')
      setConnectionStatus('reconnecting')
    })

    webSocketService.onMessage('connection_established', () => {
      console.log('✅ Connection re-established in game')
      setConnectionStatus('connected')
    })

    // Nếu có stored question, tiếp tục game
    if (storedQuestion && gamePhase === 'waiting') {
      setGamePhase('playing')
    }
  }

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
  }, [gamePhase])

  const handleAnswerSelect = (answerId) => {
    if (gamePhase !== 'playing' || hasAnswered) return
    setSelectedAnswer(answerId)
  }

  const handleSubmitAnswer = () => {
    if (!selectedAnswer || hasAnswered || !webSocketService.isConnected()) return

    setHasAnswered(true)
    setGamePhase('waiting')
    
    console.log('📤 Submitting answer:', selectedAnswer)
    
    // Gửi answer lên server
    const answerMessage = {
      type: 'submit_answer',
      question_id: currentQuestion.id,
      answer_id: selectedAnswer
    }
    webSocketService.send(answerMessage)
  }

  const handleTimeUp = () => {
    if (hasAnswered) return
    
    console.log('⏰ Time up!')
    setGamePhase('waiting')
    setHasAnswered(true)
    
    console.log('⏰ Time up')
    
    // Báo server time up
    const timeUpMessage = {
      type: 'time_up'
    }
    webSocketService.send(timeUpMessage)
  }

  // ==================== UI Helpers ====================

  const getTimerColor = () => {
    const percentage = (timeRemaining / (currentQuestion?.time_limit || 20)) * 100
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
    
    return baseClass + "bg-white text-gray-800 border-gray-300 hover:border-purple-400 hover:bg-purple-50 hover:scale-105 active:scale-95 cursor-pointer"
  }

  const getSyncStatusColor = () => {
    switch (syncStatus) {
      case 'synced': return 'text-green-500'
      case 'syncing': return 'text-yellow-500'
      case 'error': return 'text-red-500'
      default: return 'text-gray-500'
    }
  }

  const getSyncStatusText = () => {
    switch (syncStatus) {
      case 'synced': return '✓ Synced'
      case 'syncing': return '⟳ Syncing...'
      case 'error': return '✗ Out of sync'
      default: return '?'
    }
  }

  const sortedScores = [...scores].sort((a, b) => b.score - a.score)

  // Hiển thị reconnecting overlay nếu đang reconnect
  if (connectionStatus === 'reconnecting') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-purple-600 to-pink-600">
        <div className="text-center">
          <div className="text-white text-6xl mb-4 animate-spin">🔄</div>
          <div className="text-white text-2xl font-bold">Reconnecting...</div>
          <div className="text-white/70 text-sm mt-2">Please wait while we restore your game</div>
        </div>
      </div>
    )
  }

  if (!currentQuestion) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-purple-600 to-pink-600">
        <div className="text-center">
          <div className="text-6xl mb-4 animate-bounce">🎉</div>
          <div className="text-white text-3xl font-bold">Game Finished!</div>
          <div className="text-white/80 text-lg mt-2">Calculating results...</div>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-600 to-pink-600 p-4">
      <div className="max-w-7xl mx-auto">
        
        {/* Connection Status Indicator */}
        {connectionStatus !== 'connected' && (
          <div className="fixed top-4 right-4 z-50">
            <div className="px-4 py-2 bg-yellow-500 text-white rounded-full shadow-lg font-bold text-sm animate-pulse">
              🟠 Reconnecting...
            </div>
          </div>
        )}
        
        {/* Header */}
        <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-4 mb-4 shadow-lg">
          <div className="flex items-center justify-between flex-wrap gap-4">
            <div className="flex items-center gap-4">
              <div className="text-2xl font-black text-purple-600">
                Question {questionNumber}/{totalQuestions}
              </div>
              
              {/* Connection Status */}
              <div className={`text-xs font-bold ${getSyncStatusColor()}`}>
                {getSyncStatusText()}
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
                Your Score: <span className="text-2xl font-black text-purple-600">
                  {scores.find(s => s.player_id === user.id)?.score || 0}
                </span>
              </div>
            </div>
          </div>
          
          {/* Error Banner */}
          {wsError && (
            <div className="mt-2 bg-red-50 border border-red-200 rounded-lg p-2 text-red-600 text-sm">
              ⚠️ {wsError}
            </div>
          )}
        </div>

        {/* Main Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
          
          {/* Main Game Area */}
          <div className="lg:col-span-3 space-y-4">
            
            {/* Timer Circle */}
            <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-8 shadow-lg">
              <div className="flex items-center justify-center mb-6">
                <div className="relative w-32 h-32">
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
                      strokeDashoffset={`${2 * Math.PI * 56 * (1 - timeRemaining / (currentQuestion.time_limit || 20))}`}
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
                {gamePhase === 'result' && earnedPoints > 0 && (
                  <div className="text-green-600 font-bold text-xl animate-bounce">
                    🎉 Correct! +100 points
                  </div>
                )}
                {gamePhase === 'result' && selectedAnswer && selectedAnswer !== correctAnswerId && (
                  <div className="text-red-600 font-bold text-xl">
                    ❌ Wrong answer
                  </div>
                )}
                {gamePhase === 'result' && !selectedAnswer && (
                  <div className="text-gray-600 font-bold text-xl">
                    ⏰ Time out
                  </div>
                )}
              </div>

              {/* Answer Buttons */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                {currentQuestion.answers.map((answer, index) => (
                  <button
                    key={answer.id}
                    onClick={() => handleAnswerSelect(answer.id)}
                    disabled={gamePhase !== 'playing' || hasAnswered}
                    disabled={gamePhase !== 'playing' || hasAnswered}
                    className={getAnswerClass(answer.id)}
                  >
                    <div className="flex items-center gap-3">
                      <span className="w-8 h-8 rounded-full bg-purple-600 text-white flex items-center justify-center font-black flex-shrink-0">
                        {String.fromCharCode(65 + index)}
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
                  disabled={!selectedAnswer || hasAnswered || connectionStatus !== 'connected'}
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
                    key={player.player_id}
                    className={`p-3 rounded-xl transition-all duration-300 ${
                      player.player_id === user.id
                        ? 'bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-400'
                        : 'bg-gray-50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <div className={`w-8 h-8 rounded-full flex items-center justify-center font-black text-sm flex-shrink-0 ${
                        index === 0 ? 'bg-yellow-400 text-yellow-900' :
                        index === 1 ? 'bg-gray-300 text-gray-700' :
                        index === 2 ? 'bg-orange-400 text-orange-900' :
                        'bg-gray-200 text-gray-600'
                      }`}>
                        {index + 1}
                      </div>
                      
                      <div className="flex-1 min-w-0">
                        <div className="font-bold text-gray-800 truncate text-sm">
                          {player.player_name}
                          {player.player_id === user.id && (
                            <span className="ml-1 text-xs text-purple-600">(You)</span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
                          Correct: {player.correct_answers || 0}
                        </div>
                      </div>
                      
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