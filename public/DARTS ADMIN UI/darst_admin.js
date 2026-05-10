// ================================================================
// STATE
// ================================================================
let gameType = 301;
let legsToWin = 3;
let mode = 'one-sided';
let currentPlayer = 0; // index 0-3
let inputStr = '';
let matchId = 0;
let _currentWSRoom = null; // Track current WebSocket room
let _clientId = null; // Unique client ID to prevent self-application
let _lastBroadcastTime = 0; // Timestamp of last local broadcast
let _lastAppliedVersion = 0; // Last applied state version to prevent outdated updates
let _stateVersionCounter = 0; // Counter to ensure unique versions in the same millisecond
let currentLeg = 1;

const COLORS = ['#CC0000','#003399','#FFE600','#E65C00'];
const TEXT_COLORS = ['#fff','#fff','#000','#fff'];
const DEFAULT_NAMES = ['PLAYER 1','PLAYER 2','PLAYER 3','PLAYER 4'];

let players = [0,1,2,3].map(i => ({
  playerNumber: i+1,
  name: DEFAULT_NAMES[i],
  team: 'TEAM',
  score: gameType,
  legsWon: 0,
  // per-player flag for match winner (used by viewers)
  isWinner: false,
  throws: [],        // [{value, scoreBefore, scoreAfter, isBust}]
  undoStack: [],
  redoStack: [],
  saveEnabled: true,
  dbPlayerId: null,
}));
// history of leg winners as player index (0-3), e.g. [1,0] => leg1 won by player2, leg2 won by player1
let legsHistory = [];
// Prevents renderCards() from firing a debounced publish when we want one clean publish afterwards
let _suppressPublish = false;
// Prevents broadcasting when applying received states to avoid loops
let _suppressBroadcast = false;

// --- Persistence & live publish -------------------------------------------------
const DARTS_LOCAL_KEY = 'darts_admin_state_v1';
const DARTS_MODE_KEY = 'darts_admin_mode_v1';
let _publishTimer = null;
// Reduced debounce interval for snappier updates
const PUBLISH_DEBOUNCE_MS = 250;
// Event seq / last event marker to notify viewers about leg/match wins
window.__dartsEventSeq = window.__dartsEventSeq || 0;
window.__dartsLastEvent = window.__dartsLastEvent || null;

function serializeLiveState() {
  return {
    match_id: matchId || null,
    players: players.map(p => ({
      player_number: p.playerNumber,
      name: p.name ?? '',
      team: p.team ?? '',
      score: typeof p.score === 'number' ? p.score : gameType,
      legs: typeof p.legsWon === 'number' ? p.legsWon : 0,
      is_winner: !!p.isWinner,
      db_id: p.dbPlayerId || null,
      save_enabled: p.saveEnabled ? 1 : 0,
      hist: (p.throws || []).slice(-20).map(t => ({ v: t.value ?? t.throw_value ?? 0, bust: !!t.isBust || !!t.is_bust })),
      undo_stack: (p.undoStack || []).slice(),
      redo_stack: (p.redoStack || []).slice()
    })),
    currentPlayer: currentPlayer,
    gameType: gameType,
    legsToWin: legsToWin,
    currentLeg: currentLeg,
    legs_history: legsHistory.slice(),
    last_throws: players.map(p => (p.throws || []).slice(-4).map(t => ({ v: t.value ?? t.throw_value ?? 0, bust: !!t.isBust || !!t.is_bust }))),
    updated_at: new Date().toISOString(),
    _last_event: window.__dartsLastEvent || null,
    inputStr: inputStr,
    _stateVersion: (function(){ // mode is local-only and not shared in the canonical live state

      const now = Date.now();
      _stateVersionCounter = (_stateVersionCounter + 1) % 1000;
      return now * 1000 + _stateVersionCounter;
    })()
    // ✅ SSOT SAFE ADD END
  };
}

function saveLocalState() {
  try {
    const payload = { match_id: matchId || 0, saved_at: Date.now(), state: serializeLiveState(), event: window.__dartsLastEvent || null };
    try { localStorage.setItem(DARTS_LOCAL_KEY, JSON.stringify(payload)); } catch(e) {}
  } catch (e) {}
}

function restoreLocalState() {
  try {
    const raw = localStorage.getItem(DARTS_LOCAL_KEY);
    if (!raw) return false;
    const obj = JSON.parse(raw);
    if (!obj || !obj.state) return false;
    applyState(obj.state);
    try { if (obj.event) window.__dartsLastEvent = obj.event; } catch(e) {}
    return true;
  } catch (e) { return false; }
}

function applyState(st) {
  if (!st) return;
  // ✅ SSOT SAFE ADD START — prevent outdated broadcasts from causing flickering
  if (typeof st._stateVersion === 'number' && st._stateVersion <= _lastAppliedVersion) {
    console.log('Ignoring outdated state version:', st._stateVersion, '<=', _lastAppliedVersion);
    return;
  }
  _lastAppliedVersion = st._stateVersion || 0;
  // ✅ SSOT SAFE ADD END

  const previousMatchId = matchId;
  if (st.match_id !== undefined && st.match_id !== null) {
    const parsedMatchId = parseInt(st.match_id, 10);
    matchId = Number.isNaN(parsedMatchId) ? 0 : parsedMatchId;
  }

  // Suppress broadcasting during state application to prevent loops
  _suppressBroadcast = true;
  try {
    if (st.gameType)                          gameType      = st.gameType;
    if (st.legsToWin)                         legsToWin     = st.legsToWin;
    if (typeof st.currentPlayer === 'number') currentPlayer = st.currentPlayer;
    if (typeof st.currentLeg    === 'number') currentLeg    = st.currentLeg;
    if (Array.isArray(st.legs_history))       legsHistory   = st.legs_history.slice();

  // Update active card classes and arrow buttons after restoring currentPlayer
  players.forEach((_, idx) => {
    const c = document.getElementById('card-' + idx);
    if (c) c.className = 'player-card' + (idx === currentPlayer ? ' active-card' : '');
  });
  updateArrowBtns();

  // mode is a local-only UI preference and is not restored from shared state
  if (typeof st.inputStr === 'string') {
    inputStr = st.inputStr;
    const display = document.getElementById('throw-display');
    if (display) display.textContent = inputStr || '0';
  }

  if (st.players && Array.isArray(st.players)) {
    st.players.forEach((sp, i) => {
      if (!players[i]) return;
      if (sp.name  !== undefined) players[i].name       = sp.name;
      if (sp.team  !== undefined) players[i].team       = sp.team;
      if (typeof sp.score === 'number') players[i].score     = sp.score;
      if (typeof sp.legs  === 'number') players[i].legsWon   = sp.legs;
      if (sp.db_id)                     players[i].dbPlayerId = sp.db_id;
        if (sp.save_enabled !== undefined) players[i].saveEnabled = !!sp.save_enabled;
      // ✅ FIX: Always update throws array, including clearing it for new match
      if (Array.isArray(sp.hist)) {
        players[i].throws = sp.hist.length ? sp.hist.map(h => ({ value: h.v, isBust: !!h.bust, scoreBefore: 0, scoreAfter: h.v })) : [];
        if (sp.hist.length > 0) console.log('[applyState] Updated P' + (i+1) + ' throws: ' + sp.hist.length + ' throws');
      }
      if (Array.isArray(sp.undo_stack)) {
        players[i].undoStack = sp.undo_stack.map(t => ({
          value: t.value,
          scoreBefore: t.scoreBefore,
          scoreAfter: t.scoreAfter,
          isBust: !!t.isBust
        }));
      }
      if (Array.isArray(sp.redo_stack)) {
        players[i].redoStack = sp.redo_stack.map(t => ({
          value: t.value,
          scoreBefore: t.scoreBefore,
          scoreAfter: t.scoreAfter,
          isBust: !!t.isBust
        }));
      }
      players[i].isWinner = !!sp.is_winner;
    });
  }

  // Sync UI controls to restored values
  const ltwInput = document.getElementById('legs-to-win-input');
  if (ltwInput) ltwInput.value = legsToWin;
  const _presetValues = [301, 501, 701];
  document.querySelectorAll('[data-gt]').forEach(b => {
    b.classList.toggle('active', parseInt(b.dataset.gt) === gameType);
  });
  // Sync custom game type input — show value only when it's not a preset
  const _ci = document.getElementById('custom-game-input');
  if (_ci) {
    if (_presetValues.includes(gameType)) {
      _ci.value = '';
    } else {
      _ci.value = gameType;
    }
  }
  } finally {
    _suppressBroadcast = false;
  }

  if (String(matchId) !== String(previousMatchId)) {
    try { joinWebSocketRoom(); } catch (e) { console.error('[applyState] Failed to rejoin WS room after matchId change', e); }
  }
}

