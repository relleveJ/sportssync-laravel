// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
const state = {
  teamA: { name:'TEAM A', players:[], foul:0, timeout:0 },
  teamB: { name:'TEAM B', players:[], foul:0, timeout:0 },
  shared: { foul:0, timeout:0, quarter:1 }
};
const STATS = ['pts','foul','reb','ast','blk','stl'];
let pCount = { A:0, B:0 };

// Previously the admin intentionally removed any persisted live state on load.
// Keep persisted state so admin page restores players and scores across reloads.
// (Loading occurs after BroadcastChannel / storage key is declared.)

// ═══════════════════════════════════════════════════════
//  LIVE SCORE
// ═══════════════════════════════════════════════════════
function recalcScore(team) {
  const total = state['team'+team].players.reduce((s,p) => s + p.pts, 0);
  const el = document.getElementById('score'+team);
  el.textContent = total;
  el.style.transform = 'scale(1.22)';
  setTimeout(() => { el.style.transform = 'scale(1)'; }, 140);
}

// ═══════════════════════════════════════════════════════
//  SHARED COUNTERS (two-sided mode right panel)
// ═══════════════════════════════════════════════════════
function adjustShared(key, delta) {
  state.shared[key] = Math.max(0, state.shared[key] + delta);
  const el = document.getElementById(key+'Val');
  if (el) {
    el.textContent = state.shared[key];
    el.style.transform = 'scale(1.2)';
    setTimeout(() => { el.style.transform = 'scale(1)'; }, 130);
  }
    // also update per-two-sided quarter display if present
    if (key === 'quarter') {
      const perQ = document.getElementById('per_quarterVal');
      if (perQ) perQ.textContent = state.shared.quarter;
      const qEls = [document.getElementById('quarterVal'), document.getElementById('per_quarterVal')];
      qEls.forEach(q => { if (q && q.style) { q.style.transform = 'scale(1.2)'; setTimeout(() => { if (q) q.style.transform = 'scale(1)'; }, 130); } });
    }
}

// ═══════════════════════════════════════════════════════
//  INLINE TEAM STATS BAR COUNTERS (one-sided mode)
// ═══════════════════════════════════════════════════════
function adjustTsb(team, key, delta) {
  // Quarter is a shared value; route quarter adjustments to shared state.
  if (key === 'quarter') {
    state.shared.quarter = Math.max(0, state.shared.quarter + delta);
    const elq = document.getElementById('quarterVal');
    if (elq) {
      elq.textContent = state.shared.quarter;
      if (elq.style) elq.style.transform = 'scale(1.25)';
      setTimeout(() => { if (elq && elq.style) elq.style.transform = 'scale(1)'; }, 130);
    }
    return;
  }
  state['team'+team][key] = Math.max(0, state['team'+team][key] + delta);
  const el = document.getElementById('tsb'+team+'_'+key);
  if (el) el.textContent = state['team'+team][key];
  if (el) el.style.transform = 'scale(1.25)';
  setTimeout(() => { if (el) el.style.transform = 'scale(1)'; }, 130);

  // Also update right-panel per-team counters if present
  const rightEl = document.getElementById('right_tsb'+team+'_'+key);
  if (rightEl) {
    rightEl.textContent = state['team'+team][key];
    if (rightEl.style) rightEl.style.transform = 'scale(1.25)';
    setTimeout(() => { if (rightEl && rightEl.style) rightEl.style.transform = 'scale(1)'; }, 130);
  }
}

// ═══════════════════════════════════════════════════════
//  TEAM NAME
// ═══════════════════════════════════════════════════════
function onTeamName(team) {
  const v = document.getElementById('team'+team+'Name').value;
  state['team'+team].name = v;
  document.getElementById('label'+team).textContent = v || ('TEAM '+team);
}

// ═══════════════════════════════════════════════════════
//  ADD PLAYER
// ═══════════════════════════════════════════════════════
function addPlayer(team) {
  pCount[team]++;
  const id = 'p'+team+pCount[team];
  const p = { id, no:'', name:'', pts:0, foul:0, reb:0, ast:0, blk:0, stl:0, techFoul:0, techReason:'', selected:false };
  state['team'+team].players.push(p);
  renderRow(team, p);
}

