// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
// Number of active lineup slots per team (changed from 5 to 6)
const ACTIVE_LINEUP_SIZE = 6;

const state = {
  teamA: { name: 'TEAM A', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null), score: 0 },
  teamB: { name: 'TEAM B', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null), score: 0 },
  shared: { set: 1 }
};
const VB_STATS = ['pts', 'spike', 'ace', 'exSet', 'exDig', 'blk'];
const VB_STAT_LABELS = { pts: 'PTS', spike: 'SPIKE', ace: 'ACE', exSet: 'EX SET', exDig: 'EX DIG', blk: 'BLK' };
let pCount = { A: 0, B: 0 };
const IS_VOLLEYBALL_PAGE = typeof document !== 'undefined' && document.body && document.body.dataset && document.body.dataset.sport === 'volleyball';
// Unique client id for this admin page (used in action metadata)
const CLIENT_ID = (window.__clientId = window.__clientId || ('c_' + Math.random().toString(36).slice(2,10)));

// ═══════════════════════════════════════════════════════
//  BROADCAST SETUP
// ═══════════════════════════════════════════════════════
const _VB_STORAGE_KEY  = 'volleyballLiveState';
const _VB_CHANNEL_NAME = 'volleyball_live';
let _vbBC = null;
try {
  _vbBC = new BroadcastChannel(_VB_CHANNEL_NAME);
  // Receive state updates from other admin tabs in the same browser
  _vbBC.onmessage = function(e) {
    try {
      if (!e || !e.data) return;
      var data = e.data;
      if (data && data.type === 'new_match' && data.match_id) {
        adoptNewMatch({ match_id: data.match_id, payload: data.payload });
        return;
      }
      // Unwrap { match_id, payload } envelope
      var payload = (data && data.payload !== undefined) ? data.payload : data;
      if (!payload || typeof payload !== 'object') return;
      // Skip our own echoed messages and reset markers
      if (payload._ssot_client && payload._ssot_client === CLIENT_ID) return;
      if (payload._ssot_reset) return;
      var incomingTs = payload._ssot_ts || 0;
      if (incomingTs && incomingTs <= _lastPersistedTs) return;
      var myMid = getMatchId();
      if (data.match_id && myMid && String(data.match_id) !== String(myMid) && String(myMid) !== '0') return;
      // Apply to admin UI
      try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify({ match_id: myMid, payload })); } catch (_) {}
      loadPersistedState();
      if (incomingTs) _lastPersistedTs = incomingTs;
    } catch (_) {}
  };
} catch (_) {}

// ═══════════════════════════════════════════════════════
//  WEBSOCKET
// ═══════════════════════════════════════════════════════
let _ws = null;
// Track the last WS payload we applied so we don't echo our own broadcasts back to ourselves
let _wsLastAppliedTs = 0;
try {
  if (location && location.hostname) {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let url = proto + '//' + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _ws = new WebSocket(url);
    _ws.addEventListener('open', () => {
      _setWSStatus('connected');
      try { _ws.send(JSON.stringify({ type: 'join', match_id: getMatchId() })); } catch (_) {}
      try {
        // If the admin page restored state from the server but the WebSocket opened
        // after that restoration, resend the current authoritative state so viewers
        // receive the full roster update even on reload or reconnect.
        if (state.teamA.players.length || state.teamB.players.length || (state.shared && typeof state.shared.set === 'number')) {
          _ws.send(JSON.stringify({
            type: 'volleyball_state',
            sport: 'volleyball',
            match_id: getMatchId(),
            payload: buildStatePayload(),
          }));
        }
      } catch (_) {}
    });
    _ws.addEventListener('close', () => { _setWSStatus('disconnected'); });
    _ws.addEventListener('error', () => { _setWSStatus('error'); });

    // Multi-admin sync: apply state pushed by OTHER admin clients via the WS relay.
    // We guard by _ssot_client so we don't re-apply our own broadcasts.
    _ws.addEventListener('message', function(ev) {
      try {
        const m = JSON.parse(ev.data);
        if (!m) return;
        if (m.sport && m.sport !== 'volleyball') return;
        if (m.type === 'new_match' && m.match_id) {
          adoptNewMatch({ match_id: m.match_id, payload: m.payload });
          return;
        }
        var payload = null;
        if ((m.type === 'room_state' || m.type === 'applied_action') && m.payload) {
          payload = m.payload.volleyball || m.payload.volleyball_state || m.payload;
        } else if ((m.type === 'volleyball_state' || m.type === 'state' || m.type === 'action') && m.payload) {
          payload = m.payload;
        }
        if (!payload || typeof payload !== 'object') return;
        // Skip our own echoed messages
        if (payload._ssot_client && payload._ssot_client === CLIENT_ID) return;
        // Skip stale messages (older than last applied)
        const incomingTs = payload._ssot_ts || 0;
        if (incomingTs && incomingTs <= _wsLastAppliedTs) return;
        if (incomingTs) _wsLastAppliedTs = incomingTs;
        // Skip reset markers — handled separately
        if (payload._ssot_reset) return;
        // Validate match_id — only apply updates for the current match
        const myMid = getMatchId();
        if (m.match_id && myMid && String(m.match_id) !== String(myMid) && String(myMid) !== '0') return;
        // Apply the incoming state to localStorage then load it into the admin UI
        try {
          const wrapper = JSON.stringify({ match_id: myMid, payload });
          localStorage.setItem(_VB_STORAGE_KEY, wrapper);
        } catch (_) {}
        loadPersistedState();
        _setServerSyncStatus('ok');
      } catch (_) {}
    });
  }
} catch (_) { _ws = null; }

function getMatchId() {
  try {
    const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';
    if (window.__matchId) return String(window.__matchId);
    const el = document.getElementById('matchId');
    if (el) return String(el.value || el.textContent || '').trim() || DEFAULT_ROOM_ID;
    return sessionStorage.getItem('volleyball_match_id') || DEFAULT_ROOM_ID;
  } catch (_) { return '0'; }
}

function adoptNewMatch(event) {
  try {
    if (!event || !event.match_id) return false;
    const newMid = String(event.match_id);
    const myMid = getMatchId();
    if (newMid === myMid) return false;
    try { sessionStorage.setItem('volleyball_match_id', newMid); } catch (_) {}
    try { window.__matchId = newMid; } catch (_) {}
    try { if (_ws && _ws.readyState === WebSocket.OPEN) _ws.send(JSON.stringify({ type: 'join', match_id: newMid })); } catch (_) {}
    if (event.payload && typeof event.payload === 'object') {
      try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify({ match_id: newMid, payload: event.payload })); } catch (_) {}
      loadPersistedState();
    } else {
      try {
        fetch('state.php?match_id=' + encodeURIComponent(newMid) + '&t=' + Date.now(), { cache: 'no-store', credentials: 'include' })
          .then(r => r.json())
          .then(j => {
            if (j && j.success && j.payload) {
              try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify({ match_id: newMid, payload: j.payload })); } catch (_) {}
              loadPersistedState();
            }
          }).catch(_ => {});
      } catch (_) {}
    }
    try { showToast('New match adopted: ' + newMid); } catch (_) {}
    return true;
  } catch (_) { return false; }
}