function publishLiveStateDebounced() {
  // Short debounce for rapid UI interactions but immediate local broadcast
  try { broadcastLiveState(); } catch (e) {}
  if (_publishTimer) clearTimeout(_publishTimer);
  _publishTimer = setTimeout(() => {
    _publishTimer = null;
    publishLiveState();
  }, PUBLISH_DEBOUNCE_MS);
}

// Lightweight WS send queue helper (available before socket exists)
window._dartsWSSendQueue = window._dartsWSSendQueue || [];
function sendWSMessage(msgObj) {
  try {
    const s = JSON.stringify(msgObj);
    if (window._dartsWS && window._dartsWS.readyState === WebSocket.OPEN) {
      window._dartsWS.send(s);
    } else {
      window._dartsWSSendQueue.push(s);
      try { initWebSocket(); } catch(e) {}
    }
  } catch (e) {
    console.error('sendWSMessage error', e);
  }
}

// sendWithAck: attempt to send a message and wait for an ACK from server.
// Retries with exponential backoff up to opts.retries (default 4).
function sendWithAck(msgObj, opts = {}) {
  const retries = opts.retries == null ? 4 : opts.retries;
  const timeout = opts.timeout == null ? 2000 : opts.timeout;
  const baseDelay = opts.baseDelay == null ? 800 : opts.baseDelay;
  const id = 'm_' + Date.now() + '_' + Math.floor(Math.random()*1000000);
  msgObj._msg_id = id;
  let attempts = 0;
  return new Promise((resolve, reject) => {
    window._dartsWSPendingAcks = window._dartsWSPendingAcks || {};
    function attemptSend() {
      attempts++;
      try { sendWSMessage(msgObj); } catch(e) {}
      const timer = setTimeout(() => {
        // timeout for this attempt
        if (attempts <= retries) {
          const delay = baseDelay * Math.pow(2, attempts-1);
          setTimeout(attemptSend, delay);
        } else {
          delete window._dartsWSPendingAcks[id];
          reject(new Error('WS ack timeout'));
        }
      }, timeout);
      window._dartsWSPendingAcks[id] = { resolve: () => { clearTimeout(timer); delete window._dartsWSPendingAcks[id]; resolve(true); }, reject: () => { clearTimeout(timer); delete window._dartsWSPendingAcks[id]; reject(new Error('WS ack rejected')); } };
    }
    attemptSend();
  });
}

function publishLiveState() {
  // publish even without a DB match yet — use 0 as pending key
  const payload = { match_id: matchId, state: serializeLiveState(), client_id: _clientId };
  console.log('[publishLiveState] Sending to state.php: match_id=' + matchId + ' inputStr=' + (payload.state.inputStr || 'N/A') + ' currentPlayer=' + (payload.state.currentPlayer || 'N/A'));
  fetch('state.php', { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) })
    .then(r => {
      console.log('[publishLiveState] Response status: ' + r.status);
      if (r.status === 200) {
        return r.json();
      } else {
        throw new Error('HTTP ' + r.status);
      }
    })
    .then(data => {
      console.log('[publishLiveState] Response data:', data);
      if (data && data.success) {
        console.log('[publishLiveState] Server persistence successful');
        // Server has persisted the state - no need to broadcast again
        // The immediate broadcast from padPress() already sent the update
      } else {
        console.error('[publishLiveState] Server returned error:', data ? data.message : 'Unknown error');
      }
    })
    .catch((err) => {
      console.error('[publishLiveState] Request failed:', err);
      // Even if persistence fails, the local broadcast already happened
    });
}

// Broadcast to other tabs in same browser and to ws-server for cross-device viewers
function broadcastLiveState(stateObj) {
  // Don't broadcast if suppressed (during state application)
  if (_suppressBroadcast) return;

  if (!stateObj) stateObj = serializeLiveState();
  const now = Date.now();
  _lastBroadcastTime = now;

  // Log throws data to verify they're being broadcast
  const throwsSummary = stateObj.players ? stateObj.players.map((p, i) => (p.hist || []).length + ' throws for P' + (i+1)).join(', ') : 'N/A';
  console.log('[broadcastLiveState] Broadcasting: match_id=' + matchId + ' inputStr=' + (stateObj.inputStr || '0') + ' throws=' + throwsSummary + ' client=' + _clientId);

  // BroadcastChannel for same-browser instant push
  try {
    if (!window._dartsBC) window._dartsBC = new BroadcastChannel('darts_live');
    const bcMessage = {
      match_id: String(matchId || 0),
      state: stateObj,
      client_id: _clientId,
      timestamp: now
    };
    window._dartsBC.postMessage(bcMessage);
    console.log('[broadcastLiveState] BroadcastChannel sent: inputStr=' + (stateObj.inputStr || '0'));
  } catch (e) {
    console.error('[broadcastLiveState] BroadcastChannel error:', e);
  }

  // WebSocket relay to central ws-server
  try {
    if (!window._dartsWS || window._dartsWS.readyState !== WebSocket.OPEN) {
      try { initWebSocket(); } catch(e) {}
    }
    const wsMsg = {
      type: 'state',
      match_id: String(matchId || 0),
      payload: stateObj,
      client_id: _clientId,
      timestamp: now
    };
    console.log('[broadcastLiveState] Sending WebSocket: match_id=' + matchId + ' inputStr=' + (stateObj.inputStr || '0') + ' ws_state=' + (window._dartsWS ? (window._dartsWS.readyState === WebSocket.OPEN ? 'OPEN' : 'NOT_OPEN') : 'NULL'));
    try { sendWSMessage(wsMsg); } catch(e) { console.error('[broadcastLiveState] sendWSMessage error:', e); }
    console.log('[broadcastLiveState] WebSocket message queued/sent');
  } catch (e) {
    console.error('[broadcastLiveState] WebSocket error:', e);
  }
}

// Broadcast a canonical state object (from server) to BC/WS — used after server save
function broadcastCanonicalState(stateObj) {
  const now = Date.now();
  try {
    if (!window._dartsBC) window._dartsBC = new BroadcastChannel('darts_live');
    const bcMessage = {
      match_id: String(matchId || 0),
      state: stateObj,
      client_id: _clientId,
      timestamp: now
    };
    window._dartsBC.postMessage(bcMessage);
    console.debug('[admin] BroadcastChannel postMessage (canonical)');
  } catch (e) {}
  try {
    if (window._dartsWS && window._dartsWS.readyState === WebSocket.OPEN) {
      const wsMsg = {
        type: 'state',
        match_id: String(matchId || 0),
        payload: stateObj,
        client_id: _clientId,
        timestamp: now
      };
      window._dartsWS.send(JSON.stringify(wsMsg));
      console.debug('[admin] sent canonical state to ws-server');
    }
  } catch (e) {}
}