// ═══════════════════════════════════════════════════════
//  RENDER PLAYER ROW
// ═══════════════════════════════════════════════════════
function renderRow(team, p) {
  const tbody = document.getElementById('tbody'+team);

  /* ── MAIN ROW ── */
  const tr = document.createElement('tr');
  tr.className = 'player-main-row';
  tr.id = 'row_'+p.id;

  // Checkbox
  const tdCb = document.createElement('td');
  tdCb.className = 'player-cb-cell';
  const cb = document.createElement('input');
  cb.type = 'checkbox'; cb.className = 'player-cb';
  cb.checked = p.selected; cb.title = 'Select player';
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
  iNo.type='text'; iNo.value=p.no; iNo.placeholder='#'; iNo.maxLength=3;
  iNo.oninput = e => p.no = e.target.value;
  tdNo.appendChild(iNo); tr.appendChild(tdNo);

  // Name
  const tdNm = document.createElement('td');
  tdNm.className = 'td-name';
  const iNm = document.createElement('input');
  iNm.type='text'; iNm.value=p.name; iNm.placeholder='Player name';
  iNm.oninput = e => p.name = e.target.value;
  tdNm.appendChild(iNm); tr.appendChild(tdNm);

  // Stats
  STATS.forEach(stat => {
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
      }
    };

    const bP = document.createElement('button');
    bP.className = 'sbtn plus'; bP.textContent = '+';
    bP.onclick = () => {
      p[stat]++;
      vSpan.textContent = p[stat];
      if (stat === 'pts') recalcScore(team);
    };

    wrap.appendChild(bM); wrap.appendChild(vSpan); wrap.appendChild(bP);
    td.appendChild(wrap); tr.appendChild(td);
  });

  // TF display
  const tdTF = document.createElement('td');
  const tfDisplay = document.createElement('span');
  tfDisplay.className = 'stat-val';
  tfDisplay.style.color = '#e87c7c';
  tfDisplay.textContent = p.techFoul;
  tdTF.appendChild(tfDisplay); tr.appendChild(tdTF);

  // DEL
  const tdDel = document.createElement('td');
  const bDel = document.createElement('button');
  bDel.className = 'btn-del'; bDel.textContent = '✕'; bDel.title = 'Remove player';
  bDel.onclick = () => {
    const arr = state['team'+team].players;
    arr.splice(arr.findIndex(x => x.id === p.id), 1);
    tr.remove(); techTr.remove();
    recalcScore(team);
    syncSelectAll(team);
  };
  tdDel.appendChild(bDel); tr.appendChild(tdDel);
  tbody.appendChild(tr);

  /* ── TECH FOUL ROW ── */
  const techTr = document.createElement('tr');
  techTr.className = 'player-tech-row';
  techTr.id = 'techrow_'+p.id;

  const techTd = document.createElement('td');
  techTd.colSpan = 11; // +1 for checkbox column
  const inner = document.createElement('div');
  inner.className = 'tech-inner';

  const lbl = document.createElement('span');
  lbl.className = 'tech-label'; lbl.textContent = 'Tech Foul:';
  inner.appendChild(lbl);

  const ctr = document.createElement('div');
  ctr.className = 'tech-counter';

  const tfVal = document.createElement('span');
  tfVal.className = 'tech-count-val'; tfVal.textContent = p.techFoul;

  const tfM = document.createElement('button');
  tfM.className = 'tbtn minus'; tfM.textContent = '−';
  tfM.onclick = () => {
    if (p.techFoul > 0) {
      p.techFoul--;
      tfVal.textContent = p.techFoul;
      tfDisplay.textContent = p.techFoul;
    }
  };

  const tfP = document.createElement('button');
  tfP.className = 'tbtn plus'; tfP.textContent = '+';
  tfP.onclick = () => {
    p.techFoul++;
    tfVal.textContent = p.techFoul;
    tfDisplay.textContent = p.techFoul;
  };

  ctr.appendChild(tfM); ctr.appendChild(tfVal); ctr.appendChild(tfP);
  inner.appendChild(ctr);

  const reasonInp = document.createElement('input');
  reasonInp.type = 'text';
  reasonInp.className = 'tech-reason-input';
  reasonInp.placeholder = 'Reason / description of technical foul…';
  reasonInp.value = p.techReason;
  reasonInp.oninput = e => p.techReason = e.target.value;
  inner.appendChild(reasonInp);

  techTd.appendChild(inner);
  techTr.appendChild(techTd);
  tbody.appendChild(techTr);
}

// ═══════════════════════════════════════════════════════
//  GAME TIMER
// ═══════════════════════════════════════════════════════
let gtTotalSecs = 10 * 60;
let gtRemaining = 10 * 60;
let gtRunning   = false;
let gtInterval  = null; // legacy var kept for compatibility
let gtLastTick  = null;

const gtTimeEl   = document.getElementById('gtTime');
const gtBlock    = document.getElementById('gtBlock');
const gtPlayBtn  = document.getElementById('gtPlayBtn');
const gtPauseBtn = document.getElementById('gtPauseBtn');
const GT_DANGER  = 60;

