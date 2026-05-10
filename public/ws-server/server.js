const WebSocket = require('ws');
const http = require('http');

const PORT = process.env.PORT ? parseInt(process.env.PORT, 10) : 3000;
const ALLOWED_ORIGINS = [];  // Empty list: allow all origins
const WS_TOKEN = null;  // Disabled: allow all connections

// Create a single HTTP server and attach the WebSocket server to it so we can
// expose a small HTTP hook (`/emit`) that server-side PHP can call to request
// broadcasts (e.g. `new_match`) without relying on an admin browser client.
const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/emit') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      try {
        const obj = JSON.parse(body || '{}');
        console.log('[/emit] Received broadcast: type=' + obj.type + ' match_id=' + obj.match_id + ' timestamp=' + new Date().toISOString());
        // Simple token check: allow header `x-ws-token` or `token` in JSON body
        // Removed token check to allow any admin user with admin role to access and update
        // const tokenHeader = (req.headers['x-ws-token'] || req.headers['x-ws-token'.toLowerCase()]) || null;
        // if (WS_TOKEN && tokenHeader !== WS_TOKEN && obj.token !== WS_TOKEN) {
        //   res.writeHead(403, { 'Content-Type': 'application/json' });
        //   res.end(JSON.stringify({ success: false, message: 'invalid token' }));
        //   return;
        // }
        // Hand off to the same message handler used for WS clients
        handleMessageObject(obj, null);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true }));
      } catch (e) {
        console.error('[/emit] JSON parse error:', e.message);
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: 'invalid json' }));
      }
    });
    return;
  }
  // For any other request, return 404
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ success: false, message: 'not found' }));
});

const wss = new WebSocket.Server({ server });

server.listen(PORT, () => {
  console.log('Sportssync WS+HTTP server listening on port', PORT);
});

server.on('error', (err) => {
  if (err && err.code === 'EADDRINUSE') {
    console.error('Error: port', PORT, 'is already in use. Another process is listening on this port.');
    console.error('Please stop the other process or change PORT.');
    process.exit(1);
  }
  console.error('HTTP server error', err);
  process.exit(1);
});

wss.on('error', (err) => {
  console.error('WebSocket server error', err);
});

// Rooms map: match_id -> Set of WebSocket clients
const rooms = new Map();
// Admin clients: Set of WebSocket clients with admin role
const adminClients = new Set();
// Last state cache per room: match_id -> payload object
const lastState = new Map();

function sendRaw(ws, data) {
  const raw = typeof data === 'string' ? data : JSON.stringify(data);
  try { ws.send(raw); } catch (e) { /* ignore */ }
}

// Send to all admin clients
function sendToAdmins(data, sender) {
  const raw = typeof data === 'string' ? data : JSON.stringify(data);
  adminClients.forEach(function each(client) {
    if (client !== sender && client.readyState === WebSocket.OPEN) {
      try { client.send(raw); } catch (e) { /* ignore individual send errors */ }
    }
  });
}

// Send to all clients in a room, optionally excluding the sender.
function sendToRoom(matchId, data, sender) {
  if (matchId == null) return;
  const roomSet = rooms.get(String(matchId));
  if (!roomSet) return;
  const raw = typeof data === 'string' ? data : JSON.stringify(data);
  roomSet.forEach(function each(client) {
    if (client === sender) return;
    if (client.readyState === WebSocket.OPEN) {
      try { client.send(raw); } catch (e) { /* ignore individual send errors */ }
    }
  });
}

