// ============================================================
// tabletennis_viewer.js — Read-only live viewer
// Aligned with badminton_viewer.js pattern:
//   - Reads from STORAGE_KEY 'tabletennisMatchState' (matches saveLocalState)
//   - BroadcastChannel 'tt_live' for instant same-browser tab sync
//   - localStorage 'storage' event for cross-tab updates
//   - WebSocket relay for cross-device updates
//   - render() handles both the new saveLocalState payload shape
//     (teamA.name, teamA.score, etc.) and legacy flat shape
// ============================================================

const STORAGE_KEY   = 'tabletennisMatchState';
const CHANNEL_NAME  = 'tt_live';
let _ttShownPopupKeys = {};

function makeTTPopupKey(type, payload) {
  if (!type || !payload) return null;
  const matchId = payload.match_id || payload.matchId || '';
  if (type === 'set') {
    return 'set:' + matchId + ':' + payload.setNumber + ':' + payload.winner + ':' + payload.scoreA + ':' + payload.scoreB;
  }
  if (type === 'match') {
    return 'match:' + matchId + ':' + payload.winner + ':' + payload.gamesA + ':' + payload.gamesB;
  }
  return null;
}

function getLatestSetWinnerPayload(viewerState) {
  if (!viewerState || !Array.isArray(viewerState.sets) || !viewerState.sets.length) return null;
  const last = viewerState.sets[viewerState.sets.length - 1];
  const winner = last && (last.winner || last.set_winner || last.setWinner) ? (last.winner || last.set_winner || last.setWinner) : null;
  if (!winner) return null;
  const setNumber = last.setNumber || last.set_number || viewerState.sets.length;
  const scoreA = last.teamAScore != null ? last.teamAScore : (last.team_a_score != null ? last.team_a_score : 0);
  const scoreB = last.teamBScore != null ? last.teamBScore : (last.team_b_score != null ? last.team_b_score : 0);
  const winnerName = winner === 'B' ? (viewerState.teamB && viewerState.teamB.name ? viewerState.teamB.name : 'TEAM B') : (viewerState.teamA && viewerState.teamA.name ? viewerState.teamA.name : 'TEAM A');
  return { match_id: viewerState.match_id || viewerState.matchId || '', setNumber: Number(setNumber), winner: String(winner).toUpperCase(), winnerName: String(winnerName), scoreA: Number(scoreA), scoreB: Number(scoreB) };
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
  return { match_id: viewerState.match_id || viewerState.matchId || '', winner: winner, winnerName: String(winnerName || (winner === 'B' ? 'TEAM B' : 'TEAM A')), gamesA: gamesA, gamesB: gamesB };
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

function maybeShowViewerWinnerPopup(viewerState) {
  if (!viewerState || typeof viewerState !== 'object') return;
  const setPayload = viewerState._latestSetWinner || getLatestSetWinnerPayload(viewerState);
  if (setPayload) {
    const key = makeTTPopupKey('set', setPayload);
    if (key && !_ttShownPopupKeys[key]) {
      _ttShownPopupKeys[key] = true;
      showWinnerModal('🏆 SET WINNER', `${setPayload.winnerName} wins Set ${setPayload.setNumber}! Final score ${setPayload.scoreA} — ${setPayload.scoreB}.`);
      if (setPayload.winner === 'A') flashEl('panelA'); else flashEl('panelB');
    }
  }
  const matchPayload = viewerState._latestMatchWinner || getMatchWinnerPayload(viewerState);
  if (matchPayload) {
    const key = makeTTPopupKey('match', matchPayload);
    if (key && !_ttShownPopupKeys[key]) {
      _ttShownPopupKeys[key] = true;
      showWinnerModal('🏁 MATCH WINNER', `${matchPayload.winnerName} wins the match! Final games ${matchPayload.gamesA} — ${matchPayload.gamesB}.`);
      if (matchPayload.winner === 'A') flashEl('panelA'); else flashEl('panelB');
    }
  }
}

function _buildViewerResetState(payload) {
  payload = payload || {};
  return {
    _resetApplied: true,
    match_id: payload.match_id || null,
    matchType: 'singles',
    serving: 'A',
    swapped: false,
    bestOf: 3,
    currentSet: 1,
    committee: '',
    teamA: { name: 'TEAM A', score: 0, gamesWon: 0, timeout: 0, players: [''] },
    teamB: { name: 'TEAM B', score: 0, gamesWon: 0, timeout: 0, players: [''] },
    sets: [],
    manualWinners: {}
  };
}

function _applyViewerReset(payload) {
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
  try { sessionStorage.removeItem('tabletennis_match_id'); } catch (_) {}
  render(_buildViewerResetState(payload));
}

function _resetIfLocalStateWasActive() {
  try {
    if (localStorage.getItem(STORAGE_KEY)) _applyViewerReset();
  } catch (_) {}
}

let _wsLive = false;
let _pollTimer = null;
const POLL_INTERVAL_MS = 500;

// ── BroadcastChannel listener (instant, same browser) ───────────
let bc = null;
try {
  bc = new BroadcastChannel(CHANNEL_NAME);
  bc.onmessage = function(e) {
    if (e.data && typeof e.data === 'object' && e.data._reset === true) {
      _applyViewerReset(e.data);
      return;
    }
    // ✅ SSOT NEW MATCH — Patch 7: fresh match broadcast via BroadcastChannel (same browser)
    if (e.data && typeof e.data === 'object' && e.data._newMatch === true && e.data.match_id) {
      try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
      try { sessionStorage.setItem('tabletennis_match_id', String(e.data.match_id)); } catch (_) {}
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.assign({}, e.data, { _savedAt: new Date().toISOString() }))); } catch (_) {}
      render(e.data);
      return;
    }
    // ✅ SSOT NEW MATCH — end
    if (e.data && typeof e.data === 'object') render(e.data);
  };
} catch (_) {}