function gtFmt(secs) {
  const m = Math.floor(secs / 60);
  const s = Math.floor(secs % 60);
  return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
function gtRender() {
  const expired = gtRemaining <= 0;
  gtTimeEl.textContent = gtFmt(gtRemaining);
  gtTimeEl.className   = 'gt-time' + (expired ? ' expired' : gtRemaining <= GT_DANGER ? ' danger' : '');
  gtBlock.className    = 'game-timer-block' +
    (expired ? ' gt-expired' : gtRunning && gtRemaining <= GT_DANGER ? ' gt-danger' : gtRunning ? ' gt-running' : '');
}
function gtTick() {
  if (!gtRunning) return;
  const now = performance.now();
  const dt  = (now - gtLastTick) / 1000;
  gtLastTick = now;
  gtRemaining = Math.max(0, gtRemaining - dt);
  gtRender();
  if (gtRemaining <= 0) {
    gtRunning = false;
    clearInterval(gtInterval); gtInterval = null;
    gtPlayBtn.disabled = true; gtPauseBtn.disabled = true;
    const orig = document.title; let f = 0;
    const fl = setInterval(() => {
      document.title = f++ % 2 === 0 ? '⏰ GAME OVER!' : orig;
      if (f >= 8) { clearInterval(fl); document.title = orig; }
    }, 450);
  }
}
function gtPlay() {
  if (gtRunning || gtRemaining <= 0) return;
  gtRunning = true;
  gtLastTick = null; // mainLoop will stamp on first frame, preventing stale-gap jumps
  ensureGtLoop();
  gtPlayBtn.disabled = true; gtPauseBtn.disabled = false;
  gtRender();
}
function gtPause() {
  if (!gtRunning) return;
  gtRunning = false;
  gtLastTick = null; // discard timestamp so resume never inherits a stale gap
  // aggressively stop any scheduled loops/intervals for game timer
  if (_gtLoopId) { cancelAnimationFrame(_gtLoopId); _gtLoopId = null; }
  if (gtInterval) { clearInterval(gtInterval); gtInterval = null; }
  gtPlayBtn.disabled = false; gtPauseBtn.disabled = true;
  gtRender();
}
function gtReset() {
  gtRunning = false;
  gtLastTick = null;
  gtRemaining = gtTotalSecs;
  // aggressively stop any scheduled loops/intervals for game timer
  if (_gtLoopId) { cancelAnimationFrame(_gtLoopId); _gtLoopId = null; }
  if (gtInterval) { clearInterval(gtInterval); gtInterval = null; }
  gtPlayBtn.disabled = false; gtPauseBtn.disabled = true;
  gtRender();
}
function gtSetDuration() {
  const mins  = parseInt(document.getElementById('gtInputMin').value) || 0;
  const secs  = parseInt(document.getElementById('gtInputSec').value) || 0;
  const total = Math.max(1, mins * 60 + secs);
  gtTotalSecs = total; gtRemaining = total;
  gtRunning  = false;
  gtLastTick = null;
  // stop gt loop when duration is set manually
  stopGtLoopIfIdle();
  gtPlayBtn.disabled = false; gtPauseBtn.disabled = true;
  gtRender();
}
gtRender();

// ═══════════════════════════════════════════════════════
//  SHOT CLOCK
// ═══════════════════════════════════════════════════════
const SC_CIRCUMFERENCE    = 2 * Math.PI * 52;
const SC_DANGER_THRESHOLD = 5;
let scPresetVal = 24, scTotal = 24, scRemaining = 24.0;
let scRunning = false, scInterval = null, scLastTick = null;

const scTimeEl   = document.getElementById('scTime');
const scTenthEl  = document.getElementById('scTenth');
const scRingEl   = document.getElementById('scRing');
const scBlock    = document.getElementById('scBlock');
const scPlayBtn  = document.getElementById('scPlayBtn');
const scPauseBtn = document.getElementById('scPauseBtn');

function scRenderFrame() {
  const secs = Math.ceil(scRemaining);
  const tenths = (scRemaining % 1).toFixed(1).slice(1);
  const expired = scRemaining <= 0;
  scTimeEl.textContent = expired ? '0' : secs;
  scTenthEl.textContent = (!expired && scRemaining < 10) ? tenths : '';
  scTimeEl.className = 'sc-time' + (expired ? ' expired' : scRemaining <= SC_DANGER_THRESHOLD ? ' danger' : '');
  const pct = Math.max(0, scRemaining / scTotal);
  const offset = SC_CIRCUMFERENCE * (1 - pct);
  scRingEl.style.strokeDashoffset = offset;
  scRingEl.style.stroke = expired ? '#e74c3c'
    : scRemaining <= SC_DANGER_THRESHOLD ? '#e74c3c'
    : scRemaining <= scTotal * 0.5 ? '#e67e22' : '#F5C518';
  scBlock.className = 'shot-clock-block' +
    (expired ? ' sc-expired' : scRunning && scRemaining <= SC_DANGER_THRESHOLD ? ' sc-danger' : scRunning ? ' sc-running' : '');
}
function scTick() {
  if (!scRunning) return;
  const now = performance.now();
  const dt = (now - scLastTick) / 1000;
  scLastTick = now;
  scRemaining = Math.max(0, scRemaining - dt);
  scRenderFrame();
  if (scRemaining <= 0) {
    scRunning = false;
    clearInterval(scInterval); scInterval = null;
    scPlayBtn.disabled = true; scPauseBtn.disabled = true;
    const orig = document.title; let flashes = 0;
    const fl = setInterval(() => {
      document.title = flashes++ % 2 === 0 ? '🔴 SHOT CLOCK!' : orig;
      if (flashes >= 6) { clearInterval(fl); document.title = orig; }
    }, 400);
  }
}
function scPlay() {
  if (scRunning || scRemaining <= 0) return;
  scRunning = true;
  scLastTick = null; // mainLoop will stamp on first frame, preventing stale-gap jumps
  ensureScLoop();
  scPlayBtn.disabled = true; scPauseBtn.disabled = false;
  scRenderFrame();
}
function scPause() {
  if (!scRunning) return;
  scRunning = false;
  scLastTick = null; // discard timestamp so resume never inherits a stale gap
  // aggressively stop any scheduled loops/intervals for shot clock
  if (_scLoopId) { cancelAnimationFrame(_scLoopId); _scLoopId = null; }
  if (scInterval) { clearInterval(scInterval); scInterval = null; }
  scPlayBtn.disabled = false; scPauseBtn.disabled = true;
  scRenderFrame();
}
function scReset() {
  scRunning = false;
  scLastTick = null;
  scRemaining = scTotal;
  // aggressively stop any scheduled loops/intervals for shot clock
  if (_scLoopId) { cancelAnimationFrame(_scLoopId); _scLoopId = null; }
  if (scInterval) { clearInterval(scInterval); scInterval = null; }
  scPlayBtn.disabled = false; scPauseBtn.disabled = true;
  scRenderFrame();
}
function scPreset(secs) {
  scPresetVal = secs; scTotal = secs;
  document.getElementById('preset24').classList.toggle('active', secs === 24);
  document.getElementById('preset14').classList.toggle('active', secs === 14);
  scReset();
}
scRenderFrame();

// ═══════════════════════════════════════════════════════
//  CHECKBOXES
// ═══════════════════════════════════════════════════════
function toggleSelectAll(team, masterCb) {
  const players = state['team'+team].players;
  players.forEach(p => { p.selected = masterCb.checked; });
  const tbody = document.getElementById('tbody'+team);
  tbody.querySelectorAll('.player-cb').forEach(cb => {
    cb.checked = masterCb.checked;
    const row = cb.closest('tr');
    if (row) row.classList.toggle('row-checked', masterCb.checked);
  });
}
function syncSelectAll(team) {
  const players = state['team'+team].players;
  const master  = document.getElementById('selectAll'+team);
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
  const arr = state['team'+team].players;
  const toDelete = arr.filter(p => p.selected);
  if (toDelete.length === 0) return;
  toDelete.forEach(p => {
    const mainRow = document.getElementById('row_'+p.id);
    const techRow = document.getElementById('techrow_'+p.id);
    if (mainRow) mainRow.remove();
    if (techRow) techRow.remove();
  });
  state['team'+team].players = arr.filter(p => !p.selected);
  recalcScore(team);
  syncSelectAll(team);
}

// ═══════════════════════════════════════════════════════
//  VIEW MODE (one-sided / two-sided)
// ═══════════════════════════════════════════════════════
// restore persisted view mode if present
let viewMode = 'two';
try {
  const sv = localStorage.getItem('basketball_viewMode');
  if (sv === 'one' || sv === 'two') viewMode = sv;
} catch (e) {}
let activeTab = 'A';

function toggleViewMode() {
  viewMode = viewMode === 'two' ? 'one' : 'two';
  try { localStorage.setItem('basketball_viewMode', viewMode); } catch (e) {}
  applyViewMode();
}

function applyViewMode() {
  const grid    = document.getElementById('mainGrid');
  const btn     = document.getElementById('viewToggleBtn');
  const panelA  = document.getElementById('panelA');
  const panelB  = document.getElementById('panelB');
  const sharedC = document.getElementById('sharedCounters');
  const sharedD = document.getElementById('sharedCounterDivider');
  const perTeamC = document.getElementById('perTeamCounters');

  if (viewMode === 'two') {
    grid.classList.remove('one-sided');
    btn.textContent = '⇄ Two-Sided';
    btn.classList.add('two-sided');
    panelA.classList.add('visible');
    panelB.classList.add('visible');
    // show per-team quick controls on the right panel in two-sided mode
    if (perTeamC) perTeamC.style.display = '';
    // quarter control stays visible in both modes
    if (sharedC) sharedC.style.display = 'none';
    if (sharedD) sharedD.style.display = 'none';
  } else {
    grid.classList.add('one-sided');
    btn.textContent = '⇆ One-Sided';
    btn.classList.remove('two-sided');
    panelA.classList.toggle('visible', activeTab === 'A');
    panelB.classList.toggle('visible', activeTab === 'B');
    highlightTab(activeTab);
    // hide per-team controls and show quarter-only area in one-sided
    if (perTeamC) perTeamC.style.display = 'none';
    if (sharedC) sharedC.style.display = ''; // show shared quarter control below shot clock
    if (sharedD) sharedD.style.display = '';
  }

  // keep right-panel counters in sync with state whenever view changes
  syncRightPanelCounters();
}

// Update right-panel elements from current state
function syncRightPanelCounters() {
  try {
    const rA_f = document.getElementById('right_tsbA_foul');
    const rA_t = document.getElementById('right_tsbA_timeout');
    const rB_f = document.getElementById('right_tsbB_foul');
    const rB_t = document.getElementById('right_tsbB_timeout');
    const qEl  = document.getElementById('quarterVal');
    const perQ = document.getElementById('per_quarterVal');
    const tsbA_f = document.getElementById('tsbA_foul');
    const tsbA_t = document.getElementById('tsbA_timeout');
    const tsbB_f = document.getElementById('tsbB_foul');
    const tsbB_t = document.getElementById('tsbB_timeout');
    const foulValEl = document.getElementById('foulVal');
    const timeoutValEl = document.getElementById('timeoutVal');

    if (rA_f) rA_f.textContent = state.teamA.foul;
    if (rA_t) rA_t.textContent = state.teamA.timeout;
    if (rB_f) rB_f.textContent = state.teamB.foul;
    if (rB_t) rB_t.textContent = state.teamB.timeout;
    if (qEl) qEl.textContent = state.shared.quarter;
    if (perQ) perQ.textContent = state.shared.quarter;
    if (tsbA_f) tsbA_f.textContent = state.teamA.foul;
    if (tsbA_t) tsbA_t.textContent = state.teamA.timeout;
    if (tsbB_f) tsbB_f.textContent = state.teamB.foul;
    if (tsbB_t) tsbB_t.textContent = state.teamB.timeout;
    // keep legacy shared displays (if any) in sync but they are hidden
    if (foulValEl) foulValEl.textContent = state.shared.foul;
    if (timeoutValEl) timeoutValEl.textContent = state.shared.timeout;
  } catch (e) { /* ignore */ }
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
//  SAVE FILE — posts game data to save_game.php
// ═══════════════════════════════════════════════════════
async function saveFile() {
  const scoreA = state.teamA.players.reduce((s,p) => s+p.pts, 0);
  const scoreB = state.teamB.players.reduce((s,p) => s+p.pts, 0);
  const committee = document.getElementById('committeeInput')?.value?.trim() || '';
  const payload = {
    teamA: { ...state.teamA, score: scoreA },
    teamB: { ...state.teamB, score: scoreB },
    shared: state.shared,
    committee
  };

  // Open a placeholder tab immediately to avoid popup blocking.
  const reportWin = window.open('', '_blank');
  if (!reportWin) {
    // Non-blocking in-page notice if popups blocked
    showToast('Please allow popups for this site so the match report can open automatically.');
  }

  try {
    const res = await fetch('save_game.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      const reportUrl = 'report.php?match_id=' + data.match_id;
      // attempt to navigate the placeholder window
      if (reportWin) {
        try { reportWin.location = reportUrl; reportWin.focus(); }
        catch (e) { /* ignore */ }
      }
      // Always show a toast with an explicit Open Report button as fallback
      showToast('✅ Game saved! Match ID: ' + data.match_id, 'Report opened (or use the button).', 'Open Report', reportUrl);
    } else {
      if (reportWin) reportWin.close();
      showToast('❌ Save failed: ' + data.error);
    }
  } catch (err) {
    if (reportWin) reportWin.close();
    showToast('❌ Network error: ' + err.message);
  }
}

// Reset the current match: clear state, DOM, localStorage and broadcast reset.
function resetMatch() {
  try {
    const ok = confirm('Warning: data can be lost. Do you want to reset the match?');
    if (!ok) return;

    // Stop timers and loops
    try { gtReset(); scReset(); } catch (e) {}
    gtRunning = false; scRunning = false;

    // Clear in-memory state
    state.teamA = { name: 'TEAM A', players: [], foul: 0, timeout: 0 };
    state.teamB = { name: 'TEAM B', players: [], foul: 0, timeout: 0 };
    state.shared = { foul: 0, timeout: 0, quarter: 1 };
    pCount = { A: 0, B: 0 };

    // Clear DOM player rows and inputs
    try { document.getElementById('tbodyA').innerHTML = ''; } catch (e) {}
    try { document.getElementById('tbodyB').innerHTML = ''; } catch (e) {}
    try { document.getElementById('teamAName').value = state.teamA.name; } catch (e) {}
    try { document.getElementById('teamBName').value = state.teamB.name; } catch (e) {}
    try { document.getElementById('labelA').textContent = state.teamA.name; } catch (e) {}
    try { document.getElementById('labelB').textContent = state.teamB.name; } catch (e) {}

    // Reset displayed counters and scores
    try { document.getElementById('tsbA_foul').textContent = '0'; } catch (e) {}
    try { document.getElementById('tsbA_timeout').textContent = '0'; } catch (e) {}
    try { document.getElementById('tsbB_foul').textContent = '0'; } catch (e) {}
    try { document.getElementById('tsbB_timeout').textContent = '0'; } catch (e) {}
    try { document.getElementById('scoreA').textContent = '0'; } catch (e) {}
    try { document.getElementById('scoreB').textContent = '0'; } catch (e) {}
    try { document.getElementById('quarterVal').textContent = '1'; } catch (e) {}
    try { document.getElementById('per_quarterVal').textContent = '1'; } catch (e) {}

    // Clear persisted storage
    try { localStorage.removeItem(_BK_STORAGE_KEY); } catch (e) {}

    // Broadcast empty/initial state to viewers
    try { broadcastState(); } catch (e) {}

    showToast('Match reset — local data cleared');
  } catch (err) {
    console.error('resetMatch error', err);
    showToast('Error resetting match');
  }
}

// Delete saved match rows from server DB. Calls delete_match.php.
async function deleteSavedMatch() {
  try {
    const matchId = getMatchId();
    if (!matchId) {
      alert('No saved match_id found for this session. Save the game first.');
      return;
    }
    const ok = confirm('Warning: this will PERMANENTLY delete the saved match and its players from the server database. Continue?');
    if (!ok) return;

    const res = await fetch('delete_match.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ match_id: String(matchId) })
    });
    const data = await res.json();
    if (data && data.success) {
      showToast('Saved match deleted from server.');
      // also clear local live state and DOM to avoid confusion
      resetMatch();
    } else {
      showToast('Delete failed: ' + (data && data.error ? data.error : 'Unknown error'));
    }
  } catch (e) {
    console.error('deleteSavedMatch error', e);
    showToast('Network error while deleting match');
  }
}

// ═══════════════════════════════════════════════════════
//  LIVE BROADCAST — feeds basketball_viewer.php instantly
//  Writes full state to localStorage + BroadcastChannel
//  Called automatically after every state-changing action.
// ═══════════════════════════════════════════════════════
const _BK_STORAGE_KEY  = 'basketballLiveState';
const _BK_CHANNEL_NAME = 'basketball_live';
let   _bkBC = null;
try { _bkBC = new BroadcastChannel(_BK_CHANNEL_NAME); } catch (_) {}

// Load persisted state from localStorage (if any) so admin UI restores
// players, team names, counters and timers across reloads.
function loadPersistedState() {
  try {
    const raw = localStorage.getItem(_BK_STORAGE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw);
    if (!data) return;

    // Restore team names/counters
    if (data.teamA) {
      state.teamA.name = data.teamA.name || state.teamA.name;
      state.teamA.foul = typeof data.teamA.foul === 'number' ? data.teamA.foul : state.teamA.foul;
      state.teamA.timeout = typeof data.teamA.timeout === 'number' ? data.teamA.timeout : state.teamA.timeout;
    }
    if (data.teamB) {
      state.teamB.name = data.teamB.name || state.teamB.name;
      state.teamB.foul = typeof data.teamB.foul === 'number' ? data.teamB.foul : state.teamB.foul;
      state.teamB.timeout = typeof data.teamB.timeout === 'number' ? data.teamB.timeout : state.teamB.timeout;
    }
    if (data.shared) {
      state.shared = Object.assign({}, state.shared, data.shared);
    }

    // restore players (clear existing if any)
    state.teamA.players = [];
    state.teamB.players = [];
    pCount = { A:0, B:0 };
    if (data.teamA && Array.isArray(data.teamA.players)) {
      data.teamA.players.forEach(p => {
        if (!p.id) { pCount.A++; p.id = 'pA' + pCount.A; }
        p.selected = !!p.selected;
        state.teamA.players.push(Object.assign({ pts:0,foul:0,reb:0,ast:0,blk:0,stl:0,techFoul:0,techReason:'' }, p));
      });
    }
    if (data.teamB && Array.isArray(data.teamB.players)) {
      data.teamB.players.forEach(p => {
        if (!p.id) { pCount.B++; p.id = 'pB' + pCount.B; }
        p.selected = !!p.selected;
        state.teamB.players.push(Object.assign({ pts:0,foul:0,reb:0,ast:0,blk:0,stl:0,techFoul:0,techReason:'' }, p));
      });
    }

    // Restore timers if present
    if (data.gameTimer) {
      if (typeof data.gameTimer.total === 'number') gtTotalSecs = data.gameTimer.total;
      if (typeof data.gameTimer.remaining === 'number') gtRemaining = data.gameTimer.remaining;
      gtRunning = !!data.gameTimer.running;
    }
    if (data.shotClock) {
      if (typeof data.shotClock.total === 'number') { scTotal = data.shotClock.total; scPresetVal = data.shotClock.total; }
      if (typeof data.shotClock.remaining === 'number') scRemaining = data.shotClock.remaining;
      scRunning = !!data.shotClock.running;
    }

    // committee input
    try { if (data.committee && document.getElementById('committeeInput')) document.getElementById('committeeInput').value = data.committee; } catch (e) {}

    // viewMode is stored separately; try to restore it too
    try { const vm = localStorage.getItem('basketball_viewMode'); if (vm === 'one' || vm === 'two') viewMode = vm; } catch (e) {}

    // Render DOM from restored state
    try {
      document.getElementById('teamAName').value = state.teamA.name;
      document.getElementById('teamBName').value = state.teamB.name;
      document.getElementById('labelA').textContent = state.teamA.name || 'TEAM A';
      document.getElementById('labelB').textContent = state.teamB.name || 'TEAM B';
      // clear tables then render players
      const tA = document.getElementById('tbodyA'); if (tA) tA.innerHTML = '';
      const tB = document.getElementById('tbodyB'); if (tB) tB.innerHTML = '';
      state.teamA.players.forEach(p => { renderRow('A', p); });
      state.teamB.players.forEach(p => { renderRow('B', p); });
      // update counters and scores
      syncRightPanelCounters();
      recalcScore('A'); recalcScore('B');
      gtRender(); scRenderFrame();
      // If timers are marked running, restart local loops to avoid pause on reload
      try {
        if (gtRunning) { gtPlayBtn.disabled = true; gtPauseBtn.disabled = false; ensureGtLoop(); }
        if (scRunning) { scPlayBtn.disabled = true; scPauseBtn.disabled = false; ensureScLoop(); }
      } catch (e) {}
    } catch (e) { /* ignore render failures */ }
  } catch (e) { /* invalid JSON or storage access denied */ }
}

// If no localStorage payload found, try loading canonical state from server
async function loadStateFromServerIfMissing() {
  try {
    const raw = localStorage.getItem(_BK_STORAGE_KEY);
    if (raw) return; // already have local state
    const mid = getMatchId();
    if (!mid) return;
    const res = await fetch('state.php?match_id=' + encodeURIComponent(mid));
    const j = await res.json();
    if (j && j.success && j.payload) {
      try { localStorage.setItem(_BK_STORAGE_KEY, JSON.stringify(j.payload)); } catch (e) {}
      // apply it
      try { const tmp = JSON.parse(JSON.stringify(j.payload)); scheduleRender(tmp); } catch (e) {}
      // also call loadPersistedState to ensure admin UI picks it up
      try { loadPersistedState(); } catch (e) {}
    }
  } catch (e) {}
}

// WebSocket relay (Option A): try connect to local WS server to broadcast
// admin updates to remote viewers across devices.
let _ws = null;
try {
  if (location && location.hostname) {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let url = proto + '//' + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _ws = new WebSocket(url);
    _ws.addEventListener('open', () => { console.info('Sportssync WS connected'); _setWSStatus('connected'); try { _ws.send(JSON.stringify({ type: 'join', match_id: getMatchId() })); } catch (e) {} });
    _ws.addEventListener('close', () => { console.info('Sportssync WS closed'); _setWSStatus('disconnected'); });
    _ws.addEventListener('error', () => { _setWSStatus('error'); /* ignore */ });
  }
} catch (_) { _ws = null; }

function getMatchId() {
  try {
    if (window.MATCH_DATA && MATCH_DATA.match_id) return String(MATCH_DATA.match_id);
    if (window.__matchId) return String(window.__matchId);
    const el = document.getElementById('matchId'); if (el) return String(el.value || el.textContent || '').trim() || null;
    return null;
  } catch (e) { return null; }
}

// Independent rAF loops for each timer to avoid cross-talk and ensure
// each timer is updated only by its own loop.
let _gtLoopId = null;
let _scLoopId = null;
let _lastBroadcastTime = 0;
const BROADCAST_THROTTLE_MS = 100; // send BC updates at most every 100ms

function tryThrottledBroadcast(now) {
  const since = now - (_lastBroadcastTime || 0);
  if (since >= BROADCAST_THROTTLE_MS) {
    _lastBroadcastTime = now;
    postImmediateTimerUpdate();
    broadcastTimerStateThrottled();
  }
}

function ensureGtLoop() {
  if (_gtLoopId) return;
  _gtLoopId = requestAnimationFrame(gtLoop);
}
function stopGtLoopIfIdle() {
  if (!_gtLoopId) return;
  if (!gtRunning) {
    cancelAnimationFrame(_gtLoopId);
    _gtLoopId = null;
  }
}
function gtLoop(now) {
  if (gtRunning) {
    if (gtLastTick === null) gtLastTick = now;
    const gtDt = Math.max(0, (now - gtLastTick) / 1000);
    gtLastTick = now;
    gtRemaining = Math.max(0, gtRemaining - gtDt);
    if (gtRemaining <= 0) {
      gtRunning = false;
      gtLastTick = null;
      if (gtPlayBtn)  gtPlayBtn.disabled  = true;
      if (gtPauseBtn) gtPauseBtn.disabled = true;
      const orig = document.title; let f = 0;
      const fl = setInterval(() => {
        document.title = f++ % 2 === 0 ? '\u23F0 GAME OVER!' : orig;
        if (f >= 8) { clearInterval(fl); document.title = orig; }
      }, 450);
    }
  }
  gtRender();
  tryThrottledBroadcast(now);
  if (gtRunning) _gtLoopId = requestAnimationFrame(gtLoop); else _gtLoopId = null;
}

function ensureScLoop() {
  if (_scLoopId) return;
  _scLoopId = requestAnimationFrame(scLoop);
}
function stopScLoopIfIdle() {
  if (!_scLoopId) return;
  if (!scRunning) {
    cancelAnimationFrame(_scLoopId);
    _scLoopId = null;
  }
}
function scLoop(now) {
  if (scRunning) {
    if (scLastTick === null) scLastTick = now;
    const scDt = Math.max(0, (now - scLastTick) / 1000);
    scLastTick = now;
    scRemaining = Math.max(0, scRemaining - scDt);
    if (scRemaining <= 0) {
      scRunning = false;
      scLastTick = null;
      if (scPlayBtn)  scPlayBtn.disabled  = true;
      if (scPauseBtn) scPauseBtn.disabled = true;
      const orig = document.title; let flashes = 0;
      const fl = setInterval(() => {
        document.title = flashes++ % 2 === 0 ? '\uD83D\uDD34 SHOT CLOCK!' : orig;
        if (flashes >= 6) { clearInterval(fl); document.title = orig; }
      }, 400);
    }
  }
  scRenderFrame();
  tryThrottledBroadcast(now);
  if (scRunning) _scLoopId = requestAnimationFrame(scLoop); else _scLoopId = null;
}

function broadcastState() {
  try {
    const payload = buildStatePayload();

    // Post to BroadcastChannel first (fast, non-blocking)
    if (_bkBC) {
      try { _bkBC.postMessage(payload); } catch (_) { /* ignore */ }
    }

    // Also send full state to WS relay so cross-device viewers receive/cache it
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        _ws.send(JSON.stringify({ type: 'state', match_id: getMatchId(), payload }));
      }
    } catch (_) {}

    // Defer localStorage write to next tick to avoid blocking timers/UI
    setTimeout(function () {
      try { localStorage.setItem(_BK_STORAGE_KEY, JSON.stringify(payload)); } catch (_) { }
    }, 0);

    // Persist canonical state to server (throttled via simple debounce)
    try {
      schedulePersistToServer(payload);
    } catch (_) {}
  } catch (_) {}
}