async function newMatch() {
  try {
    if (!confirm('Create a new match and reset live state for all admins?')) return;
    const res = await fetch('new_match.php', { method: 'POST', credentials: 'include' });
    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch (jsonError) {
      console.error('newMatch invalid response', text);
      showToast('Failed to create new match');
      return;
    }
    if (!res.ok || !data || !data.success || !data.match_id) {
      console.error('newMatch failure', res.status, data);
      showToast(data && data.error ? 'New match failed: ' + data.error : 'Failed to create new match');
      return;
    }
    const newMid = String(data.match_id);
    try { sessionStorage.setItem('volleyball_match_id', newMid); } catch (_) {}
    try { window.__matchId = newMid; } catch (_) {}
    if (data.payload && typeof data.payload === 'object') {
      try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify({ match_id: newMid, payload: data.payload })); } catch (_) {}
    }
    loadPersistedState();
    try { showToast('✅ New match created: ' + newMid); } catch (_) {}
    
    // Build broadcast payload ensuring fresh timestamp and complete empty state
    const broadcastPayload = data.payload || buildStatePayload();
    broadcastPayload._ssot_ts = Date.now();
    broadcastPayload._ssot_client = CLIENT_ID;
    // Explicitly ensure new match payload has empty arrays for viewer to treat as meaningful
    broadcastPayload.teamA.players = [];
    broadcastPayload.teamB.players = [];
    broadcastPayload.teamA.lineupPlayers = Array(ACTIVE_LINEUP_SIZE).fill(null);
    broadcastPayload.teamB.lineupPlayers = Array(ACTIVE_LINEUP_SIZE).fill(null);
    
    // Broadcast new match to BroadcastChannel so other admin tabs get notified
    try { if (_vbBC) _vbBC.postMessage({ type: 'new_match', match_id: newMid, payload: broadcastPayload }); } catch (_) {}
    try { localStorage.setItem('volleyball_new_match', JSON.stringify({ match_id: newMid, payload: broadcastPayload, ts: Date.now() })); } catch (_) {}
    
    // Broadcast via WebSocket so viewers on other devices see the new match immediately
    // Send new_match event which resets viewer's SSOT guard, clears caches, and triggers refresh
    if (_ws && _ws.readyState === WebSocket.OPEN) {
      try { _ws.send(JSON.stringify({ type: 'join', match_id: newMid })); } catch (_) {}
      try { _ws.send(JSON.stringify({ type: 'new_match', sport: 'volleyball', match_id: newMid, payload: broadcastPayload })); } catch (_) {}
    }
    
    return;
  } catch (e) {
    console.error('newMatch error', e);
    showToast('Error creating new match');
  }
}

window.addEventListener('storage', function(e) {
  try {
    if (!e || !e.key) return;
    if (e.key !== 'volleyball_new_match') return;
    const obj = e.newValue ? JSON.parse(e.newValue) : null;
    if (obj && obj.match_id) {
      adoptNewMatch({ match_id: obj.match_id, payload: obj.payload });
    }
  } catch (_) {}
});

// ═══════════════════════════════════════════════════════
//  LIVE SCORE
// ═══════════════════════════════════════════════════════
function recalcScore(team) {
  const teamState = state['team' + team];
  const total = typeof teamState.score === 'number'
    ? teamState.score
    : teamState.players.reduce((s, p) => s + (p.pts || 0), 0);
  const el = document.getElementById('score' + team);
  if (el) {
    el.textContent = total;
    el.style.transform = 'scale(1.22)';
    setTimeout(() => { el.style.transform = 'scale(1)'; }, 140);
  }
}

function updatePlayerStat(team, p, stat, delta, valueEl, ptsValueEl) {
  const oldValue = p[stat] || 0;
  const newValue = Math.max(0, oldValue + delta);
  if (newValue === oldValue) return;
  p[stat] = newValue;
  valueEl.textContent = p[stat];

  let ptsDelta = 0;
  if (['spike', 'ace'].includes(stat)) {
    const oldPts = p.pts || 0;
    const newPts = Math.max(0, oldPts + delta);
    if (newPts !== oldPts) {
      p.pts = newPts;
      if (ptsValueEl) ptsValueEl.textContent = p.pts;
      ptsDelta = newPts - oldPts;
    }
  }

  if (['pts', 'spike', 'ace'].includes(stat)) {
    if (stat === 'pts') ptsDelta = delta;
    const teamState = state['team' + team];
    teamState.score = Math.max(0, (typeof teamState.score === 'number' ? teamState.score : 0) + ptsDelta);
    recalcScore(team);
  }
  broadcastState();
}

// ═══════════════════════════════════════════════════════
//  TEAM NAME
// ═══════════════════════════════════════════════════════
function onTeamName(team) {
  const v = document.getElementById('team' + team + 'Name').value;
  state['team' + team].name = v;
  const lbl = document.getElementById('label' + team);
  if (lbl) lbl.textContent = v || ('TEAM ' + team);
  // Update lineup labels
  const lineupLbl = document.getElementById('lineupLabel' + team);
  if (lineupLbl) lineupLbl.textContent = (v || 'TEAM ' + team).toUpperCase() + ' — ACTIVE LINEUP';
  const toLbl = document.getElementById('toLabelA' === 'toLabelA' && team === 'A' ? 'toLabelA' : 'toLabelB');
  const toEl = document.getElementById('toLabelA'.replace('A', team));
  if (toEl) toEl.textContent = v || 'TEAM ' + team;
  renderLineupCircle(team);
  broadcastState();
}

// ═══════════════════════════════════════════════════════
//  TIMEOUT & SET ADJUSTMENTS
// ═══════════════════════════════════════════════════════
function adjustTimeout(team, delta) {
  state['team' + team].timeout = Math.max(0, state['team' + team].timeout + delta);
  const v = state['team' + team].timeout;
  // Update all timeout displays for this team
  ['tsb' + team + '_timeout', 'timeout' + team].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.textContent = v; el.style.transform = 'scale(1.2)'; setTimeout(() => { el.style.transform = 'scale(1)'; }, 130); }
  });
  broadcastState();
}

function adjustSet(delta) {
  state.shared.set = Math.max(1, Math.min(5, state.shared.set + delta));
  const v = state.shared.set;
  ['setVal', 'tsbA_set', 'tsbB_set'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.textContent = v; el.style.transform = 'scale(1.2)'; setTimeout(() => { el.style.transform = 'scale(1)'; }, 130); }
  });
  broadcastState();
}

function adjustTeamScore(team, delta) {
  const t = state[team];
  t.score = Math.max(0, (typeof t.score === 'number' ? t.score : 0) + delta);
  recalcScore(team === 'teamA' ? 'A' : 'B');
  broadcastState();
}

// ═══════════════════════════════════════════════════════
//  ADD PLAYER
// ═══════════════════════════════════════════════════════
function addPlayer(team) {
  pCount[team]++;
  const id = 'p' + team + pCount[team];
  const p = { id, no: '', name: '', pts: 0, spike: 0, ace: 0, exSet: 0, exDig: 0, blk: 0, selected: false };
  state['team' + team].players.push(p);
  renderRow(team, p);
  refreshLineupSelects(team);
  broadcastState();
}

