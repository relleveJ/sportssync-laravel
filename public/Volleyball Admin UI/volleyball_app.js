// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
// Number of active lineup slots per team (changed from 5 to 6)
const ACTIVE_LINEUP_SIZE = 6;

const state = {
  teamA: { name: 'TEAM A', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null) },
  teamB: { name: 'TEAM B', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null) },
  shared: { set: 1 }
};
const VB_STATS = ['pts', 'spike', 'ace', 'exSet', 'exDig'];
const VB_STAT_LABELS = { pts: 'PTS', spike: 'SPIKE', ace: 'ACE', exSet: 'EX SET', exDig: 'EX DIG' };
let pCount = { A: 0, B: 0 };

// ═══════════════════════════════════════════════════════
//  BROADCAST SETUP
// ═══════════════════════════════════════════════════════
const _VB_STORAGE_KEY  = 'volleyballLiveState';
const _VB_CHANNEL_NAME = 'volleyball_live';
let _vbBC = null;
try { _vbBC = new BroadcastChannel(_VB_CHANNEL_NAME); } catch (_) {}

// ═══════════════════════════════════════════════════════
//  WEBSOCKET
// ═══════════════════════════════════════════════════════
let _ws = null;
try {
  if (location && location.hostname) {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let url = proto + '//' + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _ws = new WebSocket(url);
    _ws.addEventListener('open', () => {
      _setWSStatus('connected');
      try { _ws.send(JSON.stringify({ type: 'join', match_id: getMatchId() })); } catch (_) {}
    });
    _ws.addEventListener('close', () => { _setWSStatus('disconnected'); });
    _ws.addEventListener('error', () => { _setWSStatus('error'); });
  }
} catch (_) { _ws = null; }

function getMatchId() {
  try {
    if (window.__matchId) return String(window.__matchId);
    const el = document.getElementById('matchId');
    if (el) return String(el.value || el.textContent || '').trim() || null;
    return sessionStorage.getItem('volleyball_match_id') || null;
  } catch (_) { return null; }
}