// Build the canonical full-state payload used for broadcasts and caching
function buildStatePayload() {
  const committee = document.getElementById('committeeInput')?.value?.trim() || '';
  const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
  const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
  return {
    teamA: {
      name: state.teamA.name,
      score: scoreA,
      foul: state.teamA.foul,
      timeout: state.teamA.timeout,
      quarter: state.shared.quarter,
      players: state.teamA.players.map(p => ({ id: p.id, no: p.no, name: p.name, pts: p.pts, foul: p.foul, reb: p.reb, ast: p.ast, blk: p.blk, stl: p.stl, techFoul: p.techFoul, techReason: p.techReason }))
    },
    teamB: {
      name: state.teamB.name,
      score: scoreB,
      foul: state.teamB.foul,
      timeout: state.teamB.timeout,
      quarter: state.shared.quarter,
      players: state.teamB.players.map(p => ({ id: p.id, no: p.no, name: p.name, pts: p.pts, foul: p.foul, reb: p.reb, ast: p.ast, blk: p.blk, stl: p.stl, techFoul: p.techFoul, techReason: p.techReason }))
    },
    shared: { ...state.shared },
    committee,
    gameTimer: {
      remaining: typeof gtRemaining !== 'undefined' ? gtRemaining : 0,
      total: typeof gtTotalSecs !== 'undefined' ? gtTotalSecs : 600,
      running: typeof gtRunning !== 'undefined' ? gtRunning : false
    },
    shotClock: {
      remaining: typeof scRemaining !== 'undefined' ? scRemaining : 24,
      total: typeof scTotal !== 'undefined' ? scTotal : 24,
      running: typeof scRunning !== 'undefined' ? scRunning : false
    }
  };
}

