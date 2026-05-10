let state = {
  matchType: 'singles',
  serving: 'A',
  swapped: false,
  teamA: { name: 'TEAM A', players: [''], score: 0, gamesWon: 0, timeout: 0 },
  teamB: { name: 'TEAM B', players: [''], score: 0, gamesWon: 0, timeout: 0 },
  bestOf: 3,
  currentSet: 1,
  // map of manual winner flags per setNumber: { '1': 'A', '2': 'B' }
  manualWinners: {}
};

// Track previous sets length to detect new sets
let _prevSetsLength = 0;

// Track previous winner team to detect match winner
let _prevWinnerTeam = null;

// Page guard to prevent cross-sport bleed
const IS_BADMINTON_PAGE = typeof document !== 'undefined' && document.body && document.body.dataset && document.body.dataset.sport === 'badminton';

// ✅ SSOT FIX START — Patch 2
// Stable per-tab identifier: prevents the admin from re-broadcasting
// its own writes when they arrive back via WS from other clients.
const _ADMIN_TAB_ID = (function() {
  try {
    let id = sessionStorage.getItem('_adminTabId');
    if (!id) { id = Math.random().toString(36).slice(2); sessionStorage.setItem('_adminTabId', id); }
    return id;
  } catch (_) { return Math.random().toString(36).slice(2); }
})();
// ✅ SSOT FIX END

// History of completed sets. Each entry: { setNumber, teamAScore, teamBScore, teamATimeout, teamBTimeout, serving, winner }
// NOTE: persisted to localStorage so it survives page refreshes.
let setHistory = [];

// Key used by viewer to read live state
const STORAGE_KEY = 'badmintonMatchState';
// Key for full admin state (scores + setHistory + match_id)
const ADMIN_STATE_KEY = 'badmintonAdminState';

// ── Debounced server-side state persist (state.php) ──────────────
// Fires ~400 ms after the last action so we don't hammer the server
// on rapid score changes. Keeps the DB in sync for cross-device
// viewers that join mid-match or reload.
function normalizeMatchType(type) {
  const raw = String(type || 'singles').toLowerCase();
  if (raw.indexOf('mixed') !== -1) return 'mixed';
  if (raw.indexOf('double') !== -1) return 'doubles';
  return 'singles';
}

function expectedPlayerCount(type) {
  return normalizeMatchType(type || state.matchType) === 'singles' ? 1 : 2;
}

function normalizePlayers(players, type) {
  const count = expectedPlayerCount(type);
  const values = Array.isArray(players) ? players : [];
  const out = [];
  for (let i = 0; i < count; i++) out.push(values[i] == null ? '' : String(values[i]));
  return out;
}

function normalizeTeamPlayers(team) {
  const teamKey = 'team' + team;
  if (!state[teamKey]) return [];
  state.matchType = normalizeMatchType(state.matchType);
  state[teamKey].players = normalizePlayers(state[teamKey].players, state.matchType);
  return state[teamKey].players;
}

function syncPlayerInputs(team) {
  const teamKey = 'team' + team;
  const container = document.getElementById('bd-players' + team);
  if (!container || !state[teamKey]) {
    normalizeTeamPlayers(team);
    return;
  }
  const values = Array.from(container.querySelectorAll('input')).map(function(input) {
    return input.value || '';
  });
  state[teamKey].players = normalizePlayers(values, state.matchType);
}

function syncAllPlayerInputs() {
  syncPlayerInputs('A');
  syncPlayerInputs('B');
}

function getPlayerRole(type, idx) {
  const normalized = normalizeMatchType(type);
  if (normalized === 'singles') return 'Player';
  if (normalized === 'mixed') return idx === 0 ? 'Male Player' : 'Female Player';
  return 'Player ' + (idx + 1);
}