// ── WebSocket relay ──────────────────────────────────────────────
(function initWS() {
  try {
    const scheme = (location.protocol === 'https:') ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    const _ws = new WebSocket(url);
    _ws.addEventListener('open', function () {
      _wsLive = true;
      try {
        const mid = (window.MATCH_DATA && MATCH_DATA.match_id) ? MATCH_DATA.match_id : (window.__matchId || null);
        if (mid) _ws.send(JSON.stringify({ type: 'join', match_id: String(mid) }));
      } catch(_) {}
    });
    _ws.addEventListener('message', function (ev) {
      try {
        const m = JSON.parse(ev.data);
        if (m) {
          if (m.type === 'last_state' && m.payload) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(m.payload)); } catch(_) {}
            render(m.payload);
          } else if ((m.type === 'tabletennis_state' || m.type === 'state') && m.payload) {
            render(m.payload);
          } else if (m.type === 'applied_action' && m.payload) {
            // applied_action may contain a payload describing a state change
            try { render(m.payload); } catch(_) {}
          } else if (m.type === 'new_match') {
            if (m.payload && m.payload._reset === true) {
              _applyViewerReset(m.payload);
              return;
            }
            // ✅ SSOT NEW MATCH — Patch 8
            // When admin creates a new match, a new_match message arrives with
            // a full freshViewerState payload (has match_id but no _reset flag).
            // Apply it immediately so viewers see the new blank slate in real-time.
            const newMid = m.match_id || (m.payload && m.payload.match_id) || null;
            if (newMid && m.payload && typeof m.payload === 'object' && !m.payload._reset) {
              try {
                // Wipe stale localStorage so no previous match data bleeds in
                try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
                try { sessionStorage.setItem('tabletennis_match_id', String(newMid)); } catch (_) {}
                const freshState = Object.assign({}, m.payload, { match_id: String(newMid) });
                try { localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.assign({}, freshState, { _savedAt: new Date().toISOString() }))); } catch (_) {}
                render(freshState);
              } catch (_) {}
              return;
            }
            // ✅ SSOT NEW MATCH — end
            // Fallback: payload has no state — fetch canonical state from server
            if (newMid) {
              try { sessionStorage.setItem('tabletennis_match_id', String(newMid)); } catch (_) {}
              fetch('state.php?match_id=' + encodeURIComponent(newMid)).then(function(res){ return res.text(); }).then(function(txt){ try { const obj = txt ? JSON.parse(txt) : null; if (obj) { try { localStorage.setItem(STORAGE_KEY, JSON.stringify(obj)); } catch(_) {} render(obj); } } catch(_){} }).catch(function(){});
            }
          }
        }
      } catch (_) {}
    });
    _ws.addEventListener('close',  function () { _wsLive = false; setTimeout(initWS, 2000); });
    _ws.addEventListener('error',  function () {});
  } catch (_) {}
})();