// Initialize a persistent WebSocket connection and message handling.
function initWebSocket() {
  if (window._dartsWS) return;
  try {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const meta = document.querySelector('meta[name="ws-token"]');
    const wsToken = meta ? meta.getAttribute('content') : '';
    // Removed token from URL to allow any admin user with admin role to access and update
    // const url = proto + '//' + location.hostname + ':3000' + (wsToken ? ('?token=' + encodeURIComponent(wsToken)) : '');
    const url = proto + '//' + location.hostname + ':3000';
    window._dartsWS = new WebSocket(url);
    window._wsPendingCallbacks = [];

    // BroadcastChannel for cross-tab sync
    if (!window._dartsBC) {
      window._dartsBC = new BroadcastChannel('darts_live');
      window._dartsBC.onmessage = function(e) {
        const data = e.data;
        console.log('[BroadcastChannel] Received message: match_id=' + data.match_id + ' client_id=' + data.client_id + ' current_client=' + _clientId + ' has_state=' + !!data.state);

        // Ignore self-broadcasts
        if (data.client_id === _clientId) {
          console.log('[BroadcastChannel] Ignoring self-broadcast');
          return;
        }

        if (data.match_id && String(data.match_id) === String(matchId || 0) && data.state) {
          console.log('[BroadcastChannel] Applying state: inputStr=' + (data.state.inputStr || '0') + ' currentPlayer=' + (data.state.currentPlayer || 'N/A'));
          const wasSuppressPublish = _suppressPublish;
          _suppressPublish = true;
          try { applyState(data.state); renderCards(); updateArrowBtns(); } finally { _suppressPublish = wasSuppressPublish; }
        } else if (data.state && data.state._new_match) {
          console.log('[BroadcastChannel] Applying new match state');
          const wasSuppressPublish = _suppressPublish;
          _suppressPublish = true;
          try { applyState(data.state); renderCards(); updateArrowBtns(); } finally { _suppressPublish = wasSuppressPublish; }
        } else if (data.match_id && String(data.match_id) !== String(matchId || 0)) {
          console.log('[BroadcastChannel] Ignoring message for different match: ' + data.match_id + ' vs ' + matchId);
        }
      };
    }

    window._dartsWS.addEventListener('open', function () {
        console.log('[WebSocket] Connected to server');
        try {
          // flush queued messages
          if (window._dartsWSSendQueue && window._dartsWSSendQueue.length) {
            window._dartsWSSendQueue.forEach(q => { try { window._dartsWS.send(q); } catch(e) {} });
            window._dartsWSSendQueue = [];
          }
          // join the room for this match (will re-join when matchId is set)
          joinWebSocketRoom();
        } catch (e) {
          console.error('[WebSocket] Open handler error:', e);
        }
    });

    window._dartsWS.addEventListener('message', function (evt) {
      try {
        const msg = JSON.parse(evt.data);
        if (!msg) return;

        // ACK handling for sendWithAck
        if (msg.type === 'ack' && (msg.ack_id || msg.id || msg._msg_id)) {
          const aid = msg.ack_id || msg.id || msg._msg_id;
          try {
            window._dartsWSPendingAcks = window._dartsWSPendingAcks || {};
            const p = window._dartsWSPendingAcks[aid];
            if (p && p.resolve) { try { p.resolve(); } catch(e) {} }
          } catch (e) {}
          return;
        }

        // server-sent canonical / cached state
        if (msg.type === 'last_state' || msg.type === 'state' || (typeof msg.type === 'string' && msg.type.endsWith('_state'))) {
          const payload = msg.payload || msg.state || null;
          console.log(`[SYNC RECEIVED] type=${msg.type} match_id=${msg.match_id} client_id=${msg.client_id} current_client=${_clientId} inputStr=${payload?.inputStr || 'N/A'} currentPlayer=${payload?.currentPlayer || 'N/A'}`);

          // Ignore self-broadcasts
          if (msg.client_id === _clientId) {
            console.log('[SYNC RECEIVED] Ignoring self-broadcast from WebSocket');
            return;
          }

          if (((msg.type === 'last_state' && payload) || (msg.match_id && String(msg.match_id) === String(matchId || 0) && payload))) {
            console.log('[SYNC RECEIVED] Applying state: inputStr=' + (payload.inputStr || '0') + ' currentPlayer=' + (payload.currentPlayer || 'N/A'));
            const wasSuppressPublish = _suppressPublish;
            _suppressPublish = true;
            try { applyState(payload); console.log('[SYNC RECEIVED] applyState OK, inputStr now=' + inputStr); saveLocalState(); renderCards(); updateArrowBtns(); } finally { _suppressPublish = wasSuppressPublish; }
          } else if (msg.match_id && String(msg.match_id) !== String(matchId || 0)) {
            console.log('[SYNC RECEIVED] Ignoring message for different match: ' + msg.match_id + ' vs ' + matchId);
          } else if (msg.type === 'last_state' && !payload) {
            console.log('[SYNC RECEIVED] last_state received with no payload, ignoring');
          }
          // call any pending callbacks waiting for canonical state
          try {
            if (window._wsPendingCallbacks && window._wsPendingCallbacks.length) {
              const cbs = window._wsPendingCallbacks.splice(0);
              cbs.forEach(cb => { try { cb(); } catch (e) {} });
            }
          } catch (e) {}
          return;
        }

        // generic payload handling
        if (msg.state || msg.payload) {
          const wasSuppressPublish = _suppressPublish;
          _suppressPublish = true;
          try { applyState(msg.state || msg.payload); saveLocalState(); renderCards(); updateArrowBtns(); } finally { _suppressPublish = wasSuppressPublish; }
          return;
        }
      } catch (e) {
        // ignore non-JSON messages
      }
    });

    window._dartsWS.addEventListener('error', function () { /* ignore */ });
    window._dartsWS.addEventListener('close', function () { setTimeout(() => { window._dartsWS = null; }, 2000); });
  } catch (e) {}
}

// Periodic check to ensure WebSocket connection and room membership
setInterval(() => {
  try {
    joinWebSocketRoom();
  } catch (e) {
    console.error('[WebSocket] Periodic check failed:', e);
  }
}, 5000); // Check every 5 seconds

// Join the WebSocket room for the current match
function joinWebSocketRoom() {
  if (window._dartsWS && window._dartsWS.readyState === WebSocket.OPEN) {
    try {
      const roomId = String(matchId || 0);
      if (_currentWSRoom !== roomId) {
        const joinMsg = { type: 'join', match_id: roomId };
        window._dartsWS.send(JSON.stringify(joinMsg));
        _currentWSRoom = roomId;
        console.log('[WebSocket] Joined room: match_id=' + roomId);
      }
    } catch (e) {
      console.error('[WebSocket] Join room error:', e);
    }
  } else {
    // WebSocket not ready, initialize it
    console.log('[WebSocket] Not connected, initializing...');
    try { initWebSocket(); } catch(e) {}
  }
}

// Request canonical state from server via WebSocket by re-joining the room.
// Calls cb() once canonical state is received or after timeout/fallback.
function requestCanonicalViaWS(cb, timeoutMs = 2000) {
  if (!window._dartsWS || window._dartsWS.readyState !== WebSocket.OPEN) {
    if (cb) cb(false);
    return;
  }
  window._wsPendingCallbacks = window._wsPendingCallbacks || [];
  let called = false;
  const wrapped = () => { if (called) return; called = true; clearTimeout(timer); if (cb) cb(true); };
  window._wsPendingCallbacks.push(wrapped);
  const timer = setTimeout(() => {
    if (called) return; called = true;
    // remove wrapped from pending if still present
    const idx = window._wsPendingCallbacks.indexOf(wrapped);
    if (idx >= 0) window._wsPendingCallbacks.splice(idx, 1);
    if (cb) cb(false);
  }, timeoutMs);

  try { window._dartsWS.send(JSON.stringify({ type: 'join', match_id: String(matchId || 0) })); } catch (e) { wrapped(); }
}



