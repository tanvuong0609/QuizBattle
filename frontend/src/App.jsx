import { BrowserRouter, Routes, Route } from 'react-router-dom'
<<<<<<< Updated upstream
import LandingPage from './pages/LandingPage'
import LobbyPage from './pages/LobbyPage'
=======
import LandingPage from './pages/LandingPage.jsx'
import LobbyPage from './pages/LobbyPage.jsx'
import GamePage from './pages/GamePage.jsx'
import './App.css'
>>>>>>> Stashed changes

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/lobby" element={<LobbyPage />} />
        <Route path="/game" element={<GamePage />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App