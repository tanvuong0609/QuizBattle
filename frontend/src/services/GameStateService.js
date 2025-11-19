class GameStateService {
  constructor() {
    this.state = this.loadState();
  }

  loadState() {
    try {
      return JSON.parse(localStorage.getItem('quizBattleAppState') || '{}');
    } catch {
      return {};
    }
  }

  saveState(newState) {
    this.state = { ...this.state, ...newState };
    try {
      localStorage.setItem('quizBattleAppState', JSON.stringify(this.state));
    } catch (error) {
      console.error('Failed to save game state:', error);
    }
  }

  clearState() {
    this.state = {};
    localStorage.removeItem('quizBattleAppState');
    localStorage.removeItem('quizBattleState'); // Clear WebSocket state too
  }

  getState() {
    return this.state;
  }

  // Specific state helpers
  setUser(userData) {
    this.saveState({ user: userData });
  }

  getUser() {
    return this.state.user;
  }

  setRoom(roomData) {
    this.saveState({ room: roomData });
  }

  getRoom() {
    return this.state.room;
  }

  setGame(gameData) {
    this.saveState({ game: gameData });
  }

  getGame() {
    return this.state.game;
  }

  setCurrentQuestion(question) {
    this.saveState({ currentQuestion: question });
  }

  getCurrentQuestion() {
    return this.state.currentQuestion;
  }

  // Recovery methods
  shouldRecover() {
    return !!(this.state.room && this.state.user);
  }

  getRecoveryData() {
    return {
      user: this.state.user,
      room: this.state.room,
      game: this.state.game,
      currentQuestion: this.state.currentQuestion
    };
  }
}

export const gameStateService = new GameStateService();