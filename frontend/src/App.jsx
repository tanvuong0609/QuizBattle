import { BrowserRouter, Routes, Route } from 'react-router-dom'
import LandingPage from './pages/LandingPage'
import LobbyPage from './pages/LobbyPage'
import GamePage from './pages/GamePage'
import ResultsPage from './pages/ResultsPage'
import './App.css'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/lobby" element={<LobbyPage />} />
        <Route path="/game" element={<GamePage />} />
        <Route path="/result" element={<ResultsPage />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App