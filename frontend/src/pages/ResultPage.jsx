import { useState, useEffect } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'

function ResultPage() {
  const navigate = useNavigate()
  const location = useLocation()
  
  const finalScores = location.state?.finalScores || []
  const winner = location.state?.winner || null
  const gameStats = location.state?.gameStats || {}
  const username = location.state?.username || 'Player'
  
  const [showConfetti, setShowConfetti] = useState(false)
  const [animateIn, setAnimateIn] = useState(false)

  useEffect(() => {
    // Trigger animations
    setTimeout(() => setAnimateIn(true), 100)
    setTimeout(() => setShowConfetti(true), 500)
    
    // Auto hide confetti after 5 seconds
    setTimeout(() => setShowConfetti(false), 5500)
  }, [])

  // Sort scores by rank
  const sortedScores = [...finalScores].sort((a, b) => b.score - a.score)
  
  // Find current player stats
  const myStats = sortedScores.find(s => s.username === username)
  const myRank = sortedScores.findIndex(s => s.username === username) + 1
  
  // Calculate stats
  const totalQuestions = gameStats.totalQuestions || 10
  const correctAnswers = myStats?.correctAnswers || 0
  const accuracy = totalQuestions > 0 ? Math.round((correctAnswers / totalQuestions) * 100) : 0
  const avgResponseTime = gameStats.avgResponseTime || 0
  
  const isWinner = myRank === 1
  const isTopThree = myRank <= 3

  const handlePlayAgain = () => {
    navigate('/', { state: { username } })
  }

  const handleBackToLobby = () => {
    navigate('/lobby', { state: { username } })
  }

  const getRankIcon = (rank) => {
    switch(rank) {
      case 1: return '🥇'
      case 2: return '🥈'
      case 3: return '🥉'
      default: return `#${rank}`
    }
  }

  const getRankColor = (rank) => {
    switch(rank) {
      case 1: return 'from-yellow-400 to-yellow-600'
      case 2: return 'from-gray-300 to-gray-500'
      case 3: return 'from-orange-400 to-orange-600'
      default: return 'from-purple-400 to-pink-400'
    }
  }

  const getPerformanceMessage = () => {
    if (myRank === 1) return 'Outstanding! You are the Champion! 🎉'
    if (myRank === 2) return 'Great job! Second place! 🥈'
    if (myRank === 3) return 'Well done! Third place! 🥉'
    if (accuracy >= 80) return 'Excellent performance! 👏'
    if (accuracy >= 60) return 'Good effort! Keep it up! 💪'
    return 'Nice try! Practice makes perfect! 📚'
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-600 via-pink-600 to-purple-700 p-4 relative overflow-hidden">
      
      {/* Confetti Effect */}
      {showConfetti && (
        <div className="fixed inset-0 pointer-events-none z-50">
          {[...Array(50)].map((_, i) => (
            <div
              key={i}
              className="absolute w-2 h-2 rounded-full animate-confetti"
              style={{
                left: `${Math.random() * 100}%`,
                top: '-20px',
                backgroundColor: ['#fbbf24', '#f59e0b', '#ec4899', '#8b5cf6', '#10b981'][Math.floor(Math.random() * 5)],
                animationDelay: `${Math.random() * 2}s`,
                animationDuration: `${3 + Math.random() * 2}s`
              }}
            />
          ))}
        </div>
      )}

      {/* Animated Background */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-20 left-10 w-96 h-96 bg-yellow-400/20 rounded-full filter blur-3xl animate-pulse"></div>
        <div className="absolute bottom-20 right-10 w-96 h-96 bg-pink-400/20 rounded-full filter blur-3xl animate-pulse" style={{ animationDelay: '1s' }}></div>
      </div>

      <div className={`relative z-10 max-w-6xl mx-auto transition-all duration-1000 ${animateIn ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10'}`}>
        
        {/* Header */}
        <div className="text-center mb-8 pt-8">
          <div className="text-7xl mb-4 animate-bounce">
            {isWinner ? '🏆' : isTopThree ? '🎉' : '🎮'}
          </div>
          <h1 className="text-5xl font-black text-white mb-2 drop-shadow-2xl">
            Game Over!
          </h1>
          <p className="text-white/90 text-2xl font-bold">
            {getPerformanceMessage()}
          </p>
        </div>

        {/* Winner Podium */}
        {sortedScores.length >= 3 && (
          <div className="mb-8 flex items-end justify-center gap-4">
            {/* 2nd Place */}
            <div className="flex flex-col items-center animate-slide-up" style={{ animationDelay: '0.2s' }}>
              <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-4 shadow-xl border-4 border-gray-300 mb-2">
                <div className="text-4xl mb-2">🥈</div>
                <div className="font-black text-gray-700 text-lg truncate max-w-[120px]">
                  {sortedScores[1].username}
                </div>
                <div className="text-2xl font-black text-purple-600">
                  {sortedScores[1].score}
                </div>
              </div>
              <div className="w-32 h-24 bg-gradient-to-b from-gray-300 to-gray-500 rounded-t-lg flex items-center justify-center">
                <span className="text-white font-black text-3xl">2</span>
              </div>
            </div>

            {/* 1st Place - Tallest */}
            <div className="flex flex-col items-center animate-slide-up" style={{ animationDelay: '0.1s' }}>
              <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-6 shadow-2xl border-4 border-yellow-400 mb-2 scale-110">
                <div className="text-5xl mb-2">👑</div>
                <div className="font-black text-gray-800 text-xl truncate max-w-[140px]">
                  {sortedScores[0].username}
                </div>
                <div className="text-3xl font-black text-yellow-600">
                  {sortedScores[0].score}
                </div>
              </div>
              <div className="w-32 h-32 bg-gradient-to-b from-yellow-400 to-yellow-600 rounded-t-lg flex items-center justify-center">
                <span className="text-white font-black text-4xl">1</span>
              </div>
            </div>

            {/* 3rd Place */}
            <div className="flex flex-col items-center animate-slide-up" style={{ animationDelay: '0.3s' }}>
              <div className="bg-white/95 backdrop-blur-lg rounded-2xl p-4 shadow-xl border-4 border-orange-400 mb-2">
                <div className="text-4xl mb-2">🥉</div>
                <div className="font-black text-gray-700 text-lg truncate max-w-[120px]">
                  {sortedScores[2].username}
                </div>
                <div className="text-2xl font-black text-purple-600">
                  {sortedScores[2].score}
                </div>
              </div>
              <div className="w-32 h-20 bg-gradient-to-b from-orange-400 to-orange-600 rounded-t-lg flex items-center justify-center">
                <span className="text-white font-black text-3xl">3</span>
              </div>
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          
          {/* My Performance Card */}
          <div className="lg:col-span-2">
            <div className={`bg-gradient-to-br ${getRankColor(myRank)} p-1 rounded-3xl shadow-2xl mb-6`}>
              <div className="bg-white/95 backdrop-blur-lg rounded-3xl p-6">
                <div className="flex items-center justify-between mb-6">
                  <h2 className="text-2xl font-black text-gray-800">Your Performance</h2>
                  <div className="text-5xl">
                    {getRankIcon(myRank)}
                  </div>
                </div>

                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                  <div className="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-4 text-center">
                    <div className="text-3xl font-black text-purple-600">{myRank}</div>
                    <div className="text-sm text-gray-600 font-medium">Rank</div>
                  </div>
                  <div className="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-4 text-center">
                    <div className="text-3xl font-black text-purple-600">{myStats?.score || 0}</div>
                    <div className="text-sm text-gray-600 font-medium">Points</div>
                  </div>
                  <div className="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-4 text-center">
                    <div className="text-3xl font-black text-purple-600">{accuracy}%</div>
                    <div className="text-sm text-gray-600 font-medium">Accuracy</div>
                  </div>
                  <div className="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-4 text-center">
                    <div className="text-3xl font-black text-purple-600">{correctAnswers}/{totalQuestions}</div>
                    <div className="text-sm text-gray-600 font-medium">Correct</div>
                  </div>
                </div>

                {/* Accuracy Bar */}
                <div className="mt-6">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-bold text-gray-600">Accuracy Level</span>
                    <span className="text-sm font-bold text-purple-600">{accuracy}%</span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                    <div
                      className="bg-gradient-to-r from-purple-500 to-pink-500 h-4 rounded-full transition-all duration-1000"
                      style={{ width: `${accuracy}%` }}
                    ></div>
                  </div>
                </div>
              </div>
            </div>

            {/* Full Leaderboard */}
            <div className="bg-white/95 backdrop-blur-lg rounded-3xl p-6 shadow-2xl">
              <h2 className="text-2xl font-black text-gray-800 mb-4">
                🏆 Final Leaderboard
              </h2>
              
              <div className="space-y-3 max-h-96 overflow-y-auto">
                {sortedScores.map((player, index) => (
                  <div
                    key={index}
                    className={`flex items-center gap-4 p-4 rounded-xl transition-all duration-300 hover:scale-105 ${
                      player.username === username
                        ? 'bg-gradient-to-r from-purple-100 to-pink-100 border-2 border-purple-400 shadow-lg'
                        : 'bg-gray-50 hover:bg-gray-100'
                    }`}
                    style={{
                      animation: 'slideIn 0.5s ease-out',
                      animationDelay: `${index * 0.1}s`,
                      animationFillMode: 'backwards'
                    }}
                  >
                    {/* Rank Badge */}
                    <div className={`w-14 h-14 rounded-full bg-gradient-to-br ${getRankColor(index + 1)} flex items-center justify-center font-black text-white text-xl shadow-lg flex-shrink-0`}>
                      {index < 3 ? getRankIcon(index + 1) : index + 1}
                    </div>
                    
                    {/* Player Info */}
                    <div className="flex-1 min-w-0">
                      <div className="font-black text-gray-800 text-lg truncate">
                        {player.username}
                        {player.username === username && (
                          <span className="ml-2 text-xs bg-purple-500 text-white px-2 py-1 rounded-full">
                            You
                          </span>
                        )}
                      </div>
                      <div className="text-sm text-gray-500">
                        {player.correctAnswers || 0}/{totalQuestions} correct
                        {player.avgTime && (
                          <span className="ml-2">• Avg: {player.avgTime}s</span>
                        )}
                      </div>
                    </div>
                    
                    {/* Score */}
                    <div className="text-right flex-shrink-0">
                      <div className="text-3xl font-black text-purple-600">
                        {player.score}
                      </div>
                      <div className="text-xs text-gray-500 font-medium">points</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Action Sidebar */}
          <div className="space-y-6">
            
            {/* Actions */}
            <div className="bg-white/95 backdrop-blur-lg rounded-3xl p-6 shadow-2xl">
              <h3 className="text-xl font-black text-gray-800 mb-4">What's Next?</h3>
              
              <button
                onClick={handlePlayAgain}
                className="w-full py-4 px-6 bg-gradient-to-r from-purple-600 to-pink-600 text-white font-black text-lg rounded-xl hover:shadow-2xl transition-all duration-300 hover:scale-105 active:scale-95 mb-3"
              >
                🎮 Play Again
              </button>
              
              <button
                onClick={handleBackToLobby}
                className="w-full py-4 px-6 bg-gradient-to-r from-blue-500 to-cyan-500 text-white font-bold text-lg rounded-xl hover:shadow-2xl transition-all duration-300 hover:scale-105 active:scale-95 mb-3"
              >
                🚪 Back to Lobby
              </button>
              
              <button
                onClick={() => navigate('/')}
                className="w-full py-3 px-6 bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold rounded-xl transition-all duration-300"
              >
                🏠 Home
              </button>
            </div>

            {/* Game Stats */}
            <div className="bg-white/95 backdrop-blur-lg rounded-3xl p-6 shadow-2xl">
              <h3 className="text-xl font-black text-gray-800 mb-4">📊 Game Stats</h3>
              
              <div className="space-y-3">
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                  <span className="text-gray-600 font-medium">Total Players</span>
                  <span className="font-bold text-gray-800">{sortedScores.length}</span>
                </div>
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                  <span className="text-gray-600 font-medium">Questions</span>
                  <span className="font-bold text-gray-800">{totalQuestions}</span>
                </div>
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                  <span className="text-gray-600 font-medium">Duration</span>
                  <span className="font-bold text-gray-800">
                    {Math.floor((gameStats.duration || 0) / 60)}:{((gameStats.duration || 0) % 60).toString().padStart(2, '0')}
                  </span>
                </div>
                <div className="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                  <span className="text-gray-600 font-medium">Game Mode</span>
                  <span className="font-bold text-gray-800">Quick Battle</span>
                </div>
              </div>
            </div>

            {/* Achievements (Optional) */}
            {isTopThree && (
              <div className="bg-gradient-to-br from-yellow-400 to-orange-500 rounded-3xl p-6 shadow-2xl text-white">
                <h3 className="text-xl font-black mb-3">🎖️ Achievement Unlocked!</h3>
                <p className="font-bold mb-2">
                  {myRank === 1 ? 'Quiz Master' : myRank === 2 ? 'Runner-up' : 'Bronze Warrior'}
                </p>
                <p className="text-sm text-white/90">
                  You finished in top 3!
                </p>
              </div>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="mt-8 text-center">
          <p className="text-white/70 text-sm">
            Thanks for playing QuizBattle! 🎮
          </p>
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
        
        @keyframes slideUp {
          from {
            opacity: 0;
            transform: translateY(30px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }
        
        @keyframes confetti {
          0% {
            transform: translateY(-20px) rotate(0deg);
            opacity: 1;
          }
          100% {
            transform: translateY(100vh) rotate(720deg);
            opacity: 0;
          }
        }
        
        .animate-slide-up {
          animation: slideUp 0.6s ease-out;
        }
        
        .animate-confetti {
          animation: confetti linear;
        }
      `}</style>
    </div>
  )
}

export default ResultPage