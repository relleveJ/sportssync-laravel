/* ============================================================
   tabletennis_admin.js
   Logic fully aligned with badminton_admin.js:
   - STORAGE_KEY + ADMIN_STATE_KEY for localStorage persistence
   - saveAdminState / loadPersistedState for refresh recovery
   - scheduleServerPersist for debounced DB sync via state.php
   - saveLocalState (single source of truth, replaces broadcastState)
   - BroadcastChannel wrapper on saveLocalState
   - clean startNewSet (no promptAfterSetChoice complexity)
   - resetMatch clears ADMIN_STATE_KEY + STORAGE_KEY
   - buildSavePayload includes team_a_games_won / team_b_games_won
   - saveAndReport uses committee_official key
   ============================================================ */

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

// Page guard to prevent cross-sport bleed
const IS_TABLE_TENNIS_PAGE = typeof document !== 'undefined' && document.body && document.body.dataset && document.body.dataset.sport === 'table-tennis';

// History of completed sets.
// NOTE: persisted to localStorage so it survives page refreshes.
let setHistory = [];
let _ttPrevSetsLength = 0;
let _ttPrevMatchWinner = null;
let _ttShownPopupKeys = {};

// ✅ SSOT FIX START — Patch 1
// Stable per-tab identifier: prevents the admin from re-broadcasting
// its own writes when they arrive back via WS echo from the relay.
const _TT_ADMIN_TAB_ID = (function() {
  try {
    let id = sessionStorage.getItem('_ttAdminTabId');
    if (!id) { id = Math.random().toString(36).slice(2); sessionStorage.setItem('_ttAdminTabId', id); }
    return id;
  } catch (_) { return Math.random().toString(36).slice(2); }
})();
// ✅ SSOT FIX END

// Keys used by viewer / admin recovery
const STORAGE_KEY   = 'tabletennisMatchState';
const ADMIN_STATE_KEY = 'tabletennisAdminState';

// ── Debounced server-side state persist (state.php) ──────────────
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

function makeTTPopupKey(type, payload) {
  if (!type || !payload) return null;
  if (type === 'set') {
    return 'set:' + payload.setNumber + ':' + payload.winner + ':' + payload.scoreA + ':' + payload.scoreB;
  }
  if (type === 'match') {
    return 'match:' + payload.winner + ':' + payload.gamesA + ':' + payload.gamesB;
  }
  return null;
}

function getLatestSetWinnerPayload(viewerState) {
  if (!viewerState || !Array.isArray(viewerState.sets) || !viewerState.sets.length) return null;
  const last = viewerState.sets[viewerState.sets.length - 1];
  const winner = (last && (last.winner || last.set_winner)) ? (last.winner || last.set_winner) : null;
  if (!winner) return null;
  const setNumber = last.setNumber || last.set_number || viewerState.sets.length;
  const scoreA = last.teamAScore != null ? last.teamAScore : (last.team_a_score != null ? last.team_a_score : 0);
  const scoreB = last.teamBScore != null ? last.teamBScore : (last.team_b_score != null ? last.team_b_score : 0);
  const winnerName = winner === 'B' ? (viewerState.teamB && viewerState.teamB.name ? viewerState.teamB.name : 'TEAM B') : (viewerState.teamA && viewerState.teamA.name ? viewerState.teamA.name : 'TEAM A');
  return { setNumber: Number(setNumber), winner: String(winner).toUpperCase(), winnerName: String(winnerName), scoreA: Number(scoreA), scoreB: Number(scoreB) };
}

function getMatchWinnerPayload(viewerState) {
  if (!viewerState || !viewerState.teamA || !viewerState.teamB) return null;
  const bestOf = Number(viewerState.bestOf || 3);
  const needed = Math.ceil(bestOf / 2);
  const gamesA = Number(viewerState.teamA.gamesWon || 0);
  const gamesB = Number(viewerState.teamB.gamesWon || 0);
  let winner = null;
  if (gamesA >= needed && gamesA > gamesB) winner = 'A';
  else if (gamesB >= needed && gamesB > gamesA) winner = 'B';
  if (!winner) return null;
  const winnerName = winner === 'B' ? viewerState.teamB.name : viewerState.teamA.name;
  return { winner: winner, winnerName: String(winnerName || (winner === 'B' ? 'TEAM B' : 'TEAM A')), gamesA: gamesA, gamesB: gamesB };
}

function flashAdminWinnerButton(team, isFinal) {
  if (!team) return;
  const btn = document.getElementById('adminMarkWinner' + String(team).toUpperCase());
  if (!btn) return;
  const cls = isFinal ? 'flash-strong' : 'flash';
  btn.classList.remove(cls);
  void btn.offsetWidth;
  btn.classList.add(cls);
  setTimeout(function() { btn.classList.remove(cls); }, isFinal ? 1400 : 700);
}

function showWinnerModal(title, msg) {
  const modal = document.getElementById('winnerModal');
  if (!modal) return;
  const titleEl = document.getElementById('winnerModalTitle');
  const msgEl = document.getElementById('winnerModalMsg');
  if (titleEl) titleEl.textContent = title || '';
  if (msgEl) msgEl.textContent = msg || '';
  modal.style.display = 'flex';
}

function closeWinnerModal() {
  const modal = document.getElementById('winnerModal');
  if (modal) modal.style.display = 'none';
}

function maybeShowTTWinnerPopup(viewerState) {
  if (!viewerState || typeof viewerState !== 'object') return;
  const setPayload = viewerState._latestSetWinner || getLatestSetWinnerPayload(viewerState);
  if (setPayload) {
    const key = makeTTPopupKey('set', setPayload);
    if (key && !_ttShownPopupKeys[key]) {
      _ttShownPopupKeys[key] = true;
      showWinnerModal('🏆 SET WINNER', `${setPayload.winnerName} wins Set ${setPayload.setNumber}! Final score ${setPayload.scoreA} — ${setPayload.scoreB}.`);
      flashAdminWinnerButton(setPayload.winner, false);
    }
  }
  const matchPayload = viewerState._latestMatchWinner || getMatchWinnerPayload(viewerState);
  if (matchPayload) {
    const key = makeTTPopupKey('match', matchPayload);
    if (key && !_ttShownPopupKeys[key]) {
      _ttShownPopupKeys[key] = true;
      showWinnerModal('🏁 MATCH WINNER', `${matchPayload.winnerName} wins the match! Final games ${matchPayload.gamesA} — ${matchPayload.gamesB}.`);
      flashAdminWinnerButton(matchPayload.winner, true);
    }
  }
}

function getPlayerContainer(team) {
  return document.getElementById('tt-players' + team);
}

function syncPlayerInputs(team) {
  const teamKey = 'team' + team;
  const container = getPlayerContainer(team);
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
  if (normalized === 'singles') return 'Singles';
  if (normalized === 'mixed') return idx === 0 ? 'Male Player' : 'Female Player';
  return 'Player ' + (idx + 1);
}

