function LandingPage() {
  return (
    <div
      className="min-h-screen flex items-center justify-center"
      style={{
        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
      }}
    >
      <div className="text-center text-white px-4">
        <h1 className="text-6xl font-black mb-4 drop-shadow-2xl">
          Quiz<span className="text-yellow-300">Battle</span>
        </h1>
        <p className="text-xl font-medium mb-8">
          🎯 Test your knowledge in real-time battles!
        </p>
        <div className="max-w-sm mx-auto">
          <input
            type="text"
            placeholder="Enter username..."
            className="w-full px-5 py-4 text-lg rounded-xl text-gray-800 placeholder-gray-400 border-2 border-gray-200 focus:outline-none"
            disabled
          />
        </div>
        <button
          className="mt-6 px-8 py-4 bg-white/20 rounded-xl font-bold text-lg hover:bg-white/30 transition-all"
          disabled
        >
          START BATTLE ⚡
        </button>
        <div className="mt-10 text-sm text-white/70">
          Made by Your Team ❤️ <br />
          <span className="text-xs">Network Programming Project 2025</span>
        </div>
      </div>
    </div>
  )
}

export default LandingPage
