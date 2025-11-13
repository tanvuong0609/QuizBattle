<<<<<<< Updated upstream
=======
import { useState, useEffect, useRef } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'

function LobbyPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const username = location.state?.username || 'Player'
  
  const [players, setPlayers] = useState([{ username, isReady: true, joinedAt: Date.now() }])
  const [countdown, setCountdown] = useState(null)
  const [wsStatus, setWsStatus] = useState('connecting') // connecting, connected, disconnected, error
  const [errorMessage, setErrorMessage] = useState('')
  const [roomCode] = useState(() => Math.random().toString(36).substring(2, 8).toUpperCase())
  
  const wsRef = useRef(null)
  const reconnectTimeoutRef = useRef(null)
  const reconnectAttempts = useRef(0)
  const maxReconnectAttempts = 5

  navigate('/game', { state: { username, players } })

  // WebSocket Setup
  useEffect(() => {
    connectWebSocket()

    return () => {
      if (wsRef.current) {
        wsRef.current.close()
      }
      if (reconnectTimeoutRef.current) {
        clearTimeout(reconnectTimeoutRef.current)
      }
    }
  }, [])

  const connectWebSocket = () => {
    try {
      // NOTE: Thay đổi URL này khi có backend thật
      const wsUrl = import.meta.env.VITE_WS_URL || 'ws://localhost:8080'
      
      setWsStatus('connecting')
      setErrorMessage('')
      
      // Simulate WebSocket connection
      // Khi có backend thật, uncomment dòng dưới:
      // wsRef.current = new WebSocket(wsUrl)
      
      // TEMPORARY: Simulate connection success
      setTimeout(() => {
        setWsStatus('connected')
        reconnectAttempts.current = 0
        simulatePlayersJoining() // Demo: thêm người chơi fake
      }, 1000)

      // Khi có backend thật, setup các event handlers:
      /*
      wsRef.current.onopen = () => {
        console.log('✅ WebSocket connected')
        setWsStatus('connected')
        reconnectAttempts.current = 0
        
        // Send join room message
        wsRef.current.send(JSON.stringify({
          type: 'JOIN_ROOM',
          payload: { username, roomCode }
        }))
      }

      wsRef.current.onmessage = (event) => {
        const message = JSON.parse(event.data)
        handleWebSocketMessage(message)
      }

      wsRef.current.onerror = (error) => {
        console.error('❌ WebSocket error:', error)
        setWsStatus('error')
        setErrorMessage('Connection error occurred')
      }

      wsRef.current.onclose = () => {
        console.log('🔌 WebSocket disconnected')
        setWsStatus('disconnected')
        handleReconnect()
      }
      */

    } catch (error) {
      console.error('Failed to connect:', error)
      setWsStatus('error')
      setErrorMessage('Failed to connect to server')
      handleReconnect()
    }
  }

  const handleReconnect = () => {
    if (reconnectAttempts.current >= maxReconnectAttempts) {
      setErrorMessage('Unable to connect. Please refresh the page.')
      return
    }

    reconnectAttempts.current++
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts.current), 10000)
    
    setErrorMessage(`Reconnecting... (Attempt ${reconnectAttempts.current}/${maxReconnectAttempts})`)
    
    reconnectTimeoutRef.current = setTimeout(() => {
      connectWebSocket()
    }, delay)
  }

  const handleWebSocketMessage = (message) => {
    switch (message.type) {
      case 'PLAYER_JOINED':
        setPlayers(prev => [...prev, message.payload])
        break
      
      case 'PLAYER_LEFT':
        setPlayers(prev => prev.filter(p => p.username !== message.payload.username))
        break
      
      case 'ROOM_UPDATE':
        setPlayers(message.payload.players)
        break
      
      case 'GAME_STARTING':
        setCountdown(message.payload.countdown)
        break
      
      case 'GAME_START':
        navigate('/game', { state: { gameId: message.payload.gameId, username } })
        break
      
      default:
        console.log('Unknown message type:', message.type)
    }
  }

  // DEMO: Simulate players joining (xóa khi có backend thật)
  const simulatePlayersJoining = () => {
    const demoPlayers = [
      'AliceWonder', 'BobBuilder', 'CharlieChoco', 'DianaDream'
    ]
    
    let count = 0
    const interval = setInterval(() => {
      if (count >= 3) {
        clearInterval(interval)
        // Start countdown when enough players
        startCountdown()
        return
      }
      
      setPlayers(prev => [...prev, {
        username: demoPlayers[count],
        isReady: Math.random() > 0.3,
        joinedAt: Date.now()
      }])
      count++
    }, 2000)
  }

  // Countdown logic
  const startCountdown = () => {
    let count = 5
    setCountdown(count)
    
    const interval = setInterval(() => {
      count--
      setCountdown(count)
      
      if (count <= 0) {
        clearInterval(interval)
        // Navigate to game
        setTimeout(() => {
          navigate('/game', { state: { username, players } })
        }, 500)
      }
    }, 1000)
  }

  const handleLeaveRoom = () => {
    if (wsRef.current && wsRef.current.readyState === WebSocket.OPEN) {
      wsRef.current.send(JSON.stringify({
        type: 'LEAVE_ROOM',
        payload: { username }
      }))
    }
    navigate('/')
  }

  const getStatusColor = () => {
    switch (wsStatus) {
      case 'connected': return 'text-green-500'
      case 'connecting': return 'text-yellow-500'
      case 'disconnected': return 'text-orange-500'
      case 'error': return 'text-red-500'
      default: return 'text-gray-500'
    }
  }

  const getStatusText = () => {
    switch (wsStatus) {
      case 'connected': return '🟢 Connected'
      case 'connecting': return '🟡 Connecting...'
      case 'disconnected': return '🟠 Disconnected'
      case 'error': return '🔴 Connection Error'
      default: return '⚪ Unknown'
    }
  }

  const minPlayers = 2
  const maxPlayers = 8
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
                    Players ({players.length}/{maxPlayers})
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
                      key={index}
                      className="flex items-center gap-3 p-4 rounded-xl bg-gradient-to-br from-purple-50 to-pink-50 border-2 border-purple-100 hover:border-purple-300 transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
                      style={{
                        animation: 'slideIn 0.3s ease-out',
                        animationDelay: `${index * 0.1}s`,
                        animationFillMode: 'backwards'
                      }}
                    >
                      {/* Avatar */}
                      <div className="w-12 h-12 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                        {player.username[0].toUpperCase()}
                      </div>
                      
                      {/* Info */}
                      <div className="flex-1 min-w-0">
                        <div className="font-bold text-gray-800 truncate">
                          {player.username}
                          {player.username === username && (
                            <span className="ml-2 text-xs bg-purple-500 text-white px-2 py-0.5 rounded-full">
                              You
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-gray-500">
                          Joined {Math.floor((Date.now() - player.joinedAt) / 1000)}s ago
                        </div>
                      </div>
                      
                      {/* Ready Status */}
                      <div className="flex-shrink-0">
                        {player.isReady ? (
                          <span className="text-green-500 text-2xl">✓</span>
                        ) : (
                          <span className="text-gray-400 text-2xl animate-pulse">⏳</span>
                        )}
                      </div>
                    </div>
                  ))}
                  
                  {/* Empty Slots */}
                  {players.length < maxPlayers && (
                    Array.from({ length: maxPlayers - players.length }).map((_, i) => (
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
              <button
                onClick={handleLeaveRoom}
                className="w-full py-4 px-6 bg-red-500 hover:bg-red-600 text-white font-bold rounded-xl transition-all duration-300 hover:shadow-lg hover:scale-105 active:scale-95"
              >
                🚪 Leave Room
              </button>
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
>>>>>>> Stashed changes