let _serverPersistTimer = null;
function scheduleServerPersist() {
  if (_serverPersistTimer) clearTimeout(_serverPersistTimer);
  _serverPersistTimer = setTimeout(function () {
    // ✅ SSOT FIX START — Patch 2
    // Always build the payload from the canonical in-memory `state` object,
    // never from localStorage (which may be stale or written by another tab).
    try {
      syncAllPlayerInputs();
      const matchIdRaw = sessionStorage.getItem('tabletennis_match_id');
      const committee = document.getElementById('ttCommitteeInput')
        ? document.getElementById('ttCommitteeInput').value.trim() : '';
      const payload = {
        match_id:    matchIdRaw ? matchIdRaw : 'live',
        matchType:   state.matchType,
        serving:     state.serving,
        swapped:     state.swapped,
        bestOf:      state.bestOf,
        currentSet:  state.currentSet,
        committee:   committee,
        manualWinners: state.manualWinners || {},
        teamA: {
          name:     state.teamA.name,
          score:    state.teamA.score,
          gamesWon: state.teamA.gamesWon,
          timeout:  state.teamA.timeout,
          players:  state.teamA.players
        },
        teamB: {
          name:     state.teamB.name,
          score:    state.teamB.score,
          gamesWon: state.teamB.gamesWon,
          timeout:  state.teamB.timeout,
          players:  state.teamB.players
        },
        sets:    Array.isArray(setHistory) ? setHistory : [],
        _latestSetWinner: getLatestSetWinnerPayload({
          teamA: { name: state.teamA.name },
          teamB: { name: state.teamB.name },
          sets:   Array.isArray(setHistory) ? setHistory : []
        }),
        _latestMatchWinner: getMatchWinnerPayload({
          bestOf: state.bestOf,
          teamA: { name: state.teamA.name, gamesWon: state.teamA.gamesWon },
          teamB: { name: state.teamB.name, gamesWon: state.teamB.gamesWon }
        }),
        _origin: _TT_ADMIN_TAB_ID  // tag so receivers skip re-broadcasting own writes
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
    const matchIdRaw = sessionStorage.getItem('tabletennis_match_id');
    const adminSnap = {
      state: JSON.parse(JSON.stringify(state)),
      setHistory: Array.isArray(setHistory) ? setHistory.slice() : [],
      match_id: matchIdRaw || null,
      committee: (function(){ const el=document.getElementById('ttCommitteeInput'); return el?el.value.trim():''; })()
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

    const s = snap.state;
    state.matchType     = normalizeMatchType(s.matchType || 'singles');
    state.serving       = s.serving       || 'A';
    state.swapped       = s.swapped       || false;
    state.bestOf        = s.bestOf        || 3;
    state.currentSet    = s.currentSet    || 1;
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

    if (Array.isArray(snap.setHistory)) {
      setHistory.length = 0;
      snap.setHistory.forEach(function(h){ setHistory.push(h); });
    }

    if (snap.match_id) {
      sessionStorage.setItem('tabletennis_match_id', snap.match_id);
    }

    // Restore DOM
    const safe = function(id, val){ const el=document.getElementById(id); if(el) el.textContent = val; };
    safe('scoreA',        state.teamA.score);
    safe('scoreB',        state.teamB.score);
    safe('gamesA',        state.teamA.gamesWon);
    safe('gamesB',        state.teamB.gamesWon);
    safe('timeoutA',      state.teamA.timeout);
    safe('timeoutB',      state.teamB.timeout);
    safe('bestOfBox',     state.bestOf);
    safe('currentSetBox', state.currentSet);

    const spanA = document.getElementById('teamAName'); if(spanA) spanA.textContent = state.teamA.name;
    const spanB = document.getElementById('teamBName'); if(spanB) spanB.textContent = state.teamB.name;

    setMatchType(state.matchType, { skipSave: true, skipSync: true });

    const comEl = document.getElementById('ttCommitteeInput');
    if (comEl && snap.committee) comEl.value = snap.committee;

    const area  = document.getElementById('mainArea');
    const toRow = document.getElementById('timeoutRow');
    if (state.swapped) {
      if (area)  area.style.gridTemplateAreas = '"right center left"';
      if (toRow) toRow.style.flexDirection    = 'row-reverse';
    } else {
      if (area)  area.style.gridTemplateAreas = '"left center right"';
      if (toRow) toRow.style.flexDirection    = 'row';
    }

    updateLabels();
    try { updateAdminWinnerButtons(); } catch(_){}

    console.log('[tabletennis] State restored — Set ' + state.currentSet + ', Score A:' + state.teamA.score + ' B:' + state.teamB.score);
    return true;
  } catch (e) {
    console.warn('[tabletennis] loadPersistedState error:', e);
    return false;
  }
}

// ── WebSocket relay ──────────────────────────────────────────────
let _tt_ws = null;
function _initTTWS() {
  try {
    const scheme = (location.protocol === 'https:') ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _tt_ws = new WebSocket(url);
    _tt_ws.addEventListener('open', function () {
      console.log('tabletennis admin WS connected');
      _setWSStatus('connected');
      try { _tt_ws.send(JSON.stringify({ type: 'join', match_id: getMatchId() })); } catch(e){}
    });
    _tt_ws.addEventListener('message', function (ev) {
      try {
        var m = JSON.parse(ev.data);
        if (!m) return;
        if (m.sport && m.sport !== 'table-tennis') return;
        var mid = m.match_id || m.matchId || (m.payload && m.payload.match_id) || null;

        if (m.type === 'last_state' && m.payload) {
          applyRemotePayload(m.payload, mid);
          return;
        }
        if ((m.type === 'tabletennis_state' || m.type === 'state') && m.payload) {
          applyRemotePayload(m.payload, mid);
          return;
        }
        if (m.type === 'applied_action' && m.payload) {
          // applied_action carries payload for room — treat as state-ish
          applyRemotePayload(m.payload, mid);
          return;
        }
        if (m.type === 'new_match') {
          var newMid = m.match_id || (m.payload && m.payload.match_id) || null;
          if (m.payload && m.payload._reset === true) {
            _applyRemoteReset();
            return;
          }
          if (!newMid) return;
          try { sessionStorage.setItem('tabletennis_match_id', String(newMid)); } catch(_) {}
          try { _tt_ws.send(JSON.stringify({ type: 'join', match_id: String(newMid) })); } catch(_) {}
          // If payload contains full metadata/state, apply it immediately; otherwise fetch canonical state
          try {
            if (m.payload && typeof m.payload === 'object' && (m.payload.teamA || m.payload.team_a || m.payload.sets || m.payload.team_a_name)) {
              try { applyRemotePayload(m.payload, newMid); } catch(_) {}
            } else {
              fetch('state.php?match_id=' + encodeURIComponent(newMid)).then(function(res){ return res.text(); }).then(function(txt){ try { var obj = txt ? JSON.parse(txt) : null; if (obj) applyRemotePayload(obj, newMid); } catch(_){} }).catch(function(){});
            }
          } catch (_) {}
          return;
        }
      } catch (e) { /* ignore malformed messages */ }
    });
    _tt_ws.addEventListener('close', function () {
      console.log('tabletennis admin WS closed, reconnecting...');
      _setWSStatus('disconnected');
      setTimeout(_initTTWS, 2000);
    });
    _tt_ws.addEventListener('error', function () { _setWSStatus('error'); });
  } catch (e) { _tt_ws = null; }
}
_initTTWS();

function _maybeSendTTWS(payload) {
  try {
    if (_tt_ws && _tt_ws.readyState === 1) {
      _tt_ws.send(JSON.stringify({ type: 'tabletennis_state', sport: 'table-tennis', match_id: getMatchId(), payload: payload }));
    }
  } catch (e) {}
}

// Apply remote viewer-state payload into admin UI without triggering a DB persist.
function applyRemotePayload(payload, mid) {
  if (!payload || typeof payload !== 'object') return;
  // ✅ SSOT FIX START — Patch 3
  // If this payload was written by THIS tab (echoed back via WS), skip it.
  // Prevents the ping-pong loop: admin writes → server broadcasts → admin re-applies → repeat.
  if (payload._origin && payload._origin === _TT_ADMIN_TAB_ID) return;
  // ✅ SSOT FIX END
  try {
    // adopt shared match id if provided
    try { if (mid) sessionStorage.setItem('tabletennis_match_id', String(mid)); } catch(_) {}

    const tA = payload.teamA || payload.team_a || {};
    const tB = payload.teamB || payload.team_b || {};

    state.matchType = normalizeMatchType(payload.matchType || payload.match_type || state.matchType);
    state.serving   = payload.serving || payload.serving_team || state.serving;
    state.swapped   = !!payload.swapped;
    state.bestOf    = payload.bestOf || payload.best_of || state.bestOf;
    state.currentSet = payload.currentSet || payload.current_set || state.currentSet;

    state.teamA.name = (tA.name || payload.team_a_name || payload.teamAName) || state.teamA.name;
    state.teamB.name = (tB.name || payload.team_b_name || payload.teamBName) || state.teamB.name;

    state.teamA.score = (tA.score != null ? tA.score : (payload.team_a_score != null ? payload.team_a_score : state.teamA.score));
    state.teamB.score = (tB.score != null ? tB.score : (payload.team_b_score != null ? payload.team_b_score : state.teamB.score));
    state.teamA.gamesWon = (tA.gamesWon != null ? tA.gamesWon : (payload.team_a_games_won != null ? payload.team_a_games_won : state.teamA.gamesWon));
    state.teamB.gamesWon = (tB.gamesWon != null ? tB.gamesWon : (payload.team_b_games_won != null ? payload.team_b_games_won : state.teamB.gamesWon));
    state.teamA.timeout = (tA.timeout != null ? tA.timeout : (payload.timeoutA != null ? payload.timeoutA : state.teamA.timeout));
    state.teamB.timeout = (tB.timeout != null ? tB.timeout : (payload.timeoutB != null ? payload.timeoutB : state.teamB.timeout));

    // players
    var playersA = (tA.players && Array.isArray(tA.players)) ? tA.players : [payload.team_a_player1 || payload.teamAPlayer1 || '', payload.team_a_player2 || payload.teamAPlayer2 || ''];
    var playersB = (tB.players && Array.isArray(tB.players)) ? tB.players : [payload.team_b_player1 || payload.teamBPlayer1 || '', payload.team_b_player2 || payload.teamBPlayer2 || ''];
    state.teamA.players = normalizePlayers(playersA, state.matchType);
    state.teamB.players = normalizePlayers(playersB, state.matchType);

    // sets history
    if (Array.isArray(payload.sets)) {
      setHistory = payload.sets.map(function(s){
        return { setNumber: s.setNumber || s.set_number || 0, teamAScore: s.teamAScore || s.team_a_score || 0, teamBScore: s.teamBScore || s.team_b_score || 0, teamATimeout: s.teamATimeout || s.team_a_timeout_used || 0, teamBTimeout: s.teamBTimeout || s.team_b_timeout_used || 0, serving: s.serving || s.serving_team || 'A', winner: s.winner || s.set_winner || null };
      });
    }

    state.manualWinners = payload.manualWinners || payload.manual_winners || state.manualWinners || {};

    // Update DOM quickly
    try {
      var el;
      el = document.getElementById('teamAName'); if (el) el.textContent = state.teamA.name;
      el = document.getElementById('teamBName'); if (el) el.textContent = state.teamB.name;
      el = document.getElementById('scoreA'); if (el) el.textContent = state.teamA.score;
      el = document.getElementById('scoreB'); if (el) el.textContent = state.teamB.score;
      el = document.getElementById('gamesA'); if (el) el.textContent = state.teamA.gamesWon;
      el = document.getElementById('gamesB'); if (el) el.textContent = state.teamB.gamesWon;
      el = document.getElementById('timeoutA'); if (el) el.textContent = state.teamA.timeout;
      el = document.getElementById('timeoutB'); if (el) el.textContent = state.teamB.timeout;
      el = document.getElementById('bestOfBox'); if (el) el.textContent = state.bestOf;
      el = document.getElementById('currentSetBox'); if (el) el.textContent = state.currentSet;
    } catch (_) {}

    // Re-render players and labels (do not call saveLocalState to avoid feedback loops)
    try { document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === state.matchType)); } catch(_) {}
    try { renderPlayers('A'); renderPlayers('B'); } catch(_) {}

    updateLabels();
    updateAdminWinnerButtons();

    // Persist admin snapshot and update viewer localStorage / BroadcastChannel (no DB persist)
    try { saveAdminState(); } catch(_) {}
    try {
      var viewerState = { match_id: mid || payload.match_id || sessionStorage.getItem('tabletennis_match_id') || null, matchType: state.matchType, serving: state.serving, swapped: state.swapped, bestOf: state.bestOf, currentSet: state.currentSet, committee: payload.committee || payload.committee_official || '', teamA: { name: state.teamA.name, score: state.teamA.score, gamesWon: state.teamA.gamesWon, timeout: state.teamA.timeout, players: state.teamA.players }, teamB: { name: state.teamB.name, score: state.teamB.score, gamesWon: state.teamB.gamesWon, timeout: state.teamB.timeout, players: state.teamB.players }, sets: setHistory, manualWinners: state.manualWinners || {}, _savedAt: new Date().toISOString() };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(viewerState));
      if (_ttBC) _ttBC.postMessage(viewerState);
      try { maybeShowTTWinnerPopup(viewerState); } catch (_) {}
    } catch(_) {}
  } catch (e) { console.warn('applyRemotePayload error', e); }
}

function getMatchId() {
  try {
    if (window.MATCH_DATA && MATCH_DATA.match_id) return String(MATCH_DATA.match_id);
    if (window.__matchId) return String(window.__matchId);
    const el = document.getElementById('matchId'); if (el) return String(el.value || el.textContent || '').trim() || null;
    return null;
  } catch (e) { return null; }
}

// WS status indicator (dismissible)
function _ensureWSIndicator() {
  try {
    if (window.__wsStatusDismissed) return;
    if (document.getElementById('wsStatus')) return;
    const bar = document.createElement('div');
    bar.id = 'wsStatus';
    bar.style.cssText = 'position:fixed;right:12px;bottom:12px;padding:6px 10px;border-radius:6px;background:#ddd;color:#111;font-size:12px;z-index:9999;display:flex;align-items:center';
    const label = document.createElement('span');
    label.id = 'wsStatusLabel';
    label.textContent = 'WS: unknown';
    label.style.marginRight = '8px';
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button'; closeBtn.textContent = '✕'; closeBtn.title = 'Dismiss';
    closeBtn.style.cssText = 'border:none;background:transparent;cursor:pointer;font-size:12px';
    closeBtn.onclick = function () { window.__wsStatusDismissed = true; const el=document.getElementById('wsStatus'); if(el) el.remove(); };
    bar.appendChild(label); bar.appendChild(closeBtn);
    document.body.appendChild(bar);
  } catch (e) {}
}

function _setWSStatus(s) {
  try {
    if (window.__wsStatusDismissed) return;
    _ensureWSIndicator();
    const label = document.getElementById('wsStatusLabel');
    const el    = document.getElementById('wsStatus');
    if (!el || !label) return;
    if      (s === 'connected')    { el.style.background='#dff0d8'; label.style.color='#155724'; label.textContent='WS: connected'; }
    else if (s === 'disconnected') { el.style.background='#f8d7da'; label.style.color='#721c24'; label.textContent='WS: disconnected'; }
    else if (s === 'error')        { el.style.background='#fce5cd'; label.style.color='#7a4100'; label.textContent='WS: error'; }
    else                           { el.style.background='#e2e3e5'; label.style.color='#383d41'; label.textContent='WS: ' + String(s||'unknown'); }
  } catch (e) {}
}
_setWSStatus('connecting');

// ── Helper: read player input ────────────────────────────────────
function getPlayerInput(team, idx) {
  const teamKey = 'team' + team;
  if (!state[teamKey]) return '';
  normalizeTeamPlayers(team);
  return state[teamKey].players[idx] || '';
}

// ── saveLocalState: single source of truth for viewer sync ───────
function saveLocalState() {
  try {
    syncAllPlayerInputs();
    const committee = document.getElementById('ttCommitteeInput') ? document.getElementById('ttCommitteeInput').value.trim() : '';
    const matchIdRaw = sessionStorage.getItem('tabletennis_match_id');
    const viewerState = {
      match_id:    matchIdRaw || null,
      matchType:   state.matchType,
      serving:     state.serving,
      swapped:     state.swapped || false,
      bestOf:      state.bestOf,
      currentSet:  state.currentSet,
      committee:   committee,
      teamA: {
        name:     state.teamA.name,
        score:    state.teamA.score,
        gamesWon: state.teamA.gamesWon,
        timeout:  state.teamA.timeout,
        players:  normalizeTeamPlayers('A')
      },
      teamB: {
        name:     state.teamB.name,
        score:    state.teamB.score,
        gamesWon: state.teamB.gamesWon,
        timeout:  state.teamB.timeout,
        players:  normalizeTeamPlayers('B')
      },
      sets: Array.isArray(setHistory) ? setHistory.map(function(s){ return { setNumber: s.setNumber, teamAScore: s.teamAScore, teamBScore: s.teamBScore, teamATimeout: s.teamATimeout, teamBTimeout: s.teamBTimeout, serving: s.serving, winner: s.winner }; }) : [],
      manualWinners: state.manualWinners || {}
    };

    const latestSetWinner = getLatestSetWinnerPayload(viewerState);
    if (latestSetWinner && viewerState.sets.length > _ttPrevSetsLength) {
      viewerState._latestSetWinner = latestSetWinner;
      _ttPrevSetsLength = viewerState.sets.length;
    }
    const latestMatchWinner = getMatchWinnerPayload(viewerState);
    if (latestMatchWinner) {
      viewerState._latestMatchWinner = latestMatchWinner;
    }

    // ✅ SSOT FIX START — Patch 5
    // Stamp _savedAt so viewer can compare local vs DB freshness (used by Patch 6 in viewer.js)
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...viewerState, _savedAt: new Date().toISOString() }));
    // ✅ SSOT FIX END
    // Push to WS relay for cross-device viewers
    try { _maybeSendTTWS(viewerState); } catch (_) {}
    // Save full admin snapshot for reload recovery
    try { saveAdminState(); } catch (_) {}
    // Schedule debounced DB persist
    try { scheduleServerPersist(); } catch (_) {}

    maybeShowTTWinnerPopup(viewerState);
  } catch (e) {}
}