// ═══════════════════════════════════════════════════════
//  RENDER PLAYER ROW
// ═══════════════════════════════════════════════════════
function renderRow(team, p) {
  const tbody = document.getElementById('tbody' + team);

  const tr = document.createElement('tr');
  tr.className = 'player-main-row';
  tr.id = 'row_' + p.id;

  // Checkbox
  const tdCb = document.createElement('td');
  tdCb.className = 'player-cb-cell';
  const cb = document.createElement('input');
  cb.type = 'checkbox'; cb.className = 'player-cb';
  cb.checked = p.selected;
  cb.onchange = () => {
    p.selected = cb.checked;
    tr.classList.toggle('row-checked', cb.checked);
    syncSelectAll(team);
  };
  tdCb.appendChild(cb); tr.appendChild(tdCb);

  // No.
  const tdNo = document.createElement('td');
  tdNo.className = 'td-no';
  const iNo = document.createElement('input');
  iNo.type = 'text'; iNo.value = p.no; iNo.placeholder = '#'; iNo.maxLength = 3;
  iNo.oninput = e => { p.no = e.target.value; refreshLineupSelects(team); broadcastState(); };
  tdNo.appendChild(iNo); tr.appendChild(tdNo);

  // Name
  const tdNm = document.createElement('td');
  tdNm.className = 'td-name';
  const iNm = document.createElement('input');
  iNm.type = 'text'; iNm.value = p.name; iNm.placeholder = 'Player name';
  iNm.oninput = e => { p.name = e.target.value; refreshLineupSelects(team); broadcastState(); };
  tdNm.appendChild(iNm); tr.appendChild(tdNm);

  // Stats
  let ptsValueEl = null;
  VB_STATS.forEach(stat => {
    const td = document.createElement('td');
    if (stat === 'pts') td.className = 'pts-cell';
    const wrap = document.createElement('div');
    wrap.className = 'stat-cell';

    const vSpan = document.createElement('span');
    vSpan.className = 'stat-val';
    vSpan.textContent = p[stat];
    if (stat === 'pts') ptsValueEl = vSpan;

    const bM = document.createElement('button');
    bM.className = 'sbtn minus'; bM.textContent = '−';
    bM.onclick = () => {
      updatePlayerStat(team, p, stat, -1, vSpan, ptsValueEl);
    };

    const bP = document.createElement('button');
    bP.className = 'sbtn plus'; bP.textContent = '+';
    bP.onclick = () => {
      updatePlayerStat(team, p, stat, 1, vSpan, ptsValueEl);
    };

    wrap.appendChild(bM); wrap.appendChild(vSpan); wrap.appendChild(bP);
    td.appendChild(wrap); tr.appendChild(td);
  });

  // DEL
  const tdDel = document.createElement('td');
  const bDel = document.createElement('button');
  bDel.className = 'btn-del'; bDel.textContent = '✕'; bDel.title = 'Remove player';
  bDel.onclick = () => {
    const arr = state['team' + team].players;
    arr.splice(arr.findIndex(x => x.id === p.id), 1);
    tr.remove();
    // Clear this player from lineup if present
    state['team' + team].lineup = state['team' + team].lineup.map(lid => lid === p.id ? null : lid);
    recalcScore(team);
    syncSelectAll(team);
    refreshLineupSelects(team);
    renderLineupCircle(team);
    broadcastState();
  };
  tdDel.appendChild(bDel); tr.appendChild(tdDel);
  tbody.appendChild(tr);
}

// ═══════════════════════════════════════════════════════
//  CHECKBOXES
// ═══════════════════════════════════════════════════════
function toggleSelectAll(team, masterCb) {
  const players = state['team' + team].players;
  players.forEach(p => { p.selected = masterCb.checked; });
  const tbody = document.getElementById('tbody' + team);
  tbody.querySelectorAll('.player-cb').forEach(cb => {
    cb.checked = masterCb.checked;
    const row = cb.closest('tr');
    if (row) row.classList.toggle('row-checked', masterCb.checked);
  });
}

function syncSelectAll(team) {
  const players = state['team' + team].players;
  const master  = document.getElementById('selectAll' + team);
  if (!master || players.length === 0) {
    if (master) { master.checked = false; master.indeterminate = false; }
    return;
  }
  const allChecked  = players.every(p => p.selected);
  const noneChecked = players.every(p => !p.selected);
  master.indeterminate = !allChecked && !noneChecked;
  master.checked = allChecked;
}

function deleteSelected(team) {
  const arr = state['team' + team].players;
  const toDelete = arr.filter(p => p.selected);
  if (toDelete.length === 0) return;
  const deletedIds = new Set(toDelete.map(p => p.id));
  toDelete.forEach(p => {
    const row = document.getElementById('row_' + p.id);
    if (row) row.remove();
  });
  state['team' + team].players = arr.filter(p => !p.selected);
  // Clear deleted players from lineup
  state['team' + team].lineup = state['team' + team].lineup.map(lid => deletedIds.has(lid) ? null : lid);
  recalcScore(team);
  syncSelectAll(team);
  refreshLineupSelects(team);
  renderLineupCircle(team);
  broadcastState();
}

// ═══════════════════════════════════════════════════════
//  LINEUP CIRCLE
// ═══════════════════════════════════════════════════════

// Build the active select dropdowns for a team's lineup
function refreshLineupSelects(team) {
  const container = document.getElementById('lineupSlots' + team);
  if (!container) return;

  const players = state['team' + team].players;
  const lineup  = state['team' + team].lineup;

  container.innerHTML = '';
  // Render lineup selects from state.lineup (source of truth)
  for (let i = 0; i < ACTIVE_LINEUP_SIZE; i++) {
    const slot = document.createElement('div');
    slot.className = 'lineup-slot';

    const num = document.createElement('span');
    num.className = 'lineup-slot-num';
    num.textContent = (i + 1) + '.';

    const sel = document.createElement('select');

    const emptyOpt = document.createElement('option');
    emptyOpt.value = '';
    emptyOpt.textContent = '— empty —';
    sel.appendChild(emptyOpt);

    players.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.id;
      const label = (p.no ? '#' + p.no + ' ' : '') + (p.name || '(no name)');
      opt.textContent = label;
      sel.appendChild(opt);
    });

    // Set select value from state.lineup if present in options
    const currentLineupId = lineup && lineup[i] ? lineup[i] : '';
    if (currentLineupId && Array.from(sel.options).some(o => o.value === currentLineupId)) {
      sel.value = currentLineupId;
    } else {
      sel.value = '';
    }
    const idx = i;
    sel.addEventListener('change', () => {
      state['team' + team].lineup[idx] = sel.value || null;
      renderLineupCircle(team);
      broadcastState();
    });

    slot.appendChild(num);
    slot.appendChild(sel);
    container.appendChild(slot);
  }

  renderLineupCircle(team);
}

