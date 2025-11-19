class WebSocketService {
  constructor() {
    this.ws = null;
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 10;
    this.reconnectInterval = 2000;
    this.messageHandlers = new Map();
    this.pendingMessages = [];
    this.heartbeatInterval = null;
    this.heartbeatTimeout = null;
    this.isIntentionalClose = false;
    this.connectionPromise = null;
  }

  connect() {
    // Nếu đang có kết nối active, trả về promise hiện tại
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      return Promise.resolve(this.ws);
    }

    // Nếu đang trong quá trình kết nối, đợi promise đó
    if (this.connectionPromise) {
      return this.connectionPromise;
    }

    this.connectionPromise = new Promise((resolve, reject) => {
      try {
        console.log('🔌 Connecting to WebSocket server...');
        this.ws = new WebSocket('ws://localhost:8080');
        
        this.ws.onopen = () => {
          console.log('✅ WebSocket connected');
          this.reconnectAttempts = 0;
          this.isIntentionalClose = false;
          this.startHeartbeat();
          this.flushPendingMessages();
          this.connectionPromise = null;
          
          // Emit connection event
          this.emitToAllHandlers({ type: 'connection_established' });
          
          resolve(this.ws);
        };

        this.ws.onmessage = (event) => {
          this.handleMessage(event.data);
        };

        this.ws.onclose = (event) => {
          console.log('🔌 WebSocket disconnected:', event.code, event.reason);
          this.stopHeartbeat();
          this.connectionPromise = null;
          
          // Emit disconnection event
          this.emitToAllHandlers({ type: 'connection_lost' });
          
          // Tự động reconnect nếu không phải disconnect có chủ đích
          if (!this.isIntentionalClose) {
            this.handleReconnect();
          }
        };

        this.ws.onerror = (error) => {
          console.error('❌ WebSocket error:', error);
          this.connectionPromise = null;
          reject(error);
        };

      } catch (error) {
        this.connectionPromise = null;
        reject(error);
      }
    });

    return this.connectionPromise;
  }

  handleMessage(data) {
    try {
      const message = JSON.parse(data);
      console.log('📨 Received:', message.type);
      
      // Reset heartbeat timeout khi nhận được bất kỳ message nào
      this.resetHeartbeatTimeout();
      
      // Xử lý pong từ server
      if (message.type === 'pong') {
        return;
      }
      
      // Gọi tất cả handlers đã đăng ký
      this.emitToAllHandlers(message);
      
      // Gọi handler cụ thể cho type
      if (this.messageHandlers.has(message.type)) {
        const handler = this.messageHandlers.get(message.type);
        handler(message);
      }
      
    } catch (error) {
      console.error('Error parsing message:', error);
    }
  }

  emitToAllHandlers(message) {
    // Gọi global handler nếu có
    if (this.messageHandlers.has('*')) {
      const globalHandler = this.messageHandlers.get('*');
      globalHandler(message);
    }
  }

  startHeartbeat() {
    // Gửi ping mỗi 25 giây
    this.heartbeatInterval = setInterval(() => {
      if (this.isConnected()) {
        this.send({ type: 'ping' });
        // Set timeout để check nếu không nhận pong trong 10s
        this.setHeartbeatTimeout();
      }
    }, 25000);
  }

  setHeartbeatTimeout() {
    this.clearHeartbeatTimeout();
    this.heartbeatTimeout = setTimeout(() => {
      console.warn('⚠️ No pong received, connection may be dead');
      // Đóng connection và trigger reconnect
      if (this.ws) {
        this.ws.close();
      }
    }, 10000);
  }

  resetHeartbeatTimeout() {
    this.clearHeartbeatTimeout();
  }

  clearHeartbeatTimeout() {
    if (this.heartbeatTimeout) {
      clearTimeout(this.heartbeatTimeout);
      this.heartbeatTimeout = null;
    }
  }

  stopHeartbeat() {
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    }
    this.clearHeartbeatTimeout();
  }

  handleReconnect() {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.error('❌ Max reconnection attempts reached');
      this.emitToAllHandlers({ 
        type: 'connection_failed',
        message: 'Unable to reconnect to server'
      });
      return;
    }

    this.reconnectAttempts++;
    const delay = this.reconnectInterval * Math.pow(1.5, this.reconnectAttempts - 1);
    
    console.log(`🔄 Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
    
    setTimeout(() => {
      this.connect()
        .then(() => {
          console.log('✅ Reconnected successfully');
          // Khôi phục session nếu có
          this.restoreSession();
        })
        .catch(error => {
          console.error('❌ Reconnection failed:', error);
        });
    }, delay);
  }

  restoreSession() {
    const state = this.getStoredState();
    if (!state || !state.room || !state.player) {
      return;
    }

    console.log('🔄 Restoring session...');
    
    // Gửi rejoin message
    const rejoinMessage = {
      type: 'rejoin_room',
      player_id: state.player.id,
      room_id: state.room.id,
      player_name: state.player.name
    };
    
    this.send(rejoinMessage);
  }

  send(message) {
    if (this.isConnected()) {
      try {
        this.ws.send(JSON.stringify(message));
        console.log('📤 Sent:', message.type);
      } catch (error) {
        console.error('Error sending message:', error);
        this.pendingMessages.push(message);
      }
    } else {
      console.log('📦 Message queued:', message.type);
      this.pendingMessages.push(message);
      
      // Thử kết nối lại nếu chưa connected
      if (!this.connectionPromise) {
        this.connect().catch(err => {
          console.error('Failed to reconnect for sending:', err);
        });
      }
    }
  }

  flushPendingMessages() {
    console.log(`📬 Flushing ${this.pendingMessages.length} pending messages`);
    while (this.pendingMessages.length > 0) {
      const message = this.pendingMessages.shift();
      this.send(message);
    }
  }

  onMessage(type, handler) {
    this.messageHandlers.set(type, handler);
  }

  offMessage(type) {
    this.messageHandlers.delete(type);
  }

  // Đăng ký global handler để nhận tất cả messages
  onAnyMessage(handler) {
    this.messageHandlers.set('*', handler);
  }

  isConnected() {
    return this.ws && this.ws.readyState === WebSocket.OPEN;
  }

  getConnectionState() {
    if (!this.ws) return 'disconnected';
    
    switch (this.ws.readyState) {
      case WebSocket.CONNECTING: return 'connecting';
      case WebSocket.OPEN: return 'connected';
      case WebSocket.CLOSING: return 'closing';
      case WebSocket.CLOSED: return 'disconnected';
      default: return 'unknown';
    }
  }

  getStoredState() {
    try {
      const state = localStorage.getItem('quizBattleState');
      return state ? JSON.parse(state) : null;
    } catch {
      return null;
    }
  }

  setStoredState(state) {
    try {
      localStorage.setItem('quizBattleState', JSON.stringify(state));
    } catch (error) {
      console.error('Failed to save state:', error);
    }
  }

  clearStoredState() {
    localStorage.removeItem('quizBattleState');
  }

  disconnect() {
    console.log('🔌 Intentional disconnect');
    this.isIntentionalClose = true;
    this.stopHeartbeat();
    
    if (this.ws) {
      this.ws.close(1000, 'Client disconnect');
      this.ws = null;
    }
    
    this.messageHandlers.clear();
    this.pendingMessages = [];
    this.reconnectAttempts = 0;
    this.connectionPromise = null;
  }
}

// Singleton instance
export const webSocketService = new WebSocketService();