// Ensure viewers are notified if admin unloads/reloads — write synchronously
function flushStateOnUnload() {
  try {
    const payload = buildStatePayload();
    // Write synchronously to localStorage (storage event triggers in other tabs)
    try { localStorage.setItem(_BK_STORAGE_KEY, JSON.stringify(payload)); } catch (_) {}
    // Post to BroadcastChannel for same-browser immediate updates
    try { if (_bkBC) _bkBC.postMessage(payload); } catch (_) {}
    // Attempt to send via WS (may fail during unload)
    try { if (_ws && _ws.readyState === WebSocket.OPEN) _ws.send(JSON.stringify({ type: 'state', match_id: getMatchId(), payload })); } catch (_) {}
    // try to persist to server synchronously using navigator.sendBeacon if available
    try {
      const url = 'state.php';
      const mid = getMatchId();
      if (mid) {
        const body = JSON.stringify({ match_id: mid, payload });
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: 'application/json' });
          navigator.sendBeacon(url, blob);
        } else {
          // best-effort fetch with keepalive
          fetch(url, { method: 'POST', body, headers: { 'Content-Type':'application/json' }, keepalive: true }).catch(()=>{});
        }
      }
    } catch (_) {}
  } catch (_) {}
}

// Send final state when page is being unloaded so viewers don't keep running stale timers
window.addEventListener('pagehide', function () { flushStateOnUnload(); });
window.addEventListener('beforeunload', function () { flushStateOnUnload(); });