let _serverPersistTimer = null;
function scheduleServerPersist() {
  if (_serverPersistTimer) clearTimeout(_serverPersistTimer);
  _serverPersistTimer = setTimeout(function () {
    // ✅ SSOT FIX START — Patch 1
    // Always build the payload from the canonical in-memory `state` object,
    // never from localStorage (which may be stale or written by another tab).
    try {
      syncAllPlayerInputs();
      const matchIdRaw = sessionStorage.getItem('badminton_match_id');
      const committee = document.getElementById('bdCommitteeInput')
        ? document.getElementById('bdCommitteeInput').value.trim() : '';
      const payload = {
        match_id: matchIdRaw ? matchIdRaw : 'live',
        matchType:    state.matchType,
        serving:      state.serving,
        swapped:      state.swapped,
        bestOf:       state.bestOf,
        currentSet:   state.currentSet,
        manualWinners: state.manualWinners || {},
        teamAName:    state.teamA.name,
        teamBName:    state.teamB.name,
        scoreA:       state.teamA.score,
        scoreB:       state.teamB.score,
        gamesA:       state.teamA.gamesWon,
        gamesB:       state.teamB.gamesWon,
        timeoutA:     state.teamA.timeout,
        timeoutB:     state.teamB.timeout,
        servingTeam:  state.serving,
        teamAPlayer1: state.teamA.players[0] || '',
        teamAPlayer2: state.teamA.players[1] || '',
        teamBPlayer1: state.teamB.players[0] || '',
        teamBPlayer2: state.teamB.players[1] || '',
        committee:    committee,
        setHistory:   Array.isArray(setHistory) ? setHistory : [],
        // Tag this write with the originating tab so receivers can skip re-broadcasting it
        _origin: _ADMIN_TAB_ID
      };
      fetch('state.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).catch(function () { /* silent — offline is fine */ });
    } catch (e) { /* ignore */ }
    // ✅ SSOT FIX END
  }, 400);
}

// ── Save full admin state to localStorage (for refresh recovery) ─
function saveAdminState() {
  try {
    syncAllPlayerInputs();
    const matchIdRaw = sessionStorage.getItem('badminton_match_id');
    const adminSnap = {
      state: JSON.parse(JSON.stringify(state)),
      setHistory: Array.isArray(setHistory) ? setHistory.slice() : [],
      match_id: matchIdRaw || null,
      committee: (function(){ const el=document.getElementById('bdCommitteeInput'); return el?el.value.trim():''; })()
    };
    localStorage.setItem(ADMIN_STATE_KEY, JSON.stringify(adminSnap));
  } catch (e) { /* ignore */ }
}

// ── Restore full admin state from localStorage on page load ──────
function loadPersistedState() {
  try {
    const raw = localStorage.getItem(ADMIN_STATE_KEY);
    if (!raw) return false;
    const snap = JSON.parse(raw);
    if (!snap || !snap.state) return false;

    // Restore core state object
    const s = snap.state;
    state.matchType    = normalizeMatchType(s.matchType || 'singles');
    state.serving      = s.serving      || 'A';
    state.swapped      = s.swapped      || false;
    state.bestOf       = s.bestOf       || 3;
    state.currentSet   = s.currentSet   || 1;
    state.manualWinners = s.manualWinners || {};

    if (s.teamA) {
      state.teamA.name     = s.teamA.name     || 'TEAM A';
      state.teamA.players  = normalizePlayers(Array.isArray(s.teamA.players) ? s.teamA.players : [snap.playerA0 || '', snap.playerA1 || ''], state.matchType);
      state.teamA.score    = s.teamA.score    || 0;
      state.teamA.gamesWon = s.teamA.gamesWon || 0;
      state.teamA.timeout  = s.teamA.timeout  || 0;
    }
    if (s.teamB) {
      state.teamB.name     = s.teamB.name     || 'TEAM B';
      state.teamB.players  = normalizePlayers(Array.isArray(s.teamB.players) ? s.teamB.players : [snap.playerB0 || '', snap.playerB1 || ''], state.matchType);
      state.teamB.score    = s.teamB.score    || 0;
      state.teamB.gamesWon = s.teamB.gamesWon || 0;
      state.teamB.timeout  = s.teamB.timeout  || 0;
    }

    // Restore set history
    if (Array.isArray(snap.setHistory)) {
      setHistory.length = 0;
      snap.setHistory.forEach(function(h){ setHistory.push(h); });
    }

    // Restore match_id to sessionStorage
    if (snap.match_id) {
      sessionStorage.setItem('badminton_match_id', snap.match_id);
    }

    // Restore DOM: scores, games, timeouts, bestOf, currentSet
    const safe = function(id, val){ const el=document.getElementById(id); if(el) el.textContent = val; };
    safe('scoreA',      state.teamA.score);
    safe('scoreB',      state.teamB.score);
    safe('gamesA',      state.teamA.gamesWon);
    safe('gamesB',      state.teamB.gamesWon);
    safe('timeoutA',    state.teamA.timeout);
    safe('timeoutB',    state.teamB.timeout);
    safe('bestOfBox',   state.bestOf);
    safe('currentSetBox', state.currentSet);

    // Restore team name spans
    const spanA = document.getElementById('teamAName'); if(spanA) spanA.textContent = state.teamA.name;
    const spanB = document.getElementById('teamBName'); if(spanB) spanB.textContent = state.teamB.name;

    // Restore match type and render the canonical player inputs once.
    setMatchType(state.matchType, { skipSave: true, skipSync: true });

    // Restore committee/official
    const comEl = document.getElementById('bdCommitteeInput');
    if (comEl && snap.committee) comEl.value = snap.committee;

    // Restore swap layout
    const area  = document.getElementById('mainArea');
    const toRow = document.getElementById('timeoutRow');
    if (state.swapped) {
      if (area)  area.style.gridTemplateAreas = '"right center left"';
      if (toRow) toRow.style.flexDirection    = 'row-reverse';
    } else {
      if (area)  area.style.gridTemplateAreas = '"left center right"';
      if (toRow) toRow.style.flexDirection    = 'row';
    }

    // Restore serving label and match type buttons
    updateLabels();
    try { updateAdminWinnerButtons(); } catch(_){}

    console.log('[badminton] State restored from localStorage — Set ' + state.currentSet + ', Score A:' + state.teamA.score + ' B:' + state.teamB.score);
    return true;
  } catch (e) {
    console.warn('[badminton] loadPersistedState error:', e);
    return false;
  }
}

// --- WebSocket relay: broadcast viewer payloads to a local relay (ws-server)
let _ws = null;
function _initWS() {
  try {
    const scheme = (location.protocol === 'https:') ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _ws = new WebSocket(url);
    _ws.addEventListener('open', function () { console.log('badminton admin WS connected'); _setWSStatus('connected'); try { _ws.send(JSON.stringify({ type: 'join', match_id: getMatchId() })); } catch (e) {} });
    _ws.addEventListener('close', function () { console.log('badminton admin WS closed, reconnecting...'); _setWSStatus('disconnected'); setTimeout(_initWS, 2000); });
    _ws.addEventListener('error', function () { _setWSStatus('error'); /* ignore transient errors */ });
    // inbound message handler: apply remote state/action messages coming from ws-server
    _ws.addEventListener('message', function (ev) {
      try {
        const m = JSON.parse(ev.data);
        if (!m) return;
        if (m.sport && m.sport !== 'badminton') return;
        const mid = m.match_id || (m.payload && (m.payload.match_id || m.payload.matchId)) || null;

        if (m.type === 'last_state' && m.payload) {
          try { applyRemotePayload(m.payload, mid); } catch (_) {}
          return;
        }

        if ((m.type === 'badminton_state' || m.type === 'state') && m.payload) {
          try { applyRemotePayload(m.payload, mid); } catch (_) {}
          return;
        }

        if (m.type === 'applied_action' && m.payload) {
          try { applyRemotePayload(m.payload, mid); } catch (_) {}
          return;
        }

        if (m.type === 'new_match') {
          const newMid = m.match_id || (m.payload && (m.payload.match_id || m.payload.matchId)) || null;
          // ── Remote reset: another admin triggered a reset — apply it here ──
          if (m.payload && m.payload._reset === true) {
            _applyRemoteReset();
            return;
          }
          if (!newMid) return;
          try { sessionStorage.setItem('badminton_match_id', String(newMid)); } catch(_) {}
          try { _ws.send(JSON.stringify({ type: 'join', match_id: String(newMid) })); } catch(_) {}
          if (m.payload && typeof m.payload === 'object' && (m.payload.teamA || m.payload.team_a || m.payload.sets)) {
            try { applyRemotePayload(m.payload, newMid); } catch(_) {}
          } else if (newMid) {
            // fetch canonical server state if payload not provided
            try {
              fetch('state.php?match_id=' + encodeURIComponent(newMid)).then(r => r.text()).then(txt => { try { const obj = txt ? JSON.parse(txt) : null; if (obj && obj.state) applyRemotePayload(obj.state, newMid); else if (obj) applyRemotePayload(obj, newMid); } catch(_){} }).catch(()=>{});
            } catch(_) {}
          }
          return;
        }
      } catch (e) {
        // ignore malformed messages
      }
    });
  } catch (e) { _ws = null; }
}
_initWS();

function _maybeSendWS(viewerState) {
  try {
    if (_ws && _ws.readyState === 1) {
      _ws.send(JSON.stringify({ type: 'badminton_state', sport: 'badminton', match_id: getMatchId(), payload: viewerState }));
    }
  } catch (e) { /* swallow send errors */ }
}

function getMatchId() {
  try {
    if (window.MATCH_DATA && MATCH_DATA.match_id) return String(MATCH_DATA.match_id);
    if (window.__matchId) return String(window.__matchId);
    const el = document.getElementById('matchId'); if (el) return String(el.value || el.textContent || '').trim() || null;
    return null;
  } catch (e) { return null; }
}

// Apply a remote payload (from another admin or the server) into the admin UI
function applyRemotePayload(payload, mid) {
  if (!payload || typeof payload !== 'object') return;
  // ✅ SSOT FIX START — Patch 3
  // If this payload was written by THIS tab (echoed back via WS), skip it.
  // This prevents the ping-pong loop where admin A writes → server broadcasts → admin A re-applies.
  if (payload._origin && payload._origin === _ADMIN_TAB_ID) return;
  // ✅ SSOT FIX END

  // ✅ NEW MATCH HANDLING — Check if this is a new match broadcast
  if (payload._newMatch === true && payload.match_id) {
    // This is a new match broadcast from another admin tab
    try {
      // Clear all previous state
      try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
      try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
      try { sessionStorage.setItem('badminton_match_id', String(payload.match_id)); } catch (_) {}

      // Reset in-memory state to fresh defaults from the payload
      state.matchType = normalizeMatchType(payload.matchType || payload.match_type || 'singles');
      state.serving = payload.serving || payload.servingTeam || payload.serving_team || 'A';
      state.swapped = !!payload.swapped;
      state.bestOf = payload.bestOf || payload.best_of || 3;
      state.currentSet = payload.currentSet || payload.current_set || 1;
      state.manualWinners = payload.manualWinners || payload.manual_winners || {};

      // Team names
      state.teamA.name = payload.teamAName || payload.team_a_name || 'TEAM A';
      state.teamB.name = payload.teamBName || payload.team_b_name || 'TEAM B';

      // Scores / games / timeouts
      state.teamA.score = payload.scoreA || payload.team_a_score || 0;
      state.teamB.score = payload.scoreB || payload.team_b_score || 0;
      state.teamA.gamesWon = payload.gamesA || payload.team_a_games || 0;
      state.teamB.gamesWon = payload.gamesB || payload.team_b_games || 0;
      state.teamA.timeout = payload.timeoutA || payload.team_a_timeout || 0;
      state.teamB.timeout = payload.timeoutB || payload.team_b_timeout || 0;

      // Players
      state.teamA.players = normalizePlayers([
        payload.teamAPlayer1 || payload.team_a_player1 || '',
        payload.teamAPlayer2 || payload.team_a_player2 || ''
      ], state.matchType);
      state.teamB.players = normalizePlayers([
        payload.teamBPlayer1 || payload.team_b_player1 || '',
        payload.teamBPlayer2 || payload.team_b_player2 || ''
      ], state.matchType);

      // Clear set history for new match
      setHistory = [];

      // Update DOM elements for new match
      try { const elA=document.getElementById('teamAName'); if (elA) elA.textContent = state.teamA.name; } catch(_){}
      try { const elB=document.getElementById('teamBName'); if (elB) elB.textContent = state.teamB.name; } catch(_){}
      try { const comEl = document.getElementById('bdCommitteeInput'); if (comEl) comEl.value = payload.committee || ''; } catch(_){}
      try { const sA=document.getElementById('scoreA'); if (sA) sA.textContent = state.teamA.score; } catch(_){}
      try { const sB=document.getElementById('scoreB'); if (sB) sB.textContent = state.teamB.score; } catch(_){}
      try { const gA=document.getElementById('gamesA'); if (gA) gA.textContent = state.teamA.gamesWon; } catch(_){}
      try { const gB=document.getElementById('gamesB'); if (gB) gB.textContent = state.teamB.gamesWon; } catch(_){}
      try { const tAel=document.getElementById('timeoutA'); if (tAel) tAel.textContent = state.teamA.timeout; } catch(_){}
      try { const tBel=document.getElementById('timeoutB'); if (tBel) tBel.textContent = state.teamB.timeout; } catch(_){}
      try { const bo=document.getElementById('bestOfBox'); if (bo) bo.textContent = state.bestOf; } catch(_){}
      try { const cs=document.getElementById('currentSetBox'); if (cs) cs.textContent = state.currentSet; } catch(_){}

      // Update match type buttons
      document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === state.matchType));

      // Reset swap layout
      const area = document.getElementById('mainArea');
      const toRow = document.getElementById('timeoutRow');
      if (area) area.style.gridTemplateAreas = '"left center right"';
      if (toRow) toRow.style.flexDirection = 'row';

      // Re-render player inputs and labels
      try { renderPlayers('A'); renderPlayers('B'); } catch(_){}
      try { updateLabels(); } catch(_){}
      try { updateAdminWinnerButtons(); } catch(_){}

      // Persist admin snapshot
      try { saveAdminState(); } catch(_){}

      // Update viewer storage/channel
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(payload)); } catch(_){}
      try { if (_bdBC) _bdBC.postMessage(payload); } catch(_){}

      return; // Exit early for new match handling
    } catch (e) {
      console.warn('applyRemotePayload new match error', e);
      return;
    }
  }

  try {
    // adopt match id if provided
    try { if (mid) sessionStorage.setItem('badminton_match_id', String(mid)); } catch(_) {}

    // Normalize nested/new vs legacy flat payloads
    const tA = payload.teamA || payload.team_a || {};
    const tB = payload.teamB || payload.team_b || {};

    state.matchType = normalizeMatchType(payload.matchType || payload.match_type || state.matchType || 'singles');
    // Update match type buttons
    document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === state.matchType));
    state.serving   = payload.serving || payload.servingTeam || payload.serving_team || state.serving || 'A';
    state.swapped   = !!payload.swapped;
    state.bestOf    = payload.bestOf || payload.best_of || state.bestOf || 3;
    state.currentSet= payload.currentSet || payload.current_set || state.currentSet || 1;

    // Team names
    state.teamA.name = tA.name || payload.teamAName || payload.team_a_name || state.teamA.name;
    state.teamB.name = tB.name || payload.teamBName || payload.team_b_name || state.teamB.name;

    // Scores / games / timeouts
    state.teamA.score    = (tA.score != null ? tA.score : (payload.scoreA != null ? payload.scoreA : state.teamA.score)) || 0;
    state.teamB.score    = (tB.score != null ? tB.score : (payload.scoreB != null ? payload.scoreB : state.teamB.score)) || 0;
    state.teamA.gamesWon = (tA.gamesWon != null ? tA.gamesWon : (payload.gamesA != null ? payload.gamesA : state.teamA.gamesWon)) || 0;
    state.teamB.gamesWon = (tB.gamesWon != null ? tB.gamesWon : (payload.gamesB != null ? payload.gamesB : state.teamB.gamesWon)) || 0;
    state.teamA.timeout  = (tA.timeout != null ? tA.timeout : (payload.timeoutA != null ? payload.timeoutA : state.teamA.timeout)) || 0;
    state.teamB.timeout  = (tB.timeout != null ? tB.timeout : (payload.timeoutB != null ? payload.timeoutB : state.teamB.timeout)) || 0;

    // Players: prefer nested arrays, fallback to individual fields, then normalize by match type.
    state.teamA.players = normalizePlayers(Array.isArray(tA.players) ? tA.players : [payload.teamAPlayer1 || payload.team_a_player1 || '', payload.teamAPlayer2 || payload.team_a_player2 || ''], state.matchType);
    state.teamB.players = normalizePlayers(Array.isArray(tB.players) ? tB.players : [payload.teamBPlayer1 || payload.team_b_player1 || '', payload.teamBPlayer2 || payload.team_b_player2 || ''], state.matchType);

    // Sets history (support both 'sets' and 'setHistory')
    if (Array.isArray(payload.sets)) {
      setHistory = payload.sets.map(function(s){ return { setNumber: s.setNumber || s.set_number || 0, teamAScore: s.teamAScore || s.team_a_score || 0, teamBScore: s.teamBScore || s.team_b_score || 0, teamATimeout: s.teamATimeout || s.team_a_timeout_used || 0, teamBTimeout: s.teamBTimeout || s.team_b_timeout_used || 0, serving: s.serving || s.serving_team || 'A', winner: s.winner || s.set_winner || null }; });
    } else if (Array.isArray(payload.setHistory)) {
      setHistory = payload.setHistory.slice();
    }

    state.manualWinners = payload.manualWinners || payload.manual_winners || state.manualWinners || {};
    try { syncSetWinCounts(); } catch(_){}

    // Update DOM elements
    try { const elA=document.getElementById('teamAName'); if (elA) elA.textContent = state.teamA.name; } catch(_){}
    try { const elB=document.getElementById('teamBName'); if (elB) elB.textContent = state.teamB.name; } catch(_){}
    // Update committee input if present
    try { const comVal = payload.committee || payload.committee_official || ''; const comEl = document.getElementById('bdCommitteeInput'); if (comEl && String(comEl.value || '').trim() !== String(comVal || '').trim()) comEl.value = comVal; } catch(_){}
    try { const sA=document.getElementById('scoreA'); if (sA) sA.textContent = state.teamA.score; } catch(_){}
    try { const sB=document.getElementById('scoreB'); if (sB) sB.textContent = state.teamB.score; } catch(_){}
    try { const gA=document.getElementById('gamesA'); if (gA) gA.textContent = state.teamA.gamesWon; } catch(_){}
    try { const gB=document.getElementById('gamesB'); if (gB) gB.textContent = state.teamB.gamesWon; } catch(_){}
    try { const tAel=document.getElementById('timeoutA'); if (tAel) tAel.textContent = state.teamA.timeout; } catch(_){}
    try { const tBel=document.getElementById('timeoutB'); if (tBel) tBel.textContent = state.teamB.timeout; } catch(_){}
    try { const bo=document.getElementById('bestOfBox'); if (bo) bo.textContent = state.bestOf; } catch(_){}
    try { const cs=document.getElementById('currentSetBox'); if (cs) cs.textContent = state.currentSet; } catch(_){}

    // Re-render player inputs and labels
    try { renderPlayers('A'); renderPlayers('B'); } catch(_){}
    try { updateLabels(); } catch(_){}
    try { updateAdminWinnerButtons(); } catch(_){}

    // Persist admin snapshot (no server write) and also update viewer storage/channel
    try { saveAdminState(); } catch(_){}
    try {
      const viewerState = {
        sets: Array.isArray(setHistory) ? setHistory.map(s => ({ setNumber: s.setNumber, teamAScore: s.teamAScore, teamBScore: s.teamBScore, winner: getSetWinner(s) })) : [],
        swapped: state.swapped || false,
        teamAName: state.teamA.name,
        teamBName: state.teamB.name,
        scoreA: state.teamA.score,
        scoreB: state.teamB.score,
        gamesA: state.teamA.gamesWon,
        gamesB: state.teamB.gamesWon,
        bestOf: state.bestOf,
        currentSet: state.currentSet,
        timeoutA: state.teamA.timeout,
        timeoutB: state.teamB.timeout,
        servingTeam: state.serving,
        committee: payload.committee || payload.committee_official || '',
        matchType: state.matchType,
        teamAPlayer1: state.teamA.players[0] || '',
        teamAPlayer2: state.teamA.players[1] || '',
        teamBPlayer1: state.teamB.players[0] || '',
        teamBPlayer2: state.teamB.players[1] || '',
        manualWinners: state.manualWinners || {}
      };
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(viewerState)); } catch(_){}
      try { if (_bdBC) _bdBC.postMessage(viewerState); } catch(_){}
    } catch(_) {}

  } catch (e) {
    console.warn('applyRemotePayload error', e);
  }
}