// Render the SVG circle showing active players
function renderLineupCircle(team) {
  const svg = document.getElementById('lineupSvg' + team);
  if (!svg) return;

  const teamObj = state['team' + team];
  const players = teamObj.players;
  const lineup  = teamObj.lineup;
  const score   = players.reduce((s, p) => s + (p.pts || 0), 0);
  const teamName = (teamObj.name || 'TEAM ' + team).slice(0, 6).toUpperCase();

  // Clear SVG
  while (svg.firstChild) svg.removeChild(svg.firstChild);

  const ns = 'http://www.w3.org/2000/svg';
  const cx = 70, cy = 70, r = 54;

  // Outer ring
  const ring = document.createElementNS(ns, 'circle');
  ring.setAttribute('cx', cx);
  ring.setAttribute('cy', cy);
  ring.setAttribute('r', r);
  ring.setAttribute('fill', 'none');
  ring.setAttribute('stroke', '#F5C518');
  ring.setAttribute('stroke-width', '2.5');
  ring.setAttribute('opacity', '0.7');
  svg.appendChild(ring);

  // Inner fill
  const inner = document.createElementNS(ns, 'circle');
  inner.setAttribute('cx', cx);
  inner.setAttribute('cy', cy);
  inner.setAttribute('r', r - 4);
  inner.setAttribute('fill', '#0c0c0c');
  svg.appendChild(inner);

  // Center team name
  const nameText = document.createElementNS(ns, 'text');
  nameText.setAttribute('x', cx);
  nameText.setAttribute('y', cy - 10);
  nameText.setAttribute('text-anchor', 'middle');
  nameText.setAttribute('dominant-baseline', 'middle');
  nameText.setAttribute('font-family', 'Oswald, sans-serif');
  nameText.setAttribute('font-size', '8');
  nameText.setAttribute('font-weight', '600');
  nameText.setAttribute('fill', '#888');
  nameText.setAttribute('letter-spacing', '1');
  nameText.textContent = teamName;
  svg.appendChild(nameText);

  // Center score
  const scoreText = document.createElementNS(ns, 'text');
  scoreText.setAttribute('x', cx);
  scoreText.setAttribute('y', cy + 8);
  scoreText.setAttribute('text-anchor', 'middle');
  scoreText.setAttribute('dominant-baseline', 'middle');
  scoreText.setAttribute('font-family', 'Oswald, sans-serif');
  scoreText.setAttribute('font-size', '24');
  scoreText.setAttribute('font-weight', '700');
  scoreText.setAttribute('fill', '#F5C518');
  scoreText.textContent = score;
  svg.appendChild(scoreText);

  // player chips around the ring — use actual lineup length (>= ACTIVE_LINEUP_SIZE)
  const chipR = 46; // distance from center
  const effectiveSlots = lineup.length >= ACTIVE_LINEUP_SIZE ? lineup.length : ACTIVE_LINEUP_SIZE;
  const step = 360 / effectiveSlots;
  for (let i = 0; i < effectiveSlots; i++) {
    const angle = (i * step - 90) * (Math.PI / 180); // start at top
    const chipCx = cx + chipR * Math.cos(angle);
    const chipCy = cy + chipR * Math.sin(angle);

    const playerId = lineup[i];
    const player   = playerId ? players.find(p => p.id === playerId) : null;

    // Chip background pill
    const chipW = 34, chipH = 14;
    const chipRect = document.createElementNS(ns, 'rect');
    chipRect.setAttribute('x', chipCx - chipW / 2);
    chipRect.setAttribute('y', chipCy - chipH / 2);
    chipRect.setAttribute('width', chipW);
    chipRect.setAttribute('height', chipH);
    chipRect.setAttribute('rx', '7');
    chipRect.setAttribute('fill', player ? '#1a3a1a' : '#1a1a1a');
    chipRect.setAttribute('stroke', player ? '#27ae60' : '#333');
    chipRect.setAttribute('stroke-width', '1');
    svg.appendChild(chipRect);

    // Chip text
    const chipText = document.createElementNS(ns, 'text');
    chipText.setAttribute('x', chipCx);
    chipText.setAttribute('y', chipCy);
    chipText.setAttribute('text-anchor', 'middle');
    chipText.setAttribute('dominant-baseline', 'middle');
    chipText.setAttribute('font-family', 'Barlow Condensed, sans-serif');
    chipText.setAttribute('font-size', '7');
    chipText.setAttribute('fill', player ? '#f0f0f0' : '#444');
    if (player) {
      const no   = player.no   ? '#' + player.no + ' ' : '';
      const name = player.name ? player.name.slice(0, 7) : '';
      chipText.textContent = (no + name).trim() || '—';
    } else {
      chipText.textContent = String(i + 1);
    }
    svg.appendChild(chipText);
  }
}

// Rotate lineup clockwise for a team (player positions move one slot clockwise)
function rotateTeamClockwise(team) {
  try {
    const teamLineup = Array.isArray(state['team' + team].lineup) ? state['team' + team].lineup.slice() : [];
    while (teamLineup.length < ACTIVE_LINEUP_SIZE) teamLineup.push(null);
    const n = teamLineup.length; // use actual lineup size, not hardcoded cap
    const roster = (Array.isArray(state['team' + team].players) ? state['team' + team].players.filter(p => p.name && p.name.trim()).map(p => p.id) : []);

    // Build ordered unique list: prefer current lineup order, then append roster ids
    const ordered = [];
    const seen = new Set();
    for (let id of teamLineup) {
      if (id && roster.includes(id) && !seen.has(id)) { ordered.push(id); seen.add(id); }
    }
    for (let id of roster) {
      if (!seen.has(id)) { ordered.push(id); seen.add(id); }
      if (ordered.length >= n) break;
    }

    while (ordered.length < n) ordered.push(null);

    const newLineup = Array(n).fill(null);
    if (ordered.length > 0) {
      const last = ordered[ordered.length - 1];
      newLineup[0] = last || null;
      for (let i = 1; i < n; i++) newLineup[i] = ordered[i - 1] || null;
    }

    const uniqueRoster = Array.from(new Set(roster));
    if (uniqueRoster.length >= n) {
      for (let i = 0; i < n; i++) {
        if (!newLineup[i]) {
          for (let id of uniqueRoster) {
            if (!newLineup.includes(id)) { newLineup[i] = id; break; }
          }
        }
      }
    }

    state['team' + team].lineup = newLineup;
    refreshLineupSelects(team);
    renderLineupCircle(team);
    broadcastState();
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  VIEW MODE (one-sided / two-sided)
// ═══════════════════════════════════════════════════════
let viewMode = 'two';
try { const sv = localStorage.getItem('volleyball_viewMode'); if (sv === 'one' || sv === 'two') viewMode = sv; } catch (_) {}
let activeTab = 'A';

function toggleViewMode() {
  viewMode = viewMode === 'two' ? 'one' : 'two';
  try { localStorage.setItem('volleyball_viewMode', viewMode); } catch (_) {}
  applyViewMode();
}

function applyViewMode() {
  const grid   = document.getElementById('mainGrid');
  const btn    = document.getElementById('viewToggleBtn');
  const panelA = document.getElementById('panelA');
  const panelB = document.getElementById('panelB');
  if (viewMode === 'two') {
    grid.classList.remove('one-sided');
    btn.textContent = '⇄ Two-Sided';
    btn.classList.add('two-sided');
    panelA.classList.add('visible');
    panelB.classList.add('visible');
  } else {
    grid.classList.add('one-sided');
    btn.textContent = '⇆ One-Sided';
    btn.classList.remove('two-sided');
    panelA.classList.toggle('visible', activeTab === 'A');
    panelB.classList.toggle('visible', activeTab === 'B');
    highlightTab(activeTab);
  }
}

function switchTab(team) {
  if (viewMode !== 'one') return;
  activeTab = team;
  document.getElementById('panelA').classList.toggle('visible', team === 'A');
  document.getElementById('panelB').classList.toggle('visible', team === 'B');
  highlightTab(team);
}

function highlightTab(team) {
  document.getElementById('tabA').className = 'team-tab' + (team === 'A' ? ' active-a' : '');
  document.getElementById('tabB').className = 'team-tab' + (team === 'B' ? ' active-b' : '');
}

// ═══════════════════════════════════════════════════════
//  SAVE FILE
// ═══════════════════════════════════════════════════════
async function saveFile() {
  // require full active lineups before saving
  if (!validateLineupForSave()) return;
  const scoreA = typeof state.teamA.score === 'number' ? state.teamA.score : state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
  const scoreB = typeof state.teamB.score === 'number' ? state.teamB.score : state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
  const committee = document.getElementById('vbCommitteeInput')?.value?.trim() || '';
  const payload = {
    teamA: { ...state.teamA, score: scoreA },
    teamB: { ...state.teamB, score: scoreB },
    shared: state.shared,
    committee
  };

  try {
    const res = await fetch('volleyball_save_game.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    });
    const data = await res.json();
    if (data && data.success) {
      try { sessionStorage.setItem('volleyball_match_id', String(data.match_id)); } catch (e) {}
      try { sessionStorage.setItem('shouldClearPersistedOnBack:volleyball', '1'); } catch (e) {}
      try { sessionStorage.setItem('disableBackAfterSave_volleyball', '1'); } catch (e) {}
      // Redirect to report in same tab
      window.location.href = 'volleyball_report.php?match_id=' + data.match_id;
      return;
    } else {
      showToast('❌ Save failed: ' + (data && data.error ? data.error : 'Unknown error'));
    }
  } catch (err) {
    showToast('❌ Network error: ' + (err && err.message ? err.message : String(err)));
  }
}

function validateLockInPlayers() {
  const payload = buildStatePayload();
  const hasActiveLineup = function (team) {
    if (!team || !Array.isArray(team.lineupPlayers)) return false;
    return team.lineupPlayers.some(function (slot) {
      if (!slot || typeof slot !== 'object') return false;
      if (slot.id && String(slot.id).trim() !== '') return true;
      if (slot.name && String(slot.name).trim() !== '') return true;
      return false;
    });
  };
  if (!hasActiveLineup(payload.teamA) || !hasActiveLineup(payload.teamB)) {
    showToast('Please add players to Team A and Team B Active Lineup before locking players.');
    return false;
  }
  return true;
}

async function _broadcastAuthoritativeState(mid, payload) {
  if (!payload || typeof payload !== 'object') return;
  const wrapper = { match_id: mid, payload };
  try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify(wrapper)); } catch (_) {}
  if (_vbBC) try { _vbBC.postMessage(wrapper); } catch (_) {}
  if (_ws && _ws.readyState === WebSocket.OPEN) {
    try { _ws.send(JSON.stringify({ type: 'volleyball_state', sport: 'volleyball', match_id: mid, payload: payload, meta: { clientId: CLIENT_ID, ts: Date.now() } })); } catch (_) {}
    try { _ws.send(JSON.stringify({ type: 'action', sport: 'volleyball', match_id: mid, payload: payload, meta: { clientId: CLIENT_ID, ts: Date.now() } })); } catch (_) {}
  }
}

async function lockPlayers() {
  // Auto-set lineup to first 6 players from each team's roster
  ['A', 'B'].forEach(team => {
    const teamState = state['team' + team];
    const players = teamState.players || [];
    teamState.lineup = [];
    for (let i = 0; i < ACTIVE_LINEUP_SIZE; i++) {
      teamState.lineup[i] = players[i] ? players[i].id : null;
    }
    refreshLineupSelects(team);
  });

  if (!validateLockInPlayers()) return;
  const payload = buildStatePayload();
  const mid = getMatchId() || 0;

  try {
    const res = await fetch('state.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ match_id: mid, payload: payload, action: 'lock_in_players' })
    });
    const data = await res.json();
    if (data && data.success) {
      showToast('✅ Players locked in successfully. Refreshing authoritative state...');
      try {
        const resp = await fetch('state.php?match_id=' + encodeURIComponent(mid) + '&t=' + Date.now(), {
          cache: 'no-store',
          credentials: 'include'
        });
        const json = resp && resp.ok ? await resp.json() : null;
        if (json && json.success && json.payload) {
          _broadcastAuthoritativeState(mid, json.payload);
          // Also refresh local admin UI from the authoritative payload
          loadPersistedState();
          return;
        }
      } catch (_) {}
      return;
    }
    showToast('❌ Lock failed: ' + (data && data.error ? data.error : 'Unknown error'));
  } catch (err) {
    showToast('❌ Network error: ' + (err && err.message ? err.message : String(err)));
  }
}