// ----- persist to server (debounced) -----
let _persistTimer = null;
function schedulePersistToServer(payload) {
  try {
    // Don't attempt server persist if we don't have a valid match id yet
    const mid = getMatchId();
    if (!mid) return;
    if (_persistTimer) clearTimeout(_persistTimer);
    _persistTimer = setTimeout(() => {
      _persistTimer = null;
      try {
        fetch('state.php', {
          method: 'POST',
          headers: { 'Content-Type':'application/json' },
          body: JSON.stringify({ match_id: mid, payload }),
          keepalive: true
        }).catch(()=>{});
      } catch (_) {}
    }, 400);
  } catch (_) {}
}

// Hook broadcastState into every state-mutating function
const _origRecalcScore  = recalcScore;
recalcScore = function (team) { _origRecalcScore(team); broadcastState(); };

const _origAdjustShared = adjustShared;
adjustShared = function (key, delta) { _origAdjustShared(key, delta); broadcastState(); };

const _origAdjustTsb = adjustTsb;
adjustTsb = function (team, key, delta) { _origAdjustTsb(team, key, delta); broadcastState(); };

const _origOnTeamName = onTeamName;
onTeamName = function (team) { _origOnTeamName(team); broadcastState(); };

// Broadcast when committee input changes
(function () {
  const ci = document.getElementById('committeeInput');
  if (ci) ci.addEventListener('input', broadcastState);
})();