// WS status indicator (dismissible)
function _ensureWSIndicator() {
  try {
    if (window.__wsStatusDismissed) return;
    if (document.getElementById('wsStatus')) return;
    const bar = document.createElement('div');
    bar.id = 'wsStatus';
    bar.style.position = 'fixed';
    bar.style.right = '12px';
    bar.style.bottom = '12px';
    bar.style.padding = '6px 10px';
    bar.style.borderRadius = '6px';
    bar.style.background = '#ddd';
    bar.style.color = '#111';
    bar.style.fontSize = '12px';
    bar.style.zIndex = '9999';
    bar.style.display = 'flex';
    bar.style.alignItems = 'center';
    const label = document.createElement('span');
    label.id = 'wsStatusLabel'; label.textContent = 'WS: unknown'; label.style.marginRight = '8px';
    const closeBtn = document.createElement('button'); closeBtn.type = 'button'; closeBtn.textContent = '✕'; closeBtn.title = 'Dismiss WS status'; closeBtn.style.border = 'none'; closeBtn.style.background = 'transparent'; closeBtn.style.cursor = 'pointer'; closeBtn.style.fontSize = '12px'; closeBtn.onclick = function () { window.__wsStatusDismissed = true; const el = document.getElementById('wsStatus'); if (el) el.remove(); };
    bar.appendChild(label); bar.appendChild(closeBtn); document.body.appendChild(bar);
  } catch (e) {}
}

function _setWSStatus(s) {
  try {
    if (window.__wsStatusDismissed) return;
    _ensureWSIndicator();
    const label = document.getElementById('wsStatusLabel');
    const el = document.getElementById('wsStatus');
    if (!el || !label) return;
    if (s === 'connected') { el.style.background = '#dff0d8'; label.style.color = '#155724'; label.textContent = 'WS: connected'; }
    else if (s === 'disconnected') { el.style.background = '#f8d7da'; label.style.color = '#721c24'; label.textContent = 'WS: disconnected'; }
    else if (s === 'error') { el.style.background = '#fce5cd'; label.style.color = '#7a4100'; label.textContent = 'WS: error'; }
    else { el.style.background = '#e2e3e5'; label.style.color = '#383d41'; label.textContent = 'WS: ' + String(s || 'unknown'); }
  } catch (e) {}
}

_setWSStatus('connecting');

// Helper to read player input values from DOM
function getPlayerInput(team, idx) {
  const teamKey = 'team' + team;
  if (!state[teamKey]) return '';
  normalizeTeamPlayers(team);
  return state[teamKey].players[idx] || '';
}

function getSetWinnerFromScores(aScore, bScore) {
  const a = parseInt(aScore, 10) || 0;
  const b = parseInt(bScore, 10) || 0;
  if (a === b) return null;
  return a > b ? 'A' : 'B';
}

function getManualWinnerForSet(setNumber) {
  try {
    const manual = state.manualWinners || {};
    const key = String(setNumber || state.currentSet || 1);
    const winner = manual[key] ? String(manual[key]).toUpperCase() : null;
    return (winner === 'A' || winner === 'B') ? winner : null;
  } catch (_) {
    return null;
  }
}

function getSetWinner(setData) {
  if (!setData) return null;
  const setNumber = setData.setNumber || setData.set_number || state.currentSet || 1;
  const manualWinner = getManualWinnerForSet(setNumber);
  if (manualWinner) return manualWinner;
  const explicit = setData.winner || setData.set_winner || setData.setWinner || null;
  if (explicit) {
    const up = String(explicit).toUpperCase();
    if (up === 'A' || up === 'B') return up;
  }
  return getSetWinnerFromScores(
    setData.teamAScore != null ? setData.teamAScore : setData.team_a_score,
    setData.teamBScore != null ? setData.teamBScore : setData.team_b_score
  );
}

function syncSetWinCounts() {
  try {
    const completed = Array.isArray(setHistory) ? setHistory : [];
    let aWins = 0;
    let bWins = 0;
    completed.forEach(function(s) {
      const winner = getSetWinner(s);
      if (winner === 'A') aWins++;
      else if (winner === 'B') bWins++;
    });
    const currentManualWinner = getManualWinnerForSet(state.currentSet || 1);
    const currentAlreadyRecorded = completed.some(function(s) {
      return parseInt(s && (s.setNumber || s.set_number || 0), 10) === parseInt(state.currentSet || 1, 10);
    });
    if (currentManualWinner && !currentAlreadyRecorded) {
      if (currentManualWinner === 'A') aWins++;
      else if (currentManualWinner === 'B') bWins++;
    }

    state.teamA.gamesWon = aWins;
    state.teamB.gamesWon = bWins;
    const ga = document.getElementById('gamesA'); if (ga) ga.textContent = aWins;
    const gb = document.getElementById('gamesB'); if (gb) gb.textContent = bWins;
    return { aWins: aWins, bWins: bWins };
  } catch (_) {
    return { aWins: state.teamA.gamesWon || 0, bWins: state.teamB.gamesWon || 0 };
  }
}

// Persist a flattened viewer-friendly state to localStorage
function saveLocalState() {
  try {
    syncAllPlayerInputs();
    syncSetWinCounts();
    const committee = (document.getElementById('bdCommitteeInput') && document.getElementById('bdCommitteeInput').value) ? document.getElementById('bdCommitteeInput').value.trim() : '';
    const viewerState = {
      // completed sets history for viewer (array of { setNumber, teamAScore, teamBScore })
      sets: Array.isArray(setHistory) ? setHistory.map(s => ({ setNumber: s.setNumber, teamAScore: s.teamAScore, teamBScore: s.teamBScore, winner: getSetWinner(s) })) : [],
      swapped: state.swapped || false,
      teamAName: state.teamA.name || 'TEAM A',
      teamBName: state.teamB.name || 'TEAM B',
      scoreA: state.teamA.score || 0,
      scoreB: state.teamB.score || 0,
      gamesA: state.teamA.gamesWon || 0,
      gamesB: state.teamB.gamesWon || 0,
      bestOf: state.bestOf || 3,
      currentSet: state.currentSet || 1,
      timeoutA: state.teamA.timeout || 0,
      timeoutB: state.teamB.timeout || 0,
      servingTeam: state.serving || 'A',
      committee: committee || '',
      matchType: state.matchType || 'singles',
      teamAPlayer1: getPlayerInput('A', 0) || '',
      teamAPlayer2: getPlayerInput('A', 1) || '',
      teamBPlayer1: getPlayerInput('B', 0) || '',
      teamBPlayer2: getPlayerInput('B', 1) || '',
      // manual winner mapping persisted so viewer can highlight per-set
      manualWinners: state.manualWinners || {}
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...viewerState, _savedAt: new Date().toISOString() }));
    
    // Check for new set winners in admin
    if (viewerState.sets && viewerState.sets.length > _prevSetsLength) {
      const newSet = viewerState.sets[viewerState.sets.length - 1];
      if (newSet && newSet.winner) {
        const winnerName = newSet.winner === 'A' ? state.teamA.name : state.teamB.name;
        showWinnerModal('🏆 SET WINNER', `${winnerName} wins Set ${newSet.setNumber}!`);
      }
    }
    _prevSetsLength = viewerState.sets ? viewerState.sets.length : 0;
    
    // Also broadcast to WebSocket relay (if connected) so remote viewers receive updates
    try { _maybeSendWS(viewerState); } catch (_) {}
    // Persist full admin state (for refresh recovery) and schedule DB sync
    try { saveAdminState(); } catch (_) {}
    try { scheduleServerPersist(); } catch (_) {}
  } catch (e) {
    // ignore localStorage errors (e.g., private mode)
  }
}

// Toggle manual winner for the current set (persist and broadcast via saveLocalState)
function toggleManualWinner(team) {
  try {
    const setNum = String(state.currentSet || 1);
    state.manualWinners = state.manualWinners || {};
    if (state.manualWinners[setNum] === team) {
      delete state.manualWinners[setNum];
    } else {
      state.manualWinners[setNum] = team;
    }
    // update admin UI immediately, then persist and broadcast
    try { updateAdminWinnerButtons(); } catch (_) {}
    saveLocalState();
  } catch (e) { console.error('toggleManualWinner error', e); }
}

// Flash animation helper
function flashEl(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('flash');
  void el.offsetWidth;           // force reflow to restart animation
  el.classList.add('flash');
  setTimeout(function () { el.classList.remove('flash'); }, 1000);
}

// Numeric cleaner for contenteditable
function sanitizeNumeric(id, team, key) {
    const el = document.getElementById(id);
    let val = parseInt(el.textContent.replace(/\D/g, '')) || 0;
    el.textContent = val;
    state[team][key] = val;
  saveLocalState();
}

function syncScore(t) { sanitizeNumeric('score' + t, 'team' + t, 'score'); }
function syncGames(t) { sanitizeNumeric('games' + t, 'team' + t, 'gamesWon'); }
function syncTimeout(t) { sanitizeNumeric('timeout' + t, 'team' + t, 'timeout'); }

function changeScore(t, d) {
    const teamKey = 'team' + t;
    state[teamKey].score = Math.max(0, state[teamKey].score + d);
    document.getElementById('score' + t).textContent = state[teamKey].score;
  saveLocalState();
}
function changeGames(t, d) {
    const teamKey = 'team' + t;
    state[teamKey].gamesWon = Math.max(0, state[teamKey].gamesWon + d);
    document.getElementById('games' + t).textContent = state[teamKey].gamesWon;
  saveLocalState();
}
function changeTimeout(t, d) {
    const teamKey = 'team' + t;
    state[teamKey].timeout = Math.max(0, state[teamKey].timeout + d);
    document.getElementById('timeout' + t).textContent = state[teamKey].timeout;
  saveLocalState();
}

// Manual Serving Toggle
function toggleServing() {
    state.serving = state.serving === 'A' ? 'B' : 'A';
    updateLabels();
  saveLocalState();
}

function editTeamName(team) {
  const header = document.getElementById('header' + team);
  const nameSpan = document.getElementById('team' + team + 'Name');
  if (header.querySelector('input')) return;

  const currentName = state['team' + team].name;
  nameSpan.style.display = 'none';

  const input = document.createElement('input');
  input.type = 'text';
  input.value = currentName;
  header.appendChild(input);
  input.focus();

  const commit = () => {
    const newName = input.value.trim() || 'TEAM ' + team;
    state['team' + team].name = newName;
    nameSpan.textContent = newName;
    nameSpan.style.display = '';
    input.remove();
    updateLabels();
    saveLocalState();
  };

  input.onblur = commit;
  input.onkeydown = (e) => { if (e.key === 'Enter') commit(); };
}

function updateLabels() {
    document.getElementById('timeoutLabelA').textContent = state.teamA.name;
    document.getElementById('timeoutLabelB').textContent = state.teamB.name;
    document.getElementById('servingTeamLabel').textContent = state['team' + state.serving].name;

    // Update serving indicator on team headers
    const headerA = document.getElementById('headerA');
    const headerB = document.getElementById('headerB');
    if (headerA) {
      headerA.classList.toggle('serving', state.serving === 'A');
    }
    if (headerB) {
      headerB.classList.toggle('serving', state.serving === 'B');
    }
}

function swapTeams() {
  if (!confirm('Are you sure you want to swap teams? This will exchange all team data, players, scores, timeouts, and serving.')) return;

  // Swap team data
  const temp = state.teamA;
  state.teamA = state.teamB;
  state.teamB = temp;

  // Swap serving
  state.serving = state.serving === 'A' ? 'B' : 'A';

  // Toggle swapped flag
  state.swapped = !state.swapped;

  // Update layout
  const area = document.getElementById('mainArea');
  const toRow = document.getElementById('timeoutRow');
  
  if (state.swapped) {
    area.style.gridTemplateAreas = '"right center left"';
    toRow.style.flexDirection = 'row-reverse';
  } else {
    area.style.gridTemplateAreas = '"left center right"';
    toRow.style.flexDirection = 'row';
  }

  // Update DOM elements
  try { const elA=document.getElementById('teamAName'); if (elA) elA.textContent = state.teamA.name; } catch(_){}
  try { const elB=document.getElementById('teamBName'); if (elB) elB.textContent = state.teamB.name; } catch(_){}
  try { const sA=document.getElementById('scoreA'); if (sA) sA.textContent = state.teamA.score; } catch(_){}
  try { const sB=document.getElementById('scoreB'); if (sB) sB.textContent = state.teamB.score; } catch(_){}
  try { const gA=document.getElementById('gamesA'); if (gA) gA.textContent = state.teamA.gamesWon; } catch(_){}
  try { const gB=document.getElementById('gamesB'); if (gB) gB.textContent = state.teamB.gamesWon; } catch(_){}
  try { const tAel=document.getElementById('timeoutA'); if (tAel) tAel.textContent = state.teamA.timeout; } catch(_){}
  try { const tBel=document.getElementById('timeoutB'); if (tBel) tBel.textContent = state.teamB.timeout; } catch(_){}

  // Re-render player inputs and labels
  try { renderPlayers('A'); renderPlayers('B'); } catch(_){}
  try { updateLabels(); } catch(_){}

  saveLocalState();
}