// ================================================================
// RENDER
// ================================================================
function renderCards() {
  const area = document.getElementById('cards-area');
  area.innerHTML = '';
  area.className = mode === 'two-sided' ? 'two-sided' : '';

  if (mode === 'two-sided') {
    const left = document.createElement('div');
    left.className = 'side-group';
    const right = document.createElement('div');
    right.className = 'side-group';
    players.forEach((p, i) => {
      const card = buildCard(p, i);
      (i < 2 ? left : right).appendChild(card);
    });
    area.appendChild(left);
    area.appendChild(right);
  } else {
    players.forEach((p, i) => area.appendChild(buildCard(p, i)));
  }
  // persist UI state locally and publish to viewers (debounced)
  // _suppressPublish is set by startNextLeg so it can do one clean publish after full reset
  try { saveLocalState(); if (!_suppressPublish) publishLiveStateDebounced(); } catch(e) {}
}

// Also broadcast immediately (without debounce) to same-browser tabs and ws relay
function renderAndBroadcast() {
  try { saveLocalState(); publishLiveState(); broadcastLiveState(); } catch(e) {}
}

function buildCard(p, i) {
  const card = document.createElement('div');
  card.className = 'player-card' + (i === currentPlayer ? ' active-card' : '');
  card.id = 'card-' + i;
  card.onclick = () => selectPlayer(i);

  // Header
  const hdr = document.createElement('div');
  hdr.className = 'card-header';
  hdr.style.background = COLORS[i];

  const nameWrap = document.createElement('div');
  nameWrap.className = 'player-names';

  const nameInp = document.createElement('input');
  nameInp.className = 'player-name-edit';
  nameInp.value = p.name;
  nameInp.style.color = TEXT_COLORS[i];
  nameInp.onclick  = e => e.stopPropagation();
  nameInp.oninput  = e => { p.name = e.target.value.toUpperCase(); e.target.value = p.name; try { broadcastLiveState(); } catch(e) {} publishLiveStateDebounced(); };
  nameInp.onchange = e => { p.name = e.target.value.toUpperCase(); e.target.value = p.name; updateCard(i); };
  nameInp.onblur   = e => { p.name = e.target.value.toUpperCase(); e.target.value = p.name; updateCard(i); };

  const teamInp = document.createElement('input');
  teamInp.className = 'team-name-edit';
  teamInp.value = p.team;
  teamInp.style.color = TEXT_COLORS[i] === '#000' ? '#333' : 'rgba(255,255,255,.7)';
  teamInp.onclick  = e => e.stopPropagation();
  teamInp.oninput  = e => { p.team = e.target.value; try { broadcastLiveState(); } catch(e) {} publishLiveStateDebounced(); };
  teamInp.onchange = e => { p.team = e.target.value; updateCard(i); };
  teamInp.onblur   = e => { p.team = e.target.value; updateCard(i); };

  nameWrap.appendChild(nameInp);
  nameWrap.appendChild(teamInp);

  const saveWrap = document.createElement('div');
  saveWrap.className = 'save-checkbox-wrap';
  const saveChk = document.createElement('input');
  saveChk.type = 'checkbox';
  saveChk.checked = p.saveEnabled;
  saveChk.onclick = e => e.stopPropagation();
  saveChk.onchange = e => { p.saveEnabled = e.target.checked; };
  const saveLabel = document.createElement('span');
  saveLabel.textContent = 'SAVE';
  saveLabel.style.color = TEXT_COLORS[i];
  saveWrap.appendChild(saveChk);
  saveWrap.appendChild(saveLabel);

  hdr.appendChild(nameWrap);
  hdr.appendChild(saveWrap);
  card.appendChild(hdr);

  // Score
  const scoreArea = document.createElement('div');
  scoreArea.className = 'score-area';
  const scoreNum = document.createElement('div');
  scoreNum.className = 'score-number';
  scoreNum.id = 'score-' + i;
  scoreNum.textContent = p.score;
  const scoreLabel = document.createElement('div');
  scoreLabel.className = 'score-label';
  scoreLabel.textContent = 'LEG TRACKER';
  scoreArea.appendChild(scoreNum);
  scoreArea.appendChild(scoreLabel);
  card.appendChild(scoreArea);

  // Leg won
  const lwArea = document.createElement('div');
  lwArea.className = 'leg-won-area';
  const lwLabel = document.createElement('div');
  lwLabel.className = 'leg-won-label';
  lwLabel.textContent = 'LEG WON';
  const lwCounter = document.createElement('div');
  lwCounter.className = 'leg-won-counter';

  const minusBtn = document.createElement('button');
  minusBtn.className = 'lw-btn lw-minus';
  minusBtn.textContent = '−';
  minusBtn.onclick = e => { e.stopPropagation(); p.legsWon = Math.max(0, p.legsWon-1); updateCard(i); };

  const countSpan = document.createElement('div');
  countSpan.className = 'leg-won-count';
  countSpan.id = 'legs-won-' + i;
  countSpan.textContent = p.legsWon;

  const plusBtn = document.createElement('button');
  plusBtn.className = 'lw-btn lw-plus';
  plusBtn.textContent = '+';
  plusBtn.onclick = e => { e.stopPropagation(); p.legsWon++; updateCard(i); };

  lwCounter.appendChild(minusBtn);
  lwCounter.appendChild(countSpan);
  lwCounter.appendChild(plusBtn);
  lwArea.appendChild(lwLabel);
  lwArea.appendChild(lwCounter);
  card.appendChild(lwArea);

  // Last throws
  const ltArea = document.createElement('div');
  ltArea.className = 'last-throws-area';
  ltArea.id = 'throws-' + i;
  renderThrowChips(p, ltArea, i);
  card.appendChild(ltArea);

  return card;
}

function renderThrowChips(p, container, playerIndex) {
  container.innerHTML = '';
  const last4 = p.throws.slice(-4);
  console.log('[renderThrowChips] Player ' + playerIndex + ': total throws=' + p.throws.length + ' last4=' + JSON.stringify(last4.map(t => t.value)));
  last4.forEach((t, j) => {
    const globalIdx = p.throws.length - last4.length + j;
    const chip = document.createElement('span');
    chip.className = 'throw-chip' + (t.isBust ? ' bust' : '');
    chip.textContent = t.isBust ? 'BUST' : t.value;
    chip.dataset.idx = String(globalIdx);
    chip.style.cursor = 'pointer';
    chip.title = 'Click to edit this throw';
    chip.onclick = function(e) { e.stopPropagation(); editThrow(playerIndex, globalIdx); };
    container.appendChild(chip);
  });
}

function recomputePlayerScores(playerIdx) {
  const p = players[playerIdx];
  if (!p) return;
  let score = parseInt(gameType) || 301;
  p.throws.forEach((t, i) => {
    t.scoreBefore = score;
    const val = parseInt(t.value || t.throw_value || 0, 10) || 0;
    let after = score - val;
    let isBust = false;
    if (after < 0 || after === 1) {
      isBust = true;
      after = score;
    }
    t.isBust = !!isBust;
    t.scoreAfter = after;
    score = after;
  });
  p.score = score;
}

function editThrow(playerIdx, throwIdx) {
  const p = players[playerIdx];
  if (!p || throwIdx < 0 || throwIdx >= p.throws.length) return;
  const current = p.throws[throwIdx];
  const promptMsg = 'Edit throw value (number) or leave blank to delete:';
  const res = prompt(promptMsg, String(current.value ?? current.throw_value ?? ''));
  if (res === null) return; // cancelled
  const valStr = res.trim();
  if (valStr === '') {
    // delete throw
    p.throws.splice(throwIdx, 1);
  } else {
    const nv = parseInt(valStr, 10);
    if (isNaN(nv)) { alert('Invalid number'); return; }
    // set new value
    p.throws[throwIdx].value = nv;
  }
  // Recompute score chain for this player and update UI
  recomputePlayerScores(playerIdx);
  updateCard(playerIdx);
  // Persist and broadcast the throw history change immediately
  try { autoSaveLeg(-1, false, function(){ showToast('Throw updated'); }); } catch(e) {
    try { saveLocalState(); broadcastThrowHistoryUpdate(); } catch(_) {}
  }
}