// ── Numeric cleaner for contenteditable ─────────────────────────
function sanitizeNumeric(id, team, key) {
  const el = document.getElementById(id);
  let val = parseInt(el.textContent.replace(/\D/g, '')) || 0;
  el.textContent = val;
  state[team][key] = val;
  saveLocalState();
}

function syncScore(t)   { sanitizeNumeric('score'   + t, 'team' + t, 'score'); }
function syncGames(t)   { sanitizeNumeric('games'   + t, 'team' + t, 'gamesWon'); }
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

function toggleServing() {
  state.serving = state.serving === 'A' ? 'B' : 'A';
  updateLabels();
  saveLocalState();
}

function editTeamName(team) {
  const header   = document.getElementById('header' + team);
  const nameSpan = document.getElementById('team' + team + 'Name');
  if (header.querySelector('input')) return;
  const currentName = state['team' + team].name;
  nameSpan.style.display = 'none';
  const input = document.createElement('input');
  input.type = 'text'; input.value = currentName; header.appendChild(input); input.focus();
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
}

function swapTeams() {
  state.swapped = !state.swapped;
  const area  = document.getElementById('mainArea');
  const toRow = document.getElementById('timeoutRow');
  if (state.swapped) {
    area.style.gridTemplateAreas  = '"right center left"';
    toRow.style.flexDirection = 'row-reverse';
  } else {
    area.style.gridTemplateAreas  = '"left center right"';
    toRow.style.flexDirection = 'row';
  }
  saveLocalState();
}