// Broadcast timer ticks (throttled — every 250ms max to avoid spam)
// For realtime viewers: post high-frequency timer updates immediately
// over BroadcastChannel (if available), but keep full localStorage
// + full-state broadcast throttled to 250ms to avoid excessive writes.
let _bkTimerThrottle = null;
function broadcastTimerStateThrottled() {
  if (_bkTimerThrottle) return;
  _bkTimerThrottle = setTimeout(function () {
    _bkTimerThrottle = null;
    broadcastState();
  }, 250);
}

function postImmediateTimerUpdate() {
  // send minimal payload containing just timers and scores for viewers
  try {
    const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
    const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
    const payload = {
      teamA: { score: scoreA, foul: state.teamA.foul, timeout: state.teamA.timeout, quarter: state.shared.quarter },
      teamB: { score: scoreB, foul: state.teamB.foul, timeout: state.teamB.timeout, quarter: state.shared.quarter },
      shared: { ...state.shared },
      gameTimer: {
        remaining: typeof gtRemaining !== 'undefined' ? gtRemaining : 0,
        total: typeof gtTotalSecs !== 'undefined' ? gtTotalSecs : 600,
        running: typeof gtRunning !== 'undefined' ? gtRunning : false
      },
      shotClock: {
        remaining: typeof scRemaining !== 'undefined' ? scRemaining : 24,
        total: typeof scTotal !== 'undefined' ? scTotal : 24,
        running: typeof scRunning !== 'undefined' ? scRunning : false
      }
    };
    // Write minimal payload synchronously to localStorage so other tabs
    // (which may not support BroadcastChannel) receive the update via
    // the storage event immediately. This is a small payload and
    // should not block noticeably. Keep BroadcastChannel post for
    // fastest same-browser updates.
    try {
      localStorage.setItem(_BK_STORAGE_KEY, JSON.stringify(payload));
    } catch (_) { /* ignore storage errors */ }
    // Post to BroadcastChannel if available (fast same-browser updates)
    try { if (_bkBC) _bkBC.postMessage(payload); } catch (_) {}

    // Also send to WS relay (if connected) so other devices/browsers receive updates.
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
          _ws.send(JSON.stringify({ type: 'state', match_id: getMatchId(), payload }));
        }
    } catch (_) {}
  } catch (_) { /* ignore */ }
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
    const label = document.createElement('span'); label.id = 'wsStatusLabel'; label.textContent = 'WS: unknown'; label.style.marginRight = '8px';
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