function broadcastThrowHistoryUpdate() {
  try { broadcastLiveState(); } catch (e) {}
  try { publishLiveStateDebounced(); } catch (e) {}
}

function updateCard(i) {
  const p = players[i];
  // Patch name/team inputs only if not currently focused (don't clobber typing)
  const card = document.getElementById('card-' + i);
  if (card) {
    const nameInp = card.querySelector('.player-name-edit');
    if (nameInp && document.activeElement !== nameInp && nameInp.value !== p.name) nameInp.value = p.name;
    const teamInp = card.querySelector('.team-name-edit');
    if (teamInp && document.activeElement !== teamInp && teamInp.value !== p.team) teamInp.value = p.team;
    card.className = 'player-card' + (i === currentPlayer ? ' active-card' : '');
  }
  const scoreEl = document.getElementById('score-' + i);
  if (scoreEl) scoreEl.textContent = p.score;
  const lwEl = document.getElementById('legs-won-' + i);
  if (lwEl) lwEl.textContent = p.legsWon;
  const chipsEl = document.getElementById('throws-' + i);
  if (chipsEl) renderThrowChips(p, chipsEl, i);
  updateArrowBtns();
  try { saveLocalState(); } catch(e) {}
  // Broadcast immediately to other admins for real-time sync (but not during state application)
  if (!_suppressBroadcast) {
    try { broadcastLiveState(); } catch(e) {}
    // Then debounce the HTTP publish to state.php
    try { publishLiveStateDebounced(); } catch(e) {}
  }
}

function updateArrowBtns() {
  const p = players[currentPlayer];
  document.getElementById('undo-btn').disabled = p.throws.length === 0;
  document.getElementById('redo-btn').disabled = p.redoStack.length === 0;
}

// ================================================================
// PLAYER SELECTION
// ================================================================
function selectPlayer(i) {
  currentPlayer = i;
  players.forEach((_, idx) => {
    const c = document.getElementById('card-' + idx);
    if (c) c.className = 'player-card' + (idx === i ? ' active-card' : '');
  });
  updateArrowBtns();
  // Broadcast immediately to other admins for real-time sync (but not during state application)
  if (!_suppressBroadcast) {
    try { broadcastLiveState(); } catch(e) {}
    // Then debounce the HTTP publish to state.php
    try { publishLiveStateDebounced(); } catch(e) {}
  }
}

// ================================================================
// NUMPAD
// ================================================================
function padPress(digit) {
  if (inputStr.length >= 3) return;
  inputStr += digit;
  document.getElementById('throw-display').textContent = inputStr || '0';
  // Broadcast immediately to other admins
  try { broadcastLiveState(); } catch(e) {}
  // Then debounce the HTTP publish
  publishLiveStateDebounced();
}

function padClear() {
  inputStr = '';
  document.getElementById('throw-display').textContent = '0';
  // Broadcast immediately to other admins
  try { broadcastLiveState(); } catch(e) {}
  // Then debounce the HTTP publish
  publishLiveStateDebounced();
}

function enterThrow() {
  const val = parseInt(inputStr, 10);
  if (isNaN(val) || val < 0 || val > 180) { padClear(); return; }
  padClear();

  const p = players[currentPlayer];
  const before = p.score;
  const after = before - val;

  let isBust = false;
  let finalScore = after;

  if (after < 0 || after === 1) {
    isBust = true;
    finalScore = before;
  }

  const throwEntry = { value: val, scoreBefore: before, scoreAfter: finalScore, isBust };
  p.throws.push(throwEntry);
  p.redoStack = [];
  p.score = finalScore;
  updateCard(currentPlayer);

  if (!isBust && after === 0) {
    // Score hit zero — publish the final throw state immediately so viewers
    // see it before the leg-won sequence begins, then trigger leg won.
    publishLiveState();
    try { broadcastLiveState(); } catch(e) {}
    triggerLegWon(currentPlayer, true);
  }
}

// Keyboard support
document.addEventListener('keydown', e => {
  if (e.key >= '0' && e.key <= '9') padPress(e.key);
  else if (e.key === 'Enter') enterThrow();
  else if (e.key === 'Backspace' || e.key === 'Delete' || e.key.toLowerCase() === 'c') padClear();
  else if (e.key === 'ArrowLeft') undoThrow();
  else if (e.key === 'ArrowRight') redoThrow();
  else if (e.key >= '1' && e.key <= '4' && e.altKey) selectPlayer(parseInt(e.key)-1);
});

// ================================================================
// UNDO / REDO
// ================================================================
function undoThrow() {
  const p = players[currentPlayer];
  if (!p.throws.length) return;
  const last = p.throws.pop();
  p.redoStack.push(last);
  p.score = last.scoreBefore;
  updateCard(currentPlayer);
}

function redoThrow() {
  const p = players[currentPlayer];
  if (!p.redoStack.length) return;
  const t = p.redoStack.pop();
  p.throws.push(t);
  p.score = t.scoreAfter;
  updateCard(currentPlayer);
}

// ================================================================
// LEG WON
// ================================================================
function triggerLegWon(playerIdx, autoStartNext = false) {
  const p = players[playerIdx];
  p.legsWon++;
  legsHistory.push(playerIdx);
  players.forEach(pl => pl.isWinner = false);
  updateCard(playerIdx);

  // mark a last-event so viewers can show a winner overlay for this leg/match
  try {
    window.__dartsEventSeq = (window.__dartsEventSeq || 0) + 1;
    window.__dartsLastEvent = {
      id: window.__dartsEventSeq,
      type: (p.legsWon >= legsToWin) ? 'match' : 'leg',
      player_number: p.playerNumber,
      leg_number: currentLeg,
      ts: Date.now()
    };
  } catch (e) {}
  // Immediately persist and broadcast the leg-won state to all admins
  try { saveLocalState(); } catch(e) {}
  try { renderAndBroadcast(); } catch(e) {}

  // Save completed leg to DB, then decide: next leg or match over
  autoSaveLeg(playerIdx, true, () => {
    if (p.legsWon >= legsToWin) {
      // Full match won — mark winner, save match, broadcast
      p.isWinner = true;
      _suppressPublish = true;
      renderCards();
      _suppressPublish = false;
      autoSaveMatch(p);
      try { renderAndBroadcast(); } catch(e) {}
      _fetchAndBroadcastCanonical(() => {
        showModal(
          `🏆 ${p.name} Wins the Match!`,
          `Congratulations! ${p.name} has won ${p.legsWon} legs and claimed the match.`,
          [
            { label: 'View Report', cls: '', cb: () => { closeModal(); openReport(); } },
            { label: 'New Match',   cls: 'secondary', cb: newMatch }
          ]
        );
      });
    } else {
      // Leg over — broadcast updated state then offer next leg or auto-start
      try { renderAndBroadcast(); } catch(e) {}
      _fetchAndBroadcastCanonical(() => {
        if (autoStartNext) {
          // clear last-event when starting next leg to hide viewer overlay
          try { window.__dartsLastEvent = null; } catch(e) {}
          startNextLeg();
          return;
        }
        showModal(
          `🏅 ${p.name} Wins the Leg!`,
          `Leg ${currentLeg} complete. ${p.name} leads with ${p.legsWon} leg${p.legsWon>1?'s':''} (need ${legsToWin}).`,
          [{ label: 'Start Next Leg', cls: '', cb: startNextLeg }]
        );
      });
    }
  });
}

function startNextLeg() {
  currentLeg++;
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
  });
  closeModal();
  // Suppress the debounced publish inside renderCards — publish once below after full reset
  _suppressPublish = true;
  renderCards();
  _suppressPublish = false;
  // Clear last-event when officially starting a new leg so viewers hide overlays
  try { window.__dartsLastEvent = null; } catch(e) {}
  try { saveLocalState(); } catch(e) {}
  try { renderAndBroadcast(); } catch(e) {}
}