function setMatchType(type, options) {
  const opts = options || {};
  const newType = normalizeMatchType(type);
  if (!opts.skipConfirm && state.matchType !== newType) {
    const message = 'Switching match type will adjust player slots and may reset some entered player names.\n\nContinue and change to ' + newType.charAt(0).toUpperCase() + newType.slice(1) + '?';
    if (!confirm(message)) return;
  }
  if (!opts.skipSync) syncAllPlayerInputs();
  state.matchType = newType;
  state.teamA.players = normalizePlayers(state.teamA.players, state.matchType);
  state.teamB.players = normalizePlayers(state.teamB.players, state.matchType);
  document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === state.matchType));
  renderPlayers('A');
  renderPlayers('B');
  if (!opts.skipSave) saveLocalState();
}

function renderPlayers(t) {
  const container = getPlayerContainer(t);
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

function pName(team, idx) {
  return getPlayerInput(team, idx);
}

function changeBestOf(d) {
  state.bestOf = Math.max(1, Math.min(5, state.bestOf + (d > 0 ? 2 : -2)));
  document.getElementById('bestOfBox').textContent = state.bestOf;
  saveLocalState();
}

function changeSet(d) {
  state.currentSet = Math.max(1, Math.min(state.bestOf, state.currentSet + d));
  document.getElementById('currentSetBox').textContent = state.currentSet;
  saveLocalState();
  updateAdminWinnerButtons();
}

// ── startNewSet (mirrors badminton logic exactly) ────────────────
function startNewSet() {
  if (!confirm('Start a new set? This will save the current set and clear scores. Continue?')) return;
  syncAllPlayerInputs();

  try {
    const snap = {
      setNumber:   state.currentSet,
      teamAScore:  state.teamA.score,
      teamBScore:  state.teamB.score,
      teamATimeout: state.teamA.timeout,
      teamBTimeout: state.teamB.timeout,
      serving:     state.serving,
      winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : null)
    };
    setHistory.push(snap);
    // Update gamesWon from history and check auto-complete
    try { checkAutoMatchComplete(); } catch(_) {}
  } catch (e) {}

  const payload = buildSavePayload();
  fetch('save_set.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(j => {
      // ✅ SSOT FIX START — Patch 4
      // Only clear scores when the server confirms the set was saved.
      // Previously this was in .finally(), which cleared scores even on failure,
      // leaving the server and viewer out of sync with no recovery path.
      if (!j || !j.success) {
        console.warn('[tabletennis] save_set failed — scores NOT cleared. Retry save.', j);
        return;
      }
      if (j.success) sessionStorage.setItem('tabletennis_match_id', j.match_id);
      state.teamA.score = 0; state.teamB.score = 0;
      state.teamA.timeout = 0; state.teamB.timeout = 0;
      const elA = document.getElementById('scoreA'); if (elA) elA.textContent = 0;
      const elB = document.getElementById('scoreB'); if (elB) elB.textContent = 0;
      const ta  = document.getElementById('timeoutA'); if (ta) ta.textContent = 0;
      const tb  = document.getElementById('timeoutB'); if (tb) tb.textContent = 0;
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
    const aWins = completed.reduce((acc, s) => acc + ((s && s.winner === 'A') ? 1 : 0), 0);
    const bWins = completed.reduce((acc, s) => acc + ((s && s.winner === 'B') ? 1 : 0), 0);
    state.teamA.gamesWon = aWins;
    state.teamB.gamesWon = bWins;
    const ga = document.getElementById('gamesA'); if (ga) ga.textContent = aWins;
    const gb = document.getElementById('gamesB'); if (gb) gb.textContent = bWins;
    try { saveLocalState(); } catch(_) {}

    const needed = Math.ceil((state.bestOf || 3) / 2);
    if (aWins >= needed || bWins >= needed) {
      const winnerName = aWins > bWins ? state.teamA.name : state.teamB.name;
      const msg = `${winnerName} WINS THE MATCH! (${Math.max(aWins,bWins)}-${Math.min(aWins,bWins)})`;
      const modalMsg = document.getElementById('modalMsg'); if (modalMsg) modalMsg.textContent = msg;
      const modalEl  = document.getElementById('modal');    if (modalEl)  modalEl.classList.add('show');

      try {
        const matchId = sessionStorage.getItem('tabletennis_match_id');
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

// ── declareWinner (manual) ───────────────────────────────────────
function declareWinner() {
  if (!confirm('Declare winner and finalize this match? This will mark the match completed in the database. Continue?')) return;

  const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
  const currentSnap = {
    setNumber:  state.currentSet,
    teamAScore: state.teamA.score,
    teamBScore: state.teamB.score,
    teamATimeout: state.teamA.timeout,
    teamBTimeout: state.teamB.timeout,
    serving: state.serving,
    winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : null)
  };
  const allSets = completed.concat([currentSnap]);

  let aWins = 0, bWins = 0;
  allSets.forEach(s => {
    if (s && s.winner === 'A') aWins++;
    else if (s && s.winner === 'B') bWins++;
  });

  state.teamA.gamesWon = aWins; state.teamB.gamesWon = bWins;
  const ga = document.getElementById('gamesA'); if (ga) ga.textContent = aWins;
  const gb = document.getElementById('gamesB'); if (gb) gb.textContent = bWins;

  let msg;
  if (aWins === bWins) {
    msg = `The match is currently a tie (${aWins}-${bWins}).`;
  } else {
    const winnerName = aWins > bWins ? state.teamA.name : state.teamB.name;
    msg = `${winnerName} WINS THE MATCH! (${Math.max(aWins,bWins)}-${Math.min(aWins,bWins)})`;
  }

  document.getElementById('modalMsg').textContent = msg;
  document.getElementById('modal').classList.add('show');
  saveLocalState();

  try {
    const matchId = sessionStorage.getItem('tabletennis_match_id');
    if (matchId) {
      const payload = {
        match_id: parseInt(matchId,10),
        total_sets_played: allSets.length,
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
  } catch (e) { console.error(e); }
}

function closeModal() { document.getElementById('modal').classList.remove('show'); }

// ── buildSavePayload (includes team_a_games_won / team_b_games_won) ─
function buildSavePayload() {
  syncAllPlayerInputs();
  const matchIdRaw = sessionStorage.getItem('tabletennis_match_id');
  const match_id   = matchIdRaw ? parseInt(matchIdRaw,10) : null;

  function intFrom(id){ const el=document.getElementById(id); if(!el) return 0; const n=parseInt(String(el.textContent||el.value||'').replace(/\D/g,''),10); return isNaN(n)?0:n; }

  const completed    = Array.isArray(setHistory) ? setHistory.slice() : [];
  // transform camelCase history entries into snake_case expected by server
  const transformed = completed.map(s => ({
    set_number: (s.setNumber != null) ? parseInt(s.setNumber,10) : 1,
    team_a_score: (s.teamAScore != null) ? parseInt(s.teamAScore,10) : ((s.team_a_score != null) ? parseInt(s.team_a_score,10) : 0),
    team_b_score: (s.teamBScore != null) ? parseInt(s.teamBScore,10) : ((s.team_b_score != null) ? parseInt(s.team_b_score,10) : 0),
    team_a_timeout_used: (s.teamATimeout != null) ? parseInt(s.teamATimeout,10) : ((s.team_a_timeout_used != null) ? parseInt(s.team_a_timeout_used,10) : 0),
    team_b_timeout_used: (s.teamBTimeout != null) ? parseInt(s.teamBTimeout,10) : ((s.team_b_timeout_used != null) ? parseInt(s.team_b_timeout_used,10) : 0),
    serving_team: (s.serving || s.serving_team) === 'B' ? 'B' : 'A',
    set_winner: (s.winner || s.set_winner) ? String(s.winner || s.set_winner) : null
  }));
  const committee    = document.getElementById('ttCommitteeInput') ? document.getElementById('ttCommitteeInput').value.trim() : null;
  const currentSnap  = {
    set_number:           state.currentSet,
    team_a_score:         intFrom('scoreA'),
    team_b_score:         intFrom('scoreB'),
    team_a_timeout_used:  intFrom('timeoutA'),
    team_b_timeout_used:  intFrom('timeoutB'),
    serving_team:         state.serving,
    set_winner: (intFrom('scoreA') > intFrom('scoreB')) ? 'A' : (intFrom('scoreB') > intFrom('scoreA') ? 'B' : null)
  };
  // Build a deduped, ordered sets array so an empty current snapshot does not overwrite saved sets
  const byNum = {};
  let auto = 0;
  transformed.forEach(s => {
    let sn = (s.set_number != null) ? parseInt(s.set_number, 10) : 0;
    if (!sn) { auto++; sn = auto; }
    if (sn > auto) auto = sn;
    byNum[sn] = {
      set_number: sn,
      team_a_score: parseInt(s.team_a_score || 0, 10),
      team_b_score: parseInt(s.team_b_score || 0, 10),
      team_a_timeout_used: parseInt(s.team_a_timeout_used || 0, 10),
      team_b_timeout_used: parseInt(s.team_b_timeout_used || 0, 10),
      serving_team: (s.serving_team || 'A') === 'B' ? 'B' : 'A',
      set_winner: (s.set_winner || null)
    };
  });

  const currHasActivity = (currentSnap.team_a_score !== 0 || currentSnap.team_b_score !== 0 || currentSnap.team_a_timeout_used !== 0 || currentSnap.team_b_timeout_used !== 0 || currentSnap.set_winner !== null);
  if (currHasActivity || !byNum[currentSnap.set_number]) {
    byNum[currentSnap.set_number] = currentSnap;
  }
  const keys = Object.keys(byNum).map(k => parseInt(k,10)).sort((a,b)=>a-b);
  const setsArray = keys.map(k => byNum[k]);

  const payload = {
    match_id:           match_id,
    match_type:         state.matchType,
    best_of:            state.bestOf,
    team_a_name:        state.teamA.name,
    team_b_name:        state.teamB.name,
    team_a_player1:     pName('A', 0),
    team_a_player2:     pName('A', 1) || null,
    team_b_player1:     pName('B', 0),
    team_b_player2:     pName('B', 1) || null,
    committee:          committee,
    set_number:         state.currentSet,
    team_a_score:       currentSnap.team_a_score,
    team_b_score:       currentSnap.team_b_score,
    team_a_timeout_used: currentSnap.team_a_timeout_used,
    team_b_timeout_used: currentSnap.team_b_timeout_used,
    serving_team:       currentSnap.serving_team,
    set_winner:         currentSnap.set_winner,
    sets:               setsArray,
    team_a_games_won:   intFrom('gamesA'),
    team_b_games_won:   intFrom('gamesB')
  };
  return payload;
}

// ── resetMatch: clears DB, sessionStorage AND localStorage ───────
function _broadcastReset(matchId) {
  const resetPayload = { _reset: true, match_id: matchId || null };
  try { if (_ttBC) _ttBC.postMessage(resetPayload); } catch (_) {}
  try {
    if (_tt_ws && _tt_ws.readyState === 1) {
      _tt_ws.send(JSON.stringify({
        type: 'new_match',
        match_id: matchId || null,
        sport: 'tabletennis',
        payload: resetPayload
      }));
    }
  } catch (_) {}
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
}

function _applyRemoteReset() {
  try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
  try { sessionStorage.removeItem('tabletennis_match_id'); } catch (_) {}
  location.reload();
}

function resetMatch() {
  const matchIdRaw = sessionStorage.getItem('tabletennis_match_id');
  const matchId    = matchIdRaw ? parseInt(matchIdRaw,10) : null;
  if (!confirm('Reset this match? All saved set records will be deleted.')) return;
  if (!matchId) {
    _broadcastReset(null);
    sessionStorage.removeItem('tabletennis_match_id');
    try { localStorage.removeItem(ADMIN_STATE_KEY); } catch(_) {}
    try { localStorage.removeItem(STORAGE_KEY); } catch(_) {}
    location.reload();
    return;
  }
  fetch('reset_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: matchId }) })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) {
        _broadcastReset(matchId);
        sessionStorage.removeItem('tabletennis_match_id');
        try { localStorage.removeItem(ADMIN_STATE_KEY); } catch(_) {}
        try { localStorage.removeItem(STORAGE_KEY); } catch(_) {}
        alert('Match has been reset.');
        location.reload();
      } else {
        alert('Reset failed: ' + (j && j.message ? j.message : 'Unknown'));
      }
    }).catch(err => { console.error(err); alert('Reset request failed.'); });
}

// ── saveAndReport: persist → open tabletennis_report.php ─────────
function saveAndReport() {
  syncAllPlayerInputs();
  const payload = buildSavePayload();
  const committee = document.getElementById('ttCommitteeInput') ? document.getElementById('ttCommitteeInput').value.trim() : '';
  if (committee) payload.committee_official = committee;   // badminton-style key for PHP

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
      sessionStorage.setItem('tabletennis_match_id', matchId);

      // Determine set wins locally and declare only when a team reaches required wins
      let aWins = 0, bWins = 0;
      try {
        const sets = Array.isArray(payload.sets) ? payload.sets : [];
        sets.forEach(s => {
          const w = (s.set_winner || s.winner) ? String(s.set_winner || s.winner) : null;
          if (w === 'A') aWins++; else if (w === 'B') bWins++;
        });
      } catch (e) {
        aWins = payload.team_a_games_won || 0;
        bWins = payload.team_b_games_won || 0;
      }

      const bestOf = parseInt(payload.best_of || state.bestOf || 3, 10);
      const requiredWins = Math.ceil(bestOf / 2);
      const shouldDeclare = (aWins >= requiredWins) || (bWins >= requiredWins);

      const tryDeclare = shouldDeclare ? fetch('declare_winner.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ match_id: parseInt(matchId,10), total_sets_played: (payload.sets||[]).length, team_a_sets_won: aWins, team_b_sets_won: bWins, winner_team: aWins>bWins?'A':'B', winner_name: aWins>bWins?state.teamA.name:state.teamB.name })
      }).then(rr => rr.json()).catch(() => null) : Promise.resolve(null);

      tryDeclare.finally(() => {
        try { localStorage.removeItem(ADMIN_STATE_KEY); } catch (_) {}
          try { sessionStorage.setItem('tabletennis_match_id', matchId); } catch (_) {}
          const committeeParam = committee ? ('&committee=' + encodeURIComponent(committee)) : '';
          window.location.href = 'tabletennis_report.php?match_id=' + matchId + committeeParam;
      });
    } else {
      alert('Save failed: ' + (j && j.message ? j.message : 'Unknown error'));
    }
  })
  .catch(err => { console.error(err); alert('Save request failed. Check your connection.'); })
  .finally(() => { if (btn) { btn.textContent = '📊 SAVE & REPORT'; btn.disabled = false; } });
}