function setMatchType(type, options) {
  const opts = options || {};
  if (!opts.skipConfirm && !confirm('Are you sure you want to change the match type? This will reset player inputs to match the new type.')) return;
  if (!opts.skipSync) syncAllPlayerInputs();
  state.matchType = normalizeMatchType(type);
  state.teamA.players = normalizePlayers(state.teamA.players, state.matchType);
  state.teamB.players = normalizePlayers(state.teamB.players, state.matchType);
  document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === state.matchType));
  renderPlayers('A');
  renderPlayers('B');
  if (!opts.skipSave) saveLocalState();
}

function renderPlayers(t) {
  const container = document.getElementById('bd-players' + t);
  if (!container) return;
  const type = normalizeMatchType(state.matchType);
  const players = normalizeTeamPlayers(t);
  container.innerHTML = '';

  players.forEach(function(value, idx) {
    if (type === 'mixed') {
      const label = document.createElement('label');
      label.style.fontSize = '10px';
      label.textContent = idx === 0 ? 'Male' : 'Female';
      container.appendChild(label);
    }

    const input = document.createElement('input');
    input.type = 'text';
    input.value = value || '';
    input.placeholder = type === 'singles' ? 'Player name' : (type === 'mixed' ? getPlayerRole(type, idx) : 'P' + (idx + 1));
    if (idx > 0) input.style.marginTop = '5px';
    input.addEventListener('input', function() {
      state['team' + t].players[idx] = input.value;
      saveLocalState();
    });
    input.addEventListener('blur', function() {
      state['team' + t].players[idx] = input.value;
      saveLocalState();
    });
    container.appendChild(input);
  });
}

function changeBestOf(d) {
  state.bestOf = Math.max(1, Math.min(5, state.bestOf + (d > 0 ? 2 : -2)));
  document.getElementById('bestOfBox').textContent = state.bestOf;
  saveLocalState();
}

function changeSet(d) {
  state.currentSet = Math.max(1, Math.min(state.bestOf, state.currentSet + d));
  document.getElementById('currentSetBox').textContent = state.currentSet;
  try { updateAdminWinnerButtons(); } catch (_) {}
  saveLocalState();
}

function startNewSet() {
  // Confirm with user before snapshotting and clearing scores
  if (!confirm('Start a new set? This will save the current set and clear scores. Continue?')) return;
  syncAllPlayerInputs();
  // Push the just-completed set snapshot into setHistory (if any)
  try {
    const snap = {
      setNumber: state.currentSet,
      teamAScore: state.teamA.score,
      teamBScore: state.teamB.score,
      teamATimeout: state.teamA.timeout,
      teamBTimeout: state.teamB.timeout,
      serving: state.serving,
      winner: getManualWinnerForSet(state.currentSet) || getSetWinnerFromScores(state.teamA.score, state.teamB.score)
    };
    // Only push if the set has any activity (non-zero scores or timeouts), otherwise still keep a record per spec
    setHistory.push(snap);
    try { checkAutoMatchComplete(); } catch(_) {}
  } catch (e) {
    // ignore
  }
  // Immediately persist local viewer snapshot and broadcast to other tabs/clients
  try { saveLocalState(); } catch(_) {}
  // Attempt to persist the snapshot to the server, then clear scores/timeouts but NOT games won
  const payload = buildSavePayload();
  fetch('save_set.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) sessionStorage.setItem('badminton_match_id', j.match_id);
      // ✅ SSOT FIX START — Patch 4
      // Only clear scores when the server confirms the set was saved.
      // Previously this was in .finally(), which cleared scores even on failure,
      // leaving the server and viewer out of sync with no recovery path.
      if (!j || !j.success) {
        console.warn('[badminton] save_set failed — scores NOT cleared. Retry save.', j);
        return;
      }
      state.teamA.score = 0; state.teamB.score = 0;
      state.teamA.timeout = 0; state.teamB.timeout = 0;
      const elA = document.getElementById('scoreA'); if (elA) elA.textContent = 0;
      const elB = document.getElementById('scoreB'); if (elB) elB.textContent = 0;
      const ta = document.getElementById('timeoutA'); if (ta) ta.textContent = 0;
      const tb = document.getElementById('timeoutB'); if (tb) tb.textContent = 0;
      changeSet(1);
      saveLocalState();
      // ✅ SSOT FIX END
    })
    .catch(err => {
      console.error('save_set failed', err);
      // ✅ SSOT FIX START — Patch 4 (catch)
      // Do NOT clear scores on network failure — admin can retry.
      alert('Network error: set was NOT saved. Scores kept. Please retry.');
      // ✅ SSOT FIX END
    });
}

// ── Auto-declare match complete when set threshold reached ───────
function checkAutoMatchComplete() {
  try {
    const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
    const counts = syncSetWinCounts();
    const aWins = counts.aWins;
    const bWins = counts.bWins;
    try { saveLocalState(); } catch(_) {}

    const needed = Math.ceil((state.bestOf || 3) / 2);
    if (aWins >= needed || bWins >= needed) {
      const winnerName = aWins > bWins ? state.teamA.name : state.teamB.name;
      const msg = `${winnerName} WINS THE MATCH! (${Math.max(aWins,bWins)}-${Math.min(aWins,bWins)})`;
      const modalMsg = document.getElementById('modalMsg'); if (modalMsg) modalMsg.textContent = msg;
      const modalEl  = document.getElementById('modal');    if (modalEl)  modalEl.classList.add('show');

      try {
        const matchId = sessionStorage.getItem('badminton_match_id');
        if (matchId) {
          const payload = {
            match_id: parseInt(matchId,10),
            total_sets_played: completed.length,
            team_a_sets_won: aWins,
            team_b_sets_won: bWins,
            winner_team: aWins > bWins ? 'A' : (bWins > aWins ? 'B' : null),
            winner_name: aWins > bWins ? state.teamA.name : (bWins > aWins ? state.teamB.name : null)
          };
          fetch('declare_winner.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            .then(r => r.json()).then(j => { if (j && j.success) console.log('Auto-declare saved'); else console.warn('auto declare response', j); })
            .catch(e => console.error('auto declare error', e));
        }
      } catch (e) { console.error(e); }
    }
  } catch (e) { console.error('checkAutoMatchComplete error', e); }
}

function declareWinner() {
  // Confirm before declaring winner (finalizes match)
  if (!confirm('Declare winner and finalize this match? This will mark the match completed in the database. Continue?')) return;
  // Build the full list of sets: completed history + current active set
  const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
  const currentSnap = {
    setNumber: state.currentSet,
    teamAScore: state.teamA.score,
    teamBScore: state.teamB.score,
    teamATimeout: state.teamA.timeout,
    teamBTimeout: state.teamB.timeout,
    serving: state.serving,
    winner: getManualWinnerForSet(state.currentSet) || getSetWinnerFromScores(state.teamA.score, state.teamB.score)
  };
  const allSets = completed.concat([currentSnap]);

  // Aggregate set wins
  let aWins = 0, bWins = 0;
  allSets.forEach(s => {
    const winner = getSetWinner(s);
    if (winner === 'A') aWins++;
    else if (winner === 'B') bWins++;
  });

  // Update state and UI summary for games won
  state.teamA.gamesWon = aWins;
  state.teamB.gamesWon = bWins;
  const ga = document.getElementById('gamesA'); if (ga) ga.textContent = aWins;
  const gb = document.getElementById('gamesB'); if (gb) gb.textContent = bWins;

  // Determine match winner
  let msg;
  let winnerTitle = '';
  if (aWins === bWins) {
    msg = `The match is currently a tie (${aWins}-${aWins}).`;
    winnerTitle = '⚖️ TIE GAME';
  } else {
    const winnerName = aWins > bWins ? state.teamA.name : state.teamB.name;
    msg = `${winnerName} WINS THE MATCH! (${Math.max(aWins,bWins)}-${Math.min(aWins,bWins)})`;
    winnerTitle = '🏆 MATCH WINNER';
  }

  document.getElementById('modalMsg').textContent = msg;
  document.getElementById('modal').classList.add('show');
  
  // Also show the winner modal popup
  showWinnerModal(winnerTitle, msg);
  
  saveLocalState();
  // Persist match declaration to server (if match_id exists)
  try {
    const matchId = sessionStorage.getItem('badminton_match_id');
    if (matchId) {
      const payload = {
        match_id: parseInt(matchId,10),
        total_sets_played: completed.length + 1,
        team_a_sets_won: aWins,
        team_b_sets_won: bWins,
        winner_team: aWins > bWins ? 'A' : (bWins > aWins ? 'B' : null),
        winner_name: aWins > bWins ? state.teamA.name : (bWins > aWins ? state.teamB.name : null)
      };
      fetch('declare_winner.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json()).then(j => {
          if (j && j.success) alert('Match result saved to database.');
          else console.warn('declare_winner response', j);
        }).catch(e => console.error('declare_winner error', e));
    }
    else {
      // No match_id yet — create/save the full sets array first, then declare using returned id
      const savePayload = buildSavePayload();
      savePayload.sets = allSets.map(s => ({
        set_number: s.setNumber || 1,
        team_a_score: s.teamAScore || 0,
        team_b_score: s.teamBScore || 0,
        team_a_timeout_used: s.teamATimeout || 0,
        team_b_timeout_used: s.teamBTimeout || 0,
        serving_team: s.serving || 'A',
        set_winner: s.winner || null
      }));
      const committee = document.getElementById('bdCommitteeInput') ? document.getElementById('bdCommitteeInput').value.trim() : '';
      if (committee) savePayload.committee_official = committee;
      fetch('save_set.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(savePayload) })
        .then(r => r.json())
        .then(j => {
          if (j && j.success && j.match_id) {
            const newId = j.match_id;
            sessionStorage.setItem('badminton_match_id', newId);
            const payload = {
              match_id: parseInt(newId,10),
              total_sets_played: allSets.length,
              team_a_sets_won: aWins,
              team_b_sets_won: bWins,
              winner_team: aWins > bWins ? 'A' : (bWins > aWins ? 'B' : null),
              winner_name: aWins > bWins ? state.teamA.name : (bWins > aWins ? state.teamB.name : null)
            };
            return fetch('declare_winner.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
          } else {
            throw new Error('Save before declare failed');
          }
        })
        .then(rr => rr && rr.json ? rr.json() : null)
        .then(j => { if (j && j.success) alert('Match result saved to database.'); else if (j) console.warn('declare_winner response', j); })
        .catch(e => console.error('declare after save error', e));
    }
  } catch (e) { console.error(e); }
}

function closeModal() { document.getElementById('modal').classList.remove('show'); }

// Winner modal functions
function showWinnerModal(title, msg) {
  const modal = document.getElementById('winnerModal');
  if (!modal) return;
  document.getElementById('winnerModalTitle').textContent = title;
  document.getElementById('winnerModalMsg').textContent = msg;
  modal.style.display = 'flex';
}

function closeWinnerModal() {
  const modal = document.getElementById('winnerModal');
  if (modal) modal.style.display = 'none';
}
function saveAndReport() {
  syncAllPlayerInputs();
  const payload = buildSavePayload();
  const committee = document.getElementById('bdCommitteeInput') ? document.getElementById('bdCommitteeInput').value.trim() : '';
  if (committee) payload.committee_official = committee;

  // Ensure current UI snapshot is treated as the final/deciding set when appropriate
  try {
    let sets = Array.isArray(payload.sets) ? payload.sets.slice() : [];
    const maxNum = sets.length ? Math.max.apply(null, sets.map(s => parseInt(s.set_number || s.setNumber || 0) || 0)) : 0;
    const curr = {
      set_number: parseInt(payload.set_number || state.currentSet || 1, 10),
      team_a_score: parseInt(payload.team_a_score || 0, 10),
      team_b_score: parseInt(payload.team_b_score || 0, 10),
      team_a_timeout_used: parseInt(payload.team_a_timeout_used || 0, 10),
      team_b_timeout_used: parseInt(payload.team_b_timeout_used || 0, 10),
      serving_team: payload.serving_team || state.serving || 'A',
      set_winner: payload.set_winner || null
    };

    const hasActivity = (curr.team_a_score !== 0 || curr.team_b_score !== 0 || !!curr.set_winner);
    const existsSame = sets.some(s => (parseInt(s.set_number || s.setNumber || 0) === curr.set_number) && (parseInt(s.team_a_score || 0) === curr.team_a_score) && (parseInt(s.team_b_score || 0) === curr.team_b_score));
    const existsNumber = sets.some(s => parseInt(s.set_number || s.setNumber || 0) === curr.set_number);

    if (hasActivity && !existsSame) {
      // If the set number already exists but current snapshot is new, append as next set
      if (existsNumber) {
        curr.set_number = (maxNum || 0) + 1;
      }
      // Remove any existing entries with this set_number then append
      sets = sets.filter(s => parseInt(s.set_number || s.setNumber || 0) !== curr.set_number);
      sets.push(curr);
    }

    // Normalize ordering
    sets.sort((a,b) => (parseInt(a.set_number || a.setNumber || 0) - parseInt(b.set_number || b.setNumber || 0)));
    payload.sets = sets;
  } catch (e) {
    // non-fatal — fall back to original payload
    console.warn('saveAndReport: failed to normalize sets', e);
  }

  // Show a brief loading state on the button
  const btn = document.querySelector('.save-btn');
  if (btn) { btn.textContent = '⏳ Saving…'; btn.disabled = true; }

  fetch('save_set.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(j => {
    if (j && j.success) {
      const matchId = j.match_id;
      sessionStorage.setItem('badminton_match_id', matchId);

      // Determine set wins from payload.sets (server also computes), and declare only when a team reaches required wins
      let aWins = 0, bWins = 0;
      try {
        const sets = Array.isArray(payload.sets) ? payload.sets : [];
        sets.forEach(s => {
          const w = getSetWinner(s);
          if (w === 'A') aWins++; else if (w === 'B') bWins++;
        });
      } catch (e) {
        aWins = parseInt(payload.team_a_games_won || 0, 10);
        bWins = parseInt(payload.team_b_games_won || 0, 10);
      }

      const bestOf = parseInt(payload.best_of || state.bestOf || 3, 10);
      const requiredWins = Math.ceil(bestOf / 2);

      const shouldDeclare = (aWins >= requiredWins) || (bWins >= requiredWins);

      const tryDeclare = shouldDeclare ? fetch('declare_winner.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ match_id: parseInt(matchId,10), total_sets_played: (payload.sets||[]).length, team_a_sets_won: aWins, team_b_sets_won: bWins, winner_team: aWins>bWins?'A':'B', winner_name: aWins>bWins?state.teamA.name:state.teamB.name })
      }).then(rr => rr.json()).catch(() => null) : Promise.resolve(null);

      // Open report after declare (if any) resolves — clear admin snapshot and redirect in-tab
      tryDeclare.finally(() => {
        try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
        try { sessionStorage.setItem('badminton_match_id', matchId); } catch (_) {}
        window.location.href = 'badminton_report.php?match_id=' + matchId;
      });
    } else {
      alert('Save failed: ' + (j && j.message ? j.message : 'Unknown error'));
    }
  })
  .catch(err => { console.error(err); alert('Save request failed. Check your connection.'); })
  .finally(() => {
    if (btn) { btn.textContent = '📊 SAVE & REPORT'; btn.disabled = false; }
  });
}