// ================================================================
// MATCH WON (internal helpers)
// ================================================================

// Fetch the canonical server state after a save and broadcast to WS+BC viewers.
// Calls cb() when done (or on error) so callers can chain modal display.
function _fetchAndBroadcastCanonical(cb) {
  // Prefer WebSocket-driven canonical retrieval; fall back to HTTP if necessary.
  initWebSocket();
  requestCanonicalViaWS(function (ok) {
    if (ok) {
      // server should have sent canonical state which our WS handler applies.
      try { broadcastLiveState(); } catch (e) {}
      if (cb) cb();
      return;
    }
    // WS not available or timed out — fallback to HTTP fetch
    const mid = matchId || 0;
    fetch('state.php?match_id=' + encodeURIComponent(mid) + '&t=' + Date.now(), { cache: 'no-store' })
      .then(r => r.json())
      .then(js => {
        const canonical = js && js.state ? js.state : (js && js.payload ? js.payload : null);
        if (canonical) {
          try { broadcastCanonicalState(canonical); } catch (e) {}
        } else {
          try { broadcastLiveState(); } catch (e) {}
        }
      })
      .catch(() => { try { broadcastLiveState(); } catch (e) {} })
      .finally(() => { if (cb) cb(); });
  }, 2500);
}

// ================================================================
// SETTINGS
// ================================================================
function setGameType(gt, btn) {
  const newGt = parseInt(gt);
  if (gameType === newGt) return;
  if (!confirm(`Change game to ${gt}? This will reset all scores and throws but keep player names.`)) return;
  document.querySelectorAll('[data-gt]').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  gameType = newGt;
  const ci = document.getElementById('custom-game-input');
  if (ci) ci.value = '';
  _applyGameTypeChange();
}

function applyCustomGameType(val) {
  const n = parseInt(val, 10);
  if (!n || n < 1) return;
  if (!confirm(`Change game to ${n}? This will reset all scores and throws but keep player names.`)) {
    const ci = document.getElementById('custom-game-input');
    if (ci) ci.value = '';
    return;
  }
  document.querySelectorAll('[data-gt]').forEach(b => b.classList.remove('active'));
  gameType = n;
  _applyGameTypeChange();
}

// Reset only scores/throws/legs — preserve player names and teams.
// Then broadcast the updated state immediately to all clients.
function _applyGameTypeChange() {
  currentLeg = 1;
  legsHistory = [];
  try { window.__dartsLastEvent = null; } catch(e) {}
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
    p.legsWon = 0;
    p.isWinner = false;
    // dbPlayerId kept so existing DB row linkage survives
  });
  _suppressPublish = true;
  renderCards();
  _suppressPublish = false;
  try { saveLocalState(); } catch(e) {}
  try {
    const stateObj = serializeLiveState();
    if (!window._dartsBC) window._dartsBC = new BroadcastChannel('darts_live');
    window._dartsBC.postMessage({ match_id: String(matchId || 0), state: stateObj });
    try { sendWSMessage({ type: 'state', match_id: String(matchId || 0), payload: stateObj }); } catch(e) {}
    fetch('state.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ match_id: matchId || 0, state: stateObj })
    }).catch(() => {});
  } catch(e) {}
  showToast('Game set to ' + gameType + ' — scores reset.');
}

// Applies legsToWin change and broadcasts instantly to all clients
function applyLegsToWin(val) {
  const n = parseInt(val, 10);
  if (!n || n < 1) return;
  legsToWin = n;
  try { saveLocalState(); broadcastLiveState(); } catch(e) {}
  try {
    const payload = { match_id: matchId || 0, state: serializeLiveState() };
    fetch('state.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) }).catch(()=>{});
  } catch(e) {}
}

function setMode(m, btn) {
  mode = m;
  try { localStorage.setItem(DARTS_MODE_KEY, mode); } catch(e) {}
  btn.parentElement.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderCards();
  // mode is a per-admin preference like dark/light mode, so do not broadcast it to other clients.
}

function toggleDark(on) {
  document.body.classList.toggle('light-mode', !on);
  localStorage.setItem('darkMode', on ? '1' : '0');
}

function resetAllScores() {
  matchId = null;
  currentLeg = 1;
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
    p.legsWon = 0;
    p.dbPlayerId = null;
    p.isWinner = false;
  });
  legsHistory = [];
  renderCards();
}

async function newMatch() {
  if (!confirm('Start a new match? All current progress will be cleared.')) return;
  // If there is unsaved progress (throws, legs, or legsHistory), save it first to avoid losing data
  const hasProgress = players.some(p => (p.throws && p.throws.length > 0) || (p.legsWon && p.legsWon > 0)) || legsHistory.length > 0;
  const resetState = async () => {
    matchId = null;
    currentLeg = 1;
    players = [0,1,2,3].map(i => ({
      playerNumber: i+1,
      name: DEFAULT_NAMES[i],
      team: 'TEAM',
      score: gameType,
      legsWon: 0,
      throws: [],
      undoStack: [],
      redoStack: [],
      saveEnabled: true,
      dbPlayerId: null,
      isWinner: false,
    }));
    legsHistory = [];
    closeModal();
    try { window.__dartsLastEvent = null; } catch(e) {}
    renderCards();
    try { renderAndBroadcast(); } catch(e) {}
    try { sendWithAck({ type: 'new_match', match_id: '0', payload: serializeLiveState() }).catch(()=>{}); } catch(e) {}
    // Create new match
    try {
      const payload = buildSavePayload(false, -1);
      const response = await fetch('save_leg.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (data.success) {
        matchId = data.match_id;
        console.log('New match created with id:', matchId);
      }
    } catch (e) {
      console.error('Failed to create new match:', e);
    }
    // Publish fresh blank state to state.php so viewers on HTTP polling also reset
    try {
      const _nm_payload = { match_id: matchId || 0, state: serializeLiveState() };
      fetch('state.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(_nm_payload) })
        .then(() => { try { broadcastCanonicalState(serializeLiveState()); } catch(e) {} })
        .catch(() => {});
    } catch(e) {}
  };

  if (hasProgress) {
    // ask user whether to save before starting new match
    if (confirm('Save current match progress before starting a new match? (recommended)')) {
      saveBeforeNewMatch(async (ok) => {
        // proceed regardless of save success to avoid blocking the UI
        await resetState();
      });
      return;
    }
  }
  // no progress or user declined saving
  await resetState();
}

// ================================================================
// RESET MATCH (keep match ID, clear scores/throws/legs, broadcast)
// ================================================================
function resetMatch() {
  if (!confirm('Reset current match? Scores and throws will be cleared but the match ID is kept.')) return;
  currentLeg = 1;
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
    p.legsWon = 0;
    p.isWinner = false;
  });
  legsHistory = [];
  try { window.__dartsLastEvent = null; } catch(e) {}
  closeModal();
  renderCards();
  // Broadcast reset instantly to all connected clients (admin + viewer)
  try { saveLocalState(); } catch(e) {}
  try {
    const _rs = serializeLiveState();
    if (!window._dartsBC) window._dartsBC = new BroadcastChannel('darts_live');
    window._dartsBC.postMessage({ match_id: String(matchId || 0), state: _rs });
  } catch(e) {}
  try { sendWithAck({ type: 'state', match_id: String(matchId || 0), payload: serializeLiveState() }).catch(()=>{}); } catch(e) {}
  try {
    const _rp = { match_id: matchId || 0, state: serializeLiveState() };
    fetch('state.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(_rp) })
      .then(() => { try { broadcastCanonicalState(serializeLiveState()); } catch(e) {} })
      .catch(() => {});
  } catch(e) {}
  showToast('Match reset.');
}

// ================================================================
// MODAL HELPERS
// ================================================================
function showModal(title, body, actions) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').textContent = body;
  const actDiv = document.getElementById('modal-actions');
  actDiv.innerHTML = '';
  actions.forEach(a => {
    const btn = document.createElement('button');
    btn.className = 'modal-btn ' + (a.cls || '');
    btn.textContent = a.label;
    btn.onclick = a.cb;
    actDiv.appendChild(btn);
  });
  document.getElementById('modal-overlay').classList.add('show');
}

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('show');
}