// ── saveFile (local HTML export fallback) ────────────────────────
function saveFile() {
  syncAllPlayerInputs();
  const teamA = (state.teamA.name || 'TeamA').replace(/[^a-z0-9 _-]/gi, '').trim();
  const teamB = (state.teamB.name || 'TeamB').replace(/[^a-z0-9 _-]/gi, '').trim();
  const fname = 'tabletennis_report_' + teamA.replace(/\s+/g,'_') + '_vs_' + teamB.replace(/\s+/g,'_') + '_Set' + state.currentSet + '.html';
  const exportedAt = (new Date()).toLocaleString();

  const completed    = Array.isArray(setHistory) ? setHistory.slice() : [];
  const currentSnap  = { setNumber: state.currentSet, teamAScore: state.teamA.score, teamBScore: state.teamB.score, teamATimeout: state.teamA.timeout, teamBTimeout: state.teamB.timeout, serving: state.serving, winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : 'TBD') };
  const setsAll      = completed.concat([currentSnap]);
  const committee    = document.getElementById('ttCommitteeInput') ? document.getElementById('ttCommitteeInput').value.trim() : '';

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function intFrom(id){ const el=document.getElementById(id); if(!el) return 0; const n=parseInt(String(el.textContent||el.value||'').replace(/\D/g,''),10); return isNaN(n)?0:n; }

  const matchWinner = (state.teamA.gamesWon === state.teamB.gamesWon) ? 'TBD' : (state.teamA.gamesWon > state.teamB.gamesWon ? esc(state.teamA.name) : esc(state.teamB.name));

  let setsRows = '';
  let teamASetStr = '', teamBSetStr = '', teamATimeoutStr = '', teamBTimeoutStr = '';
  for (let i=0;i<setsAll.length;i++){
    const s = setsAll[i];
    const winner = s && s.winner === 'A' ? esc(state.teamA.name) : (s && s.winner === 'B' ? esc(state.teamB.name) : 'TBD');
    setsRows += '<tr><td>'+esc(s.setNumber)+'</td><td class="teamA num">'+esc(s.teamAScore)+'</td><td class="teamB num">'+esc(s.teamBScore)+'</td><td class="winner">'+winner+'</td></tr>';
    teamASetStr     += 'Set'+esc(s.setNumber)+': '+esc(s.teamAScore)     + (i<setsAll.length-1?' | ':'');
    teamBSetStr     += 'Set'+esc(s.setNumber)+': '+esc(s.teamBScore)     + (i<setsAll.length-1?' | ':'');
    teamATimeoutStr += 'Set'+esc(s.setNumber)+': '+(s.teamATimeout||0)  + (i<setsAll.length-1?' | ':'');
    teamBTimeoutStr += 'Set'+esc(s.setNumber)+': '+(s.teamBTimeout||0)  + (i<setsAll.length-1?' | ':'');
  }

  const playersA = [], playersB = [];
  if (state.matchType==='singles'){
    playersA.push({no:1,name:pName('A',0)||state.teamA.name});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name});
  } else if (state.matchType==='doubles'){
    playersA.push({no:1,name:pName('A',0)||state.teamA.name+' P1'}); playersA.push({no:2,name:pName('A',1)||state.teamA.name+' P2'});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name+' P1'}); playersB.push({no:2,name:pName('B',1)||state.teamB.name+' P2'});
  } else {
    playersA.push({no:1,name:pName('A',0)||state.teamA.name+' M'}); playersA.push({no:2,name:pName('A',1)||state.teamA.name+' F'});
    playersB.push({no:1,name:pName('B',0)||state.teamB.name+' M'}); playersB.push({no:2,name:pName('B',1)||state.teamB.name+' F'});
  }

  const css = 'body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#111}.container{max-width:900px;margin:0 auto;background:#fff;padding:18px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.06)}table{width:100%;border-collapse:collapse;margin-bottom:8px}th,td{border:1px solid #e6e6e6;padding:10px}th{background:#f0f6fb;color:#003366;font-weight:700;text-align:center}td.num{text-align:center}.teamA{background:#fff5f5}.teamB{background:#f5fbff}.winner{background:#e6f7e6;color:#006400;font-weight:700;text-align:center}.result-badge{background:#e6f7e6;color:#006400;padding:6px 10px;border-radius:4px;font-weight:700;display:inline-block;margin-left:8px}@media print{.report-toolbar{display:none}}';

  let html = '<!doctype html><html><head><meta charset="utf-8"><title>TABLE TENNIS — MATCH REPORT</title><style>'+css+'</style></head><body><div class="container">';
  html += '<h1 style="margin:0;color:#062a78;font-size:28px;letter-spacing:1px">SPORTSSYNC - TABLE TENNIS RESULT</h1>';
  html += '<div style="margin-top:8px;color:#333;font-size:14px">Date: '+esc(exportedAt)+'</div>';
  html += '<div style="margin-top:6px;color:#333;font-size:14px"><strong>Committee/Official:</strong> '+esc(committee)+'</div>';
  html += '<hr style="border:none;border-top:1px solid #ddd;margin:12px 0">';
  html += '<div class="report-toolbar" style="margin-bottom:12px;display:flex;gap:8px;justify-content:flex-end">';
  html += '<button id="dlHtml">Download HTML</button><button id="dlXls">Download Excel</button><button id="expPdf">Print PDF</button></div>';
  html += '<section><h2>Sets Summary</h2><table><thead><tr><th>Set</th><th>'+esc(state.teamA.name)+'</th><th>'+esc(state.teamB.name)+'</th><th>Winner</th></tr></thead><tbody>'+setsRows+'</tbody></table></section>';
  if (matchWinner && matchWinner !== 'TBD') html += '<div style="margin-bottom:12px"><strong>Result:</strong><span class="result-badge">'+matchWinner+'</span></div>';
  else html += '<div style="margin-bottom:12px"><strong>Result:</strong><span style="margin-left:8px;color:#666">TBD</span></div>';

  function teamBlock(players, teamState, timeoutStr, setStr) {
    let rows = '';
    players.forEach(function(pl,i){ rows += '<tr style="background:'+(i%2===0?'#fff':'#f6f6f6')+'"><td>'+esc(pl.no)+'</td><td>'+esc(pl.name)+'</td><td style="text-align:center">'+esc(teamState.score)+'</td><td style="text-align:center">'+timeoutStr+'</td><td style="text-align:center">'+esc(teamState.gamesWon)+'</td><td>'+setStr+'</td></tr>'; });
    return '<div style="background:#FFE600;padding:14px;border-radius:6px;margin-bottom:12px"><div style="font-weight:800;margin-bottom:6px;color:#062a78">'+esc(teamState.name)+'</div><table><thead><tr style="background:#333;color:#fff"><th>#</th><th>Name</th><th>Game Points</th><th>Timeouts Used</th><th>Games Won</th><th>Set Scores</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
  }
  html += teamBlock(playersA, state.teamA, teamATimeoutStr, teamASetStr);
  html += teamBlock(playersB, state.teamB, teamBTimeoutStr, teamBSetStr);
  html += '<div style="font-size:13px;color:#666">Generated by Sportssync</div></div>';

  const excelName = fname.replace(/\.html$/i, '.xls');
  html += '<script>(function(){function downloadHTML(){const h=document.documentElement.outerHTML;const blob=new Blob([h],{type:"text/html"});const a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="'+fname+'";document.body.appendChild(a);a.click();a.remove();}function downloadExcel(){try{const cont=document.querySelector(".container");const excelHtml="<html><head><meta charset=\'utf-8\'></head><body>"+(cont?cont.outerHTML:"")+("</body></html>");const blob=new Blob([excelHtml],{type:"application/vnd.ms-excel"});const a=document.createElement("a");a.href=URL.createObjectURL(blob);a.download="'+excelName+'";document.body.appendChild(a);a.click();a.remove();}catch(e){alert("Export failed");}}function exportPDF(){window.print();}document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById("dlHtml");if(d)d.addEventListener("click",downloadHTML);var x=document.getElementById("dlXls");if(x)x.addEventListener("click",downloadExcel);var p=document.getElementById("expPdf");if(p)p.addEventListener("click",exportPDF);});})();<\/script></body></html>';

  const blob = new Blob([html], { type: 'text/html' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = fname; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(a.href);

  try {
    const payload = buildSavePayload();
    if (committee) payload.committee_official = committee;
    fetch('save_set.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(r => r.json()).then(j => { if (j && j.success) { sessionStorage.setItem('tabletennis_match_id', j.match_id); alert('Set ' + payload.set_number + ' saved successfully.'); } else { alert('Save failed: ' + (j && j.message ? j.message : 'Unknown')); } })
      .catch(err => { console.error(err); alert('Save request failed.'); });
  } catch (e) { console.error('saveFile persist error', e); }
}

// ── downloadExcelSingleSheet (unchanged from original) ───────────
async function downloadExcelSingleSheet() {
  try {
    syncAllPlayerInputs();
    const payload = buildSavePayload();
    const MATCH_DATA = {
      match_id:       payload.match_id || 'local',
      saved_at:       new Date().toISOString(),
      committee:      payload.committee || '',
      match_type:     payload.match_type || state.matchType,
      best_of:        payload.best_of || state.bestOf,
      team_a_name:    payload.team_a_name || state.teamA.name,
      team_b_name:    payload.team_b_name || state.teamB.name,
      team_a_sets_won: payload.team_a_games_won || state.teamA.gamesWon || 0,
      team_b_sets_won: payload.team_b_games_won || state.teamB.gamesWon || 0,
      overall_winner:  (state.teamA.gamesWon > state.teamB.gamesWon ? state.teamA.name : (state.teamB.gamesWon > state.teamA.gamesWon ? state.teamB.name : 'TBD')),
      match_status:    'In Progress',
      players_a: [], players_b: [],
      sets: payload.sets || []
    };

    if (payload.team_a_player1) MATCH_DATA.players_a.push({ no:1, name:payload.team_a_player1, role: MATCH_DATA.match_type==='singles'?'Singles':'Player 1' });
    if (payload.team_a_player2) MATCH_DATA.players_a.push({ no:2, name:payload.team_a_player2, role:'Player 2' });
    if (payload.team_b_player1) MATCH_DATA.players_b.push({ no:1, name:payload.team_b_player1, role: MATCH_DATA.match_type==='singles'?'Singles':'Player 1' });
    if (payload.team_b_player2) MATCH_DATA.players_b.push({ no:2, name:payload.team_b_player2, role:'Player 2' });
    if (!MATCH_DATA.players_a.length) normalizeTeamPlayers('A').forEach((name,i)=> MATCH_DATA.players_a.push({ no:i+1, name:name||'—', role:getPlayerRole(state.matchType, i) }));
    if (!MATCH_DATA.players_b.length) normalizeTeamPlayers('B').forEach((name,i)=> MATCH_DATA.players_b.push({ no:i+1, name:name||'—', role:getPlayerRole(state.matchType, i) }));

    const rows = [];
    rows.push(['SPORTSSYNC — TABLE TENNIS MATCH REPORT']); rows.push([]);
    rows.push(['Field','Value']);
    rows.push(['Match ID', MATCH_DATA.match_id],['Date / Time', MATCH_DATA.saved_at],['Committee / Official', MATCH_DATA.committee||'—'],['Match Type', MATCH_DATA.match_type],['Best Of', MATCH_DATA.best_of],['Status', MATCH_DATA.match_status],['Team A', MATCH_DATA.team_a_name],['Team A Sets Won', MATCH_DATA.team_a_sets_won],['Team B', MATCH_DATA.team_b_name],['Team B Sets Won', MATCH_DATA.team_b_sets_won],['Overall Winner', MATCH_DATA.overall_winner||'TBD']);
    rows.push([]);
    rows.push(['Sets Breakdown']);
    rows.push(['Set #', MATCH_DATA.team_a_name+' Score', MATCH_DATA.team_b_name+' Score','Team A Timeout','Team B Timeout','Serving','Winner']);
    (MATCH_DATA.sets||[]).forEach(s => rows.push(['Set '+(s.set_number||s.setNumber||''), s.team_a_score||s.teamAScore||0, s.team_b_score||s.teamBScore||0, s.team_a_timeout_used?'Yes':'No', s.team_b_timeout_used?'Yes':'No', (s.serving_team||s.serving)==='A'?MATCH_DATA.team_a_name:MATCH_DATA.team_b_name, (s.set_winner||s.winner)==='A'?MATCH_DATA.team_a_name:((s.set_winner||s.winner)==='B'?MATCH_DATA.team_b_name:'TBD')]));
    rows.push([]);
    rows.push(['Players']); rows.push(['Team','#','Name','Role','Game Points','Timeouts Used','Sets Won','Set Scores']);
    const setStrA = (MATCH_DATA.sets||[]).map(s=>'Set'+(s.set_number||s.setNumber||'')+': '+(s.team_a_score||s.teamAScore||0)).join(' | ');
    const setStrB = (MATCH_DATA.sets||[]).map(s=>'Set'+(s.set_number||s.setNumber||'')+': '+(s.team_b_score||s.teamBScore||0)).join(' | ');
    (MATCH_DATA.players_a||[]).forEach((p,i)=> rows.push([MATCH_DATA.team_a_name, p.no||i+1, p.name||'—', p.role||'', state.teamA.score, 0, MATCH_DATA.team_a_sets_won, setStrA||'—']));
    (MATCH_DATA.players_b||[]).forEach((p,i)=> rows.push([MATCH_DATA.team_b_name, p.no||i+1, p.name||'—', p.role||'', state.teamB.score, 0, MATCH_DATA.team_b_sets_won, setStrB||'—']));
    rows.push([]); rows.push(['Game Result', MATCH_DATA.overall_winner||'TBD']);

    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:22},{wch:8},{wch:28},{wch:14},{wch:12},{wch:14},{wch:12},{wch:32}];
    XLSX.utils.book_append_sheet(wb, ws, 'Match Report');
    const filename = 'tabletennis_report_'+(MATCH_DATA.match_id||'local')+'.xlsx';
    const wbout = XLSX.write(wb, { bookType:'xlsx', type:'binary' });
    function s2ab(s){ const buf=new ArrayBuffer(s.length); const view=new Uint8Array(buf); for(let i=0;i<s.length;++i) view[i]=s.charCodeAt(i)&0xFF; return buf; }
    const blob = new Blob([s2ab(wbout)], { type:'application/octet-stream' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  } catch (err) { console.error('downloadExcelSingleSheet error', err); alert('Export failed: '+(err&&err.message)); }
}

// ── Manual winner helpers ────────────────────────────────────────
function updateAdminWinnerButtons() {
  try {
    const setNum = String(state.currentSet || 1);
    const manual = state.manualWinners || {};
    const winner = manual[setNum] ? String(manual[setNum]).toUpperCase() : null;
    const btnA = document.getElementById('adminMarkWinnerA');
    const btnB = document.getElementById('adminMarkWinnerB');
    if (btnA) btnA.classList.toggle('active', winner === 'A');
    if (btnB) btnB.classList.toggle('active', winner === 'B');
    const hdrA = document.getElementById('headerA');
    const hdrB = document.getElementById('headerB');
    if (hdrA) hdrA.classList.toggle('manual-winner', winner === 'A');
    if (hdrB) hdrB.classList.toggle('manual-winner', winner === 'B');
  } catch (e) {}
}

function toggleManualWinner(team) {
  try {
    const setNum = String(state.currentSet || 1);
    state.manualWinners = state.manualWinners || {};
    if (state.manualWinners[setNum] === team) delete state.manualWinners[setNum];
    else state.manualWinners[setNum] = team;
    updateAdminWinnerButtons();
    saveLocalState();
  } catch (e) { console.error('toggleManualWinner error', e); }
}

function attachAdminWinnerHandlers() {
  try {
    const btnA = document.getElementById('adminMarkWinnerA');
    const btnB = document.getElementById('adminMarkWinnerB');
    if (btnA && !btnA.dataset._bind && !btnA.getAttribute('onclick')) { btnA.addEventListener('click', function(){ toggleManualWinner('A'); }); btnA.dataset._bind = '1'; }
    if (btnB && !btnB.dataset._bind && !btnB.getAttribute('onclick')) { btnB.addEventListener('click', function(){ toggleManualWinner('B'); }); btnB.dataset._bind = '1'; }
    updateAdminWinnerButtons();
  } catch (e) {}
}
try { window.toggleManualWinner = toggleManualWinner; } catch(_) {}
attachAdminWinnerHandlers();
document.addEventListener('DOMContentLoaded', attachAdminWinnerHandlers);

// ================================================================
// PERSISTENCE LAYER — restore state or apply fresh defaults
// ================================================================
const _wasRestored = loadPersistedState();
if (!_wasRestored) {
  setMatchType('singles');
}
_ttPrevSetsLength = Array.isArray(setHistory) ? setHistory.length : 0;
updateLabels();

// Always broadcast on load (re-sync open viewer tabs)
saveLocalState();

// Committee input live sync
const committeeEl = document.getElementById('ttCommitteeInput');
if (committeeEl && !committeeEl.dataset._live) {
  committeeEl.addEventListener('input', saveLocalState);
  committeeEl.addEventListener('blur',  saveLocalState);
  committeeEl.dataset._live = '1';
}

// Swap button debounce (onclick already toggles state; we just persist after)
document.querySelectorAll('.btn-swap').forEach(btn => {
  if (btn && !btn.dataset._live) {
    btn.addEventListener('click', () => { setTimeout(saveLocalState, 50); });
    btn.dataset._live = '1';
  }
});

// ── BroadcastChannel wrapper — push every saveLocalState write to viewer tabs ─
const _TT_CHANNEL_NAME = 'tt_live';
let _ttBC = null;
try { _ttBC = new BroadcastChannel(_TT_CHANNEL_NAME); } catch(_) {}

try {
  if (_ttBC) {
    _ttBC.addEventListener('message', function(e) {
      if (e.data && e.data._reset === true) _applyRemoteReset();
    });
  }
} catch (_) {}

const _origSaveLocalState = saveLocalState;
saveLocalState = function () {
  _origSaveLocalState();
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw && _ttBC) _ttBC.postMessage(JSON.parse(raw));
  } catch (_) {}
};