// ═══════════════════════════════════════════════════════
//  LIVE SCORE
// ═══════════════════════════════════════════════════════
function recalcScore(team) {
  const total = state['team' + team].players.reduce((s, p) => s + (p.pts || 0), 0);
  const el = document.getElementById('score' + team);
  if (el) {
    el.textContent = total;
    el.style.transform = 'scale(1.22)';
    setTimeout(() => { el.style.transform = 'scale(1)'; }, 140);
  }
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

// ═══════════════════════════════════════════════════════
//  ADD PLAYER
// ═══════════════════════════════════════════════════════
function addPlayer(team) {
  pCount[team]++;
  const id = 'p' + team + pCount[team];
  const p = { id, no: '', name: '', pts: 0, spike: 0, ace: 0, exSet: 0, exDig: 0, selected: false };
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
  VB_STATS.forEach(stat => {
    const td = document.createElement('td');
    if (stat === 'pts') td.className = 'pts-cell';
    const wrap = document.createElement('div');
    wrap.className = 'stat-cell';

    const vSpan = document.createElement('span');
    vSpan.className = 'stat-val';
    vSpan.textContent = p[stat];

    const bM = document.createElement('button');
    bM.className = 'sbtn minus'; bM.textContent = '−';
    bM.onclick = () => {
      if (p[stat] > 0) {
        p[stat]--;
        vSpan.textContent = p[stat];
        if (stat === 'pts') recalcScore(team);
        broadcastState();
      }
    };

    const bP = document.createElement('button');
    bP.className = 'sbtn plus'; bP.textContent = '+';
    bP.onclick = () => {
      p[stat]++;
      vSpan.textContent = p[stat];
      if (stat === 'pts') recalcScore(team);
      broadcastState();
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

  // player chips around the ring (dynamic count)
  const chipR = 46; // distance from center
  const step = 360 / ACTIVE_LINEUP_SIZE;
  for (let i = 0; i < ACTIVE_LINEUP_SIZE; i++) {
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
    const n = ACTIVE_LINEUP_SIZE;
    const curr = Array.isArray(state['team' + team].lineup) ? state['team' + team].lineup.slice(0, n) : [];
    // roster ids in order
    const roster = (Array.isArray(state['team' + team].players) ? state['team' + team].players.map(p => p.id).filter(Boolean) : []);

    // Build ordered unique list: first take current lineup (preserve order), then append roster ids not already present
    const ordered = [];
    const seen = new Set();
    for (let id of curr) {
      if (id && !seen.has(id)) { ordered.push(id); seen.add(id); }
    }
    for (let id of roster) {
      if (!seen.has(id)) { ordered.push(id); seen.add(id); }
      if (ordered.length >= n) break;
    }
    // Pad if necessary
    while (ordered.length < n) ordered.push(null);

    // Rotate right by 1 (clockwise): new[0] = ordered[n-1], new[i] = ordered[i-1]
    const newLineup = Array(n);
    newLineup[0] = ordered[(n - 1) % ordered.length] || null;
    for (let i = 1; i < n; i++) newLineup[i] = ordered[i - 1] || null;

    // If roster contains >= n unique players, ensure no nulls by filling from roster
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
  const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
  const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
  const committee = document.getElementById('committeeInput')?.value?.trim() || '';
  const payload = {
    teamA: { ...state.teamA, score: scoreA },
    teamB: { ...state.teamB, score: scoreB },
    shared: state.shared,
    committee
  };

  const reportWin = window.open('', '_blank');

  try {
    const res = await fetch('volleyball_save_game.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      sessionStorage.setItem('volleyball_match_id', String(data.match_id));
      const reportUrl = 'volleyball_report.php?match_id=' + data.match_id;
      if (reportWin) {
        try { reportWin.location = reportUrl; reportWin.focus(); } catch (_) {}
      }
      showToast('✅ Match saved! ID: ' + data.match_id, 'Report opened.');
    } else {
      if (reportWin) reportWin.close();
      showToast('❌ Save failed: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    if (reportWin) reportWin.close();
    showToast('❌ Network error: ' + err.message);
  }
}

// ═══════════════════════════════════════════════════════
//  RESET MATCH
// ═══════════════════════════════════════════════════════
function resetMatch() {
  if (!confirm('Reset match? All local data will be cleared.')) return;

  state.teamA = { name: 'TEAM A', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null) };
  state.teamB = { name: 'TEAM B', players: [], timeout: 0, lineup: Array(ACTIVE_LINEUP_SIZE).fill(null) };
  state.shared = { set: 1 };
  pCount = { A: 0, B: 0 };

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
  broadcastState();
  showToast('Match reset — local data cleared');
}

// ═══════════════════════════════════════════════════════
//  BROADCAST STATE
// ═══════════════════════════════════════════════════════
function buildStatePayload() {
  const committee = document.getElementById('committeeInput')?.value?.trim() || '';
  const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
  const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
  return {
    teamA: {
      name:    state.teamA.name,
      score:   scoreA,
      timeout: state.teamA.timeout,
      set:     state.shared.set,
      lineup:  (state.teamA.lineup || []).slice(0, ACTIVE_LINEUP_SIZE).concat(Array(Math.max(0, ACTIVE_LINEUP_SIZE - (state.teamA.lineup || []).length)).fill(null)),
      players: state.teamA.players.map(p => ({
        id: p.id, no: p.no, name: p.name,
        pts: p.pts, spike: p.spike, ace: p.ace, exSet: p.exSet, exDig: p.exDig
      }))
    },
    teamB: {
      name:    state.teamB.name,
      score:   scoreB,
      timeout: state.teamB.timeout,
      set:     state.shared.set,
      lineup:  (state.teamB.lineup || []).slice(0, ACTIVE_LINEUP_SIZE).concat(Array(Math.max(0, ACTIVE_LINEUP_SIZE - (state.teamB.lineup || []).length)).fill(null)),
      players: state.teamB.players.map(p => ({
        id: p.id, no: p.no, name: p.name,
        pts: p.pts, spike: p.spike, ace: p.ace, exSet: p.exSet, exDig: p.exDig
      }))
    },
    shared: { ...state.shared },
    committee
  };
}

function broadcastState() {
  try {
    const payload = buildStatePayload();
    if (_vbBC) try { _vbBC.postMessage(payload); } catch (_) {}
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        _ws.send(JSON.stringify({ type: 'volleyball_state', match_id: getMatchId(), payload }));
      }
    } catch (_) {}
    setTimeout(() => {
      try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify(payload)); } catch (_) {}
    }, 0);
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
    const data = JSON.parse(raw);
    if (!data) return;

    if (data.teamA) {
      state.teamA.name    = data.teamA.name    || state.teamA.name;
      state.teamA.timeout = typeof data.teamA.timeout === 'number' ? data.teamA.timeout : state.teamA.timeout;
      if (Array.isArray(data.teamA.lineup)) {
        state.teamA.lineup = data.teamA.lineup.slice(0, ACTIVE_LINEUP_SIZE);
        while (state.teamA.lineup.length < ACTIVE_LINEUP_SIZE) state.teamA.lineup.push(null);
      }
    }
    if (data.teamB) {
      state.teamB.name    = data.teamB.name    || state.teamB.name;
      state.teamB.timeout = typeof data.teamB.timeout === 'number' ? data.teamB.timeout : state.teamB.timeout;
      if (Array.isArray(data.teamB.lineup)) {
        state.teamB.lineup = data.teamB.lineup.slice(0, ACTIVE_LINEUP_SIZE);
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
        state.teamA.players.push(Object.assign({ pts:0,spike:0,ace:0,exSet:0,exDig:0 }, p));
      });
    }
    if (data.teamB && Array.isArray(data.teamB.players)) {
      data.teamB.players.forEach(p => {
        if (!p.id) { pCount.B++; p.id = 'pB' + pCount.B; }
        p.selected = false;
        state.teamB.players.push(Object.assign({ pts:0,spike:0,ace:0,exSet:0,exDig:0 }, p));
      });
    }

    // Restore committee
    try {
      if (data.committee && document.getElementById('committeeInput')) {
        document.getElementById('committeeInput').value = data.committee;
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
    const raw = localStorage.getItem(_VB_STORAGE_KEY);
    if (raw) return;
    const mid = getMatchId();
    if (!mid) return;
    const res = await fetch('state.php?match_id=' + encodeURIComponent(mid));
    const j   = await res.json();
    if (j && j.success && j.payload) {
      try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify(j.payload)); } catch (_) {}
      loadPersistedState();
    }
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  SERVER PERSISTENCE (debounced)
// ═══════════════════════════════════════════════════════
let _persistTimer = null;
function schedulePersistToServer(payload) {
  try {
    const mid = getMatchId();
    if (!mid) return;
    clearTimeout(_persistTimer);
    _persistTimer = setTimeout(() => {
      _persistTimer = null;
      try {
        fetch('state.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ match_id: mid, payload }),
          keepalive: true
        }).catch(() => {});
      } catch (_) {}
    }, 400);
  } catch (_) {}
}

// ═══════════════════════════════════════════════════════
//  FLUSH ON UNLOAD
// ═══════════════════════════════════════════════════════
function flushStateOnUnload() {
  try {
    const payload = buildStatePayload();
    try { localStorage.setItem(_VB_STORAGE_KEY, JSON.stringify(payload)); } catch (_) {}
    try { if (_vbBC) _vbBC.postMessage(payload); } catch (_) {}
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        _ws.send(JSON.stringify({ type: 'volleyball_state', match_id: getMatchId(), payload }));
      }
    } catch (_) {}
    try {
      const mid = getMatchId();
      if (mid) {
        const body = JSON.stringify({ match_id: mid, payload });
        if (navigator.sendBeacon) {
          navigator.sendBeacon('state.php', new Blob([body], { type: 'application/json' }));
        } else {
          fetch('state.php', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => {});
        }
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
  const ci = document.getElementById('committeeInput');
  if (ci) ci.addEventListener('input', broadcastState);
})();

// ═══════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════
try { loadPersistedState(); } catch (_) {}
try { loadStateFromServerIfMissing(); } catch (_) {}
try { applyViewMode(); } catch (_) {}
try { refreshLineupSelects('A'); refreshLineupSelects('B'); } catch (_) {}
broadcastState();