// ================================================================
// TOAST
// ================================================================
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show';
  setTimeout(() => { t.className = ''; }, 2800);
}

function openReport() {
  if (!matchId) {
    showToast('No saved match yet — save first.');
    return;
  }
  // navigate to report in the same tab (do not open a new window)
  location.href = 'darts_report.php?match_id=' + encodeURIComponent(matchId);
}

function openDeclareModal() {
  // Two-step modal: first pick leg winner OR match winner
  const actions = players.map((p, i) => ({
    label: p.name + (p.legsWon > 0 ? ` (${p.legsWon} leg${p.legsWon>1?'s':''})` : ''),
    cls: '',
    cb: () => {
      closeModal();
      // Ask: leg win or match win?
      showModal(
        `Declare for ${p.name}`,
        `Did ${p.name} win the leg only, or the entire match?`,
        [
          {
            label: '🏅 Leg Win Only',
            cls: '',
            cb: () => { closeModal(); triggerLegWon(i, true); }
          },
          {
            label: '🏆 Match Win (Final)',
            cls: '',
            cb: () => { closeModal(); triggerDeclaredMatchWin(i); }
          },
          { label: 'Back', cls: 'secondary', cb: openDeclareModal }
        ]
      );
    }
  }));
  actions.push({ label: 'Cancel', cls: 'secondary', cb: closeModal });
  showModal('🏁 Declare Winner', 'Who won this leg?', actions);
}

// Called when admin manually declares a full match winner via the button.
// Saves the leg as completed, bumps legsWon, saves the match, broadcasts, then
// offers "View Report" or "New Match" — does NOT start another leg.
function triggerDeclaredMatchWin(playerIdx) {
  const p = players[playerIdx];
  p.legsWon++;
  legsHistory.push(playerIdx);
  players.forEach(pl => pl.isWinner = false);
  p.isWinner = true;
  updateCard(playerIdx);

  // mark last-event as match so viewers show the final winner overlay
  try {
    window.__dartsEventSeq = (window.__dartsEventSeq || 0) + 1;
    window.__dartsLastEvent = {
      id: window.__dartsEventSeq,
      type: 'match',
      player_number: p.playerNumber,
      leg_number: currentLeg,
      ts: Date.now()
    };
  } catch (e) {}

  // persist & broadcast immediately so viewers see the declared match winner
  try { saveLocalState(); } catch (e) {}
  try {
    if (!window._dartsBC) window._dartsBC = new BroadcastChannel('darts_live');
    window._dartsBC.postMessage({ match_id: String(matchId || 0), state: serializeLiveState() });
  } catch (e) {}
  try { sendWithAck({ type:'state', match_id: String(matchId||0), payload: serializeLiveState() }).catch(()=>{}); } catch(e) {}

  // 1. Save the winning leg to DB
  autoSaveLeg(playerIdx, true, () => {
    // 2. Save match summary + update winner_name in DB
    autoSaveMatch(p);

    // 3. Broadcast the winner state to all viewers
    try { renderAndBroadcast(); } catch(e) {}

    // 4. Fetch canonical server state and push to WS viewers
    _fetchAndBroadcastCanonical(() => {
      // 5. Show end-of-match modal (no "Next Leg" option)
      showModal(
        `🏆 ${p.name} Wins the Match!`,
        `${p.name} declared match winner with ${p.legsWon} leg${p.legsWon>1?'s':''}.`,
        [
          { label: 'View Report', cls: '', cb: () => { closeModal(); openReport(); } },
          { label: 'New Match',   cls: 'secondary', cb: newMatch }
        ]
      );
    });
  });
}

// ================================================================
// SAVE LEG (auto + manual)
// ================================================================
function buildSavePayload(isCompleted, winnerIdx) {
  return {
    match_id: matchId,
    game_type: String(gameType),
    legs_to_win: legsToWin,
    mode: mode,
    leg_number: currentLeg,
    is_completed: isCompleted,
    players: players.map((p, i) => ({
      player_number: p.playerNumber,
      player_name: p.name,
      team_name: p.team,
      save_enabled: p.saveEnabled ? 1 : 0,
      is_winner: i === winnerIdx ? 1 : 0,
      throws: p.throws.map(t => ({
        throw_value: t.value,
        score_before: t.scoreBefore,
        score_after: t.scoreAfter,
        is_bust: t.isBust ? 1 : 0,
      }))
    }))
    ,
    // include legsHistory as player_number values (1-based) so server can persist leg winners
    legs_history: legsHistory.map(idx => (players[idx] ? players[idx].playerNumber : null)).filter(x=>x!==null)
  };
}

function autoSaveLeg(winnerIdx, isCompleted, callback) {
  const payload = buildSavePayload(isCompleted, winnerIdx);
  fetch('save_leg.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(async r => {
    let data = null;
    try { data = await r.json(); } catch (e) { const txt = await r.text().catch(()=>null); console.error('Non-JSON response (autoSaveLeg):', txt); }
    if (data && data.success) {
      matchId = data.match_id || matchId;
      // Store DB player IDs
      if (data.player_ids) {
        players.forEach(p => {
          const dbId = data.player_ids[p.playerNumber];
          if (dbId) p.dbPlayerId = dbId;
        });
      }
      // after successful save, broadcast current state via WebSocket (server will cache and relay it)
      try {
        initWebSocket();
        const stateObj = serializeLiveState();
        try { broadcastCanonicalState(stateObj); } catch(e) { try { renderAndBroadcast(); } catch(e) {} }
        // Notify all viewers that a (possibly new) match exists — useful when server assigned a new match_id
        try { sendWithAck({ type: 'new_match', match_id: String(matchId || 0), payload: stateObj }).catch(()=>{}); } catch(e) {}
      } catch(e) { try { renderAndBroadcast(); } catch(e) {} }
    } else if (data) {
      console.error('autoSaveLeg failed:', data.error || data.message || data);
    }
    if (callback) callback();
  })
  .catch(err => { console.error('autoSaveLeg network error', err); if (callback) callback(); });
}

function saveCurrentLeg() {
  const payload = buildSavePayload(false, -1);
  fetch('save_leg.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(async r => {
    try {
      const data = await r.json();
        if (data && data.success) {
        matchId = data.match_id || matchId;
        showToast('Leg progress saved.');
        // Navigate to server-generated report page in same tab (no new window)
          try {
            initWebSocket();
            const stateObj = serializeLiveState();
            try { broadcastCanonicalState(stateObj); } catch(e) {}
            try { sendWithAck({ type: 'new_match', match_id: String(matchId || 0), payload: stateObj }).catch(()=>{}); } catch(e) {}
          } catch (e) {}
        if (matchId) {
          try { sessionStorage.setItem('disableBackAfterSave_darts', '1'); } catch(e){}
          location.href = 'darts_report.php?match_id=' + encodeURIComponent(matchId);
        }
      } else {
        console.error('saveCurrentLeg server error:', data);
        showToast('Save failed: ' + (data.error || data.message || 'server error'));
      }
    } catch (e) {
      const txt = await r.text().catch(()=>null);
      console.error('saveCurrentLeg parse error, response text:', txt, e);
      showToast('Save failed: server returned unexpected response');
    }
  })
  .catch(err => { console.error('saveCurrentLeg network error', err); showToast('Save failed: network error'); });
}