// ── localStorage 'storage' event (cross-tab) ────────────────────
window.addEventListener('storage', function(e) {
  if (e.key !== STORAGE_KEY) return;
  if (e.newValue === null) { _applyViewerReset(); return; }
  try {
    const state = e.newValue ? JSON.parse(e.newValue) : null;
    if (state && state._reset === true) { _applyViewerReset(state); return; }
    if (state) render(state);
  } catch (_) {}
});

function _readStateFromDB() {
  try {
    let url = 'state.php';
    if (window.MATCH_DATA && MATCH_DATA.match_id) {
      url = 'state.php?match_id=' + encodeURIComponent(MATCH_DATA.match_id);
    } else if (window.__matchId) {
      url = 'state.php?match_id=' + encodeURIComponent(window.__matchId);
    }

    fetch(url)
      .then(function(res) { return res.ok ? res.text() : ''; })
      .then(function(txt) {
        let state = null;
        try { state = txt ? JSON.parse(txt) : null; } catch (_) { state = null; }
        if (state && state._reset === true) {
          _applyViewerReset(state);
          return;
        }
        if (state && typeof state === 'object' && Object.keys(state).length > 0) {
          // ✅ SSOT FIX START — Patch 6
          // DB (state.php) is the SSOT. Always apply and cache its state when:
          //   a) it is not a reset signal, AND
          //   b) its match_id differs from local (different match) → always apply
          //   c) same match_id: apply only if DB is fresher than local copy
          // Previously, having ANY non-empty localStorage caused the DB payload to be
          // cached but never rendered, leaving cross-device viewers stuck on stale state.
          try {
            const localRaw = localStorage.getItem(STORAGE_KEY);
            let shouldApply = true;
            if (localRaw) {
              try {
                const localParsed = JSON.parse(localRaw);
                const localMatch  = localParsed && localParsed.match_id ? String(localParsed.match_id) : null;
                const remoteMatch = state && state.match_id ? String(state.match_id) : null;
                // Different match → reset then apply fresh state
                if (localMatch && remoteMatch && localMatch !== remoteMatch) {
                  _applyViewerReset();
                  shouldApply = true;
                } else if (localMatch && remoteMatch && localMatch === remoteMatch) {
                  // Same match: skip only if local copy is definitely fresher
                  if (localParsed._savedAt && state.updated_at &&
                      localParsed._savedAt >= state.updated_at) {
                    shouldApply = false;
                  }
                }
              } catch (_) {}
            }
            if (shouldApply) {
              try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (_) {}
              render(state);
            }
          } catch (_) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (_) {}
            render(state);
          }
          // ✅ SSOT FIX END
        } else {
          _resetIfLocalStateWasActive();
        }
      })
      .catch(function() {});
  } catch (_) {}
}

function _startPoll() {
  if (_pollTimer) return;
  _pollTimer = setInterval(function() {
    if (_wsLive) return;
    _readStateFromDB();
  }, POLL_INTERVAL_MS);
}
_startPoll();

// ── Helpers ─────────────────────────────────────────────────────
function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = (val == null ? '' : val);
}

function flashEl(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('flash');
  void el.offsetWidth;   // reflow to restart animation
  el.classList.add('flash');
  setTimeout(function() { el.classList.remove('flash'); }, 500);
}

function setMatchTypeDisplay(type) {
  const map = { singles: 'mtSingles', doubles: 'mtDoubles', mixed: 'mtMixed' };
  Object.keys(map).forEach(function(k) {
    const el = document.getElementById(map[k]);
    if (el) el.classList.toggle('active', k === type);
  });
}