// ═══════════════════════════════════════════════════════
//  RESET MATCH
// ═══════════════════════════════════════════════════════
async function resetMatch() {
  if (!confirm('Reset match? All local data will be cleared.')) return;

  const mid = getMatchId() || '0';

  // Clear admin state locally
  state.teamA = { name: 'TEAM A', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null), score: 0 };
  state.teamB = { name: 'TEAM B', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null), score: 0 };
  state.shared = { set: 1 };
  pCount = { A: 0, B: 0 };

  // Update DOM
  try { document.getElementById('tbodyA').innerHTML = ''; } catch (_) {}
  try { document.getElementById('tbodyB').innerHTML = ''; } catch (_) {}
  try { document.getElementById('teamAName').value = 'TEAM A'; } catch (_) {}
  try { document.getElementById('teamBName').value = 'TEAM B'; } catch (_) {}
  try { document.getElementById('labelA').textContent = 'TEAM A'; } catch (_) {}
  try { document.getElementById('labelB').textContent = 'TEAM B'; } catch (_) {}
  try { document.getElementById('scoreA').textContent = '0'; } catch (_) {}
  try { document.getElementById('scoreB').textContent = '0'; } catch (_) {}
  try { document.getElementById('setVal').textContent = '1'; } catch (_) {}
  try { document.getElementById('timeoutA').textContent = '0'; } catch (_) {}
  try { document.getElementById('timeoutB').textContent = '0'; } catch (_) {}

  refreshLineupSelects('A');
  refreshLineupSelects('B');

  try { localStorage.removeItem(_VB_STORAGE_KEY); } catch (_) {}

  // Build complete empty reset payload — this MUST be meaningful so viewer accepts it
  const emptyPayload = buildStatePayload();
  emptyPayload._ssot_ts = Date.now();
  emptyPayload._ssot_client = CLIENT_ID;
  // Explicitly ensure reset payload has empty but defined arrays for viewer to accept as "meaningful"
  emptyPayload.teamA.players = [];
  emptyPayload.teamB.players = [];
  emptyPayload.teamA.lineupPlayers = Array(ACTIVE_LINEUP_SIZE).fill(null);
  emptyPayload.teamB.lineupPlayers = Array(ACTIVE_LINEUP_SIZE).fill(null);

  try {
    await fetch('state.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ match_id: mid, payload: emptyPayload })
    });
  } catch (_) {}

  // Broadcast reset via new_match event so viewer resets SSOT guard, clears cache, and refreshes
  try { if (_vbBC) _vbBC.postMessage({ type: 'new_match', match_id: mid, payload: emptyPayload }); } catch (_) {}
  try { localStorage.setItem('volleyball_new_match', JSON.stringify({ match_id: mid, payload: emptyPayload, ts: Date.now() })); } catch (_) {}
  
  // Send new_match event via WebSocket to trigger viewer refresh
  if (_ws && _ws.readyState === WebSocket.OPEN) {
    try { _ws.send(JSON.stringify({ type: 'new_match', sport: 'volleyball', match_id: mid, payload: emptyPayload })); } catch (_) {}
  }

  showToast('✅ Match reset — all data cleared on server and viewers');
}

// ═══════════════════════════════════════════════════════
//  BROADCAST STATE
// ═══════════════════════════════════════════════════════
function buildStatePayload() {
  const committee = document.getElementById('vbCommitteeInput')?.value?.trim() || '';
  const scoreA = typeof state.teamA.score === 'number' ? state.teamA.score : state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
  const scoreB = typeof state.teamB.score === 'number' ? state.teamB.score : state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
  // helper to build lineupPlayers array (objects with id,no,name) for robust viewer rendering
  function buildLineupPlayersFor(teamState) {
    const rawLineup = teamState.lineup || [];
    const lineup = rawLineup.slice();
    while (lineup.length < ACTIVE_LINEUP_SIZE) lineup.push(null);
    const players = teamState.players || [];
    return lineup.map((slotId, idx) => {
      if (slotId && typeof slotId === 'string') {
        const p = players.find(x => x.id === slotId);
        return { id: slotId, no: p ? (p.no || '') : '', name: p ? (p.name || '') : '' };
      }
      return { id: null, no: '', name: '' };
    });
  }

  return {
    teamA: {
      name:    state.teamA.name,
      score:   scoreA,
      timeout: state.teamA.timeout,
      set:     state.shared.set,
      // Pad to minimum ACTIVE_LINEUP_SIZE but never truncate beyond it —
      // preserves any extra slots added in the future without data loss.
      lineup:  (function(l){ var a = (l||[]).slice(); while(a.length < ACTIVE_LINEUP_SIZE) a.push(null); return a; })(state.teamA.lineup),
      lineupPlayers: buildLineupPlayersFor(state.teamA),
      players: state.teamA.players.map(p => ({
        id: p.id, no: p.no, name: p.name,
        pts: p.pts, spike: p.spike, ace: p.ace, exSet: p.exSet, exDig: p.exDig, blk: p.blk || 0
      }))
    },
    teamB: {
      name:    state.teamB.name,
      score:   scoreB,
      timeout: state.teamB.timeout,
      set:     state.shared.set,
      lineup:  (function(l){ var a = (l||[]).slice(); while(a.length < ACTIVE_LINEUP_SIZE) a.push(null); return a; })(state.teamB.lineup),
      lineupPlayers: buildLineupPlayersFor(state.teamB),
      players: state.teamB.players.map(p => ({
        id: p.id, no: p.no, name: p.name,
        pts: p.pts, spike: p.spike, ace: p.ace, exSet: p.exSet, exDig: p.exDig, blk: p.blk || 0
      }))
    },
    shared: { ...state.shared },
    committee,
    // ✅ SSOT SAFE ADD START — Patch A: version timestamp for multi-admin conflict resolution
    _ssot_ts: Date.now(),
    _ssot_client: CLIENT_ID
    // ✅ SSOT SAFE ADD END
  };
}

