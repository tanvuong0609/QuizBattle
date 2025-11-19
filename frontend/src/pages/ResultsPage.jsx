import { useEffect } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { gameStateService } from '../services/GameStateService'
import { webSocketService } from '../services/WebSocketService'

function ResultsPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { scores } = location.state || {}

  useEffect(() => {
    if (!scores) {
      console.warn('⚠️ No scores data, redirecting to home')
      navigate('/')
      return
    }

    // Cleanup game state khi vào results
    // Nhưng vẫn giữ WebSocket connection cho lần chơi tiếp theo
    console.log('🏁 Game finished, results page loaded')

    return () => {
      // Không cần cleanup gì ở đây
    }
  }, [scores, navigate])

  const handlePlayAgain = () => {
    console.log('🔄 Play again clicked')
    
    // Clear game state
    gameStateService.clearState()
    webSocketService.clearStoredState()
    
    // Disconnect WebSocket để bắt đầu fresh connection
    webSocketService.disconnect()
    
    // Navigate về home
    navigate('/')
  }

  const handleGoHome = () => {
    console.log('🏠 Go home clicked')
    
    // Clear game state
    gameStateService.clearState()
    webSocketService.clearStoredState()
    
    // Disconnect WebSocket
    webSocketService.disconnect()
    
    // Navigate về home
    navigate('/')
  }

  const getRankColor = (index) => {
    switch (index) {
      case 0: return 'from-yellow-400 to-yellow-600'
      case 1: return 'from-gray-400 to-gray-600'
      case 2: return 'from-orange-400 to-orange-600'
      default: return 'from-purple-400 to-pink-600'
    }
  }

  const getRankIcon = (index) => {
    switch (index) {
      case 0: return '🥇'
      case 1: return '🥈'
      case 2: return '🥉'
      default: return '🎯'
    }
  }

  const getRankMessage = (index) => {
    switch (index) {
      case 0: return '🎊 Amazing! You are the Quiz Champion!'
      case 1: return '⭐ Great job! You came in second place!'
      case 2: return '🌟 Well done! You came in third place!'
      default: return '💪 Good effort! Keep practicing!'
    }
  }

  const user = gameStateService.getUser()
  const userScore = scores?.find(s => s.player_id === user?.id)
  const userRank = scores?.findIndex(s => s.player_id === user?.id) ?? -1

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-600 to-pink-600 p-4">
      <div className="max-w-4xl mx-auto">
        
        {/* Header */}
        <div className="text-center mb-8 pt-8">
          <div className="inline-block mb-6">
            <div className="text-8xl animate-bounce">🏆</div>
          </div>
          <h1 className="text-6xl font-black text-white mb-4">
            Game Results
          </h1>
          <p className="text-white/80 text-xl">Congratulations on completing the quiz!</p>
        </div>

        {/* Your Score & Rank */}
        <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-8 shadow-2xl mb-8 text-center">
          <h2 className="text-3xl font-black text-gray-800 mb-4">Your Performance</h2>
          
          {/* Rank Badge */}
          <div className="mb-4">
            <div className={`inline-flex items-center justify-center w-20 h-20 rounded-2xl bg-gradient-to-r ${getRankColor(userRank)} text-white text-4xl`}>
              {getRankIcon(userRank)}
            </div>
          </div>

          {/* Score */}
          <div className="text-6xl font-black text-purple-600 mb-2">{userScore?.score || 0}</div>
          <div className="text-gray-600 text-lg mb-4">Total Points</div>
          
          {/* Stats */}
          <div className="grid grid-cols-3 gap-4 mb-4">
            <div className="bg-purple-50 rounded-xl p-3">
              <div className="text-2xl font-black text-purple-600">{userScore?.correct_answers || 0}</div>
              <div className="text-xs text-gray-600">Correct Answers</div>
            </div>
            <div className="bg-purple-50 rounded-xl p-3">
              <div className="text-2xl font-black text-purple-600">#{userRank + 1}</div>
              <div className="text-xs text-gray-600">Your Rank</div>
            </div>
            <div className="bg-purple-50 rounded-xl p-3">
              <div className="text-2xl font-black text-purple-600">{scores?.length || 0}</div>
              <div className="text-xs text-gray-600">Total Players</div>
            </div>
          </div>

          {/* Rank Message */}
          <div className="text-lg font-bold text-purple-600">
            {getRankMessage(userRank)}
          </div>
        </div>

        {/* Leaderboard */}
        <div className="bg-white/95 backdrop-blur-xl rounded-3xl p-8 shadow-2xl mb-8">
          <h2 className="text-3xl font-black text-gray-800 mb-6 text-center">🏅 Leaderboard</h2>
          
          <div className="space-y-4">
            {scores && scores.length > 0 ? (
              scores.map((player, index) => (
                <div
                  key={player.player_id}
                  className={`p-6 rounded-2xl transition-all duration-300 ${
                    player.player_id === user?.id
                      ? 'bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-400 transform scale-105'
                      : 'bg-gray-50 hover:bg-gray-100'
                  }`}
                  style={{
                    animation: 'slideUp 0.5s ease-out',
                    animationDelay: `${index * 0.1}s`,
                    animationFillMode: 'backwards'
                  }}
                >
                  <div className="flex items-center gap-4">
                    {/* Rank */}
                    <div className={`w-16 h-16 rounded-2xl bg-gradient-to-r ${getRankColor(index)} flex items-center justify-center text-white font-black text-xl flex-shrink-0 shadow-lg`}>
                      {getRankIcon(index)}
                    </div>
                    
                    {/* Player Info */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-3 mb-1">
                        <div className="text-xl font-black text-gray-800 truncate">
                          {player.player_name}
                        </div>
                        {player.player_id === user?.id && (
                          <span className="bg-purple-500 text-white px-3 py-1 rounded-full text-xs font-bold flex-shrink-0">
                            You
                          </span>
                        )}
                      </div>
                      <div className="text-sm text-gray-500">
                        {index === 0 ? '👑 Quiz Champion!' : 
                         index === 1 ? '⭐ Runner-up' :
                         index === 2 ? '🌟 Third Place' :
                         `Rank ${index + 1}`}
                      </div>
                    </div>
                    
                    {/* Score & Stats */}
                    <div className="text-right">
                      <div className="text-3xl font-black text-purple-600 mb-1">
                        {player.score}
                      </div>
                      <div className="text-xs text-gray-500">
                        {player.correct_answers || 0} correct
                      </div>
                    </div>
                  </div>
                </div>
              ))
            ) : (
              <div className="text-center py-8 text-gray-500">
                <div className="text-6xl mb-4">📊</div>
                <p>No leaderboard data available</p>
              </div>
            )}
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-col sm:flex-row gap-4 justify-center">
          <button
            onClick={handlePlayAgain}
            className="px-12 py-4 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-black text-xl rounded-2xl hover:shadow-2xl transition-all duration-300 hover:scale-105 active:scale-95 flex items-center justify-center gap-3"
          >
            <span className="text-2xl">🎮</span>
            <span>Play Again</span>
          </button>
          
          <button
            onClick={handleGoHome}
            className="px-12 py-4 bg-white text-purple-600 font-black text-xl rounded-2xl hover:shadow-2xl transition-all duration-300 hover:scale-105 active:scale-95 border-2 border-purple-600 flex items-center justify-center gap-3"
          >
            <span className="text-2xl">🏠</span>
            <span>Go Home</span>
          </button>
        </div>

        {/* Share Results Section */}
        <div className="mt-8 text-center">
          <div className="inline-block bg-white/90 backdrop-blur-lg rounded-2xl px-8 py-4 shadow-lg border border-white/30">
            <p className="text-gray-700 font-semibold mb-2">
              Share your achievement!
            </p>
            <div className="flex gap-3 justify-center">
              <button className="w-12 h-12 rounded-full bg-blue-500 text-white flex items-center justify-center hover:scale-110 transition-transform">
                📘
              </button>
              <button className="w-12 h-12 rounded-full bg-blue-400 text-white flex items-center justify-center hover:scale-110 transition-transform">
                🐦
              </button>
              <button className="w-12 h-12 rounded-full bg-green-500 text-white flex items-center justify-center hover:scale-110 transition-transform">
                💬
              </button>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="mt-8 text-center">
          <div className="inline-block bg-white/90 backdrop-blur-lg rounded-2xl px-6 py-3 shadow-lg border border-white/30">
            <p className="text-gray-700 font-semibold text-sm flex items-center gap-2">
              <span className="text-red-500 animate-pulse">❤️</span>
              Thanks for playing QuizBattle!
              <span className="text-red-500 animate-pulse">❤️</span>
            </p>
          </div>
        </div>
      </div>

      {/* Custom Animations */}
      <style>{`
        @keyframes slideUp {
          from {
            opacity: 0;
            transform: translateY(20px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }
      `}</style>
    </div>
  )
}

export default ResultsPage