function renderPlayers(containerId, players) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  (players || []).forEach(function(p) {
    if (!p.name || !p.name.trim()) return;
    const row  = document.createElement('div');
    row.className = 'player-row';
    const role = document.createElement('span');
    role.className   = 'player-role';
    role.textContent = p.role || '';
    const name = document.createElement('span');
    name.textContent = p.name;
    row.appendChild(role);
    row.appendChild(name);
    container.appendChild(row);
  });
}

function buildPlayers(state, team) {
  const type = (state.matchType || 'singles').toLowerCase();
  const tObj = state['team' + team] || {};
  const p    = tObj.players || [];
  let list   = [];
  if (type === 'singles') {
    list.push({ role: 'Singles',       name: p[0] || '' });
  } else if (type === 'doubles') {
    list.push({ role: 'Player 1',      name: p[0] || '' });
    list.push({ role: 'Player 2',      name: p[1] || '' });
  } else {
    list.push({ role: 'Male Player',   name: p[0] || '' });
    list.push({ role: 'Female Player', name: p[1] || '' });
  }
  return list.filter(function(x) { return x.name.trim() !== ''; });
}

// Cache previous scores for flash detection
var _prev = { scoreA: null, scoreB: null, gamesA: null, gamesB: null };

// ── Main render ──────────────────────────────────────────────────
// Handles the saveLocalState payload shape:
//   state.teamA.name / .score / .gamesWon / .timeout / .players[]
// Also handles legacy flat shape as a fallback.
function render(state) {
  if (!state) return;

  // ── Normalise: support both nested (new) and flat (legacy) shape ─
  const tA = state.teamA || {};
  const tB = state.teamB || {};

  const teamAName = tA.name || state.teamAName || 'TEAM A';
  const teamBName = tB.name || state.teamBName || 'TEAM B';

  setText('teamAName', teamAName);
  setText('teamBName', teamBName);

  // Scores — flash on change
  const newScoreA = tA.score  != null ? tA.score  : (state.scoreA  != null ? state.scoreA  : 0);
  const newScoreB = tB.score  != null ? tB.score  : (state.scoreB  != null ? state.scoreB  : 0);
  const newGamesA = tA.gamesWon != null ? tA.gamesWon : (state.gamesA != null ? state.gamesA : 0);
  const newGamesB = tB.gamesWon != null ? tB.gamesWon : (state.gamesB != null ? state.gamesB : 0);

  if (_prev.scoreA !== null && newScoreA !== _prev.scoreA) flashEl('scoreA');
  if (_prev.scoreB !== null && newScoreB !== _prev.scoreB) flashEl('scoreB');
  if (_prev.gamesA !== null && (_prev.gamesA !== newGamesA || _prev.gamesB !== newGamesB)) {
    if (newGamesA > newGamesB) flashEl('panelA');
    else if (newGamesB > newGamesA) flashEl('panelB');
  }
  _prev.scoreA = newScoreA; _prev.scoreB = newScoreB;
  _prev.gamesA = newGamesA; _prev.gamesB = newGamesB;

  setText('scoreA',   newScoreA);
  setText('scoreB',   newScoreB);
  setText('gamesA',   newGamesA);
  setText('gamesB',   newGamesB);
  setText('timeoutA', tA.timeout  != null ? tA.timeout  : (state.timeoutA  != null ? state.timeoutA  : 0));
  setText('timeoutB', tB.timeout  != null ? tB.timeout  : (state.timeoutB  != null ? state.timeoutB  : 0));

  // Center info
  setText('bestOfBox',     state.bestOf     != null ? state.bestOf     : 3);
  setText('currentSetBox', state.currentSet != null ? state.currentSet : 1);

  // Serving — support both 'A'/'B' token and full name
  const servingTeam  = state.serving || state.servingTeam || 'A';
  const servingName  = (servingTeam === 'B') ? teamBName : teamAName;
  setText('servingTeamLabel', servingName);

  // Timeout labels
  setText('timeoutLabelA', teamAName);
  setText('timeoutLabelB', teamBName);

  // Committee: prefer explicit committee in payload or fallback to provided committee field
  const committee = (state.committee || state.committee_official || '') || (tA.committee || '');
  setText('committeeDisplay', (committee || '').trim() || '—');

  // Match type tabs
  const typeRaw = (state.matchType || 'singles').toLowerCase();
  const typeKey = typeRaw.indexOf('mixed')  !== -1 ? 'mixed'
                : typeRaw.indexOf('double') !== -1 ? 'doubles'
                : 'singles';
  setMatchTypeDisplay(typeKey);

  // Players — build from nested teamA.players[] array
  renderPlayers('tt-playersA', buildPlayers(state, 'A'));
  renderPlayers('tt-playersB', buildPlayers(state, 'B'));

  // Swap layout
  const area  = document.getElementById('mainArea');
  const toRow = document.getElementById('timeoutRow');
  if (state.swapped) {
    if (area)  area.style.gridTemplateAreas  = '"right center left"';
    if (toRow) toRow.style.flexDirection = 'row-reverse';
  } else {
    if (area)  area.style.gridTemplateAreas  = '"left center right"';
    if (toRow) toRow.style.flexDirection = 'row';
  }

  // Status bar
  setText('statusMatchType',  state.matchType || 'Singles');
  setText('statusBestOf',     state.bestOf    != null ? state.bestOf    : 3);
  setText('statusCurrentSet', state.currentSet!= null ? state.currentSet: 1);
  setText('statusScore',      newScoreA + ' — ' + newScoreB);
  setText('statusGames',      newGamesA + ' — ' + newGamesB);
  setText('statusServing',    servingName);

  // Previous set score
  try {
    const sets = state.sets || state.setHistory || [];
    if (Array.isArray(sets) && sets.length) {
      const last  = sets[sets.length - 1] || {};
      const a     = last.teamAScore != null ? last.teamAScore : (last.team_a_score != null ? last.team_a_score : 0);
      const b     = last.teamBScore != null ? last.teamBScore : (last.team_b_score != null ? last.team_b_score : 0);
      const setNo = last.setNumber  || last.set_number || '';
      setText('statusPrevSet', (setNo ? 'Set ' + setNo + ': ' : '') + a + ' — ' + b);
    } else {
      setText('statusPrevSet', '—');
    }
  } catch (_) { setText('statusPrevSet', '—'); }

  // Previous set winner
  try {
    const manual = state.manualWinners || {};
    const sets   = state.sets || state.setHistory || [];
    let prevWinnerLabel = '—';
    if (Array.isArray(sets) && sets.length) {
      const last    = sets[sets.length - 1] || {};
      const lastNum = String(last.setNumber || last.set_number || sets.length);
      if (manual[lastNum]) {
        prevWinnerLabel = manual[lastNum] === 'A' ? teamAName : teamBName;
      } else {
        const w = last.winner || last.set_winner || last.setWinner || null;
        if (w === 'A')      prevWinnerLabel = teamAName;
        else if (w === 'B') prevWinnerLabel = teamBName;
        else                prevWinnerLabel = '—';
      }
    }
    setText('statusPrevWinner', prevWinnerLabel);
  } catch (_) { setText('statusPrevWinner', '—'); }

  // Manual per-set winner highlight
  try {
    const manual = state.manualWinners || {};
    const setNum = String(state.currentSet || 1);
    const mw     = manual[setNum] ? String(manual[setNum]).toUpperCase() : null;
    const hdrA   = document.querySelector('.team-header-a');
    const hdrB   = document.querySelector('.team-header-b');
    if (hdrA) hdrA.classList.toggle('manual-winner', mw === 'A');
    if (hdrB) hdrB.classList.toggle('manual-winner', mw === 'B');
  } catch (_) {}

  try { maybeShowViewerWinnerPopup(state); } catch (_) {}
}

// ── Initial load from localStorage (restore if admin was already open) ──
(function init() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) render(JSON.parse(raw));
  } catch (_) {}
})();