// Legacy saveFile kept as alias for any internal callers (startNewSet, etc.)
function saveFile() {
  syncAllPlayerInputs();
  // Build a self-contained HTML report and download it
  const teamA = (state.teamA.name || 'TeamA').replace(/[^a-z0-9 _-]/gi, '').trim();
  const teamB = (state.teamB.name || 'TeamB').replace(/[^a-z0-9 _-]/gi, '').trim();
  const fname = `badminton_report_${teamA.replace(/\s+/g,'_')}_vs_${teamB.replace(/\s+/g,'_')}_Set${state.currentSet}.html`;

  const now = new Date();
  const exportedAt = now.toLocaleString();
  const committee = (document.getElementById('bdCommitteeInput') && document.getElementById('bdCommitteeInput').value) ? document.getElementById('bdCommitteeInput').value.trim() : '';

  // Prepare player lineup rows based on match type
  function getPlayerRows() {
    const rows = [];
    const type = normalizeMatchType(state.matchType);
    // Read inputs from DOM to get current player names
    function nameFor(team, idx) {
      return getPlayerInput(team, idx);
    }
    if (type === 'singles') {
      rows.push(['A','Singles', nameFor('A',0)]);
      rows.push(['B','Singles', nameFor('B',0)]);
    } else if (type === 'doubles') {
      rows.push(['A','Player 1', nameFor('A',0)]);
      rows.push(['A','Player 2', nameFor('A',1)]);
      rows.push(['B','Player 1', nameFor('B',0)]);
      rows.push(['B','Player 2', nameFor('B',1)]);
    } else { // mixed
      rows.push(['A','Male Player', nameFor('A',0)]);
      rows.push(['A','Female Player', nameFor('A',1)]);
      rows.push(['B','Male Player', nameFor('B',0)]);
      rows.push(['B','Female Player', nameFor('B',1)]);
    }
    return rows;
  }

  // Build rows and full HTML using the compact modern layout (adapted from table-tennis design)
  const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
  const currentSnap = {
    setNumber: state.currentSet,
    teamAScore: state.teamA.score,
    teamBScore: state.teamB.score,
    teamATimeout: state.teamA.timeout,
    teamBTimeout: state.teamB.timeout,
    serving: state.serving,
    winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : 'TBD')
  };
  const setsAll = completed.concat([currentSnap]);

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  const css = 'body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#111}.container{max-width:900px;margin:0 auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.06)}.header{background:linear-gradient(90deg,#FFE600,#FFD166);color:#000;text-align:center;padding:18px;border-radius:6px;margin-bottom:14px}.header h1{margin:0;font-size:20px}.meta{font-size:13px;margin-top:6px;color:#333}.meta.small{font-size:12px;color:#555;margin-top:4px}.report-toolbar{display:flex;gap:8px;justify-content:flex-end;margin-bottom:12px}.report-toolbar button{background:#003366;color:#fff;border:none;padding:8px 12px;border-radius:4px;cursor:pointer;font-weight:700}section{margin-bottom:16px}h2{font-size:14px;margin:0 0 8px 0;color:#003366}table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #e6e6e6;padding:10px}th{background:#f0f6fb;color:#003366;font-weight:700;text-align:center}td{text-align:left}td.num{text-align:center}tr:nth-child(even){background:#fafafa}.teamA{background:#fff5f5}.teamB{background:#f5fbff}.winner{background:#e6f7e6;color:#006400;font-weight:700;text-align:center}.result-badge{background:#e6f7e6;color:#006400;padding:6px 10px;border-radius:4px;font-weight:700;display:inline-block;margin-left:8px}.meta-table td{border:none;padding:6px 8px}footer{margin-top:12px;text-align:right;font-size:13px;color:#333}@media print{body{padding:0;background:#fff}.report-toolbar{display:none}.container{box-shadow:none;border-radius:0;padding:8px;margin:0}.header{border-radius:0}}';


  // Build rows
  const players = getPlayerRows();
  let playersRows = '';
  for (let i=0;i<players.length;i++){ const r=players[i]; playersRows += '<tr><td>'+esc(r[0])+'</td><td>'+esc(r[1])+'</td><td>'+esc(r[2])+'</td></tr>'; }

  let setsRows = '';
  for (let i=0;i<setsAll.length;i++){
    const s = setsAll[i];
    const winner = s && s.winner === 'A' ? esc(state.teamA.name) : (s && s.winner === 'B' ? esc(state.teamB.name) : 'TBD');
    setsRows += '<tr><td>'+esc(s.setNumber)+'</td><td class="teamA num">'+esc(s.teamAScore)+'</td><td class="teamB num">'+esc(s.teamBScore)+'</td><td class="winner">'+winner+'</td></tr>';
  }

    // Persist match declaration to server (if match_id exists) and save sets first
  let teamASetStr = '';
  let teamBSetStr = '';
  // Build per-set timeout breakdown strings to display in the "Timeouts Used" column
  let teamATimeoutStr = '';
  let teamBTimeoutStr = '';
  for (let i=0;i<setsAll.length;i++){
    const s = setsAll[i];
    if (!s) continue;
    teamASetStr += 'Set'+esc(s.setNumber)+': '+esc(s.teamAScore) + (i < setsAll.length-1 ? ' | ' : '');
    teamBSetStr += 'Set'+esc(s.setNumber)+': '+esc(s.teamBScore) + (i < setsAll.length-1 ? ' | ' : '');
    const taTO = (typeof s.teamATimeout !== 'undefined') ? esc(s.teamATimeout) : '0';
    const tbTO = (typeof s.teamBTimeout !== 'undefined') ? esc(s.teamBTimeout) : '0';
    teamATimeoutStr += 'Set'+esc(s.setNumber)+': '+taTO + (i < setsAll.length-1 ? ' | ' : '');
    teamBTimeoutStr += 'Set'+esc(s.setNumber)+': '+tbTO + (i < setsAll.length-1 ? ' | ' : '');
  }

  const servingName = esc(state['team' + state.serving].name || state.serving);
  const matchWinner = (state.teamA.gamesWon === state.teamB.gamesWon) ? 'TBD' : (state.teamA.gamesWon > state.teamB.gamesWon ? esc(state.teamA.name) : esc(state.teamB.name));
  const metaStatus = (state.teamA.gamesWon === state.teamB.gamesWon) ? 'In Progress' : 'Completed';

  var html = '';
  html += '<!doctype html><html><head><meta charset="utf-8"><title>BADMINTON — MATCH REPORT</title><style>'+css+'</style></head><body>';
  html += '<div class="container">';
  // New badminton-style header to match the provided design
  html += '<div style="margin-bottom:8px">';
  html += '<h1 style="margin:0;color:#062a78;font-size:28px;letter-spacing:1px">SPORTSSYNC - BADMINTON RESULT</h1>';
  html += '<div style="margin-top:8px;color:#333;font-size:14px">Date: '+esc(exportedAt)+'</div>';
  html += '<div style="margin-top:6px;color:#333;font-size:14px"><strong>Committee/Official:</strong> '+esc(committee)+'</div>';
  html += '<hr style="border:none;border-top:1px solid #ddd;margin:12px 0">';
  html += '</div>';

  // Export toolbar inside the report (HTML / Excel / PDF)
  html += '<div class="report-toolbar" style="margin-bottom:12px;display:flex;gap:8px;justify-content:flex-end">';
  html += '<button id="dlHtml" class="report-btn">Download HTML</button>';
  html += '<button id="dlXls" class="report-btn">Download Excel</button>';
  html += '<button id="expPdf" class="report-btn">Print PDF</button>';
  html += '</div>';

  // Player stats: two separate tables (Team A and Team B)
  function intFrom(id){ const el=document.getElementById(id); if(!el) return 0; const n=parseInt(String(el.textContent||el.value||'').replace(/\D/g,''),10); return isNaN(n)?0:n; }
  const teamA_timeouts = intFrom('timeoutA');
  const teamB_timeouts = intFrom('timeoutB');
  const teamA_games = intFrom('gamesA');
  const teamB_games = intFrom('gamesB');
  const teamA_points = intFrom('scoreA');
  const teamB_points = intFrom('scoreB');

  function pName(team, idx){ return getPlayerInput(team, idx); }
  const playersA = [];
  const playersB = [];
  if(state.matchType==='singles'){
    playersA.push({no:1,name:pName('A',0)||state.teamA.name});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name});
  } else if(state.matchType==='doubles'){
    playersA.push({no:1,name:pName('A',0)||state.teamA.name+' P1'});
    playersA.push({no:2,name:pName('A',1)||state.teamA.name+' P2'});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name+' P1'});
    playersB.push({no:2,name:pName('B',1)||state.teamB.name+' P2'});
  } else {
    playersA.push({no:1,name:pName('A',0)||state.teamA.name+' M'});
    playersA.push({no:2,name:pName('A',1)||state.teamA.name+' F'});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name+' M'});
    playersB.push({no:2,name:pName('B',1)||state.teamB.name+' F'});
  }

  // Instead of side-by-side columns, render a full-width Sets summary and then separate
  // stacked sections for Team A and Team B (badminton-specific requirement).
  html += '<div style="margin-bottom:14px">';
  html += '<div style="font-weight:700;margin-bottom:8px;color:#111">Sets Summary</div>';
  html += '<table style="width:100%;border-collapse:collapse;margin-bottom:12px">';
  html += '<thead><tr style="background:#333;color:#fff"><th style="padding:10px;text-align:left">Set #</th><th style="padding:10px;text-align:center">Team A</th><th style="padding:10px;text-align:center">Team B</th><th style="padding:10px;text-align:center">Winner</th></tr></thead>';
  html += '<tbody>' + setsRows + '</tbody></table>';

  // Result summary for overall match winner (highlight if decided)
  if (matchWinner && matchWinner !== 'TBD') {
    html += '<div style="margin-bottom:12px"><strong>Result:</strong><span class="result-badge">'+matchWinner+'</span></div>';
  } else {
    html += '<div style="margin-bottom:12px"><strong>Result:</strong><span style="margin-left:8px;color:#666">TBD</span></div>';
  }

  // Team A full-width block
  html += '<div style="background:#FFE600;padding:14px;border-radius:6px;margin-bottom:12px;color:#000;font-weight:800">';
  html += '<div style="font-weight:800;margin-bottom:6px;color:#062a78">'+esc(state.teamA.name)+'</div>';
  html += '<table style="width:100%;border-collapse:collapse;margin-bottom:8px">';
  html += '<thead><tr style="background:#333;color:#fff"><th style="padding:10px;text-align:left">#</th><th style="padding:10px;text-align:left">Name</th><th style="padding:10px;width:120px;text-align:center">Game Points</th><th style="padding:10px;width:120px;text-align:center">Timeouts Used</th><th style="padding:10px;width:120px;text-align:center">Games Won</th><th style="padding:10px;text-align:left">Set Scores</th></tr></thead>';
  html += '<tbody>';
  for(let i=0;i<playersA.length;i++){ const pl=playersA[i]; html += '<tr style="background:'+(i%2===0? '#fff':'#f6f6f6')+'"><td style="padding:8px">'+esc(pl.no)+'</td><td style="padding:8px">'+esc(pl.name)+'</td><td style="padding:8px;text-align:center">'+esc(teamA_points)+'</td><td style="padding:8px;text-align:center">'+teamATimeoutStr+'</td><td style="padding:8px;text-align:center">'+esc(teamA_games)+'</td><td style="padding:8px">'+teamASetStr+'</td></tr>'; }
  html += '</tbody></table></div>';

  // Team B full-width block
  html += '<div style="background:#FFE600;padding:14px;border-radius:6px;margin-bottom:12px;color:#000;font-weight:800">';
  html += '<div style="font-weight:800;margin-bottom:6px;color:#062a78">'+esc(state.teamB.name)+'</div>';
  html += '<table style="width:100%;border-collapse:collapse;margin-bottom:8px">';
  html += '<thead><tr style="background:#333;color:#fff"><th style="padding:10px;text-align:left">#</th><th style="padding:10px;text-align:left">Name</th><th style="padding:10px;width:120px;text-align:center">Game Points</th><th style="padding:10px;width:120px;text-align:center">Timeouts Used</th><th style="padding:10px;width:120px;text-align:center">Games Won</th><th style="padding:10px;text-align:left">Set Scores</th></tr></thead>';
  html += '<tbody>';
  for(let i=0;i<playersB.length;i++){ const pl=playersB[i]; html += '<tr style="background:'+(i%2===0? '#fff':'#f6f6f6')+'"><td style="padding:8px">'+esc(pl.no)+'</td><td style="padding:8px">'+esc(pl.name)+'</td><td style="padding:8px;text-align:center">'+esc(teamB_points)+'</td><td style="padding:8px;text-align:center">'+teamBTimeoutStr+'</td><td style="padding:8px;text-align:center">'+esc(teamB_games)+'</td><td style="padding:8px">'+teamBSetStr+'</td></tr>'; }
  html += '</tbody></table></div>';
  html += '</div>';

  // small footer
  html += '<div style="font-size:13px;color:#666">Generated by Sportssync</div>';
  html += '</div>';
  
  // helper to trigger download blob
  function triggerDownload(content, mime, name){ const b=new Blob([content],{type:mime}); const a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=name; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href); }
  // Inserted script: HTML download, Excel (xls) download, and print
  const excelName = fname.replace(/\.html$/i, '.xls');
  // Use safer quoting for the injected script so the generated HTML parses correctly
  html += '<script>' +
    '(function(){' +
    'function downloadHTML(){const h=document.documentElement.outerHTML;const blob=new Blob([h],{type:"text/html"});const a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="'+fname+'";document.body.appendChild(a);a.click();a.remove();}' +
    'function downloadExcel(){try{const cont=document.querySelector(".container");const excelStyle="table, th, td { border: 1px solid #000; border-collapse: collapse; padding:8px; } table { width:100%; border-collapse:collapse; } th { background:#f0f6fb; } .winner{background:#e6f7e6;color:#006400;font-weight:700;text-align:center;} .result-badge{background:#e6f7e6;color:#006400;padding:6px 10px;border-radius:4px;font-weight:700;display:inline-block;margin-left:8px} .container { padding:10px; }";const excelHtml="<html><head><meta charset=\'utf-8\'><style>"+excelStyle+"</style></head><body>"+(cont?cont.outerHTML:document.documentElement.outerHTML)+"</body></html>";const blob=new Blob([excelHtml],{type:"application/vnd.ms-excel"});const a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="'+excelName+'";document.body.appendChild(a);a.click();a.remove();}catch(e){console.error(e);alert("Excel export failed");}}' +
    'function exportPDF(){window.print();}' +
    'document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById("dlHtml"); if(d) d.addEventListener("click",downloadHTML); var x=document.getElementById("dlXls"); if(x) x.addEventListener("click",downloadExcel); var p=document.getElementById("expPdf"); if(p) p.addEventListener("click",exportPDF);});' +
    '})();' +
  '</script>';
  html += '</body></html>';

  // default: download HTML
  triggerDownload(html, 'text/html', fname);

  // Also persist this set to the server
  try {
    const payload = buildSavePayload();
    // attach committee so backend can persist it
    if (committee) payload.committee_official = committee;
    fetch('save_set.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(r => r.json()).then(j => {
      if (j && j.success) {
        sessionStorage.setItem('badminton_match_id', j.match_id);
        alert(`Set ${payload.set_number} saved successfully.`);
      } else {
        alert('Save failed: ' + (j && j.message ? j.message : 'Unknown'));
      }
    }).catch(err => { console.error(err); alert('Save request failed.'); });
  } catch (e) {
    console.error('saveFile persist error', e);
  }
}