// Hook into game timer and shot clock tick functions
const _origGtTick = gtTick;
gtTick = function () { _origGtTick(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };

const _origScTick = scTick;
scTick = function () { _origScTick(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };

// Also broadcast on play/pause/reset of both clocks — send immediate
// lightweight timer updates first, then schedule a throttled full-state
// write to localStorage to avoid blocking UI when starting timers.
const _origGtPlay  = gtPlay;
gtPlay  = function () { _origGtPlay(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origGtPause = gtPause;
gtPause = function () { _origGtPause(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origGtReset = gtReset;
gtReset = function () { _origGtReset(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origScPlay  = scPlay;
scPlay  = function () { _origScPlay(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origScPause = scPause;
scPause = function () { _origScPause(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origScReset = scReset;
scReset = function () { _origScReset(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };
const _origScPreset = scPreset;
scPreset = function (s) { _origScPreset(s); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };

// Broadcast player name / number input changes — hook onto addPlayer
const _origAddPlayer = addPlayer;
addPlayer = function (team) {
  _origAddPlayer(team);
  // Re-attach input listeners to the newly added row's inputs
  const tbody = document.getElementById('tbody' + team);
  if (tbody) {
    tbody.querySelectorAll('input').forEach(function (inp) {
      if (!inp.dataset._bk) {
        inp.addEventListener('input', broadcastState);
        inp.dataset._bk = '1';
      }
    });
  }
  broadcastState();
};

// Load persisted state (if any) then broadcast on page load so viewer sees state immediately
try { loadPersistedState(); } catch (e) {}
broadcastState();
// Initialize view and right-panel counters
try {
  // ensure state defaults are applied to DOM on load (overrides any leftover values)
  state.teamA.foul = state.teamA.foul || 0;
  state.teamA.timeout = state.teamA.timeout || 0;
  state.teamB.foul = state.teamB.foul || 0;
  state.teamB.timeout = state.teamB.timeout || 0;
  state.shared.quarter = typeof state.shared.quarter === 'number' ? state.shared.quarter : 1;
  applyViewMode();
  syncRightPanelCounters();
} catch (e) { /* ignore early load errors */ }

// Simple toast notification (non-blocking)
function showToast(title, subtitle) {
  try {
    const id = 'saveToast';
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div'); el.id = id;
      el.style.position = 'fixed'; el.style.right = '18px'; el.style.top = '18px';
      el.style.zIndex = 99999; el.style.minWidth = '260px';
      document.body.appendChild(el);
    }
    const card = document.createElement('div');
    card.style.background = '#0f0f0f'; card.style.color = '#fff';
    card.style.border = '1px solid #222'; card.style.padding = '12px 14px';
    card.style.marginTop = '8px'; card.style.borderRadius = '6px';
    card.style.boxShadow = '0 6px 18px rgba(0,0,0,0.6)';
    const t = document.createElement('div'); t.textContent = title; t.style.fontWeight = '700';
    card.appendChild(t);
    if (subtitle) { const s = document.createElement('div'); s.textContent = subtitle; s.style.opacity = '0.85'; s.style.fontSize = '13px'; s.style.marginTop = '6px'; card.appendChild(s); }
    el.appendChild(card);
    setTimeout(() => { card.style.transition = 'opacity 300ms, transform 300ms'; card.style.opacity = '0'; card.style.transform = 'translateY(-6px)'; setTimeout(() => card.remove(), 350); }, 4200);
  } catch (e) { /* ignore */ }
}