// Validate that both teams have ACTIVE_LINEUP_SIZE named players in their active lineup
function validateLineupForSave() {
  try {
    const payload = buildStatePayload();
    const a = payload.teamA.lineupPlayers || [];
    const b = payload.teamB.lineupPlayers || [];
    const okA = a.filter(x => x && x.name && String(x.name).trim().length > 0).length >= ACTIVE_LINEUP_SIZE;
    const okB = b.filter(x => x && x.name && String(x.name).trim().length > 0).length >= ACTIVE_LINEUP_SIZE;
    if (!okA || !okB) {
      showToast('Active lineup must contain ' + ACTIVE_LINEUP_SIZE + ' players with names for BOTH teams before saving.');
      return false;
    }
    return true;
  } catch (e) { return false; }
}

function broadcastState() {
  try {
    const payload = buildStatePayload();
    const mid = getMatchId() || 0;
    const wrapper = { match_id: mid, payload };
    // Write canonical wrapper to localStorage immediately (instant local render)
    try {
      const s = JSON.stringify(wrapper);
      try { localStorage.setItem(_VB_STORAGE_KEY, s); } catch (_) {}
    } catch (_) {}
    // Broadcast in-process (same-browser) so other tabs update instantly
    if (_vbBC) try { _vbBC.postMessage(wrapper); } catch (_) {}

    // Primary real-time: emit authoritative volleyball_state over WS immediately
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        try {
          // ensure timestamp/client present
          payload._ssot_ts = payload._ssot_ts || Date.now();
          payload._ssot_client = payload._ssot_client || CLIENT_ID;
          _wsLastAppliedTs = payload._ssot_ts || 0; // avoid re-applying our own broadcast
          _ws.send(JSON.stringify({ type: 'volleyball_state', sport: 'volleyball', match_id: mid, payload: payload, meta: { clientId: CLIENT_ID, ts: Date.now() } }));
        } catch (_) {}
        // Also send an 'action' envelope for legacy relay handlers (non-blocking)
        try { _ws.send(JSON.stringify({ type: 'action', sport: 'volleyball', match_id: mid, payload: payload, meta: { clientId: CLIENT_ID, ts: Date.now() } })); } catch(_) {}
      }
    } catch (_) {}

    // Persist to server (non-blocking from UI perspective)
    schedulePersistToServer(payload);
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  LOAD PERSISTED STATE
// ═══════════════════════════════════════════════════════
function loadPersistedState() {
  try {
    const raw = localStorage.getItem(_VB_STORAGE_KEY);
    if (!raw) return;
    var parsed = JSON.parse(raw);
    // If the stored snapshot is for a different match, ignore it
    if (parsed && parsed.match_id && window.__matchId && String(parsed.match_id) !== String(window.__matchId)) return;
    const data = parsed && parsed.payload ? parsed.payload : parsed;
    if (!data) return;

    if (data.teamA) {
      state.teamA.name    = data.teamA.name    || state.teamA.name;
      state.teamA.timeout = typeof data.teamA.timeout === 'number' ? data.teamA.timeout : state.teamA.timeout;
      state.teamA.score   = typeof data.teamA.score === 'number' ? data.teamA.score : null;
      if (Array.isArray(data.teamA.lineup)) {
        state.teamA.lineup = data.teamA.lineup.slice();
        while (state.teamA.lineup.length < ACTIVE_LINEUP_SIZE) state.teamA.lineup.push(null);
      } else if (Array.isArray(data.teamA.lineupPlayers)) {
        state.teamA.lineup = data.teamA.lineupPlayers.map(lp => lp && lp.id ? lp.id : null);
        while (state.teamA.lineup.length < ACTIVE_LINEUP_SIZE) state.teamA.lineup.push(null);
      }
    }
    if (data.teamB) {
      state.teamB.name    = data.teamB.name    || state.teamB.name;
      state.teamB.timeout = typeof data.teamB.timeout === 'number' ? data.teamB.timeout : state.teamB.timeout;
      state.teamB.score   = typeof data.teamB.score === 'number' ? data.teamB.score : null;
      if (Array.isArray(data.teamB.lineup)) {
        state.teamB.lineup = data.teamB.lineup.slice();
        while (state.teamB.lineup.length < ACTIVE_LINEUP_SIZE) state.teamB.lineup.push(null);
      } else if (Array.isArray(data.teamB.lineupPlayers)) {
        state.teamB.lineup = data.teamB.lineupPlayers.map(lp => lp && lp.id ? lp.id : null);
        while (state.teamB.lineup.length < ACTIVE_LINEUP_SIZE) state.teamB.lineup.push(null);
      }
    }
    if (data.shared) {
      if (typeof data.shared.set === 'number') state.shared.set = data.shared.set;
    }

    state.teamA.players = [];
    state.teamB.players = [];
    pCount = { A: 0, B: 0 };

    if (data.teamA && Array.isArray(data.teamA.players)) {
      data.teamA.players.forEach(p => {
        if (!p.id) { pCount.A++; p.id = 'pA' + pCount.A; }
        p.selected = false;
        state.teamA.players.push(Object.assign({ pts:0,spike:0,ace:0,exSet:0,exDig:0,blk:0 }, p));
      });
    }
    state.teamA.score = typeof state.teamA.score === 'number' ? state.teamA.score : state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
    if (data.teamB && Array.isArray(data.teamB.players)) {
      data.teamB.players.forEach(p => {
        if (!p.id) { pCount.B++; p.id = 'pB' + pCount.B; }
        p.selected = false;
        state.teamB.players.push(Object.assign({ pts:0,spike:0,ace:0,exSet:0,exDig:0,blk:0 }, p));
      });
    }
    state.teamB.score = typeof state.teamB.score === 'number' ? state.teamB.score : state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);

    // Restore committee
    try {
      if (data.committee && document.getElementById('vbCommitteeInput')) {
        document.getElementById('vbCommitteeInput').value = data.committee;
      }
    } catch (_) {}

    // Render DOM
    try {
      const nameAEl = document.getElementById('teamAName');
      const nameBEl = document.getElementById('teamBName');
      if (nameAEl) nameAEl.value = state.teamA.name;
      if (nameBEl) nameBEl.value = state.teamB.name;
      ['A','B'].forEach(t => {
        const lbl = document.getElementById('label'+t);
        if (lbl) lbl.textContent = state['team'+t].name;
        const tbody = document.getElementById('tbody'+t);
        if (tbody) tbody.innerHTML = '';
        state['team'+t].players.forEach(p => renderRow(t, p));
        recalcScore(t);
      });
      // Restore counters
      ['setVal','tsbA_set','tsbB_set'].forEach(id => {
        const el = document.getElementById(id); if (el) el.textContent = state.shared.set;
      });
      ['A','B'].forEach(t => {
        const v = state['team'+t].timeout;
        ['tsb'+t+'_timeout','timeout'+t].forEach(id => {
          const el = document.getElementById(id); if (el) el.textContent = v;
        });
      });
      // Restore lineup selects and circles
      refreshLineupSelects('A');
      refreshLineupSelects('B');
    } catch (e) { /* ignore render failures */ }
  } catch (_) {}
}