// ================================================================
// ✅ SSOT NEW MATCH — openNewMatchModal / confirmNewMatch
//
// Flow (strict SSOT):
//   1. Admin fills in the modal and clicks "Start Match"
//   2. confirmNewMatch() POSTs to save_set.php (no match_id = new DB row)
//   3. On success:
//      a. Clear all client state (localStorage, sessionStorage, state obj)
//      b. Write new match_id to sessionStorage
//      c. _broadcastNewMatch(newMatchId, freshState):
//         → POST state.php          (DB SSOT updated)
//         → WS new_match broadcast  (remote viewers update instantly)
//         → BroadcastChannel        (same-browser tabs update instantly)
//         → localStorage write      (storage-event tabs update instantly)
//      d. Re-render admin UI
// ================================================================

let _nmTTBestOf = 3;
let _nmTTType   = 'singles';

function openNewMatchModal() {
  _nmTTBestOf = 3;
  _nmTTType   = 'singles';
  const ta = document.getElementById('nmTTTeamA'); if (ta) ta.value = '';
  const tb = document.getElementById('nmTTTeamB'); if (tb) tb.value = '';
  const co = document.getElementById('nmTTCommittee'); if (co) co.value = '';
  const bo = document.getElementById('nmTTBestOf');    if (bo) bo.textContent = _nmTTBestOf;
  document.querySelectorAll('.nm-tt-type-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.type === _nmTTType);
  });
  _nmTTRenderPlayers();
  const modal = document.getElementById('newMatchModal');
  if (modal) modal.style.display = 'flex';
}