// ── Export helpers — save to DB then open report page ──────────────────────
// badminton_report.php hosts the proper SheetJS .xlsx export button.
function generateReportAndPersist(choice) {
  syncAllPlayerInputs();
  const payload = buildSavePayload();
  const committee = document.getElementById('bdCommitteeInput') ? document.getElementById('bdCommitteeInput').value.trim() : '';
  if (committee) payload.committee_official = committee;

  fetch('save_set.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(j => {
      if (j && j.success) {
        const matchId = j.match_id;
        try { sessionStorage.setItem('badminton_match_id', matchId); } catch (_) {}
        try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
        // Redirect to the report in the same tab (print can be triggered there manually)
        window.location.href = 'badminton_report.php?match_id=' + matchId;
      } else {
        alert('Save failed: ' + (j && j.message ? j.message : 'Unknown error'));
      }
  })
  .catch(err => { console.error(err); alert('Save request failed.'); });
}

function exportHTML(){ generateReportAndPersist('html'); }
function exportExcel(){ downloadExcelSingleSheet(); }
function exportPDF(){ generateReportAndPersist('print'); }

// Client-side single-sheet Excel exporter with column colors, borders, and highlighted result
async function downloadExcelSingleSheet() {
  syncAllPlayerInputs();
  // Ensure SheetJS is loaded
  if (typeof window.XLSX === 'undefined') {
    await new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js';
      s.onload = resolve; s.onerror = () => reject(new Error('SheetJS load failed'));
      document.head.appendChild(s);
    });
  }

  // Build MATCH-like data from current UI state
  const payload = buildSavePayload();
  const MATCH = {
    match_id: payload.match_id || (sessionStorage.getItem('badminton_match_id') || null),
    saved_at: new Date().toLocaleString(),
    committee: (document.getElementById('bdCommitteeInput') && document.getElementById('bdCommitteeInput').value) ? document.getElementById('bdCommitteeInput').value.trim() : '',
    match_type: payload.match_type,
    best_of: payload.best_of,
    match_status: (payload.team_a_games_won === payload.team_b_games_won) ? 'In Progress' : 'Completed',
    team_a_name: payload.team_a_name,
    team_b_name: payload.team_b_name,
    team_a_sets_won: payload.team_a_games_won || 0,
    team_b_sets_won: payload.team_b_games_won || 0,
    sets: payload.sets || [],
    players_a: [],
    players_b: []
  };

  // read player inputs
  function readPlayers(team) {
    const out = [];
    const players = normalizeTeamPlayers(team);
    for (let i = 0; i < players.length; i++) {
      out.push({ no: i+1, name: players[i] || '', role: getPlayerRole(state.matchType, i) });
    }
    return out;
  }
  MATCH.players_a = readPlayers('A');
  MATCH.players_b = readPlayers('B');

  // Build rows for a single sheet: Summary -> Sets -> Players -> Game Result
  const rows = [];
  rows.push(['SPORTSSYNC — BADMINTON MATCH REPORT']);
  rows.push([]);
  rows.push(['Field','Value']);
  rows.push(['Match ID', MATCH.match_id || '']);
  rows.push(['Date / Time', MATCH.saved_at]);
  rows.push(['Committee / Official', MATCH.committee || '—']);
  rows.push(['Match Type', MATCH.match_type]);
  rows.push(['Best Of', MATCH.best_of]);
  rows.push(['Status', MATCH.match_status]);
  rows.push(['Team A', MATCH.team_a_name]);
  rows.push(['Team A Sets Won', MATCH.team_a_sets_won]);
  rows.push(['Team B', MATCH.team_b_name]);
  rows.push(['Team B Sets Won', MATCH.team_b_sets_won]);
  rows.push(['Overall Winner', MATCH.team_a_sets_won > MATCH.team_b_sets_won ? MATCH.team_a_name : (MATCH.team_b_sets_won > MATCH.team_a_sets_won ? MATCH.team_b_name : 'TBD')]);
  rows.push([]);

  // Sets header and rows
  rows.push(['Sets Breakdown']);
  rows.push(['Set #', MATCH.team_a_name + ' Score', MATCH.team_b_name + ' Score', 'Team A Timeout', 'Team B Timeout', 'Serving', 'Winner']);
  (MATCH.sets || []).forEach(s => rows.push(['Set ' + s.set_number, s.team_a_score, s.team_b_score, s.team_a_timeout_used ? 'Yes' : 'No', s.team_b_timeout_used ? 'Yes' : 'No', s.serving_team === 'A' ? MATCH.team_a_name : MATCH.team_b_name, s.set_winner === 'A' ? MATCH.team_a_name : (s.set_winner === 'B' ? MATCH.team_b_name : 'TBD')]));
  rows.push([]);

  // Players header and rows
  rows.push(['Players']);
  rows.push(['Team','#','Name','Role','Game Points','Timeouts Used','Sets Won','Set Scores']);
  MATCH.players_a.forEach(p => rows.push([MATCH.team_a_name || 'Team A', p.no, p.name || '—', p.role || '', state.teamA.score, state.teamA.timeout, MATCH.team_a_sets_won, '']));
  MATCH.players_b.forEach(p => rows.push([MATCH.team_b_name || 'Team B', p.no, p.name || '—', p.role || '', state.teamB.score, state.teamB.timeout, MATCH.team_b_sets_won, '']));
  rows.push([]);

  // Game/result row
  const overall = MATCH.team_a_sets_won > MATCH.team_b_sets_won ? MATCH.team_a_name : (MATCH.team_b_sets_won > MATCH.team_a_sets_won ? MATCH.team_b_name : 'TBD');
  rows.push(['Game Result', overall]);

  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(rows);

  // Column widths (8 columns)
  ws['!cols'] = [{wch:22},{wch:8},{wch:28},{wch:14},{wch:12},{wch:14},{wch:12},{wch:32}];

  // Styling helpers
  function cellStyle(bold, bg, color) {
    const b = { style: 'thin', color: { rgb: '000000' } };
    return { font: { bold: !!bold, color: { rgb: color || '111111' }, name: 'Calibri', sz: 11 }, fill: { fgColor: { rgb: bg || 'FFFFFF' }, patternType: 'solid' }, alignment: { horizontal: 'center', vertical: 'center', wrapText: true }, border: { top: b, bottom: b, left: b, right: b } };
  }

  // Apply styles: title, field header, table headers, per-column colors, and highlight result
  const range = XLSX.utils.decode_range(ws['!ref']);
  const colColors = ['FFF2CC','F8F8F8','FFFFFF','F8F8F8','FFFFFF','F8F8F8','FFFFFF','FFFFFF'];

  for (let R = range.s.r; R <= range.e.r; ++R) {
    for (let C = range.s.c; C <= range.e.c; ++C) {
      const ref = XLSX.utils.encode_cell({r:R,c:C});
      const cell = ws[ref];
      if (!cell) continue;
      const val = String(cell.v || '').toLowerCase();
      // Title row
      if (R === 0) { cell.s = cellStyle(true, 'FFE600', '062A78'); continue; }
      // Field header row
      if (R === 2 && (cell.v === 'Field' || cell.v === 'Value')) { cell.s = cellStyle(true, '333333', 'FFE600'); continue; }
      // Sets header (look for 'set #' heading)
      if (cell.v === 'Set #' || cell.v === 'Set #' || val.indexOf('set #') !== -1) { cell.s = cellStyle(true, '333333', 'FFE600'); continue; }
      // Players header (first cell 'Team' and second cell '#')
      if (cell.v === 'Team' || cell.v === '#') {
        // mark the whole header row for players
        const prow = R;
        for (let cc = range.s.c; cc <= range.e.c; ++cc) {
          const pref = XLSX.utils.encode_cell({r:prow,c:cc}); if (ws[pref]) ws[pref].s = cellStyle(true, '333333', 'FFE600');
        }
        break;
      }
      // Default cell style using column color
      const bg = colColors[C - range.s.c] || 'FFFFFF';
      ws[ref].s = cellStyle(false, bg, '111111');
    }
  }

  // Highlight 'Overall Winner' or 'Game Result' rows green
  for (let R = range.s.r; R <= range.e.r; ++R) {
    const ref0 = XLSX.utils.encode_cell({r:R,c:0});
    const cell0 = ws[ref0];
    if (!cell0) continue;
    const txt = String(cell0.v || '').toLowerCase();
    if (txt.indexOf('overall winner') !== -1 || txt.indexOf('game result') !== -1) {
      const ref1 = XLSX.utils.encode_cell({r:R,c:1});
      if (ws[ref0]) ws[ref0].s = cellStyle(true, 'E6F7E6', '006400');
      if (ws[ref1]) ws[ref1].s = cellStyle(true, 'E6F7E6', '006400');
    }
  }

  XLSX.utils.book_append_sheet(wb, ws, 'Match Report');
  const fname = `badminton_report_${(MATCH.team_a_name||'TeamA').replace(/\s+/g,'_')}_vs_${(MATCH.team_b_name||'TeamB').replace(/\s+/g,'_')}_${MATCH.match_id||'report'}.xlsx`;
  XLSX.writeFile(wb, fname, { bookType: 'xlsx', cellStyles: true });
}

