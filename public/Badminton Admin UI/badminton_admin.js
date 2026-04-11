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
let _serverPersistTimer = null;
function scheduleServerPersist() {
  if (_serverPersistTimer) clearTimeout(_serverPersistTimer);
  _serverPersistTimer = setTimeout(function () {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const viewerState = JSON.parse(raw);
      // Attach match_id and full setHistory so state.php stores the complete picture
      const matchIdRaw = sessionStorage.getItem('badminton_match_id');
      viewerState.match_id = matchIdRaw ? matchIdRaw : 'live';
      viewerState.setHistory = Array.isArray(setHistory) ? setHistory : [];
      fetch('state.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(viewerState)
      }).catch(function () { /* silent — offline is fine */ });
    } catch (e) { /* ignore */ }
  }, 400);
}

// ── Save full admin state to localStorage (for refresh recovery) ─
function saveAdminState() {
  try {
    const matchIdRaw = sessionStorage.getItem('badminton_match_id');
    const adminSnap = {
      state: JSON.parse(JSON.stringify(state)),
      setHistory: Array.isArray(setHistory) ? setHistory.slice() : [],
      match_id: matchIdRaw || null,
      // capture player input values since they live in the DOM
      playerA0: (function(){ const c=document.getElementById('playersA'); if(!c) return ''; const i=c.querySelectorAll('input'); return i[0]?i[0].value:''; })(),
      playerA1: (function(){ const c=document.getElementById('playersA'); if(!c) return ''; const i=c.querySelectorAll('input'); return i[1]?i[1].value:''; })(),
      playerB0: (function(){ const c=document.getElementById('playersB'); if(!c) return ''; const i=c.querySelectorAll('input'); return i[0]?i[0].value:''; })(),
      playerB1: (function(){ const c=document.getElementById('playersB'); if(!c) return ''; const i=c.querySelectorAll('input'); return i[1]?i[1].value:''; })(),
      committee: (function(){ const el=document.getElementById('committeeInput'); return el?el.value.trim():''; })()
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
    state.matchType    = s.matchType    || 'singles';
    state.serving      = s.serving      || 'A';
    state.swapped      = s.swapped      || false;
    state.bestOf       = s.bestOf       || 3;
    state.currentSet   = s.currentSet   || 1;
    state.manualWinners = s.manualWinners || {};

    if (s.teamA) {
      state.teamA.name     = s.teamA.name     || 'TEAM A';
      state.teamA.score    = s.teamA.score    || 0;
      state.teamA.gamesWon = s.teamA.gamesWon || 0;
      state.teamA.timeout  = s.teamA.timeout  || 0;
    }
    if (s.teamB) {
      state.teamB.name     = s.teamB.name     || 'TEAM B';
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

    // Restore match type (re-renders player inputs)
    setMatchType(state.matchType);

    // Restore player names after renderPlayers recreates inputs
    setTimeout(function(){
      try {
        const cA = document.getElementById('playersA');
        const cB = document.getElementById('playersB');
        if (cA) { const i=cA.querySelectorAll('input'); if(i[0]) i[0].value=snap.playerA0||''; if(i[1]) i[1].value=snap.playerA1||''; }
        if (cB) { const i=cB.querySelectorAll('input'); if(i[0]) i[0].value=snap.playerB0||''; if(i[1]) i[1].value=snap.playerB1||''; }
      } catch(e){}
    }, 0);

    // Restore committee/official
    const comEl = document.getElementById('committeeInput');
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
  } catch (e) { _ws = null; }
}
_initWS();

function _maybeSendWS(viewerState) {
  try {
    if (_ws && _ws.readyState === 1) {
      _ws.send(JSON.stringify({ type: 'state', match_id: getMatchId(), payload: viewerState }));
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
  const container = document.getElementById('players' + team);
  if (!container) return '';
  const inputs = container.querySelectorAll('input');
  return (inputs[idx] && inputs[idx].value) ? inputs[idx].value : '';
}

// Persist a flattened viewer-friendly state to localStorage
function saveLocalState() {
  try {
    const committee = (document.getElementById('committeeInput') && document.getElementById('committeeInput').value) ? document.getElementById('committeeInput').value.trim() : '';
    const viewerState = {
      // completed sets history for viewer (array of { setNumber, teamAScore, teamBScore })
      sets: Array.isArray(setHistory) ? setHistory.map(s => ({ setNumber: s.setNumber, teamAScore: s.teamAScore, teamBScore: s.teamBScore })) : [],
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
    localStorage.setItem(STORAGE_KEY, JSON.stringify(viewerState));
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
}

function swapTeams() {
  state.swapped = !state.swapped;
  const area = document.getElementById('mainArea');
  const toRow = document.getElementById('timeoutRow');
  
  if (state.swapped) {
    area.style.gridTemplateAreas = '"right center left"';
    toRow.style.flexDirection = 'row-reverse';
  } else {
    area.style.gridTemplateAreas = '"left center right"';
    toRow.style.flexDirection = 'row';
  }
  saveLocalState();
}

function setMatchType(type) {
  state.matchType = type;
  document.querySelectorAll('.mt-btn').forEach(b => b.classList.toggle('active', b.dataset.type === type));
  renderPlayers('A');
  renderPlayers('B');
  saveLocalState();
}

function renderPlayers(t) {
    const container = document.getElementById('players' + t);
    let html = '';
    if (state.matchType === 'singles') html = `<input type="text" placeholder="Player name">`;
    else if (state.matchType === 'doubles') html = `<input type="text" placeholder="P1"><input type="text" placeholder="P2" style="margin-top:5px">`;
    else html = `<label style="font-size:10px">Male</label><input type="text"><label style="font-size:10px">Female</label><input type="text">`;
    container.innerHTML = html;
    // attach live-save listeners to any player input so viewer updates as you type
    const inputs = container.querySelectorAll('input');
    inputs.forEach((inp, idx) => {
      // set initial value if previously present in DOM or state
      try {
        const existing = inp.value || '';
        if (!existing && state && state['team' + t] && Array.isArray(state['team' + t].players)) {
          inp.value = state['team' + t].players[idx] || '';
        }
      } catch (e) {}
      // avoid attaching multiple listeners
      if (!inp.dataset._live) {
        inp.addEventListener('input', saveLocalState);
        inp.addEventListener('blur', saveLocalState);
        inp.dataset._live = '1';
      }
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
  // Push the just-completed set snapshot into setHistory (if any)
  try {
    const snap = {
      setNumber: state.currentSet,
      teamAScore: state.teamA.score,
      teamBScore: state.teamB.score,
      teamATimeout: state.teamA.timeout,
      teamBTimeout: state.teamB.timeout,
      serving: state.serving,
      winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : null)
    };
    // Only push if the set has any activity (non-zero scores or timeouts), otherwise still keep a record per spec
    setHistory.push(snap);
  } catch (e) {
    // ignore
  }
  // Attempt to persist the snapshot to the server, then clear scores/timeouts but NOT games won
  const payload = buildSavePayload();
  fetch('save_set.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) sessionStorage.setItem('badminton_match_id', j.match_id);
    })
    .catch(err => { console.error('save_set failed', err); })
    .finally(() => {
      state.teamA.score = 0; state.teamB.score = 0;
      state.teamA.timeout = 0; state.teamB.timeout = 0;
      const elA = document.getElementById('scoreA'); if (elA) elA.textContent = 0;
      const elB = document.getElementById('scoreB'); if (elB) elB.textContent = 0;
      const ta = document.getElementById('timeoutA'); if (ta) ta.textContent = 0;
      const tb = document.getElementById('timeoutB'); if (tb) tb.textContent = 0;
      changeSet(1);
      saveLocalState();
    });
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
    winner: state.teamA.score > state.teamB.score ? 'A' : (state.teamB.score > state.teamA.score ? 'B' : null)
  };
  const allSets = completed.concat([currentSnap]);

  // Aggregate set wins
  let aWins = 0, bWins = 0;
  allSets.forEach(s => {
    if (s && s.winner === 'A') aWins++;
    else if (s && s.winner === 'B') bWins++;
  });

  // Update state and UI summary for games won
  state.teamA.gamesWon = aWins;
  state.teamB.gamesWon = bWins;
  const ga = document.getElementById('gamesA'); if (ga) ga.textContent = aWins;
  const gb = document.getElementById('gamesB'); if (gb) gb.textContent = bWins;

  // Determine match winner
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
  } catch (e) { console.error(e); }
}

function closeModal() { document.getElementById('modal').classList.remove('show'); }

// ── saveAndReport: persist to DB via save_set.php, then open badminton_report.php ──
function saveAndReport() {
  const payload = buildSavePayload();
  const committee = document.getElementById('committeeInput') ? document.getElementById('committeeInput').value.trim() : '';
  if (committee) payload.committee_official = committee;

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

      // Determine set wins from payload.sets (server also computes, but do it here to decide whether to declare)
      let aWins = 0, bWins = 0;
      try {
        const sets = Array.isArray(payload.sets) ? payload.sets : [];
        sets.forEach(s => {
          const w = (s.set_winner || s.winner) ? String(s.set_winner || s.winner) : null;
          if (w === 'A') aWins++; else if (w === 'B') bWins++;
        });
      } catch (e) { aWins = payload.team_a_games_won || 0; bWins = payload.team_b_games_won || 0; }

      // If a winner exists (unequal set wins), call declare_winner.php to finalize the match server-side
      const tryDeclare = (aWins !== bWins) ? fetch('declare_winner.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ match_id: parseInt(matchId,10), total_sets_played: (payload.sets||[]).length, team_a_sets_won: aWins, team_b_sets_won: bWins, winner_team: aWins>bWins?'A':'B', winner_name: aWins>bWins?state.teamA.name:state.teamB.name })
      }).then(rr => rr.json()).catch(() => null) : Promise.resolve(null);

      // Open report after declare (if any) resolves — still open even if declare fails
      tryDeclare.finally(() => {
        window.open('badminton_report.php?match_id=' + matchId, '_blank');
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
  // Build a self-contained HTML report and download it
  const teamA = (state.teamA.name || 'TeamA').replace(/[^a-z0-9 _-]/gi, '').trim();
  const teamB = (state.teamB.name || 'TeamB').replace(/[^a-z0-9 _-]/gi, '').trim();
  const fname = `badminton_report_${teamA.replace(/\s+/g,'_')}_vs_${teamB.replace(/\s+/g,'_')}_Set${state.currentSet}.html`;

  const now = new Date();
  const exportedAt = now.toLocaleString();
  const committee = (document.getElementById('committeeInput') && document.getElementById('committeeInput').value) ? document.getElementById('committeeInput').value.trim() : '';

  // Prepare player lineup rows based on match type
  function getPlayerRows() {
    const rows = [];
    const type = state.matchType.toLowerCase();
    // Read inputs from DOM to get current player names
    function nameFor(team, idx) {
      const container = document.getElementById('players' + team);
      if (!container) return '';
      const inputs = container.querySelectorAll('input');
      return (inputs[idx] && inputs[idx].value) ? inputs[idx].value : '';
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

  // Build per-team set score summary string (e.g. "Set1: 21 | Set2: 18")
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

  function pName(team, idx){ const container=document.getElementById('players'+team); if(!container) return ''; const inputs=container.querySelectorAll('input'); return (inputs[idx]&&inputs[idx].value)?inputs[idx].value:(''); }
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
  const payload = buildSavePayload();
  const committee = document.getElementById('committeeInput') ? document.getElementById('committeeInput').value.trim() : '';
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
      sessionStorage.setItem('badminton_match_id', matchId);
      const reportUrl = 'badminton_report.php?match_id=' + matchId;
      if (choice === 'print') {
        const w = window.open(reportUrl, '_blank');
        // Let the page load then trigger its print
        if (w) w.addEventListener('load', () => w.print());
      } else {
        // For both 'html' and 'excel', open the report page —
        // the Export Excel button there produces a proper SheetJS .xlsx file.
        window.open(reportUrl, '_blank');
      }
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
    committee: (document.getElementById('committeeInput') && document.getElementById('committeeInput').value) ? document.getElementById('committeeInput').value.trim() : '',
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
    const container = document.getElementById('players' + team);
    if (!container) return out;
    const inputs = container.querySelectorAll('input');
    for (let i = 0; i < inputs.length; i++) {
      out.push({ no: i+1, name: inputs[i].value || '', role: (state.matchType==='singles'?'Singles':('P'+(i+1))) });
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
  const matchIdRaw = sessionStorage.getItem('badminton_match_id');
  const match_id = matchIdRaw ? parseInt(matchIdRaw,10) : null;
  // always capture committee/official here so every server save includes it
  const committee = document.getElementById('committeeInput') ? document.getElementById('committeeInput').value.trim() : '';
  // read player inputs
  function nameFor(team, idx) {
    const container = document.getElementById('players' + team);
    if (!container) return '';
    const inputs = container.querySelectorAll('input');
    return (inputs[idx] && inputs[idx].value) ? inputs[idx].value : '';
  }
  function intFrom(id){ const el=document.getElementById(id); if(!el) return 0; const n=parseInt(String(el.textContent||el.value||'').replace(/\D/g,''),10); return isNaN(n)?0:n; }

  // Build sets array: transform internal `setHistory` (camelCase) to
  // snake_case keys expected by the server, then append current snap.
  const completed = Array.isArray(setHistory) ? setHistory.slice() : [];
  const transformed = completed.map(s => ({
    set_number: (s.setNumber != null) ? parseInt(s.setNumber, 10) : 1,
    team_a_score: (s.teamAScore != null) ? parseInt(s.teamAScore, 10) : ((s.team_a_score != null) ? parseInt(s.team_a_score,10) : 0),
    team_b_score: (s.teamBScore != null) ? parseInt(s.teamBScore, 10) : ((s.team_b_score != null) ? parseInt(s.team_b_score,10) : 0),
    team_a_timeout_used: (s.teamATimeout != null) ? parseInt(s.teamATimeout, 10) : ((s.team_a_timeout_used != null) ? parseInt(s.team_a_timeout_used,10) : 0),
    team_b_timeout_used: (s.teamBTimeout != null) ? parseInt(s.teamBTimeout, 10) : ((s.team_b_timeout_used != null) ? parseInt(s.team_b_timeout_used,10) : 0),
    serving_team: (s.serving || s.serving_team) === 'B' ? 'B' : 'A',
    set_winner: (s.winner || s.set_winner) ? String(s.winner || s.set_winner) : null
  }));

  const currentSnap = {
    set_number: state.currentSet,
    team_a_score: intFrom('scoreA'),
    team_b_score: intFrom('scoreB'),
    team_a_timeout_used: intFrom('timeoutA'),
    team_b_timeout_used: intFrom('timeoutB'),
    serving_team: state.serving,
    set_winner: (intFrom('scoreA') > intFrom('scoreB')) ? 'A' : (intFrom('scoreB') > intFrom('scoreA') ? 'B' : null)
  };

  const setsArray = transformed.concat([currentSnap]);

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

// Reset match handler called by Reset Match button
function resetMatch() {
  const matchIdRaw = sessionStorage.getItem('badminton_match_id');
  const matchId = matchIdRaw ? parseInt(matchIdRaw,10) : null;
  if (!confirm('Reset this match? All saved set records will be deleted.')) return;
  if (!matchId) {
    sessionStorage.removeItem('badminton_match_id');
    location.reload();
    return;
  }
  fetch('reset_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: matchId }) })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) {
        sessionStorage.removeItem('badminton_match_id');
        // Clear all persisted state so the page starts fresh
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
const committeeEl = document.getElementById('committeeInput');
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

// Attach now and also on DOMContentLoaded to handle different load timings
attachAdminWinnerHandlers();
document.addEventListener('DOMContentLoaded', attachAdminWinnerHandlers);