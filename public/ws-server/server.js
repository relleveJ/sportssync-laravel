const WebSocket = require('ws');

const PORT = process.env.PORT ? parseInt(process.env.PORT, 10) : 3000;
// Optional runtime security config
const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS ? process.env.ALLOWED_ORIGINS.split(',').map(s => s.trim()).filter(Boolean) : [];
const WS_TOKEN = process.env.WS_TOKEN || null; // if set, clients must connect with ?token=...

const wss = new WebSocket.Server({ port: PORT });

wss.on('listening', () => {
  console.log('Sportssync WS server listening on port', PORT);
});

wss.on('error', (err) => {
  if (err && err.code === 'EADDRINUSE') {
    console.error('Error: port', PORT, 'is already in use. Another process is listening on this port.');
    console.error('Please stop the other process or change PORT.');
    process.exit(1);
  }
  console.error('WebSocket server error', err);
  process.exit(1);
});

// Rooms map: match_id -> Set of WebSocket clients
const rooms = new Map();
// Last state cache per room: match_id -> payload object
const lastState = new Map();

function sendRaw(ws, data) {
  const raw = typeof data === 'string' ? data : JSON.stringify(data);
  try { ws.send(raw); } catch (e) { /* ignore */ }
}

// Send to all clients in a room except optional sender
function sendToRoom(roomId, data, sender) {
  if (!roomId) return;
  const set = rooms.get(String(roomId));
  if (!set) return;
  const raw = typeof data === 'string' ? data : JSON.stringify(data);
  set.forEach(function each(client) {
    if (client !== sender && client.readyState === WebSocket.OPEN) {
      try { client.send(raw); } catch (e) { /* ignore individual send errors */ }
    }
  });
}

wss.on('connection', function connection(ws, req) {
  // Basic auth / origin checks
  try {
    const parsed = new URL(req.url, 'http://localhost');
    const token = parsed.searchParams.get('token');
    if (WS_TOKEN && token !== WS_TOKEN) {
      console.warn('WS connection rejected: invalid token', req.socket.remoteAddress);
      try { ws.close(4003, 'auth failed'); } catch (_) {}
      return;
    }
  } catch (_) {}

  const origin = req.headers && (req.headers.origin || req.headers.referer || null);
  if (ALLOWED_ORIGINS.length) {
    if (!origin || !ALLOWED_ORIGINS.includes(origin)) {
      console.warn('WS connection rejected: origin not allowed', origin);
      try { ws.close(4003, 'origin not allowed'); } catch (_) {}
      return;
    }
  }

  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });

  ws.on('message', function incoming(message) {
    // Expect JSON messages with optional { type, match_id, payload }
    try {
      const obj = JSON.parse(message);
      if (!obj || typeof obj !== 'object') return;

      const matchId = obj.match_id != null ? String(obj.match_id) : null;
      // join message: add socket to a room and send last state if available
      if (obj.type === 'join') {
        // remove from previous room if any
        if (ws._room) {
          const prev = rooms.get(ws._room);
          if (prev) prev.delete(ws);
        }
        if (matchId) {
          let set = rooms.get(matchId);
          if (!set) { set = new Set(); rooms.set(matchId, set); }
          set.add(ws);
          ws._room = matchId;
          // send last cached state for this room (if any)
          if (lastState.has(matchId)) {
            const cached = lastState.get(matchId);
            sendRaw(ws, {
              type:    'last_state',
              sport:   cached && cached.sport ? cached.sport : 'state',
              payload: cached && cached.payload ? cached.payload : cached
            });
          }
        } else {
          ws._room = null;
        }
        return;
      }

      // leave message: remove from room
      if (obj.type === 'leave') {
        if (ws._room) {
          const set = rooms.get(ws._room);
          if (set) set.delete(ws);
          ws._room = null;
        }
        return;
      }

      // state message: cache and broadcast within room
      // Supports sport-typed messages: badminton_state, tabletennis_state, basketball_state
      const STATE_TYPES = ['state','badminton_state','tabletennis_state','basketball_state'];
      if (STATE_TYPES.includes(obj.type) && matchId) {
        try {
          lastState.set(matchId, { sport: obj.sport || obj.type, payload: obj.payload || null });
        } catch (_) {}
        // Broadcast with original type so viewers can filter by sport
        sendToRoom(matchId, obj, ws);
        return;
      }

      // Convenience: broadcast any named state without matchId to all clients
      if (STATE_TYPES.includes(obj.type) && !matchId) {
        const raw = JSON.stringify(obj);
        wss.clients.forEach(function each(client) {
          if (client !== ws && client.readyState === WebSocket.OPEN) {
            try { client.send(raw); } catch (_) {}
          }
        });
        return;
      }

      // other typed messages: if matchId present, broadcast within room
      if (matchId) {
        sendToRoom(matchId, obj, ws);
        return;
      }

      // fallback: broadcast to all if no matchId provided
      const raw = typeof message === 'string' ? message : JSON.stringify(message);
      wss.clients.forEach(function each(client) {
        if (client !== ws && client.readyState === WebSocket.OPEN) {
          try { client.send(raw); } catch (e) { /* ignore */ }
        }
      });
    } catch (e) {
      // ignore non-JSON messages or parse errors
    }
  });

  ws.on('close', function () {
    if (ws._room) {
      const set = rooms.get(ws._room);
      if (set) set.delete(ws);
      ws._room = null;
    }
  });
});

// Simple heartbeat to detect broken connections
const interval = setInterval(function ping() {
  wss.clients.forEach(function each(ws) {
    if (ws.isAlive === false) return ws.terminate();
    ws.isAlive = false;
    ws.ping(() => {});
  });
}, 30000);

wss.on('close', function close() {
  clearInterval(interval);
});