// Ensure an export toolbar exists in the admin UI with HTML / Excel / PDF actions
function ensureExportToolbar(){
  if (document.getElementById('badmintonExportToolbar')) return;
  const bar = document.createElement('div');
  bar.id = 'badmintonExportToolbar';
  bar.className = 'report-toolbar';
  // start hidden; show after a successful save
  bar.style.display = 'none';
  bar.style.gap = '8px';
  bar.style.justifyContent = 'flex-end';
  bar.style.margin = '10px 0';

  function makeBtn(text, onClick){
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.style.background = '#003366';
    b.style.color = '#fff';
    b.style.border = 'none';
    b.style.padding = '8px 12px';
    b.style.borderRadius = '4px';
    b.style.cursor = 'pointer';
    b.style.fontWeight = '700';
    b.onclick = onClick;
    return b;
  }

  const htmlBtn = makeBtn('Download HTML', exportHTML);
  const xlsBtn = makeBtn('Download Excel', exportExcel);
  const pdfBtn = makeBtn('Print PDF', exportPDF);

  bar.appendChild(htmlBtn);
  bar.appendChild(xlsBtn);
  bar.appendChild(pdfBtn);

  // Insert near top of the page if mainArea exists, otherwise at body start
  const main = document.getElementById('mainArea');
  if (main && main.parentNode) main.parentNode.insertBefore(bar, main);
  else document.body.insertBefore(bar, document.body.firstChild);
}

// Reveal the export toolbar (create it first if missing)
function showExportToolbar(){
  ensureExportToolbar();
  const bar = document.getElementById('badmintonExportToolbar');
  if (bar) bar.style.display = 'flex';
}

// Build full payload for save_set.php using current UI state
function buildSavePayload() {
  syncAllPlayerInputs();
  syncSetWinCounts();
  const matchIdRaw = sessionStorage.getItem('badminton_match_id');
  const match_id = matchIdRaw ? parseInt(matchIdRaw,10) : null;
  // always capture committee/official here so every server save includes it
  const committee = document.getElementById('bdCommitteeInput') ? document.getElementById('bdCommitteeInput').value.trim() : '';
  // read player inputs
  function nameFor(team, idx) {
    return getPlayerInput(team, idx);
  }
  function intFrom(id){ const el=document.getElementById(id); if(!el) return 0; const n=parseInt(String(el.textContent||el.value||'').replace(/\D/g,''),10); return isNaN(n)?0:n; }

  // Build sets array robustly:
  // - normalize entries from local `setHistory` (handling both camelCase and snake_case)
  // - deduplicate by `set_number` keeping the most recent data
  // - ensure the current UI snapshot (current set scores) always overwrites any earlier entry
  const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
  const byNum = {};
  // Auto-increment for missing/zero set numbers
  let auto = 0;
  completed.forEach(s => {
    let sn = (s.setNumber != null) ? parseInt(s.setNumber, 10) : (s.set_number != null ? parseInt(s.set_number,10) : 0);
    if (!sn) { auto++; sn = auto; } else { if (sn > auto) auto = sn; }
    const ta = (s.teamAScore != null) ? parseInt(s.teamAScore,10) : ((s.team_a_score != null) ? parseInt(s.team_a_score,10) : 0);
    const tb = (s.teamBScore != null) ? parseInt(s.teamBScore,10) : ((s.team_b_score != null) ? parseInt(s.team_b_score,10) : 0);
    const ta_to = (s.teamATimeout != null) ? parseInt(s.teamATimeout,10) : ((s.team_a_timeout_used != null) ? parseInt(s.team_a_timeout_used,10) : 0);
    const tb_to = (s.teamBTimeout != null) ? parseInt(s.teamBTimeout,10) : ((s.team_b_timeout_used != null) ? parseInt(s.team_b_timeout_used,10) : 0);
    const serve = (s.serving || s.serving_team) === 'B' ? 'B' : 'A';
    const sw = getSetWinner(s);
    byNum[sn] = {
      set_number: sn,
      team_a_score: ta,
      team_b_score: tb,
      team_a_timeout_used: ta_to,
      team_b_timeout_used: tb_to,
      serving_team: serve,
      set_winner: sw
    };
  });

  // Current snapshot (always include / overwrite)
  const currentSnap = {
    set_number: parseInt(state.currentSet || 1, 10),
    team_a_score: intFrom('scoreA'),
    team_b_score: intFrom('scoreB'),
    team_a_timeout_used: intFrom('timeoutA'),
    team_b_timeout_used: intFrom('timeoutB'),
    serving_team: state.serving === 'B' ? 'B' : 'A',
    set_winner: getManualWinnerForSet(state.currentSet) || getSetWinnerFromScores(intFrom('scoreA'), intFrom('scoreB'))
  };
  // ensure auto increments if needed
  if (currentSnap.set_number && currentSnap.set_number > auto) auto = currentSnap.set_number;
  // Only overwrite an existing set entry with the current snapshot if the snapshot has activity
  const currHasActivity = (currentSnap.team_a_score !== 0 || currentSnap.team_b_score !== 0 || currentSnap.team_a_timeout_used !== 0 || currentSnap.team_b_timeout_used !== 0 || currentSnap.set_winner !== null);
  if (currHasActivity || !byNum[currentSnap.set_number]) {
    byNum[currentSnap.set_number] = currentSnap;
  }

  // Produce ordered array
  const keys = Object.keys(byNum).map(k => parseInt(k,10)).sort((a,b)=>a-b);
  const setsArray = keys.map(k => byNum[k]);

  const payload = {
    match_id: match_id,
    match_type: state.matchType,
    best_of: state.bestOf,
    team_a_name: state.teamA.name,
    team_b_name: state.teamB.name,
    team_a_player1: nameFor('A',0),
    team_a_player2: nameFor('A',1) || null,
    team_b_player1: nameFor('B',0),
    team_b_player2: nameFor('B',1) || null,
    set_number: state.currentSet,
    team_a_score: currentSnap.team_a_score,
    team_b_score: currentSnap.team_b_score,
    team_a_timeout_used: currentSnap.team_a_timeout_used,
    team_b_timeout_used: currentSnap.team_b_timeout_used,
    serving_team: currentSnap.serving_team,
    set_winner: currentSnap.set_winner,
    sets: setsArray,
    team_a_games_won: intFrom('gamesA'),
    team_b_games_won: intFrom('gamesB')
  };
  if (committee) payload.committee_official = committee;
  return payload;
}

// ── Broadcast a reset signal to all connected clients (WS + BroadcastChannel) ──
// Called locally after a successful server reset so every admin/viewer tab
// clears its state without a manual refresh.
function _broadcastReset(matchId) {
  // 1. BroadcastChannel — instant push to same-browser tabs (admin + viewer)
  const resetPayload = { _reset: true, match_id: matchId || null };
  try { if (_bdBC) _bdBC.postMessage(resetPayload); } catch (_) {}
  // 2. WebSocket relay — reaches other devices / browsers
  try {
    if (_ws && _ws.readyState === 1) {
      _ws.send(JSON.stringify({
        type: 'new_match',
        match_id: matchId || null,
        sport: 'badminton',
        payload: resetPayload
      }));
    }
  } catch (_) {}
  // 3. localStorage clear — picked up by 'storage' event listeners in other tabs
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
}

// Apply a remote reset: clear all state and reload the page cleanly.
function _applyRemoteReset() {
  try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
  try { sessionStorage.removeItem('badminton_match_id'); } catch (_) {}
  location.reload();
}

// Reset match handler called by Reset Match button
function resetMatch() {
  const matchIdRaw = sessionStorage.getItem('badminton_match_id');
  const matchId = matchIdRaw ? parseInt(matchIdRaw,10) : null;
  if (!confirm('Reset this match? All saved set records will be deleted.')) return;
  if (!matchId) {
    // No server match yet — broadcast reset locally then reload
    _broadcastReset(null);
    try { localStorage.removeItem(ADMIN_STATE_KEY); } catch(_) {}
    try { localStorage.removeItem(STORAGE_KEY); } catch(_) {}
    sessionStorage.removeItem('badminton_match_id');
    location.reload();
    return;
  }
  fetch('reset_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: matchId }) })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) {
        // Broadcast reset to ALL other connected clients BEFORE wiping local state
        _broadcastReset(matchId);
        sessionStorage.removeItem('badminton_match_id');
        try { localStorage.removeItem(ADMIN_STATE_KEY); } catch(_) {}
        try { localStorage.removeItem(STORAGE_KEY); } catch(_) {}
        alert('Match has been reset.');
        location.reload();
      } else {
        alert('Reset failed: ' + (j && j.message ? j.message : 'Unknown'));
      }
    }).catch(err => { console.error(err); alert('Reset request failed.'); });
}

// ================================================================
// PERSISTENCE LAYER — localStorage restore + debounced DB auto-save
// ================================================================

// (loadPersistedState, scheduleServerPersist, and saveAdminState are defined at the top of this file)

// ── Bootstrap: restore persisted state, then update UI ───────────
const _wasRestored = loadPersistedState();
if (!_wasRestored) {
  // Fresh start — apply defaults
  setMatchType('singles');
}
updateLabels();
ensureExportToolbar();
// Always write state on load (re-broadcasts to viewer tabs after restore)
saveLocalState();
// ensure committee input updates viewer as you type
const committeeEl = document.getElementById('bdCommitteeInput');
if (committeeEl && !committeeEl.dataset._live) {
  committeeEl.addEventListener('input', saveLocalState);
  committeeEl.addEventListener('blur', saveLocalState);
  committeeEl.dataset._live = '1';
}
// Attach live listener to swap button(s) so viewer updates immediately on click.
// Note: the HTML has `onclick="swapTeams()"`. To avoid double-toggling we do NOT
// call `swapTeams()` here — we only persist state shortly after the native handler.
document.querySelectorAll('.btn-swap').forEach(btn => {
  if (btn && !btn.dataset._live) {
    btn.addEventListener('click', () => {
      // let the existing onclick handler run first, then persist
      setTimeout(saveLocalState, 50);
    });
    btn.dataset._live = '1';
  }
});

// ── BroadcastChannel broadcast (feeds badminton_viewer.js instantly) ─────────
// Wraps saveLocalState so every write also pushes to any open viewer tab
// via BroadcastChannel — giving sub-100ms updates without a page refresh.
const _BD_CHANNEL_NAME = 'badminton_live';
let   _bdBC = null;
try { _bdBC = new BroadcastChannel(_BD_CHANNEL_NAME); } catch (_) {}

// Listen for reset broadcasts from other admin tabs on the same device
try {
  if (_bdBC) {
    _bdBC.addEventListener('message', function (e) {
      if (e.data && e.data._reset === true) {
        _applyRemoteReset();
      }
    });
  }
} catch (_) {}

const _origSaveLocalState = saveLocalState;
saveLocalState = function () {
  _origSaveLocalState();
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw && _bdBC) _bdBC.postMessage(JSON.parse(raw));
  } catch (_) {}
};