// Centralized handler: accepts an object message and an optional sender (ws)
function handleMessageObject(obj, sender) {
  try {
    if (!obj || typeof obj !== 'object') return;
    // ensure a top-level timestamp exists for ordering on clients
    try { if (typeof obj.ts !== 'number') obj.ts = Date.now(); } catch (e) { obj.ts = Date.now(); }
    const matchId = obj.match_id != null ? String(obj.match_id) : null;
    const ackId = obj._msg_id || obj._msgId || obj.msg_id || null;
    if (ackId && sender) {
      try { sendRaw(sender, { type: 'ack', ack_id: ackId }); } catch (e) {}
    }

    // join message: add socket to a room and send last state if available
    if (obj.type === 'join') {
      if (!sender) return;
      console.log('Client joined match_id:', matchId, 'from', sender._remoteAddress);
      // Check if client has admin role
      const isAdmin = obj.role === 'admin' || obj.role === 'superadmin';
      if (isAdmin) {
        adminClients.add(sender);
        sender._isAdmin = true;
      } else {
        // Remove from admin clients if role changed
        adminClients.delete(sender);
        sender._isAdmin = false;
      }
      if (sender._room) {
        const prev = rooms.get(sender._room);
        if (prev) prev.delete(sender);
      }
      if (matchId) {
        let set = rooms.get(matchId);
        if (!set) { set = new Set(); rooms.set(matchId, set); }
        set.add(sender);
        sender._room = matchId;
        if (lastState.has(matchId)) {
          const cached = lastState.get(matchId);
          sendRaw(sender, {
            type:    'applied_action',            match_id: matchId,            sport:   cached && cached.sport ? cached.sport : 'state',
            payload: cached && cached.payload ? cached.payload : cached,
            ts:      cached && cached.ts ? cached.ts : Date.now()
          });
        }
      } else {
        sender._room = null;
      }
      return;
    }

    // action message: translate to 'applied_action' and broadcast within room
    if (obj.type === 'action') {
      if (matchId) {
        try {
          const out = { type: 'applied_action', sport: obj.sport || 'volleyball', match_id: matchId, payload: obj.payload || null, meta: obj.meta || null };
          sendToRoom(matchId, out, sender);
        } catch (_) {}
        return;
      }
      // no matchId: broadcast to all
      try {
        const out = { type: 'applied_action', sport: obj.sport || 'volleyball', payload: obj.payload || null, meta: obj.meta || null };
        const raw = JSON.stringify(out);
        wss.clients.forEach(function each(client) {
          if (client.readyState === WebSocket.OPEN) {
            try { client.send(raw); } catch (_) {}
          }
        });
      } catch (_) {}
      return;
    }

    // leave message
    if (obj.type === 'leave') {
      if (!sender) return;
      adminClients.delete(sender);
      if (sender._room) {
        const set = rooms.get(sender._room);
        if (set) set.delete(sender);
        sender._room = null;
      }
      return;
    }

    // Special: new_match event — broadcast to ALL clients so viewers on any page
    if (obj.type === 'new_match') {
      try {
        const newMid = obj.match_id != null ? String(obj.match_id) : (obj.payload && obj.payload.match_id ? String(obj.payload.match_id) : null);
        if (newMid) {
          try { lastState.set(newMid, { sport: obj.sport || 'state', payload: obj.payload || null, ts: obj.ts || Date.now() }); } catch (_) {}
        }
      } catch (_) {}
      const raw = JSON.stringify(obj);
      wss.clients.forEach(function each(client) {
        if (client.readyState === WebSocket.OPEN) {
          try { client.send(raw); } catch (e) { /* ignore */ }
        }
      });
      return;
    }

    // timer_control message: broadcast to all admin clients
    if (obj.type === 'timer_control') {
      sendToAdmins(obj, sender);
      return;
    }

    // timer_update message: if it has control meta, broadcast to all admin clients
    if (obj.type === 'timer_update' && obj.meta && obj.meta.control) {
      if (matchId) {
        try {
          const existing = lastState.get(matchId) || { sport: 'basketball_state', payload: {} };
          const payload = Object.assign({}, existing.payload || {});
          if (obj.gameTimer) payload.gameTimer = obj.gameTimer;
          if (obj.shotClock) payload.shotClock = obj.shotClock;
          lastState.set(matchId, { sport: existing.sport || 'basketball_state', payload: payload, ts: obj.ts || Date.now() });
        } catch (_) {}
      }
      sendToAdmins(obj, sender);
      return;
    }

    // state message: cache and broadcast within room
    const STATE_TYPES = ['state','badminton_state','tabletennis_state','basketball_state','basketball:state-sync'];
    if (STATE_TYPES.includes(obj.type) && matchId) {
      try {
        const incoming = obj.payload || null;
        const isTimerOnly = incoming &&
          (incoming.gameTimer || incoming.shotClock || incoming.game_timer || incoming.shot_clock) &&
          !incoming.teamA && !incoming.teamB;
        if (isTimerOnly && lastState.has(matchId)) {
          const existing = lastState.get(matchId);
          const existingPayload = (existing && existing.payload) ? existing.payload : {};
          const mergedPayload = Object.assign({}, existingPayload, {
            gameTimer: incoming.gameTimer || incoming.game_timer || existingPayload.gameTimer || existingPayload.game_timer,
            shotClock: incoming.shotClock || incoming.shot_clock || existingPayload.shotClock || existingPayload.shot_clock,
            game_timer: incoming.game_timer || incoming.gameTimer || existingPayload.game_timer || existingPayload.gameTimer,
            shot_clock: incoming.shot_clock || incoming.shotClock || existingPayload.shot_clock || existingPayload.shotClock,
          });
          lastState.set(matchId, { sport: existing.sport || obj.type, payload: mergedPayload, ts: obj.ts || Date.now() });
        } else {
          lastState.set(matchId, { sport: obj.sport || obj.type, payload: obj.payload || null, ts: obj.ts || Date.now() });
        }
      } catch (_) {}
      const roomSet = rooms.get(matchId);
      const clientCount = roomSet ? roomSet.size : 0;
      console.log(`[BROADCAST] type=${obj.type} match_id=${matchId} clients=${clientCount} timestamp=${new Date().toISOString()}`);
      sendToRoom(matchId, obj, sender);
      return;
    }

    // Convenience: broadcast any named state without matchId to all clients
    if (STATE_TYPES.includes(obj.type) && !matchId) {
      const raw = JSON.stringify(obj);
      wss.clients.forEach(function each(client) {
        if (client !== sender && client.readyState === WebSocket.OPEN) {
          try { client.send(raw); } catch (_) {}
        }
      });
      return;
    }

    // other typed messages: if matchId present, broadcast within room
    if (matchId) {
      sendToRoom(matchId, obj, sender);
      return;
    }

    // fallback: broadcast to all admins if no matchId provided
    const raw = JSON.stringify(obj);
    wss.clients.forEach(function each(client) {
      if (client.readyState === WebSocket.OPEN) {
        try { client.send(raw); } catch (e) { /* ignore */ }
      }
    });
  } catch (e) {
    // ignore
  }
}


