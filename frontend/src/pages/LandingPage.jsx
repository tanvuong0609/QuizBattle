import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { webSocketService } from '../services/WebSocketService'
import { gameStateService } from '../services/GameStateService'

function LandingPage() {
  const navigate = useNavigate()
  const [username, setUsername] = useState(gameStateService.getUser()?.username || '')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const [isVisible, setIsVisible] = useState(false)
  const [connectionStatus, setConnectionStatus] = useState('disconnected')

  useEffect(() => {
    setTimeout(() => setIsVisible(true), 100)
    
    // Kiểm tra recovery
    if (gameStateService.shouldRecover()) {
      console.log('🔄 Recovery data available')
      // Tự động kết nối lại
      handleRecoverGame()
    }

    // Cleanup khi unmount
    return () => {
      // Không disconnect ở đây để giữ kết nối
      webSocketService.offMessage('room_joined')
      webSocketService.offMessage('error')
      webSocketService.offMessage('connection_established')
      webSocketService.offMessage('connection_lost')
    }
  }, [])

  const validateUsername = (value) => {
    if (!value) return 'Tên người chơi không được để trống'
    if (value.length < 3) return 'Tên người chơi phải có ít nhất 3 ký tự'
    if (value.length > 20) return 'Tên người chơi không được vượt quá 20 ký tự'
    if (!/^[a-zA-Z0-9_-]+$/.test(value)) {
      return 'Chỉ cho phép chữ cái, số, _ và - trong tên người chơi'
    }
    return ''
  }

  const handleUsernameChange = (e) => {
    const value = e.target.value
    setUsername(value)
    if (value) {
      setError(validateUsername(value))
    } else {
      setError('')
    }
  }

  const handleQuickPlay = async () => {
    const validationError = validateUsername(username)
    if (validationError) {
      setError(validationError)
      return
    }

    setLoading(true)
    setError('')
    
    try {
      // Bước 1: Lưu thông tin user trước
      gameStateService.setUser({ username, joinedAt: Date.now() })
      
      // Bước 2: Kết nối WebSocket
      console.log('🔌 Establishing WebSocket connection...')
      setConnectionStatus('connecting')
      
      await webSocketService.connect()
      
      console.log('✅ WebSocket connected, joining room...')
      setConnectionStatus('connected')
      
      // Bước 3: Đăng ký handlers
      setupMessageHandlers()
      
      // Bước 4: Gửi join message
      const joinMessage = {
        type: 'join_room',
        player_name: username,
        is_recovery: false
      }
      
      webSocketService.send(joinMessage)
      
    } catch (error) {
      console.error('❌ Connection failed:', error)
      setError('Không thể kết nối đến server. Vui lòng thử lại!')
      setConnectionStatus('error')
      setLoading(false)
    }
  }

  const setupMessageHandlers = () => {
    // Handler khi join room thành công
    webSocketService.onMessage('room_joined', (data) => {
      console.log('✅ Room joined:', data.room_code)
      
      // Lưu thông tin room và player
      gameStateService.setRoom(data.room)
      gameStateService.setUser(data.player)
      
      // Lưu vào WebSocket state để recovery
      webSocketService.setStoredState({
        room: data.room,
        player: data.player
      })
      
      // Chuyển sang lobby
      setLoading(false)
      navigate('/lobby')
    })
    
    // Handler khi có lỗi
    webSocketService.onMessage('error', (data) => {
      console.error('❌ Server error:', data.message)
      setError(data.message || 'Có lỗi xảy ra')
      setConnectionStatus('error')
      setLoading(false)
    })

    // Handler khi mất kết nối
    webSocketService.onMessage('connection_lost', () => {
      setConnectionStatus('reconnecting')
    })

    // Handler khi kết nối lại
    webSocketService.onMessage('connection_established', () => {
      setConnectionStatus('connected')
    })
  }

  const handleRecoverGame = async () => {
    if (!gameStateService.shouldRecover()) return
    
    const recoveryData = gameStateService.getRecoveryData()
    setUsername(recoveryData.user?.username || '')
    setLoading(true)
    
    try {
      console.log('🔄 Recovering game session...')
      setConnectionStatus('connecting')
      
      await webSocketService.connect()
      
      console.log('✅ WebSocket connected, restoring session...')
      setConnectionStatus('connected')
      
      // Setup handlers cho recovery
      setupRecoveryHandlers()
      
      // WebSocket service sẽ tự động gửi rejoin message
      
    } catch (error) {
      console.error('❌ Recovery failed:', error)
      setError('Khôi phục game thất bại. Vui lòng bắt đầu game mới.')
      gameStateService.clearState()
      webSocketService.clearStoredState()
      setConnectionStatus('error')
      setLoading(false)
    }
  }

  const setupRecoveryHandlers = () => {
    webSocketService.onMessage('room_joined', (data) => {
      console.log('✅ Room rejoined:', data.room_code)
      
      gameStateService.setRoom(data.room)
      
      setLoading(false)
      
      // Kiểm tra trạng thái game
      if (data.room.status === 'playing') {
        navigate('/game')
      } else {
        navigate('/lobby')
      }
    })
    
    webSocketService.onMessage('game_state', (data) => {
      console.log('🎮 Game state received')
      gameStateService.setGame(data.game)
      
      if (data.game.status === 'playing') {
        navigate('/game')
      } else {
        navigate('/lobby')
      }
      
      setLoading(false)
    })
    
    webSocketService.onMessage('error', (data) => {
      console.error('❌ Recovery error:', data.message)
      // Nếu recovery thất bại, xóa state và bắt đầu mới
      gameStateService.clearState()
      webSocketService.clearStoredState()
      setError(data.message || 'Không thể khôi phục game. Vui lòng bắt đầu mới.')
      setConnectionStatus('error')
      setLoading(false)
    })
  }

  const handleKeyPress = (e) => {
    if (e.key === 'Enter' && !error && username && !loading) {
      handleQuickPlay()
    }
  }

  const getConnectionStatusDisplay = () => {
    switch (connectionStatus) {
      case 'connecting':
        return { text: '🟡 Đang kết nối...', color: 'text-yellow-500' }
      case 'connected':
        return { text: '🟢 Đã kết nối', color: 'text-green-500' }
      case 'reconnecting':
        return { text: '🟠 Đang kết nối lại...', color: 'text-orange-500' }
      case 'error':
        return { text: '🔴 Lỗi kết nối', color: 'text-red-500' }
      default:
        return { text: '⚪ Chưa kết nối', color: 'text-gray-500' }
    }
  }

  const statusDisplay = getConnectionStatusDisplay()

  return (
    <div className="min-h-screen relative overflow-hidden" style={{
      background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
    }}>
      {/* Animated Background Shapes */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-0 left-0 w-96 h-96 bg-purple-400/30 rounded-full mix-blend-multiply filter blur-3xl animate-blob"></div>
        <div className="absolute top-0 right-0 w-96 h-96 bg-yellow-400/30 rounded-full mix-blend-multiply filter blur-3xl animate-blob animation-delay-2000"></div>
        <div className="absolute bottom-0 left-1/2 w-96 h-96 bg-pink-400/30 rounded-full mix-blend-multiply filter blur-3xl animate-blob animation-delay-4000"></div>
      </div>

      {/* Floating Particles */}
      <div className="absolute inset-0 pointer-events-none">
        {[...Array(20)].map((_, i) => (
          <div
            key={i}
            className="absolute w-2 h-2 bg-white/20 rounded-full"
            style={{
              left: `${Math.random() * 100}%`,
              top: `${Math.random() * 100}%`,
              animation: `float ${5 + Math.random() * 10}s ease-in-out infinite`,
              animationDelay: `${Math.random() * 5}s`
            }}
          />
        ))}
      </div>

      {/* Recovery Banner */}
      {gameStateService.shouldRecover() && !loading && (
        <div className="relative z-20">
          <div className="bg-yellow-500 text-white text-center py-3 px-4 mb-4 mx-4 rounded-xl shadow-lg">
            <p className="font-bold">🔄 Game đang chờ bạn!</p>
            <button
              onClick={handleRecoverGame}
              className="mt-2 px-4 py-2 bg-white text-yellow-600 rounded-lg font-bold hover:bg-gray-100 transition-colors"
            >
              Tiếp tục chơi
            </button>
          </div>
        </div>
      )}

      {/* Connection Status Indicator */}
      {connectionStatus !== 'disconnected' && (
        <div className="fixed top-4 right-4 z-50">
          <div className={`px-4 py-2 bg-white/90 backdrop-blur-lg rounded-full shadow-lg ${statusDisplay.color} font-bold text-sm`}>
            {statusDisplay.text}
          </div>
        </div>
      )}

      {/* Main Content */}
      <div className="relative z-10 min-h-screen flex items-center justify-center p-4">
        <div className={`w-full max-w-md transition-all duration-1000 ${isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'}`}>
          
          {/* Header */}
          <div className="text-center mb-8">
            {/* Animated Icon */}
            <div className="inline-block mb-6 relative">
              <div className="absolute inset-0 bg-yellow-300 blur-2xl opacity-50 animate-pulse"></div>
              <div className="relative text-8xl animate-bounce" style={{ animationDuration: '2s' }}>
                ⚔️
              </div>
            </div>
            
            {/* Title */}
            <h1 className="text-6xl font-black mb-4 text-white drop-shadow-2xl">
              Quiz<span className="text-yellow-300">Battle</span>
            </h1>
            
            <div className="flex items-center justify-center gap-2 mb-3">
              <div className="h-1 w-12 bg-yellow-300 rounded-full"></div>
              <div className="h-1 w-8 bg-white/50 rounded-full"></div>
              <div className="h-1 w-12 bg-yellow-300 rounded-full"></div>
            </div>
            
            <p className="text-white/90 text-xl font-medium">
              🎯 Test your knowledge in real-time battles!
            </p>
          </div>

          {/* Main Card */}
          <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-8 shadow-2xl border border-white/20">
            
            {/* Username Input Section */}
            <div className="mb-6">
              <label className="flex items-center gap-2 text-gray-700 font-bold mb-3 text-sm uppercase tracking-wider">
                <span className="text-xl">👤</span>
                Your Battle Name
              </label>
              
              <div className="relative">
                <input
                  type="text"
                  value={username}
                  onChange={handleUsernameChange}
                  onKeyPress={handleKeyPress}
                  placeholder="Enter username..."
                  disabled={loading}
                  className={`w-full px-5 py-4 text-lg border-2 rounded-xl transition-all duration-300 focus:outline-none focus:ring-4 placeholder:text-gray-400 ${
                    error
                      ? 'border-red-400 focus:border-red-500 focus:ring-red-100 bg-red-50'
                      : username
                      ? 'border-green-400 focus:border-green-500 focus:ring-green-100 bg-green-50'
                      : 'border-gray-300 focus:border-purple-500 focus:ring-purple-100 bg-white'
                  } disabled:bg-gray-100 disabled:cursor-not-allowed`}
                />
                
                {/* Input Icon */}
                {username && !error && (
                  <div className="absolute right-4 top-1/2 -translate-y-1/2 text-green-500 text-2xl animate-bounce">
                    ✓
                  </div>
                )}
              </div>
              
              {/* Error Message */}
              {error && (
                <div className="mt-3 flex items-start gap-2 text-red-600 text-sm font-medium bg-red-50 p-3 rounded-lg border border-red-200">
                  <span className="text-lg flex-shrink-0">⚠️</span>
                  <span>{error}</span>
                </div>
              )}
              
              {/* Character Count */}
              {username && !error && (
                <div className="mt-2 text-right text-xs text-gray-500">
                  {username.length} / 20 characters
                </div>
              )}
            </div>

            {/* Quick Play Button */}
            <button
              onClick={handleQuickPlay}
              disabled={!!error || !username || loading}
              className="w-full py-5 px-6 text-xl font-black text-white rounded-xl transition-all duration-300 transform hover:scale-105 hover:shadow-2xl active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none relative overflow-hidden group"
              style={{
                background: loading ? '#9ca3af' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
              }}
            >
              {/* Button Shimmer Effect */}
              <div className="absolute inset-0 -translate-x-full group-hover:translate-x-full transition-transform duration-1000 bg-gradient-to-r from-transparent via-white/30 to-transparent"></div>
              
              <span className="relative flex items-center justify-center gap-3">
                {loading ? (
                  <>
                    <svg className="animate-spin h-7 w-7" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Connecting to Server...</span>
                  </>
                ) : (
                  <>
                    <span className="text-2xl">🎮</span>
                    <span>START BATTLE</span>
                    <span className="text-2xl">⚡</span>
                  </>
                )}
              </span>
            </button>

            {/* Keyboard Shortcut Hint */}
            {username && !error && !loading && (
              <div className="mt-4 text-center text-sm text-gray-500 flex items-center justify-center gap-2">
                <span>Press</span>
                <kbd className="px-3 py-1.5 bg-gray-200 rounded-lg text-xs font-mono font-bold shadow-sm border border-gray-300">
                  Enter ↵
                </kbd>
                <span>to start</span>
              </div>
            )}

            {/* Divider */}
            <div className="my-6 flex items-center gap-4">
              <div className="flex-1 h-px bg-gradient-to-r from-transparent to-gray-300"></div>
              <span className="text-gray-400 text-sm font-medium">Game Features</span>
              <div className="flex-1 h-px bg-gradient-to-l from-transparent to-gray-300"></div>
            </div>

            {/* Features Grid */}
            <div className="grid grid-cols-2 gap-3">
              {[
                { icon: '⚡', title: 'Real-time', color: 'yellow' },
                { icon: '🎯', title: 'Competitive', color: 'red' },
                { icon: '🏆', title: 'Leaderboard', color: 'purple' },
                { icon: '🌐', title: 'Multiplayer', color: 'blue' },
              ].map((feature, i) => (
                <div
                  key={i}
                  className="flex items-center gap-2 p-3 rounded-lg bg-gradient-to-br from-gray-50 to-gray-100 border border-gray-200 hover:shadow-md transition-all duration-300 hover:-translate-y-1"
                >
                  <span className="text-2xl">{feature.icon}</span>
                  <span className="font-semibold text-gray-700 text-sm">{feature.title}</span>
                </div>
              ))}
            </div>
          </div>

          {/* Stats Section */}
          <div className="mt-6 grid grid-cols-3 gap-4">
            {[
              { label: 'Players', value: '500+', icon: '👥' },
              { label: 'Questions', value: '300+', icon: '❓' },
              { label: 'Active Now', value: '127', icon: '🟢' },
            ].map((stat, i) => (
              <div
                key={i}
                className="bg-white/90 backdrop-blur-lg rounded-2xl p-4 text-center shadow-lg border border-white/30 hover:bg-white transition-all duration-300 hover:-translate-y-1"
              >
                <div className="text-3xl mb-1">{stat.icon}</div>
                <div className="text-2xl font-black text-purple-600">{stat.value}</div>
                <div className="text-xs text-gray-600 font-medium uppercase tracking-wide">{stat.label}</div>
              </div>
            ))}
          </div>

          {/* Footer */}
          <div className="mt-8 text-center">
            <div className="inline-block bg-white/90 backdrop-blur-lg rounded-2xl px-6 py-3 shadow-lg border border-white/30">
              <p className="text-gray-700 font-semibold text-sm flex items-center gap-2">
                <span className="text-red-500 animate-pulse">❤️</span>
                Nhóm 6
                <span className="text-red-500 animate-pulse">❤️</span>
              </p>
              <p className="text-gray-500 text-xs mt-1">Lập trình mạng</p>
            </div>
          </div>
        </div>
      </div>

      {/* Custom Animations */}
      <style>{`
        @keyframes blob {
          0%, 100% { transform: translate(0, 0) scale(1); }
          25% { transform: translate(20px, -50px) scale(1.1); }
          50% { transform: translate(-20px, 20px) scale(0.9); }
          75% { transform: translate(50px, 50px) scale(1.05); }
        }
        
        @keyframes float {
          0%, 100% { transform: translateY(0px); }
          50% { transform: translateY(-20px); }
        }
        
        .animate-blob {
          animation: blob 7s infinite;
        }
        
        .animation-delay-2000 {
          animation-delay: 2s;
        }
        
        .animation-delay-4000 {
          animation-delay: 4s;
        }
      `}</style>
    </div>
  )
}

export default LandingPage