async function loadStateFromServerIfMissing() {
  try {
    const mid = getMatchId();
    const fetchMid = (mid && mid !== '0') ? mid : 0;

    // ALWAYS fetch from server on every page load — never skip because localStorage has data.
    // This is the SSOT guarantee: server state is authoritative. localStorage is only a
    // render-speed cache and must never prevent a fresh server fetch on load.
    const res = await fetch('state.php?match_id=' + encodeURIComponent(fetchMid) + '&t=' + Date.now(), { cache: 'no-store', credentials: 'include' });
    if (!res || !res.ok) return;
    const j = await res.json();
    if (!j || !j.success || !j.payload) return;

    // Prefer the server-provided match_id when writing the canonical wrapper.
    try {
      const writeMid = (j.match_id !== undefined && j.match_id !== null) ? String(j.match_id) : String(fetchMid);
      const s = JSON.stringify({ match_id: writeMid, payload: j.payload });
      localStorage.setItem(_VB_STORAGE_KEY, s);
      // If server indicates an authoritative match_id, store it in session so
      // future getMatchId() calls and navigations will use the DB-backed match.
      if (writeMid && String(writeMid) !== '0') {
        try { sessionStorage.setItem('volleyball_match_id', String(writeMid)); } catch (_) {}
        try { window.__matchId = String(writeMid); } catch (_) {}
      }
      // Broadcast the loaded server state locally so other admin tabs update too.
      if (_vbBC) {
        try { _vbBC.postMessage({ match_id: writeMid, payload: j.payload }); } catch (_) {}
      }
    } catch (_) {}

    // Apply to admin UI — server state fully replaces any locally-cached state.
    loadPersistedState();

    // Reset the persist-guard timestamp so the next local action always writes
    // through, even if the server payload has an older _ssot_ts (written by a
    // different admin). Without this, multi-admin state would block each other.
    _lastPersistedTs = 0;
    _setServerSyncStatus('ok');
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  MULTI-ADMIN SERVER POLL (cross-device sync fallback)
// ═══════════════════════════════════════════════════════
// When WS is unavailable, admins on different devices sync via periodic poll.
// Only applies server state when it is newer than what this client last wrote,
// preventing us from overwriting our own recent changes with a stale server copy.
let _adminPollSerializedLast = null;
let _adminPollCount = 0;
async function _adminPollServerState() {
  try {
    const mid = getMatchId();
    if (mid && mid !== '0') {
      const res = await fetch('state.php?match_id=' + encodeURIComponent(mid) + '&t=' + Date.now(), { cache: 'no-store', credentials: 'include' });
      const j = await res.json();
      if (j && j.success && j.payload) {
        const payload = j.payload;
        // Only apply if from a different client and newer than our last write
        if (!(payload._ssot_client && payload._ssot_client === CLIENT_ID)) {
          const serverTs = payload._ssot_ts || 0;
          if (!serverTs || serverTs > _lastPersistedTs) {
            const serialized = JSON.stringify(payload);
            if (serialized !== _adminPollSerializedLast) {
              _adminPollSerializedLast = serialized;
              try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify({ match_id: mid, payload })); } catch (_) {}
              loadPersistedState();
              _lastPersistedTs = serverTs || 0;
              _setServerSyncStatus('ok');
            }
          }
        }
      }
    }
  } catch (_) {}
  _adminPollCount++;
  // Fast for first 10 polls (10s total), then every 5s
  const delay = _adminPollCount <= 10 ? 1000 : 5000;
  setTimeout(_adminPollServerState, delay);
}
// Start the poll after the initial server load completes (100ms grace period)
setTimeout(_adminPollServerState, 100);
let _persistTimer = null;
let _persistPending = false;
// ✅ SSOT SAFE ADD START — Patch C: last-write-wins guard variables
let _lastPersistedTs = 0;
function _ssotShouldPersist(payload) {
  try {
    const ts = payload && payload._ssot_ts ? payload._ssot_ts : Date.now();
    if (ts < _lastPersistedTs) return false; // stale — a newer persist already went out
    _lastPersistedTs = ts;
    return true;
  } catch(_) { return true; } // fail-open: always persist on unexpected error
}
// ✅ SSOT SAFE ADD END
function schedulePersistToServer(payload) {
  try {
    _persistPending = true;
    const mid = getMatchId() || 0; // use 0 to persist to pending file when no match exists yet
    clearTimeout(_persistTimer);
    _persistTimer = setTimeout(() => {
      _persistTimer = null;
      _persistPending = false;
      // ✅ SSOT SAFE ADD START — Patch C: skip stale writes from concurrent admins
      if (!_ssotShouldPersist(payload)) return;
      // ✅ SSOT SAFE ADD END
      _pushToServer(mid, payload);
    }, 250); // reduced from 400ms for faster cross-device updates
  } catch (_) {}
}

function _pushToServer(mid, payload) {
  try {
    const extraHeaders = { 'Content-Type': 'application/json' };
    try {
      if (window && window.__userId) extraHeaders['X-SS-UID'] = String(window.__userId);
      if (window && window.__role)   extraHeaders['X-SS-ROLE'] = String(window.__role);
    } catch (_) {}

    fetch('state.php', {
      method: 'POST',
      headers: extraHeaders,
      // 'include' sends session cookies even when the fetch URL differs slightly from page URL
      credentials: 'include',
      body: JSON.stringify({ match_id: mid, payload }),
      keepalive: true
    }).then(r => {
      if (r && r.ok) {
        _setServerSyncStatus('ok');
        // Broadcast the saved state to viewers immediately after successful server save
        _broadcastAuthoritativeState(mid, payload);
      }
      else {
        // Log the actual error for debugging
        r.json().then(j => console.warn('[state sync] POST failed:', j)).catch(() => {});
        _setServerSyncStatus('error');
      }
    }).catch(() => { _setServerSyncStatus('error'); });
  } catch (_) { _setServerSyncStatus('error'); }
}

// Status indicator for server sync (distinct from WS)
function _setServerSyncStatus(s) {
  try {
    let el = document.getElementById('serverSyncStatus');
    if (!el) {
      el = document.createElement('div');
      el.id = 'serverSyncStatus';
      Object.assign(el.style, {
        position: 'fixed', left: '12px', bottom: '12px',
        padding: '4px 10px', borderRadius: '5px', fontSize: '11px',
        zIndex: '9998', fontFamily: 'sans-serif', transition: 'background 0.3s'
      });
      document.body.appendChild(el);
    }
    if (s === 'ok') {
      el.style.background = '#dff0d8'; el.style.color = '#155724';
      el.textContent = '✓ Live (cross-device sync on)';
    } else {
      el.style.background = '#f8d7da'; el.style.color = '#721c24';
      el.textContent = '✗ Sync error — viewers may be delayed';
    }
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  FLUSH ON UNLOAD
// ═══════════════════════════════════════════════════════
function flushStateOnUnload() {
  try {
    const payload = buildStatePayload();
    const mid = getMatchId() || 0;
    const wrapper = { match_id: mid, payload };
    try {
      const s = JSON.stringify(wrapper);
      try { const prev = localStorage.getItem(_VB_STORAGE_KEY); if (prev !== s) localStorage.setItem(_VB_STORAGE_KEY, s); } catch (_) { localStorage.setItem(_VB_STORAGE_KEY, s); }
    } catch (_) {}
    try { if (_vbBC) _vbBC.postMessage(wrapper); } catch (_) {}
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        _ws.send(JSON.stringify({ type: 'volleyball_state', sport: 'volleyball', match_id: getMatchId(), payload }));
      }
    } catch (_) {}
    // Always flush to server on unload so cross-device viewers get the final state
    try {
      const mid = getMatchId() || 0;
      const body = JSON.stringify({ match_id: mid, payload });
      if (navigator.sendBeacon) {
        // sendBeacon can't set credentials; use fetch with keepalive as primary
        try {
          fetch('state.php', {
            method: 'POST', body, keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include'
          }).catch(() => {});
        } catch (_) {}
        // sendBeacon as backup (no auth header, but state.php allows it server-side if session cookie works)
        try { navigator.sendBeacon('state.php', new Blob([body], { type: 'application/json' })); } catch (_) {}
      } else {
        try { fetch('state.php', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'include' }).catch(() => {}); } catch (_) {}
      }
    } catch (_) {}
  } catch (_) {}
}
window.addEventListener('pagehide',    flushStateOnUnload);
window.addEventListener('beforeunload', flushStateOnUnload);