wss.on('connection', function connection(ws, req) {
  // Basic auth / origin checks
  try {
    const parsed = new URL(req.url, 'http://localhost');
    const token = parsed.searchParams.get('token');
    // Removed token check to allow any admin user with admin role to access and update
    // if (WS_TOKEN && token !== WS_TOKEN) {
    //   console.warn('WS connection rejected: invalid token', req.socket.remoteAddress);
    //   try { ws.close(4003, 'auth failed'); } catch (_) {}
    //   return;
    // }
  } catch (_) {}

  // All origins allowed, all clients welcome
  console.log('[admin connection] client connected from', req.socket.remoteAddress);

  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('close', () => {
    // Clean up admin clients set
    adminClients.delete(ws);
    // Clean up rooms
    if (ws._room) {
      const set = rooms.get(ws._room);
      if (set) set.delete(ws);
    }
  });

  ws.on('message', function incoming(message) {
    // Expect JSON messages with optional { type, match_id, payload }
    try {
      const obj = JSON.parse(message);
      if (!obj || typeof obj !== 'object') return;
      const matchId = obj.match_id != null ? String(obj.match_id) : null;
      // If sender included a message id, ACK immediately so clients can stop retrying.
      const ackId = obj._msg_id || obj._msgId || obj.msg_id || null;
      if (ackId) {
        try { sendRaw(ws, { type: 'ack', ack_id: ackId }); } catch (e) {}
      }
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
              type:    'applied_action',
              sport:   cached && cached.sport ? cached.sport : 'state',
              payload: cached && cached.payload ? cached.payload : cached,
              ts:      cached && cached.ts ? cached.ts : Date.now()
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

      // action message: translate to 'applied_action' and broadcast within room
      if (obj.type === 'action') {
        if (matchId) {
          try {
            const out = { type: 'applied_action', sport: obj.sport || 'volleyball', match_id: matchId, payload: obj.payload || null, meta: obj.meta || null };
            sendToRoom(matchId, out, ws);
          } catch (_) {}
          return;
        }
        // no matchId: broadcast to all
        try {
          const out = { type: 'applied_action', sport: obj.sport || 'volleyball', payload: obj.payload || null, meta: obj.meta || null };
          const raw = JSON.stringify(out);
          wss.clients.forEach(function each(client) {
            if (client.readyState === WebSocket.OPEN) {
              try { client.send(raw); } catch (_) {}
            }
          });
        } catch (_) {}
        return;
      }

      // Special: new_match event — broadcast to ALL clients so viewers on any page
      // can immediately transition to the new match context.
      if (obj.type === 'new_match') {
        try {
          const newMid = obj.match_id != null ? String(obj.match_id) : (obj.payload && obj.payload.match_id ? String(obj.payload.match_id) : null);
          if (newMid) {
            try { lastState.set(newMid, { sport: obj.sport || 'state', payload: obj.payload || null, ts: obj.ts || Date.now() }); } catch (_) {}
          }
        } catch (_) {}
        const raw = typeof message === 'string' ? message : JSON.stringify(obj);
        wss.clients.forEach(function each(client) {
          if (client.readyState === WebSocket.OPEN) {
            try { client.send(raw); } catch (e) { /* ignore */ }
          }
        });
        return;
      }

      // state message: cache and broadcast within room
      // Supports sport-typed messages: badminton_state, tabletennis_state, basketball_state
      const STATE_TYPES = ['state','badminton_state','tabletennis_state','basketball_state','basketball:state-sync'];
      if (STATE_TYPES.includes(obj.type) && matchId) {
        try {
          // Cache canonical state for this room (include ts)
          const incoming = obj.payload || null;
          const isTimerOnly = incoming &&
            (incoming.gameTimer || incoming.shotClock || incoming.game_timer || incoming.shot_clock) &&
            !incoming.teamA && !incoming.teamB;
          if (isTimerOnly && lastState.has(matchId)) {
            const existing = lastState.get(matchId);
            const existingPayload = (existing && existing.payload) ? existing.payload : {};
            const mergedPayload = Object.assign({}, existingPayload, {
              gameTimer: incoming.gameTimer || incoming.game_timer || existingPayload.gameTimer || existingPayload.game_timer,
              shotClock: incoming.shotClock || incoming.shot_clock || existingPayload.shotClock || existingPayload.shot_clock,
              game_timer: incoming.game_timer || incoming.gameTimer || existingPayload.game_timer || existingPayload.gameTimer,
              shot_clock: incoming.shot_clock || incoming.shotClock || existingPayload.shot_clock || existingPayload.shotClock,
            });
            lastState.set(matchId, { sport: existing.sport || obj.type, payload: mergedPayload, ts: obj.ts || Date.now() });
          } else {
            lastState.set(matchId, { sport: obj.sport || obj.type, payload: obj.payload || null, ts: obj.ts || Date.now() });
          }
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

// Server-side authoritative timer tick broadcaster
// Computes elapsed time from the cached lastState and emits lightweight
// `timer_update` messages into each match room. This ensures timers are
// driven from the server (SSOT) while avoiding constant DB writes.
const TIMER_TICK_MS = 200; // 5Hz
setInterval(() => {
  try {
    const now = Date.now();
    lastState.forEach((cached, matchId) => {
      if (!cached || !cached.payload) return;
      const p = cached.payload || {};
      const msg = { type: 'timer_update', match_id: matchId, ts: now };
      let has = false;
      try {
        if (p.gameTimer && typeof p.gameTimer === 'object') {
          const gt = p.gameTimer;
          const totalMs = (typeof gt.total_ms === 'number') ? gt.total_ms : ((typeof gt.total === 'number') ? gt.total * 1000 : null);
          const remainingMs = (typeof gt.remaining_ms === 'number') ? gt.remaining_ms : ((typeof gt.remaining === 'number') ? gt.remaining * 1000 : null);
          const lastTs = (typeof gt.start_timestamp === 'number') ? gt.start_timestamp : ((typeof gt.ts === 'number') ? gt.ts : cached.ts || now);
          const running = !!gt.running;
          const currentMs = (running && remainingMs !== null) ? Math.max(0, remainingMs - Math.max(0, now - lastTs)) : (remainingMs !== null ? remainingMs : (totalMs !== null ? totalMs : 0));
          msg.gameTimer = {
            total: totalMs !== null ? totalMs / 1000 : 0,
            remaining: Number((currentMs / 1000).toFixed(3)),
            running: running,
            ts: now
          };
          has = true;
        }
      } catch (e) {}
      try {
        if (p.shotClock && typeof p.shotClock === 'object') {
          const sc = p.shotClock;
          const totalMs = (typeof sc.total_ms === 'number') ? sc.total_ms : ((typeof sc.total === 'number') ? sc.total * 1000 : null);
          const remainingMs = (typeof sc.remaining_ms === 'number') ? sc.remaining_ms : ((typeof sc.remaining === 'number') ? sc.remaining * 1000 : null);
          const lastTs = (typeof sc.start_timestamp === 'number') ? sc.start_timestamp : ((typeof sc.ts === 'number') ? sc.ts : cached.ts || now);
          const running = !!sc.running;
          const currentMs = (running && remainingMs !== null) ? Math.max(0, remainingMs - Math.max(0, now - lastTs)) : (remainingMs !== null ? remainingMs : (totalMs !== null ? totalMs : 0));
          msg.shotClock = {
            total: totalMs !== null ? totalMs / 1000 : 0,
            remaining: Number((currentMs / 1000).toFixed(3)),
            running: running,
            ts: now
          };
          has = true;
        }
      } catch (e) {}

      if (has) {
        // Broadcast timer-only update into the room (no sender)
        try { sendToRoom(matchId, msg, null); } catch (e) {}
      }
    });
  } catch (e) { /* ignore timer loop errors */ }
}, TIMER_TICK_MS);

wss.on('close', function close() {
  clearInterval(interval);
});