function closeNewMatchModal() {
  const modal = document.getElementById('newMatchModal');
  if (modal) modal.style.display = 'none';
}

function nmTTSetType(type) {
  _nmTTType = normalizeMatchType(type);
  document.querySelectorAll('.nm-tt-type-btn').forEach(function(b) {
    b.classList.toggle('active', b.dataset.type === _nmTTType);
  });
  _nmTTRenderPlayers();
}

function nmTTChangeBestOf(d) {
  _nmTTBestOf = Math.max(1, Math.min(5, _nmTTBestOf + (d > 0 ? 2 : -2)));
  const bo = document.getElementById('nmTTBestOf'); if (bo) bo.textContent = _nmTTBestOf;
}

function _nmTTRenderPlayers() {
  const count = _nmTTType === 'singles' ? 1 : 2;
  ['A','B'].forEach(function(team) {
    const container = document.getElementById('nmTTPlayers' + team);
    if (!container) return;
    container.innerHTML = '';
    for (let i = 0; i < count; i++) {
      const label = document.createElement('label');
      label.style.cssText = 'font-size:11px;color:#aaa;margin-bottom:2px;display:block';
      if (_nmTTType === 'mixed')        label.textContent = (i === 0 ? 'Male Player' : 'Female Player');
      else if (_nmTTType === 'singles') label.textContent = 'Player Name';
      else                              label.textContent = 'Player ' + (i + 1);
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'nm-tt-player-input';
      input.placeholder = label.textContent;
      input.dataset.team  = team;
      input.dataset.index = i;
      container.appendChild(label);
      container.appendChild(input);
    }
  });
}

