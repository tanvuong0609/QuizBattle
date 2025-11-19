import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { webSocketService } from '../services/WebSocketService'
import { gameStateService } from '../services/GameStateService'

function LobbyPage() {
  const navigate = useNavigate()
  const user = gameStateService.getUser()
  const room = gameStateService.getRoom()
  
  const [players, setPlayers] = useState(room?.playerDetails || [])
  const [countdown, setCountdown] = useState(null)
  const [connectionStatus, setConnectionStatus] = useState('checking')
  const [errorMessage, setErrorMessage] = useState('')
  const [roomCode] = useState(room?.id || '')
  const [countdownInterval, setCountdownInterval] = useState(null)

  useEffect(() => {
    // Kiểm tra user và room
    if (!user || !room) {
      console.warn('⚠️ No user or room data, redirecting to home')
      navigate('/')
      return
    }

    // Kiểm tra WebSocket connection
    if (!webSocketService.isConnected()) {
      console.log('🔌 WebSocket not connected, attempting to reconnect...')
      setConnectionStatus('reconnecting')
      
      webSocketService.connect()
        .then(() => {
          console.log('✅ Reconnected in lobby')
          setConnectionStatus('connected')
          setupWebSocketHandlers()
        })
        .catch(error => {
          console.error('❌ Failed to reconnect:', error)
          setConnectionStatus('error')
          setErrorMessage('Không thể kết nối đến server')
        })
    } else {
      setConnectionStatus('connected')
      setupWebSocketHandlers()
    }
    
    // Khôi phục players từ room data
    if (room.playerDetails) {
      setPlayers(room.playerDetails)
    }

    return () => {
      // Cleanup handlers nhưng GIỮ kết nối
      webSocketService.offMessage('player_joined')
      webSocketService.offMessage('player_left')
      webSocketService.offMessage('player_rejoined')
      webSocketService.offMessage('game_starting')
      webSocketService.offMessage('new_question')
      webSocketService.offMessage('room_updated')
      webSocketService.offMessage('error')
      webSocketService.offMessage('connection_lost')
      webSocketService.offMessage('connection_established')
      
      // Clear countdown interval
      if (countdownInterval) {
        clearInterval(countdownInterval)
      }
    }
  }, [navigate, user, room])

  const setupWebSocketHandlers = () => {
    // Handler khi có player mới join
    webSocketService.onMessage('player_joined', (data) => {
      console.log('👤 Player joined:', data.player?.name)
      if (data.room?.playerDetails) {
        setPlayers(data.room.playerDetails)
        gameStateService.setRoom(data.room)
        
        // Update stored state
        webSocketService.setStoredState({
          room: data.room,
          player: user
        })
      }
    })

    // Handler khi có player rời đi
    webSocketService.onMessage('player_left', (data) => {
      console.log('👋 Player left')
      if (data.room?.playerDetails) {
        setPlayers(data.room.playerDetails)
        gameStateService.setRoom(data.room)
        
        // Update stored state
        webSocketService.setStoredState({
          room: data.room,
          player: user
        })
      }
    })

    // Handler khi có player reconnect
    webSocketService.onMessage('player_rejoined', (data) => {
      console.log('🔄 Player rejoined:', data.player?.name)
      if (data.room?.playerDetails) {
        setPlayers(data.room.playerDetails)
        gameStateService.setRoom(data.room)
      }
    })

    // Handler khi game bắt đầu
    webSocketService.onMessage('game_starting', (data) => {
      console.log('🎮 Game starting in', data.countdown, 'seconds')
      setCountdown(data.countdown || 5)
      
      // Start countdown
      let count = data.countdown || 5
      const interval = setInterval(() => {
        count--
        setCountdown(count)
        
        if (count <= 0) {
          clearInterval(interval)
          // Sẽ nhận new_question sau đó
        }
      }, 1000)
      
      setCountdownInterval(interval)
    })

    // Handler khi nhận câu hỏi đầu tiên
    webSocketService.onMessage('new_question', (data) => {
      console.log('❓ First question received')
      gameStateService.setCurrentQuestion(data.question)
      
      // Update stored state với current question
      const currentState = webSocketService.getStoredState()
      webSocketService.setStoredState({
        ...currentState,
        currentQuestion: data.question,
        gameStatus: 'playing'
      })
      
      navigate('/game')
    })

    // Handler khi room được update
    webSocketService.onMessage('room_updated', (data) => {
      console.log('🔄 Room updated')
      if (data.room?.playerDetails) {
        setPlayers(data.room.playerDetails)
        gameStateService.setRoom(data.room)
      }
    })

    // Handler khi có lỗi
    webSocketService.onMessage('error', (data) => {
      console.error('❌ Error:', data.message)
      setErrorMessage(data.message)
    })

    // Handler khi mất kết nối
    webSocketService.onMessage('connection_lost', () => {
      console.warn('⚠️ Connection lost in lobby')
      setConnectionStatus('reconnecting')
      setErrorMessage('Đang kết nối lại...')
    })

    // Handler khi kết nối lại thành công
    webSocketService.onMessage('connection_established', () => {
      console.log('✅ Connection re-established in lobby')
      setConnectionStatus('connected')
      setErrorMessage('')
      
      // WebSocket service sẽ tự động rejoin room
    })
  }

  const handleStartGame = () => {
    if (!webSocketService.isConnected()) {
      setErrorMessage('Không có kết nối đến server')
      return
    }

    console.log('🎮 Starting game...')
    const startMessage = {
      type: 'start_game',
      room_id: room.id
    }
    webSocketService.send(startMessage)
  }

  const handleLeaveRoom = () => {
    console.log('🚪 Leaving room...')
    
    const leaveMessage = {
      type: 'leave_room',
      room_id: room.id,
      player_id: user.id
    }
    webSocketService.send(leaveMessage)
    
    // Clear state
    gameStateService.clearState()
    webSocketService.clearStoredState()
    
    // Disconnect WebSocket khi rời phòng
    webSocketService.disconnect()
    
    navigate('/')
  }

  const getStatusColor = () => {
    switch (connectionStatus) {
      case 'connected': return 'text-green-500'
      case 'connecting': 
      case 'checking': 
      case 'reconnecting': return 'text-yellow-500'
      case 'error': return 'text-red-500'
      default: return 'text-gray-500'
    }
  }

  const getStatusText = () => {
    switch (connectionStatus) {
      case 'connected': return '🟢 Connected'
      case 'connecting': return '🟡 Connecting...'
      case 'checking': return '🟡 Checking connection...'
      case 'reconnecting': return '🟠 Reconnecting...'
      case 'error': return '🔴 Connection Error'
      default: return '⚪ Unknown'
    }
  }

  const minPlayers = 2
  const isEnoughPlayers = players.length >= minPlayers

  return (
    <div className="min-h-screen relative overflow-hidden" style={{
      background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    }}>
      {/* Animated Background */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-20 left-10 w-72 h-72 bg-purple-400/20 rounded-full filter blur-3xl animate-pulse"></div>
        <div className="absolute bottom-20 right-10 w-96 h-96 bg-pink-400/20 rounded-full filter blur-3xl animate-pulse" style={{ animationDelay: '1s' }}></div>
      </div>

      {/* Main Content */}
      <div className="relative z-10 min-h-screen p-4">
        <div className="max-w-4xl mx-auto">
          
          {/* Header */}
          <div className="text-center mb-6 pt-8">
            <div className="inline-block mb-4">
              <div className="text-6xl animate-bounce" style={{ animationDuration: '2s' }}>🎮</div>
            </div>
            <h1 className="text-5xl font-black text-white mb-2">
              Battle Lobby
            </h1>
            <p className="text-white/80 text-lg">Waiting for players to join...</p>
          </div>

          {/* Connection Status Bar */}
          <div className="mb-4">
            <div className="bg-white/90 backdrop-blur-lg rounded-2xl p-4 shadow-lg">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <span className={`font-bold ${getStatusColor()}`}>
                    {getStatusText()}
                  </span>
                  {errorMessage && (
                    <span className="text-red-600 text-sm">• {errorMessage}</span>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-gray-600 text-sm font-medium">Room Code:</span>
                  <code className="bg-purple-100 text-purple-700 px-3 py-1 rounded-lg font-mono font-bold">
                    {roomCode}
                  </code>
                </div>
              </div>
            </div>
          </div>

          {/* Countdown Overlay */}
          {countdown !== null && (
            <div className="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center">
              <div className="text-center">
                <div className="text-9xl font-black text-white mb-4 animate-bounce">
                  {countdown}
                </div>
                <p className="text-3xl text-white font-bold">Game Starting...</p>
              </div>
            </div>
          )}

          {/* Main Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            {/* Players List */}
            <div className="lg:col-span-2">
              <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-6 shadow-2xl">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-2xl font-black text-gray-800">
                    Players ({players.length}/4)
                  </h2>
                  {!isEnoughPlayers && (
                    <span className="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-sm font-bold animate-pulse">
                      Waiting for more players
                    </span>
                  )}
                </div>

                {/* Players Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-96 overflow-y-auto">
                  {players.map((player, index) => (
                    <div
                      key={player.id || index}
                      className="flex items-center gap-3 p-4 rounded-xl bg-gradient-to-br from-purple-50 to-pink-50 border-2 border-purple-100 hover:border-purple-300 transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
                      style={{
                        animation: 'slideIn 0.3s ease-out',
                        animationDelay: `${index * 0.1}s`,
                        animationFillMode: 'backwards'
                      }}
                    >
                      {/* Avatar */}
                      <div className="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                        {player.name[0].toUpperCase()}
                      </div>
                      
                      {/* Info */}
                      <div className="flex-1 min-w-0">
                        <div className="font-bold text-gray-800 truncate">
                          {player.name}
                          {player.id === user.id && (
                            <span className="ml-2 text-xs bg-purple-500 text-white px-2 py-0.5 rounded-full">
                              You
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
                          {player.joinedAt && `Joined ${Math.floor((Date.now() - new Date(player.joinedAt).getTime()) / 1000)}s ago`}
                        </div>
                      </div>
                      
                      {/* Connection Status */}
                      <div className="flex-shrink-0">
                        {player.connected ? (
                          <span className="text-green-500 text-2xl" title="Connected">✓</span>
                        ) : (
                          <span className="text-gray-400 text-2xl animate-pulse" title="Disconnected">⏳</span>
                        )}
                      </div>
                    </div>
                  ))}
                  
                  {/* Empty Slots */}
                  {players.length < 4 && (
                    Array.from({ length: 4 - players.length }).map((_, i) => (
                      <div
                        key={`empty-${i}`}
                        className="flex items-center justify-center p-4 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50"
                      >
                        <span className="text-gray-400 text-sm font-medium">
                          Waiting for player...
                        </span>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>

            {/* Sidebar */}
            <div className="space-y-6">
              
              {/* Game Info */}
              <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-6 shadow-2xl">
                <h3 className="text-xl font-black text-gray-800 mb-4">Game Info</h3>
                <div className="space-y-3">
                  <div className="flex items-center justify-between p-3 bg-purple-50 rounded-xl">
                    <span className="text-gray-600 font-medium">Mode</span>
                    <span className="font-bold text-purple-600">Quick Battle</span>
                  </div>
                  <div className="flex items-center justify-between p-3 bg-purple-50 rounded-xl">
                    <span className="text-gray-600 font-medium">Questions</span>
                    <span className="font-bold text-purple-600">10</span>
                  </div>
                  <div className="flex items-center justify-between p-3 bg-purple-50 rounded-xl">
                    <span className="text-gray-600 font-medium">Time/Q</span>
                    <span className="font-bold text-purple-600">15s</span>
                  </div>
                  <div className="flex items-center justify-between p-3 bg-purple-50 rounded-xl">
                    <span className="text-gray-600 font-medium">Difficulty</span>
                    <span className="font-bold text-purple-600">Mixed</span>
                  </div>
                </div>
              </div>

              {/* Rules */}
              <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-6 shadow-2xl">
                <h3 className="text-xl font-black text-gray-800 mb-4">📋 Rules</h3>
                <ul className="space-y-2 text-sm text-gray-600">
                  <li className="flex items-start gap-2">
                    <span className="text-green-500 font-bold">✓</span>
                    <span>Answer quickly for bonus points</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="text-green-500 font-bold">✓</span>
                    <span>First to answer gets +50 points</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="text-green-500 font-bold">✓</span>
                    <span>Wrong answer = no points</span>
                  </li>
                  <li className="flex items-start gap-2">
                    <span className="text-green-500 font-bold">✓</span>
                    <span>Highest score wins!</span>
                  </li>
                </ul>
              </div>

              {/* Actions */}
              <div className="space-y-3">
                <button
                  onClick={handleStartGame}
                  disabled={!isEnoughPlayers || connectionStatus !== 'connected'}
                  className="w-full py-4 px-6 bg-green-500 hover:bg-green-600 disabled:bg-gray-400 text-white font-bold rounded-xl transition-all duration-300 hover:shadow-lg hover:scale-105 active:scale-95 disabled:cursor-not-allowed disabled:scale-100"
                >
                  🚀 Start Game
                </button>
                
                <button
                  onClick={handleLeaveRoom}
                  className="w-full py-4 px-6 bg-red-500 hover:bg-red-600 text-white font-bold rounded-xl transition-all duration-300 hover:shadow-lg hover:scale-105 active:scale-95"
                >
                  🚪 Leave Room
                </button>
              </div>
            </div>
          </div>

          {/* Footer Info */}
          <div className="mt-6 text-center">
            <p className="text-white/70 text-sm">
              {isEnoughPlayers 
                ? '✅ Game will start when all players are ready' 
                : `⏳ Waiting for ${minPlayers - players.length} more player(s)`
              }
            </p>
          </div>
        </div>
      </div>

      {/* Custom Animations */}
      <style>{`
        @keyframes slideIn {
          from {
            opacity: 0;
            transform: translateX(-20px);
          }
          to {
            opacity: 1;
            transform: translateX(0);
          }
        }
      `}</style>
    </div>
  )
}

export default LobbyPage