// ================================================================
// SAVE MATCH
// ================================================================
function autoSaveMatch(winnerPlayer) {
  // Save match summary and broadcast canonical server state once saved
  if (!matchId) return;
  const winnerDbId = winnerPlayer.dbPlayerId;
  const payload = {
    match_id: matchId,
    total_legs: players.reduce((s, p) => s + p.legsWon, 0),
    legs_won: { p1: players[0].legsWon, p2: players[1].legsWon, p3: players[2].legsWon, p4: players[3].legsWon },
    winner_player_id: winnerDbId,
    winner_name: winnerPlayer.name,
  };
  fetch('save_match.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(async r => {
    try {
      const data = await r.json();
      if (data && data.success) {
        // Broadcast current state via WebSocket so viewers receive canonical update
        try {
          initWebSocket();
          const stateObj = serializeLiveState();
          try { broadcastCanonicalState(stateObj); } catch(e) {}
        } catch(e) {}
      }
    } catch(e) {}
  }).catch(() => {});
}

// Save current leg to server (without navigating) then run callback — used before starting a new match
function saveBeforeNewMatch(cb) {
  const payload = buildSavePayload(false, -1);
  fetch('save_leg.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).then(async r => {
    try {
      const data = await r.json();
      if (data && data.success) {
        matchId = data.match_id || matchId;
        // Broadcast current state via WebSocket so viewers receive canonical update
        if (matchId) {
          try {
            initWebSocket();
            const stateObj = serializeLiveState();
            try { broadcastCanonicalState(stateObj); } catch(e) {}
            try { sendWithAck({ type: 'new_match', match_id: String(matchId || 0), payload: stateObj }).catch(()=>{}); } catch(e) {}
            if (cb) cb(true);
          } catch(e) { if (cb) cb(true); }
        } else {
          if (cb) cb(true);
        }
      } else {
        if (cb) cb(false);
      }
    } catch(e) { if (cb) cb(false); }
  }).catch(err => { console.error('saveBeforeNewMatch error', err); if (cb) cb(false); });
}

// Inline report removed — server-side darts_report.php is used for match report/export

// ================================================================
// INIT
// ================================================================
(function init() {
  // Generate unique client ID
  _clientId = 'client_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
  console.log('[INIT] Client ID:', _clientId);

  // Initialize WebSocket early to ensure connection
  console.log('[INIT] Initializing WebSocket...');
  try { initWebSocket(); } catch(e) { console.error('[INIT] WebSocket init failed:', e); }

  // Dark mode
  const dark = localStorage.getItem('darkMode');
  const isDark = dark === null ? true : dark === '1';
  document.getElementById('dark-mode-toggle').checked = isDark;
  document.body.classList.toggle('light-mode', !isDark);

  // Restore per-user mode preference from localStorage.
  try {
    const storedMode = localStorage.getItem(DARTS_MODE_KEY);
    if (storedMode === 'one-sided' || storedMode === 'two-sided') {
      mode = storedMode;
    }
    document.querySelectorAll('#settings .seg-btn').forEach(function(b) {
      var onclick = b.getAttribute('onclick') || '';
      var m = onclick.match(/setMode\('([^']+)'/);
      if (m) b.classList.toggle('active', m[1] === mode);
    });
  } catch (e) {}

  // If the page was opened as a deliberate "New Match" navigation
  // (darts_report.php redirects to index.php?new=1), clear any saved
  // local state so the admin starts fresh.
  let _isNewMatch = false;
  try {
    const params = new URLSearchParams(location.search);
    // Extract match_id from URL if provided (ensures all admins get same match)
    const urlMatchId = params.get('match_id');
    if (urlMatchId) {
      matchId = parseInt(urlMatchId, 10) || 0;
      console.log('[INIT] Match ID from URL: ' + matchId);
      try { sessionStorage.setItem('darts_match_id', String(matchId)); } catch(e) {}
      // Ensure WebSocket joins the correct room immediately
      joinWebSocketRoom();
    }
    if (params.get('new') === '1') {
      _isNewMatch = true;
      try { localStorage.removeItem(DARTS_LOCAL_KEY); } catch(e) {}
      try { sessionStorage.removeItem('darts_match_id'); } catch(e) {}
      try { sessionStorage.setItem('disableBackAfterSave_darts', '1'); } catch(e) {}
      // remove query param so subsequent reloads are clean
      try { history.replaceState(null, '', location.pathname); } catch(e) {}
    }
  } catch(e) {}

  // Step 1 — apply localStorage immediately so the page isn't blank while
  // SSOT FIX: Fetch server state first to ensure consistency
  _suppressPublish = true;
  // Use the match_id from URL if available, otherwise 0
  const fetchMatchId = matchId || 0;
  fetch('state.php?match_id=' + fetchMatchId)
    .then(r => r.json())
    .then(data => {
      if (data.state) {
        applyState(data.state);
        saveLocalState();
      } else {
        restoreLocalState();
      }
      renderCards();
      updateArrowBtns();
      _suppressPublish = false;
    })
    .catch(() => {
      restoreLocalState();
      renderCards();
      updateArrowBtns();
      _suppressPublish = false;
    });

  // FIX: When this is a deliberate new-match start (?new=1), do NOT fetch the
  // canonical server state — that would restore the just-finished match from the
  // pending state file, overwriting the fresh board and re-showing old throw chips.
  // Instead, publish the clean state immediately so viewers update right away.
  if (_isNewMatch) {
    // Create new match for ?new=1
    try {
      const payload = buildSavePayload(false, -1);
      fetch('save_leg.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          matchId = data.match_id;
          console.log('New match created on load:', matchId);
        }
      })
      .catch(e => console.error('Failed to create new match on load:', e))
      .finally(() => {
        _suppressPublish = false; // re-enable: clean new-match state is safe to publish
        try { initWebSocket(); } catch(e) {}
        try { publishLiveState(); broadcastLiveState(); } catch(e) {}
        // Also send an explicit new_match signal via WS so cross-device viewers
        // that are stuck on the new match_id switch over immediately.
        try { sendWithAck({ type: 'new_match', match_id: String(matchId || 0), payload: serializeLiveState() }).catch(()=>{}); } catch(e) {}
      });
    } catch(e) {
      _suppressPublish = false;
      try { initWebSocket(); } catch(e) {}
      try { publishLiveState(); broadcastLiveState(); } catch(e) {}
      try { sendWithAck({ type: 'new_match', match_id: '0', payload: serializeLiveState() }).catch(()=>{}); } catch(e) {}
    }
    return;
  }

  // Step 2 — fetch the canonical server state (state.php).
  //          The server is the single source of truth — it wins over localStorage.
  //          This restores data correctly even in a different browser/device/incognito.
  const localMatchId = matchId || 0;
  // Prefer WebSocket for fast canonical state retrieval; fallback to HTTP polling
  initWebSocket();
  requestCanonicalViaWS(function (ok) {
    if (!ok) {
      fetch('state.php?match_id=' + encodeURIComponent(localMatchId) + '&t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
          const st = data && data.state ? data.state : (data && data.payload ? data.payload : null);
          // Cancel any debounce that was started by the initial renderCards() call —
          // it carries stale localStorage data and must not fire after this point.
          if (_publishTimer) { clearTimeout(_publishTimer); _publishTimer = null; }
          if (st) {
            // Server has live state — apply it, overwriting localStorage data
            applyState(st);
            // Also save it back to localStorage so the next reload is instant
            saveLocalState();
          }
          // Re-enable publish only after canonical state is confirmed and applied
          _suppressPublish = false;
          renderCards();
          updateArrowBtns();
          // Broadcast the canonical (server) state — not the stale local state
          try { publishLiveState(); } catch(e) {}
        })
        .catch(() => {
          // Network error: still re-enable publish so the UI remains functional
          if (_publishTimer) { clearTimeout(_publishTimer); _publishTimer = null; }
          _suppressPublish = false;
          try { publishLiveState(); } catch(e) {}
        });
    } else {
      // WS delivered canonical state (handled in onmessage). Cancel stale debounce,
      // re-enable publish, then persist/broadcast the now-canonical board.
      if (_publishTimer) { clearTimeout(_publishTimer); _publishTimer = null; }
      _suppressPublish = false;
      try { publishLiveState(); } catch(e) {}
    }
  }, 2500);
})();