// ── Broadcast a fully-fresh match state to ALL clients ──────────
function _broadcastNewMatchTT(matchId, freshViewerState) {
  // 1. DB SSOT — write to state.php (cross-device viewers polling will pick this up)
  try {
    fetch('state.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({}, freshViewerState, {
        match_id: String(matchId),
        _origin: _TT_ADMIN_TAB_ID
      }))
    }).catch(function() {});
  } catch (_) {}

  // 2. WebSocket relay — remote devices (other computers, phones, TVs)
  try {
    if (_tt_ws && _tt_ws.readyState === 1) {
      _tt_ws.send(JSON.stringify({
        type: 'new_match',
        match_id: String(matchId),
        sport: 'tabletennis',
        payload: Object.assign({}, freshViewerState, { match_id: String(matchId) })
      }));
    }
  } catch (_) {}

  // 3. BroadcastChannel — same-browser tabs in < 10 ms
  try {
    if (_ttBC) _ttBC.postMessage(Object.assign({}, freshViewerState, {
      match_id: String(matchId),
      _newMatch: true  // tells viewer.js to wipe stale state
    }));
  } catch (_) {}

  // 4. localStorage — storage-event fallback for other tabs
  try {
    const stamped = Object.assign({}, freshViewerState, {
      match_id: String(matchId),
      _savedAt: new Date().toISOString()
    });
    localStorage.setItem(STORAGE_KEY, JSON.stringify(stamped));
  } catch (_) {}
}

function confirmNewMatch() {
  const btn = document.getElementById('nmTTConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Creating…'; }

  const teamAName = (document.getElementById('nmTTTeamA').value.trim() || 'TEAM A').toUpperCase();
  const teamBName = (document.getElementById('nmTTTeamB').value.trim() || 'TEAM B').toUpperCase();
  const committee = document.getElementById('nmTTCommittee').value.trim();

  function getPlayers(team) {
    const inputs = document.querySelectorAll('#nmTTPlayers' + team + ' input');
    return Array.from(inputs).map(function(i) { return i.value.trim(); });
  }
  const playersA = getPlayers('A');
  const playersB = getPlayers('B');

  // No match_id → save_set.php creates a new DB row
  const createPayload = {
    match_type:         _nmTTType,
    best_of:            _nmTTBestOf,
    team_a_name:        teamAName,
    team_b_name:        teamBName,
    team_a_player1:     playersA[0] || null,
    team_a_player2:     playersA[1] || null,
    team_b_player1:     playersB[0] || null,
    team_b_player2:     playersB[1] || null,
    committee:          committee || null,
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
    try { sessionStorage.removeItem('tabletennis_match_id'); } catch (_) {}

    // ── Step 2: Reset in-memory state to fresh defaults ──────────
    state.matchType     = normalizeMatchType(_nmTTType);
    state.serving       = 'A';
    state.swapped       = false;
    state.bestOf        = _nmTTBestOf;
    state.currentSet    = 1;
    state.manualWinners = {};
    state.teamA = { name: teamAName, players: normalizePlayers(playersA, state.matchType), score: 0, gamesWon: 0, timeout: 0 };
    state.teamB = { name: teamBName, players: normalizePlayers(playersB, state.matchType), score: 0, gamesWon: 0, timeout: 0 };
    setHistory = [];

    // ── Step 3: Write new match_id to sessionStorage ─────────────
    sessionStorage.setItem('tabletennis_match_id', newMatchId);

    // ── Step 4: Build canonical fresh viewer state ────────────────
    const freshViewerState = {
      match_id:      newMatchId,
      matchType:     state.matchType,
      serving:       'A',
      swapped:       false,
      bestOf:        state.bestOf,
      currentSet:    1,
      committee:     committee,
      manualWinners: {},
      teamA: { name: teamAName, score: 0, gamesWon: 0, timeout: 0, players: normalizePlayers(playersA, state.matchType) },
      teamB: { name: teamBName, score: 0, gamesWon: 0, timeout: 0, players: normalizePlayers(playersB, state.matchType) },
      sets: []
    };

    // ── Step 5: Broadcast to DB + WS + BroadcastChannel + localStorage ──
    _broadcastNewMatchTT(newMatchId, freshViewerState);

    // ── Step 6: Re-render admin UI ────────────────────────────────
    ['scoreA','scoreB','gamesA','gamesB','timeoutA','timeoutB'].forEach(function(id) {
      const el = document.getElementById(id); if (el) el.textContent = 0;
    });
    const boEl = document.getElementById('bestOfBox');    if (boEl) boEl.textContent = state.bestOf;
    const csEl = document.getElementById('currentSetBox'); if (csEl) csEl.textContent = 1;
    const spanA = document.getElementById('teamAName');   if (spanA) spanA.textContent = teamAName;
    const spanB = document.getElementById('teamBName');   if (spanB) spanB.textContent = teamBName;

    const comEl = document.getElementById('ttCommitteeInput'); if (comEl) comEl.value = committee;

    const area  = document.getElementById('mainArea');
    const toRow = document.getElementById('timeoutRow');
    if (area)  area.style.gridTemplateAreas = '"left center right"';
    if (toRow) toRow.style.flexDirection    = 'row';

    setMatchType(state.matchType, { skipSave: true, skipSync: true });
    ['A','B'].forEach(function(team) {
      const container = getPlayerContainer(team);
      if (!container) return;
      const inputs = container.querySelectorAll('input');
      const src = team === 'A' ? playersA : playersB;
      inputs.forEach(function(inp, idx) { inp.value = src[idx] || ''; });
    });

    updateLabels();
    try { updateAdminWinnerButtons(); } catch (_) {}
    saveAdminState();

    // ── Step 7: Close modal and show toast ───────────────────────
    closeNewMatchModal();
    if (btn) { btn.disabled = false; btn.textContent = '✅ Start Match'; }

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