// ----- Admin UI helpers: reflect manual winner state on admin buttons -----
function updateAdminWinnerButtons() {
  try {
    const setNum = String(state.currentSet || 1);
    const manual = state.manualWinners || {};
    const winner = manual[setNum] ? String(manual[setNum]).toUpperCase() : null;
    const btnA = document.getElementById('adminMarkWinnerA');
    const btnB = document.getElementById('adminMarkWinnerB');
    if (btnA) btnA.classList.toggle('active', winner === 'A');
    if (btnB) btnB.classList.toggle('active', winner === 'B');
    // reflect highlight on the admin team headers as well
    const hdrA = document.getElementById('headerA');
    const hdrB = document.getElementById('headerB');
    if (hdrA) hdrA.classList.toggle('manual-winner', winner === 'A');
    if (hdrB) hdrB.classList.toggle('manual-winner', winner === 'B');
  } catch (e) { /* ignore UI sync errors */ }
}

// Attach robust click handlers and expose toggle for inline handlers/other scripts.
function attachAdminWinnerHandlers() {
  try {
    const btnA = document.getElementById('adminMarkWinnerA');
    const btnB = document.getElementById('adminMarkWinnerB');
    // Avoid adding a second handler if an inline `onclick` exists (prevents double-toggle)
    if (btnA && !btnA.dataset._bind && !btnA.getAttribute('onclick')) { btnA.addEventListener('click', function(e){ toggleManualWinner('A'); }); btnA.dataset._bind = '1'; }
    if (btnB && !btnB.dataset._bind && !btnB.getAttribute('onclick')) { btnB.addEventListener('click', function(e){ toggleManualWinner('B'); }); btnB.dataset._bind = '1'; }
    // initial sync
    updateAdminWinnerButtons();
  } catch (e) { /* ignore */ }
}

// Expose globally so inline `onclick` attributes still work and other scripts can call it.
try { window.toggleManualWinner = toggleManualWinner; } catch (_) {}


// ================================================================
// ✅ SSOT NEW MATCH — openNewMatchModal / confirmNewMatch
//
// Flow (strict SSOT):
//   1. Admin fills in the modal and clicks "Start Match"
//   2. confirmNewMatch() POSTs to save_set.php with NO match_id
//      → server creates a fresh row, returns { success, match_id }
//   3. On success:
//      a. Clear all client state (localStorage, sessionStorage, state obj)
//      b. Write new match_id to sessionStorage
//      c. Call _broadcastNewMatch(newMatchId, freshState) which:
//         - Posts fresh state to state.php (DB SSOT updated)
//         - Sends WS new_match broadcast (remote viewers update instantly)
//         - Sends BroadcastChannel message (same-browser tabs update instantly)
//         - Writes to localStorage (storage-event tabs update instantly)
//      d. Render admin UI with the fresh state
// ================================================================

// ── Modal state for the new-match form ──────────────────────────
let _nmBestOf = 3;
let _nmType   = 'singles';

function openNewMatchModal() {
  _nmBestOf = 3;
  _nmType   = 'singles';
  // Reset form fields
  const ta = document.getElementById('nmTeamA'); if (ta) ta.value = '';
  const tb = document.getElementById('nmTeamB'); if (tb) tb.value = '';
  const co = document.getElementById('nmCommittee'); if (co) co.value = '';
  const bo = document.getElementById('nmBestOf');    if (bo) bo.textContent = _nmBestOf;
  // Sync type buttons
  document.querySelectorAll('.nm-type-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.type === _nmType);
  });
  _nmRenderPlayers();
  // Show
  const modal = document.getElementById('newMatchModal');
  if (modal) { modal.style.display = 'flex'; }
}

function closeNewMatchModal() {
  const modal = document.getElementById('newMatchModal');
  if (modal) modal.style.display = 'none';
}

function nmSetType(type) {
  _nmType = normalizeMatchType(type);
  document.querySelectorAll('.nm-type-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.type === _nmType);
  });
  _nmRenderPlayers();
}

function nmChangeBestOf(d) {
  _nmBestOf = Math.max(1, Math.min(9, _nmBestOf + (d > 0 ? 2 : -2)));
  const bo = document.getElementById('nmBestOf'); if (bo) bo.textContent = _nmBestOf;
}

function _nmRenderPlayers() {
  const count = _nmType === 'singles' ? 1 : 2;
  ['A','B'].forEach(function(team) {
    const container = document.getElementById('nmPlayers' + team);
    if (!container) return;
    container.innerHTML = '';
    for (let i = 0; i < count; i++) {
      const label = document.createElement('label');
      label.style.cssText = 'font-size:11px;color:#aaa;margin-bottom:2px;display:block';
      if (_nmType === 'mixed')   label.textContent = (i === 0 ? 'Male Player' : 'Female Player');
      else if (_nmType === 'singles') label.textContent = 'Player Name';
      else                            label.textContent = 'Player ' + (i + 1);
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'nm-player-input';
      input.placeholder = label.textContent;
      input.dataset.team  = team;
      input.dataset.index = i;
      container.appendChild(label);
      container.appendChild(input);
    }
  });
}

// ── Core: broadcast a fully fresh match state to ALL clients ────
function _broadcastNewMatch(matchId, freshViewerState) {
  // 1. DB SSOT — write to state.php (cross-device viewers polling will pick this up)
  try {
    fetch('state.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({}, freshViewerState, {
        match_id: String(matchId),
        _origin: _ADMIN_TAB_ID
      }))
    }).catch(function() {});
  } catch (_) {}

  // 2. WebSocket relay — remote devices (other computers, phones, TVs)
  try {
    if (_ws && _ws.readyState === 1) {
      _ws.send(JSON.stringify({
        type: 'new_match',
        match_id: String(matchId),
        sport: 'badminton',
        payload: Object.assign({}, freshViewerState, { match_id: String(matchId), _newMatch: true })
      }));
    }
  } catch (_) {}

  // 3. BroadcastChannel — same-browser tabs get it in <10 ms
  try {
    if (_bdBC) _bdBC.postMessage(Object.assign({}, freshViewerState, {
      match_id: String(matchId),
      _newMatch: true  // ✅ tells viewer.js this is a new match, not a score update
    }));
  } catch (_) {}

  // 4. localStorage — other-tab 'storage' event fallback
  try {
    const stamped = Object.assign({}, freshViewerState, {
      match_id: String(matchId),
      _savedAt: new Date().toISOString()
    });
    localStorage.setItem(STORAGE_KEY, JSON.stringify(stamped));
  } catch (_) {}
}

function confirmNewMatch() {
  const btn = document.getElementById('nmConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Creating…'; }

  // Read form values
  const teamAName = (document.getElementById('nmTeamA').value.trim() || 'TEAM A').toUpperCase();
  const teamBName = (document.getElementById('nmTeamB').value.trim() || 'TEAM B').toUpperCase();
  const committee = document.getElementById('nmCommittee').value.trim();

  // Collect player names
  function getPlayers(team) {
    const inputs = document.querySelectorAll('#nmPlayers' + team + ' input');
    return Array.from(inputs).map(function(i) { return i.value.trim(); });
  }
  const playersA = getPlayers('A');
  const playersB = getPlayers('B');

  // Build the payload for save_set.php — NO match_id forces a new DB row
  const createPayload = {
    match_type:     _nmType,
    best_of:        _nmBestOf,
    team_a_name:    teamAName,
    team_b_name:    teamBName,
    team_a_player1: playersA[0] || null,
    team_a_player2: playersA[1] || null,
    team_b_player1: playersB[0] || null,
    team_b_player2: playersB[1] || null,
    committee_official: committee || null,
    // Empty set so save_set.php upserts a clean set-1 record
    sets: [{
      set_number: 1, team_a_score: 0, team_b_score: 0,
      team_a_timeout_used: 0, team_b_timeout_used: 0,
      serving_team: 'A', set_winner: null
    }]
  };

  fetch('save_set.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(createPayload)
  })
  .then(function(r) { return r.json(); })
  .then(function(j) {
    if (!j || !j.success) {
      alert('Failed to create match: ' + (j && j.message ? j.message : 'Server error'));
      if (btn) { btn.disabled = false; btn.textContent = '✅ Start Match'; }
      return;
    }

    const newMatchId = String(j.match_id);

    // ── Step 1: Wipe ALL previous state everywhere ───────────────
    try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
    try { localStorage.removeItem(STORAGE_KEY); }     catch (_) {}
    try { sessionStorage.removeItem('badminton_match_id'); } catch (_) {}

    // ── Step 2: Reset in-memory state to fresh defaults ──────────
    state.matchType     = normalizeMatchType(_nmType);
    state.serving       = 'A';
    state.swapped       = false;
    state.bestOf        = _nmBestOf;
    state.currentSet    = 1;
    state.manualWinners = {};
    state.teamA = { name: teamAName, players: normalizePlayers(playersA, state.matchType), score: 0, gamesWon: 0, timeout: 0 };
    state.teamB = { name: teamBName, players: normalizePlayers(playersB, state.matchType), score: 0, gamesWon: 0, timeout: 0 };
    setHistory = [];

    // ── Step 3: Write new match_id to sessionStorage ─────────────
    sessionStorage.setItem('badminton_match_id', newMatchId);

    // ── Step 4: Build canonical fresh viewer state ────────────────
    const freshViewerState = {
      match_id:     newMatchId,
      matchType:    state.matchType,
      serving:      'A',
      swapped:      false,
      bestOf:       state.bestOf,
      currentSet:   1,
      manualWinners: {},
      teamAName:    teamAName,
      teamBName:    teamBName,
      scoreA:       0, scoreB: 0,
      gamesA:       0, gamesB: 0,
      timeoutA:     0, timeoutB: 0,
      servingTeam:  'A',
      committee:    committee,
      teamAPlayer1: playersA[0] || '',
      teamAPlayer2: playersA[1] || '',
      teamBPlayer1: playersB[0] || '',
      teamBPlayer2: playersB[1] || '',
      sets:         []
    };

    // ── Step 5: Broadcast to DB + WS + BroadcastChannel + localStorage ──
    _broadcastNewMatch(newMatchId, freshViewerState);

    // ── Step 6: Re-render admin UI ────────────────────────────────
    // Update DOM scoreboxes
    ['scoreA','scoreB','gamesA','gamesB','timeoutA','timeoutB'].forEach(function(id) {
      const el = document.getElementById(id); if (el) el.textContent = 0;
    });
    const boEl = document.getElementById('bestOfBox');   if (boEl) boEl.textContent = state.bestOf;
    const csEl = document.getElementById('currentSetBox'); if (csEl) csEl.textContent = 1;
    const spanA = document.getElementById('teamAName');  if (spanA) spanA.textContent = teamAName;
    const spanB = document.getElementById('teamBName');  if (spanB) spanB.textContent = teamBName;

    // Committee input
    const comEl = document.getElementById('bdCommitteeInput'); if (comEl) comEl.value = committee;

    // Reset swap layout
    const area  = document.getElementById('mainArea');
    const toRow = document.getElementById('timeoutRow');
    if (area)  area.style.gridTemplateAreas = '"left center right"';
    if (toRow) toRow.style.flexDirection    = 'row';

    // Match type buttons + player inputs
    setMatchType(state.matchType, { skipSave: true, skipSync: true });
    // Fill in player names that were entered in the modal
    ['A','B'].forEach(function(team) {
      const container = document.getElementById('players' + team);
      if (!container) return;
      const inputs = container.querySelectorAll('input');
      const src = team === 'A' ? playersA : playersB;
      inputs.forEach(function(inp, idx) { inp.value = src[idx] || ''; });
    });

    updateLabels();
    try { updateAdminWinnerButtons(); } catch (_) {}
    // Persist full admin snapshot (for this tab's own refresh recovery)
    saveAdminState();

    // ── Step 7: Close modal and confirm ──────────────────────────
    closeNewMatchModal();
    if (btn) { btn.disabled = false; btn.textContent = '✅ Start Match'; }

    // Brief toast so admin knows the match was created and broadcast
    (function() {
      const toast = document.createElement('div');
      toast.textContent = '✅ Match #' + newMatchId + ' created & broadcast to all viewers!';
      toast.style.cssText = 'position:fixed;bottom:70px;left:50%;transform:translateX(-50%);background:#1a5c1a;color:#fff;padding:10px 20px;border-radius:8px;font-weight:700;font-size:13px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.5);white-space:nowrap';
      document.body.appendChild(toast);
      setTimeout(function() { toast.style.opacity='0'; toast.style.transition='opacity .4s'; setTimeout(function(){ toast.remove(); }, 450); }, 3000);
    })();
  })
  .catch(function(err) {
    console.error('New match creation failed', err);
    alert('Network error. Could not create match. Please try again.');
    if (btn) { btn.disabled = false; btn.textContent = '✅ Start Match'; }
  });
}
// ✅ SSOT NEW MATCH — end