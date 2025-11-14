import { useState, useEffect, useRef } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'

function GamePage() {
  const navigate = useNavigate()
  const location = useLocation()
  const username = location.state?.username || 'Player'
  const players = location.state?.players || []
  const gameId = location.state?.gameId || 'demo-game'
  
  // Game State
  const [currentQuestion, setCurrentQuestion] = useState(null)
  const [selectedAnswer, setSelectedAnswer] = useState(null)
  const [timeRemaining, setTimeRemaining] = useState(20)
  const [questionNumber, setQuestionNumber] = useState(0)
  const [totalQuestions, setTotalQuestions] = useState(10)
  const [gamePhase, setGamePhase] = useState('loading') // loading, playing, waiting, result, finished
  const [correctAnswerId, setCorrectAnswerId] = useState(null)
  const [hasAnswered, setHasAnswered] = useState(false)
  const [myScore, setMyScore] = useState(0)
  const [earnedPoints, setEarnedPoints] = useState(0)
  
  // WebSocket State
  const [wsConnected, setWsConnected] = useState(false)
  const [wsError, setWsError] = useState(null)
  const [syncStatus, setSyncStatus] = useState('syncing') // syncing, synced, error
  
  // Scores - synchronized with server
  const [scores, setScores] = useState(
    players.map(p => ({ 
      username: p.username, 
      score: 0, 
      answeredCount: 0,
      lastAnswerTime: null 
    }))
  )
  
  // Refs
  const wsRef = useRef(null)
  const timerRef = useRef(null)
  const heartbeatRef = useRef(null)
  const reconnectTimeoutRef = useRef(null)
  const reconnectAttempts = useRef(0)
  const maxReconnectAttempts = 5

  // ==================== WebSocket Setup ====================
  
  useEffect(() => {
    connectWebSocket()

    return () => {
      cleanup()
    }
  }, [])

  const connectWebSocket = () => {
    try {
      const wsUrl = import.meta.env.VITE_WS_URL || 'ws://localhost:8080'
      
      console.log('🔌 Connecting to WebSocket:', wsUrl)
      setSyncStatus('syncing')
      setWsError(null)
      
      wsRef.current = new WebSocket(wsUrl)

      // Connection timeout
      const connectionTimeout = setTimeout(() => {
        if (wsRef.current?.readyState !== WebSocket.OPEN) {
          console.error('⏰ Connection timeout')
          wsRef.current?.close()
          handleConnectionError('Connection timeout')
        }
      }, 10000)

      wsRef.current.onopen = () => {
        clearTimeout(connectionTimeout)
        console.log('✅ WebSocket connected')
        setWsConnected(true)
        setSyncStatus('synced')
        reconnectAttempts.current = 0
        
        // Send join game message
        sendMessage('JOIN_GAME', {
          username,
          gameId,
          timestamp: Date.now()
        })
        
        // Start heartbeat
        startHeartbeat()
      }

      wsRef.current.onmessage = (event) => {
        try {
          const message = JSON.parse(event.data)
          handleWebSocketMessage(message)
        } catch (error) {
          console.error('❌ Failed to parse message:', error)
        }
      }

      wsRef.current.onerror = (error) => {
        clearTimeout(connectionTimeout)
        console.error('❌ WebSocket error:', error)
        handleConnectionError('WebSocket error occurred')
      }

      wsRef.current.onclose = (event) => {
        clearTimeout(connectionTimeout)
        console.log('🔌 WebSocket disconnected:', event.code, event.reason)
        setWsConnected(false)
        setSyncStatus('error')
        stopHeartbeat()
        
        // Attempt reconnect if not intentional close
        if (event.code !== 1000 && gamePhase !== 'finished') {
          handleReconnect()
        }
      }

    } catch (error) {
      console.error('❌ Failed to create WebSocket:', error)
      handleConnectionError('Failed to connect')
    }
  }

  const handleConnectionError = (errorMessage) => {
    setWsError(errorMessage)
    setSyncStatus('error')
    setWsConnected(false)
    
    // Try reconnect
    if (gamePhase !== 'finished') {
      handleReconnect()
    }
  }

  const handleReconnect = () => {
    if (reconnectAttempts.current >= maxReconnectAttempts) {
      setWsError('Unable to reconnect. Please refresh the page.')
      return
    }

    reconnectAttempts.current++
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts.current), 10000)
    
    console.log(`🔄 Reconnecting in ${delay}ms (attempt ${reconnectAttempts.current}/${maxReconnectAttempts})`)
    setWsError(`Reconnecting... (${reconnectAttempts.current}/${maxReconnectAttempts})`)
    
    reconnectTimeoutRef.current = setTimeout(() => {
      connectWebSocket()
    }, delay)
  }

  const sendMessage = (type, payload) => {
    if (wsRef.current?.readyState === WebSocket.OPEN) {
      const message = {
        type,
        payload: {
          ...payload,
          username,
          gameId,
          timestamp: Date.now()
        }
      }
      wsRef.current.send(JSON.stringify(message))
      console.log('📤 Sent:', type, payload)
    } else {
      console.warn('⚠️ WebSocket not connected, cannot send:', type)
      setSyncStatus('error')
    }
  }

  const startHeartbeat = () => {
    heartbeatRef.current = setInterval(() => {
      sendMessage('HEARTBEAT', {})
    }, 30000) // Every 30 seconds
  }

  const stopHeartbeat = () => {
    if (heartbeatRef.current) {
      clearInterval(heartbeatRef.current)
      heartbeatRef.current = null
    }
  }

  const cleanup = () => {
    if (wsRef.current) {
      wsRef.current.close(1000, 'Component unmounting')
      wsRef.current = null
    }
    if (timerRef.current) {
      clearInterval(timerRef.current)
    }
    if (heartbeatRef.current) {
      clearInterval(heartbeatRef.current)
    }
    if (reconnectTimeoutRef.current) {
      clearTimeout(reconnectTimeoutRef.current)
    }
  }

  // ==================== WebSocket Event Handlers ====================

  const handleWebSocketMessage = (message) => {
    console.log('📩 Received:', message.type)
    
    // Update sync status
    setSyncStatus('synced')
    
    switch (message.type) {
      case 'QUESTION':
        handleQuestionEvent(message.payload)
        break
      
      case 'ANSWER_RESULT':
        handleAnswerResultEvent(message.payload)
        break
      
      case 'GAME_END':
        handleGameEndEvent(message.payload)
        break
      
      case 'PLAYER_ANSWERED':
        handlePlayerAnsweredEvent(message.payload)
        break
      
      case 'SCORE_UPDATE':
        handleScoreUpdateEvent(message.payload)
        break
      
      case 'SYNC_STATE':
        handleSyncStateEvent(message.payload)
        break
      
      case 'ERROR':
        handleErrorEvent(message.payload)
        break
      
      case 'PONG':
        // Heartbeat response
        break
      
      default:
        console.warn('⚠️ Unknown message type:', message.type)
    }
  }

  // Handle "QUESTION" event - Hiển thị câu hỏi mới
  const handleQuestionEvent = (payload) => {
    console.log('📝 New question received:', payload.questionNumber)
    
    // Stop previous timer
    if (timerRef.current) {
      clearInterval(timerRef.current)
    }
    
    // Update question state
    setCurrentQuestion({
      id: payload.id || payload.questionId,
      question: payload.question,
      answers: payload.answers,
      timeLimit: payload.timeLimit || 20
    })
    
    setQuestionNumber(payload.questionNumber)
    setTotalQuestions(payload.totalQuestions || 10)
    setTimeRemaining(payload.timeLimit || 20)
    setSelectedAnswer(null)
    setCorrectAnswerId(null)
    setHasAnswered(false)
    setEarnedPoints(0)
    setGamePhase('playing')
    
    // Start new timer
    startTimer(payload.timeLimit || 20)
  }

  // Handle "ANSWER_RESULT" event - Hiển thị kết quả
  const handleAnswerResultEvent = (payload) => {
    console.log('✅ Answer result received:', payload)
    
    // Stop timer
    if (timerRef.current) {
      clearInterval(timerRef.current)
    }
    
    setGamePhase('result')
    setCorrectAnswerId(payload.correctAnswerId)
    
    // Update my score
    const myResult = payload.results?.find(r => r.username === username)
    if (myResult) {
      setMyScore(myResult.totalScore)
      setEarnedPoints(myResult.earnedPoints || 0)
    }
    
    // Update all scores (server is source of truth)
    if (payload.scores) {
      setScores(payload.scores)
    }
    
    // Auto proceed to next question after 3 seconds
    setTimeout(() => {
      setGamePhase('waiting')
    }, 3000)
  }

  // Handle "GAME_END" event - Hiển thị kết quả cuối
  const handleGameEndEvent = (payload) => {
    console.log('🏁 Game ended:', payload)
    
    // Stop all timers
    if (timerRef.current) {
      clearInterval(timerRef.current)
    }
    
    setGamePhase('finished')
    
    // Navigate to result page after 2 seconds
    setTimeout(() => {
      navigate('/result', {
        state: {
          finalScores: payload.finalScores || scores,
          winner: payload.winner,
          gameStats: payload.stats,
          username
        }
      })
    }, 2000)
  }

  // Handle player answered notification
  const handlePlayerAnsweredEvent = (payload) => {
    console.log('👤 Player answered:', payload.username)
    
    // Update player status in scores
    setScores(prev => prev.map(player => 
      player.username === payload.username
        ? { ...player, answeredCount: player.answeredCount + 1, lastAnswerTime: Date.now() }
        : player
    ))
  }

  // Handle score update
  const handleScoreUpdateEvent = (payload) => {
    console.log('📊 Score update received')
    
    if (payload.scores) {
      setScores(payload.scores)
    }
    
    if (payload.myScore !== undefined) {
      setMyScore(payload.myScore)
    }
  }

  // Handle sync state (for reconnection)
  const handleSyncStateEvent = (payload) => {
    console.log('🔄 Syncing state from server')
    
    // Restore game state from server
    if (payload.currentQuestion) {
      handleQuestionEvent(payload.currentQuestion)
    }
    
    if (payload.scores) {
      setScores(payload.scores)
    }
    
    if (payload.phase) {
      setGamePhase(payload.phase)
    }
    
    setSyncStatus('synced')
  }

  // Handle error
  const handleErrorEvent = (payload) => {
    console.error('❌ Server error:', payload.message)
    setWsError(payload.message)
    
    if (payload.fatal) {
      // Fatal error, go back to lobby
      setTimeout(() => {
        navigate('/lobby', { state: { username, error: payload.message } })
      }, 2000)
    }
  }

  // ==================== Timer Logic ====================

  const startTimer = (duration) => {
    let timeLeft = duration
    
    timerRef.current = setInterval(() => {
      timeLeft--
      setTimeRemaining(timeLeft)
      
      if (timeLeft <= 0) {
        clearInterval(timerRef.current)
        handleTimeUp()
      }
    }, 1000)
  }

  const handleTimeUp = () => {
    if (hasAnswered) return
    
    console.log('⏰ Time up!')
    setGamePhase('waiting')
    setHasAnswered(true)
    
    // Send timeout to server
    sendMessage('ANSWER_TIMEOUT', {
      questionId: currentQuestion.id
    })
  }

  // ==================== Answer Handling ====================

  const handleAnswerSelect = (answerId) => {
    if (gamePhase !== 'playing' || hasAnswered) return
    setSelectedAnswer(answerId)
  }

  const handleSubmitAnswer = () => {
    if (!selectedAnswer || hasAnswered || gamePhase !== 'playing') return

    setHasAnswered(true)
    setGamePhase('waiting')
    
    // Stop timer
    if (timerRef.current) {
      clearInterval(timerRef.current)
    }
    
    // Send answer to server
    sendMessage('SUBMIT_ANSWER', {
      questionId: currentQuestion.id,
      answerId: selectedAnswer,
      timeSpent: currentQuestion.timeLimit - timeRemaining
    })
    
    console.log('📤 Answer submitted:', selectedAnswer)
  }

  // ==================== UI Helpers ====================

  const getTimerColor = () => {
    if (!currentQuestion) return '#10b981'
    const percentage = (timeRemaining / currentQuestion.timeLimit) * 100
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

  // ==================== Render ====================

  if (gamePhase === 'loading' || !currentQuestion) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-purple-600 to-pink-600">
        <div className="text-center">
          <div className="text-6xl mb-4 animate-bounce">⏳</div>
          <div className="text-white text-2xl font-bold mb-2">
            {wsConnected ? 'Waiting for questions...' : 'Connecting to server...'}
          </div>
          {wsError && (
            <div className="text-red-300 text-sm mt-2">{wsError}</div>
          )}
        </div>
      </div>
    )
  }

  if (gamePhase === 'finished') {
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
                Your Score: <span className="text-2xl font-black text-purple-600">{myScore}</span>
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
                {gamePhase === 'result' && earnedPoints > 0 && (
                  <div className="text-green-600 font-bold text-xl animate-bounce">
                    🎉 Correct! +{earnedPoints} points
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
                          {player.username}
                          {player.username === username && (
                            <span className="ml-1 text-xs text-purple-600">(You)</span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
                          {player.answeredCount}/{totalQuestions} answered
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