// ═══════════════════════════════════════════════════════
//  WS STATUS INDICATOR
// ═══════════════════════════════════════════════════════
function _ensureWSIndicator() {
  try {
    if (window.__wsStatusDismissed) return;
    if (document.getElementById('wsStatus')) return;
    const bar = document.createElement('div');
    bar.id = 'wsStatus';
    Object.assign(bar.style, { position:'fixed', right:'12px', bottom:'12px', padding:'6px 10px', borderRadius:'6px', background:'#ddd', color:'#111', fontSize:'12px', zIndex:'9999', display:'flex', alignItems:'center' });
    const label = document.createElement('span'); label.id = 'wsStatusLabel'; label.textContent = 'WS: connecting'; label.style.marginRight = '8px';
    const closeBtn = document.createElement('button'); closeBtn.type = 'button'; closeBtn.textContent = '✕'; closeBtn.style.cssText = 'border:none;background:transparent;cursor:pointer;font-size:12px;'; closeBtn.onclick = () => { window.__wsStatusDismissed = true; const el = document.getElementById('wsStatus'); if (el) el.remove(); };
    bar.appendChild(label); bar.appendChild(closeBtn); document.body.appendChild(bar);
  } catch (_) {}
}
function _setWSStatus(s) {
  try {
    if (window.__wsStatusDismissed) return;
    _ensureWSIndicator();
    const label = document.getElementById('wsStatusLabel');
    const el    = document.getElementById('wsStatus');
    if (!el || !label) return;
    if (s === 'connected')    { el.style.background = '#dff0d8'; label.style.color = '#155724'; label.textContent = 'WS: connected'; }
    else if (s === 'disconnected') { el.style.background = '#f8d7da'; label.style.color = '#721c24'; label.textContent = 'WS: disconnected'; }
    else if (s === 'error')   { el.style.background = '#fce5cd'; label.style.color = '#7a4100'; label.textContent = 'WS: error'; }
    else { el.style.background = '#e2e3e5'; label.style.color = '#383d41'; label.textContent = 'WS: ' + String(s); }
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  TOAST
// ═══════════════════════════════════════════════════════
function showToast(title, subtitle) {
  try {
    const id = 'vbSaveToast';
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div'); el.id = id;
      Object.assign(el.style, { position:'fixed', right:'18px', top:'18px', zIndex:'99999', minWidth:'260px' });
      document.body.appendChild(el);
    }
    const card = document.createElement('div');
    Object.assign(card.style, { background:'#0f0f0f', color:'#fff', border:'1px solid #222', padding:'12px 14px', marginTop:'8px', borderRadius:'6px', boxShadow:'0 6px 18px rgba(0,0,0,0.6)' });
    const t = document.createElement('div'); t.textContent = title; t.style.fontWeight = '700';
    card.appendChild(t);
    if (subtitle) { const s = document.createElement('div'); s.textContent = subtitle; s.style.cssText = 'opacity:0.85;font-size:13px;margin-top:6px'; card.appendChild(s); }
    el.appendChild(card);
    setTimeout(() => { card.style.transition = 'opacity 300ms,transform 300ms'; card.style.opacity = '0'; card.style.transform = 'translateY(-6px)'; setTimeout(() => card.remove(), 350); }, 4200);
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  COMMITTEE INPUT BROADCAST
// ═══════════════════════════════════════════════════════
(function () {
  const ci = document.getElementById('vbCommitteeInput');
  if (ci) ci.addEventListener('input', broadcastState);
})();

// ═══════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════
// Apply view mode and lineup selects immediately (UI chrome only — no data yet)
try { applyViewMode(); } catch (_) {}
try { refreshLineupSelects('A'); refreshLineupSelects('B'); } catch (_) {}

// SSOT-first init: always fetch server state before rendering any data.
// loadPersistedState() is intentionally NOT called here directly — it will be
// called by loadStateFromServerIfMissing() AFTER the server payload is written
// to localStorage, guaranteeing the rendered state is always server-authoritative.
// If the server fetch fails, fall back to localStorage as a last resort.
window.__ssotInitBroadcastDone = false;
(function _ssotDeferBroadcast() {
  try {
    var _ssotPromise;
    try { _ssotPromise = loadStateFromServerIfMissing(); } catch(_) { _ssotPromise = Promise.resolve(); }
    Promise.resolve(_ssotPromise).then(function() {
      // If server fetch returned nothing (offline / no state yet), fall back to localStorage
      try {
        if (!state.teamA.players.length && !state.teamB.players.length) {
          try { loadPersistedState(); } catch(_) {}
        }
      } catch(_) {}
      if (!window.__ssotInitBroadcastDone) {
        window.__ssotInitBroadcastDone = true;
        try { if (state.teamA.players.length || state.teamB.players.length) broadcastState(); } catch(_) {}
      }
    }).catch(function() {
      // On any fetch error fall back to localStorage so the page is not blank
      try { loadPersistedState(); } catch(_) {}
      if (!window.__ssotInitBroadcastDone) {
        window.__ssotInitBroadcastDone = true;
        try { if (state.teamA.players.length || state.teamB.players.length) broadcastState(); } catch(_) {}
      }
    });
  } catch(_) {
    try { loadPersistedState(); } catch(_) {}
    if (!window.__ssotInitBroadcastDone) {
      window.__ssotInitBroadcastDone = true;
      try { if (state.teamA.players.length || state.teamB.players.length) broadcastState(); } catch(_) {}
    }
  }
})();
// Announce sport selection so viewers can auto-switch to volleyball
function broadcastSportChange(sport) {
  try {
    const mid = getMatchId();
    const payload = { sport: sport };
    if (_vbBC) try { _vbBC.postMessage({ type: 'sport_change', match_id: mid, sport, payload }); } catch (_) {}
    try { if (_ws && _ws.readyState === WebSocket.OPEN) _ws.send(JSON.stringify({ type: 'sport_change', match_id: mid, sport: sport, payload })); } catch (_) {}
    try { localStorage.setItem('_last_sport', JSON.stringify({ match_id: mid, sport })); } catch (_) {}
  } catch (_) {}
}
try { broadcastSportChange('volleyball'); } catch (_) {}

// On every pageshow (including back-button bfcache restores), re-fetch server
// state so returning admins always see the current SSOT, not a stale snapshot.
window.addEventListener('pageshow', function (e) {
  try {
    // Handle post-save redirect cleanup first
    if (sessionStorage.getItem('shouldClearPersistedOnBack:volleyball') === '1') {
      sessionStorage.removeItem('shouldClearPersistedOnBack:volleyball');
      try { localStorage.removeItem(_VB_STORAGE_KEY); } catch (err) {}
      try { localStorage.removeItem('volleyball_viewMode'); } catch (err) {}
      if (e && e.persisted) { window.location.reload(); return; }
    }
    // Always re-hydrate from server on pageshow so navigation-return resets are eliminated.
    // e.persisted = true means page was restored from bfcache (back button) and JS state
    // may be completely stale — a full server re-fetch is mandatory.
    _lastPersistedTs = 0; // reset guard so poll / push work normally after rehydration
    loadStateFromServerIfMissing().catch(function() {});
  } catch (err) {}
});