// ═══════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════
const state = {
  teamA: { name:'TEAM A', players:[], foul:0, timeout:0, manualScore: 0 },
  teamB: { name:'TEAM B', players:[], foul:0, timeout:0, manualScore: 0 },
  shared: { foul:0, timeout:0, quarter:1 }
};
const STATS = ['pts','foul','reb','ast','blk','stl'];
// Saved vs Draft roster handling
// `state` is the current draft shown in the UI. `savedState` is the
// last-saved authoritative state loaded from the server. Draft edits
// are temporary until `saveRoster()` is invoked.
let savedState = null;
let draftDirty = false;
const IS_BASKETBALL_PAGE = typeof document !== 'undefined' && document.body && document.body.dataset && document.body.dataset.sport === 'basketball';

function markRosterDirty() {
  try { draftDirty = true; } catch(_){}
  try { const btn = document.getElementById('saveRosterBtn'); if (btn) btn.disabled = false; } catch(_){}
}

function clearRosterDirty() {
  try { draftDirty = false; } catch(_){}
  try { const btn = document.getElementById('saveRosterBtn'); if (btn) btn.disabled = true; } catch(_){}
}

// Save the current draft roster by syncing the current UI state to the server.
// Real-time WebSocket broadcast is used for live updates, and canonical
// state persistence is done via state.php so reloads restore the latest state.
function saveRoster() {
  try {
    const mid = getMatchId();
    if (!mid || String(mid) === '0' || isNaN(parseInt(mid,10)) || parseInt(mid,10) <= 0) { try { showToast('No match id to save roster'); } catch(_) {} return Promise.resolve({ success:false, error:'invalid match_id' }); }
    const payload = buildStatePayload();
    syncBasketballState(payload, { forceServer: true });
    clearRosterDirty();
    try { showToast('Roster sync requested'); } catch(_) {}
    return Promise.resolve({ success:true, payload });
  } catch (e) { console.error('saveRoster error', e); return Promise.resolve({ success:false, error: String(e) }); }
}

// Backwards-compat shim (unused by draft flow).
function immediatePersistRoster() { try { return saveRoster(); } catch(_) { return Promise.resolve({ success:false }); } }
let pCount = { A:0, B:0 };
// Unique client id for this admin page (used in action metadata)
const CLIENT_ID = (window.__clientId = window.__clientId || ('c_' + Math.random().toString(36).slice(2,10)));
let _lastStateResetTs = 0;
// Guard: single delegation attachment for roster event handlers
let _rosterDelegatesAttached = false;

// Internal flags to avoid re-broadcasting incoming remote updates
let _appApplyingRemote = false;
let _lastOutgoingSerialized = null;

// Previously the admin intentionally removed any persisted live state on load.
// Keep persisted state so admin page restores players and scores across reloads.
// (Loading occurs after BroadcastChannel / storage key is declared.)

// ═══════════════════════════════════════════════════════
//  LIVE SCORE
// ═══════════════════════════════════════════════════════
function recalcScore(team) {
  const playersSum = state['team'+team].players.reduce((s, p) => s + (p.pts || 0), 0);
  const manual = typeof state['team'+team].manualScore === 'number' ? state['team'+team].manualScore : 0;
  const total = playersSum + manual;
  const el = document.getElementById('score'+team);
  if (el) el.textContent = total;
  if (el) el.style.transform = 'scale(1.22)';
  setTimeout(() => { if (el) el.style.transform = 'scale(1)'; }, 140);
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
      const qEls = [document.getElementById('bbQuarterVal'), document.getElementById('bbPerQuarterVal')];
      qEls.forEach(q => { if (q && q.style) { q.style.transform = 'scale(1.2)'; setTimeout(() => { if (q) q.style.transform = 'scale(1)'; }, 130); } });
    }
    try { syncRightPanelCounters(); } catch(_) {}
    try { postImmediateTimerUpdate(); } catch(_) {}
    try { broadcastState(); } catch(_) {}
    localStorage.setItem('basketball_state', JSON.stringify(state));
}

// ═══════════════════════════════════════════════════════
//  INLINE TEAM STATS BAR COUNTERS (one-sided mode)
// ═══════════════════════════════════════════════════════
function adjustTsb(team, key, delta) {
  // Quarter is a shared value; route quarter adjustments to shared state.
  if (key === 'quarter') {
    state.shared.quarter = Math.max(0, state.shared.quarter + delta);
    const elq = document.getElementById('bbQuarterVal');
    if (elq) {
      elq.textContent = state.shared.quarter;
      if (elq.style) elq.style.transform = 'scale(1.25)';
      setTimeout(() => { if (elq && elq.style) elq.style.transform = 'scale(1)'; }, 130);
    }
    try { syncRightPanelCounters(); } catch(_) {}
    try { postImmediateTimerUpdate(); } catch(_) {}
    try { broadcastState(); } catch(_) {}
    localStorage.setItem('basketball_state', JSON.stringify(state));
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
  try { syncRightPanelCounters(); } catch(_) {}
  try { postImmediateTimerUpdate(); } catch(_) {}
  try { broadcastState(); } catch(_) {}
  localStorage.setItem('basketball_state', JSON.stringify(state));
}

// Team-level manual score adjustments (offset added to players sum)
function adjustTeamScore(team, delta) {
  try {
    state['team'+team].manualScore = Math.max(0, (state['team'+team].manualScore || 0) + delta);
    recalcScore(team);
    try { broadcastState(); } catch (e) {}
    localStorage.setItem('basketball_state', JSON.stringify(state));
  } catch (e) { /* ignore */ }
}

// ═══════════════════════════════════════════════════════
//  TEAM NAME
// ═══════════════════════════════════════════════════════
function onTeamName(team) {
  const v = document.getElementById('team'+team+'Name').value;
  state['team'+team].name = v;
  document.getElementById('label'+team).textContent = v || ('TEAM '+team);
  try { broadcastState(); } catch(_) {}
  localStorage.setItem('basketball_state', JSON.stringify(state));
}

// ═══════════════════════════════════════════════════════
//  ADD PLAYER
// ═══════════════════════════════════════════════════════
function bbAddPlayer(team) {
  const noInput = document.getElementById('addPlayerNo' + team);
  const nameInput = document.getElementById('addPlayerName' + team);
  pCount[team]++;
  const id = 'p'+team+pCount[team];
  const p = { id, no: noInput ? noInput.value.trim() : '', name: nameInput ? nameInput.value.trim() : '', pts:0, foul:0, reb:0, ast:0, blk:0, stl:0, techFoul:0, techReason:'', selected:false };
  state['team'+team].players.push(p);
  bbRenderRosterTable();
  try { markRosterDirty(); } catch(_) {}
  try { broadcastState(); } catch(_) {}
  try { persistStateImmediately(buildStatePayload()); } catch (_) {}
  localStorage.setItem('basketball_state', JSON.stringify(state));
  // Clear inputs after adding
  if (noInput) noInput.value = '';
  if (nameInput) nameInput.value = '';
}

// ═══════════════════════════════════════════════════════
//  ROSTER TABLE (clean renderer + event delegation)
// ═══════════════════════════════════════════════════════
function bbRenderRosterTable() {
  try {
    const esc = (s) => { if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };
    const isAdmin = (typeof window.__role === 'undefined') || (window.__role === 'admin' || window.__role === 'superadmin');

    const buildTeam = function(team) {
      const tbody = document.getElementById('tbody' + team);
      if (!tbody) return;
      let html = '';
      (state['team'+team].players || []).forEach(function(p) {
        html += '<tr class="player-main-row" data-player-id="' + esc(p.id) + '" data-team="' + team + '">';
        html += '<td class="player-cb-cell"><input type="checkbox" class="bbPlayerCb"' + (p.selected ? ' checked' : '') + '></td>';
        html += '<td class="td-no"><input type="text" value="' + esc(p.no) + '" class="player-no" placeholder="#" maxlength="3" ' + (!isAdmin ? 'readonly tabindex="-1"' : '') + '></td>';
        html += '<td class="td-name"><input type="text" value="' + esc(p.name) + '" class="player-name" placeholder="Player name" ' + (!isAdmin ? 'readonly tabindex="-1"' : '') + '></td>';
        // stats
        STATS.forEach(function(stat) {
          html += '<td data-stat="' + stat + '"><div class="stat-cell"><button class="bbSbtn minus" data-action="dec" data-stat="' + stat + '">−</button><span class="stat-val" data-stat="' + stat + '">' + esc(p[stat]) + '</span><button class="bbSbtn plus" data-action="inc" data-stat="' + stat + '">+</button></div></td>';
        });
        html += '<td><span class="stat-val tech-display" data-stat="techFoul">' + esc(p.techFoul) + '</span></td>';
        html += '<td><button class="bbBtnDel" data-action="delete">✕</button></td>';
        html += '</tr>';
        html += '<tr class="player-tech-row" data-player-id="' + esc(p.id) + '" data-team="' + team + '"><td colspan="11"><div class="tech-inner"><span class="tech-label">Tech Foul:</span><div class="tech-counter"><button class="tbtn minus" data-action="dec" data-stat="techFoul">−</button><span class="tech-count-val" data-stat="techFoul">' + esc(p.techFoul) + '</span><button class="tbtn plus" data-action="inc" data-stat="techFoul">+</button></div><input class="tech-reason-input" type="text" value="' + esc(p.techReason) + '" placeholder="Reason / description of technical foul…"></div></td></tr>';
      });
      tbody.innerHTML = html;
    };

    buildTeam('A'); buildTeam('B');

    // Attach event delegation once per tbody
    if (!_rosterDelegatesAttached) {
      const attach = function(tbody) {
        if (!tbody) return;
        tbody.addEventListener('click', function(ev) {
          const t = ev.target;
          const tr = t.closest('tr[data-player-id]');
          if (!tr) return;
          const pid = tr.dataset.playerId;
          const team = tr.dataset.team || (tbody.id === 'tbodyA' ? 'A' : 'B');
          const players = state['team' + team].players || [];
          const p = players.find(function(x){ return x.id === pid; });
          if (!p) return;
          const action = t.dataset.action;
          const stat = t.dataset.stat;
          if (action === 'inc' || action === 'dec') {
            if (!stat) return;
            if (action === 'inc') p[stat] = (p[stat] || 0) + 1; else p[stat] = Math.max(0, (p[stat] || 0) - 1);
            const span = tbody.querySelector('tr[data-player-id="' + pid + '"] .stat-val[data-stat="' + stat + '"]');
            if (span) span.textContent = p[stat];
            if (stat === 'pts') try { recalcScore(team); } catch(_) {}
            try { markRosterDirty(); } catch(_) {}
            try { broadcastState(); } catch(_) {}
            return;
          }
          if (action === 'delete') {
            const idx = players.findIndex(function(x){ return x.id === pid; });
            if (idx >= 0) players.splice(idx, 1);
            bbRenderRosterTable();
            try { recalcScore(team); } catch(_) {}
            try { markRosterDirty(); } catch(_) {}
            try { broadcastState({ forceServer: false }); } catch(_) {}
            try { schedulePersistToServer(buildStatePayload()); } catch(_) {}
            return;
          }
        });
        tbody.addEventListener('input', function(ev) {
          const inp = ev.target;
          const tr = inp.closest('tr[data-player-id]');
          if (!tr) return;
          const pid = tr.dataset.playerId;
          const team = tr.dataset.team || (tbody.id === 'tbodyA' ? 'A' : 'B');
          const players = state['team' + team].players || [];
          const p = players.find(function(x){ return x.id === pid; });
          if (!p) return;
          if (inp.classList.contains('player-no') || inp.classList.contains('player-name') || inp.classList.contains('tech-reason-input')) {
            if (inp.classList.contains('player-no')) p.no = inp.value;
            if (inp.classList.contains('player-name')) p.name = inp.value;
            if (inp.classList.contains('tech-reason-input')) p.techReason = inp.value;
            try { markRosterDirty(); } catch(_) {}
            localStorage.setItem('basketball_state', JSON.stringify(state));
            if (_rosterTypingDebounce) clearTimeout(_rosterTypingDebounce);
            _rosterTypingDebounce = setTimeout(function () {
              _rosterTypingDebounce = null;
              try { broadcastState({ forceServer: false }); } catch(_) {}
              try { schedulePersistToServer(buildStatePayload()); } catch(_) {}
            }, 600);
            return;
          }
        });
        tbody.addEventListener('change', function(ev) {
          const el = ev.target;
          if (!el.classList.contains('bbPlayerCb')) return;
          const tr = el.closest('tr[data-player-id]');
          if (!tr) return;
          const pid = tr.dataset.playerId;
          const team = tr.dataset.team || (tbody.id === 'tbodyA' ? 'A' : 'B');
          const players = state['team' + team].players || [];
          const p = players.find(function(x){ return x.id === pid; });
          if (!p) return;
          p.selected = !!el.checked;
          tr.classList.toggle('row-checked', !!el.checked);
          try { syncSelectAll(team); } catch(_) {}
          try { markRosterDirty(); } catch(_) {}
          try { broadcastState({ forceServer: false }); } catch(_) {}
          try { schedulePersistToServer(buildStatePayload()); } catch(_) {}
        });
      };
      const ta = document.getElementById('tbodyA'); if (ta) attach(ta);
      const tb = document.getElementById('tbodyB'); if (tb) attach(tb);
      _rosterDelegatesAttached = true;
    }
  } catch (e) { /* ignore render errors */ }
}

// ═══════════════════════════════════════════════════════
//  GAME TIMER
// ═══════════════════════════════════════════════════════
let gtTotalSecs = 10 * 60;
let gtRemaining = 10 * 60;
let gtRunning   = false;
let gtInterval  = null; // legacy var kept for compatibility
let gtLastTick  = null;
let gtAnchorTs = null; // server start_timestamp (ms) when running
let gtRemainingAtAnchor = null; // remaining (secs) corresponding to gtAnchorTs

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
  if (gtTimeEl) {
    gtTimeEl.textContent = gtFmt(gtRemaining);
    gtTimeEl.className   = 'gt-time' + (expired ? ' expired' : gtRemaining <= GT_DANGER ? ' danger' : '');
  }
  if (gtBlock) {
    gtBlock.className    = 'game-timer-block' +
      (expired ? ' gt-expired' : gtRunning && gtRemaining <= GT_DANGER ? ' gt-danger' : gtRunning ? ' gt-running' : '');
  }
}
// Centralized UI toggle for timer Play/Pause buttons
function applyTimerButtonState(timerType, running) {
  try {
    // Show/Hide play vs pause consistently and keep disabled state for
    // accessibility. Play visible when stopped/paused; Pause visible when running.
    if (timerType === 'game') {
      if (gtPlayBtn) { gtPlayBtn.style.display = running ? 'none' : ''; gtPlayBtn.disabled = !!running; }
      if (gtPauseBtn) { gtPauseBtn.style.display = running ? '' : 'none'; gtPauseBtn.disabled = !running; }
    } else if (timerType === 'shot') {
      if (scPlayBtn) { scPlayBtn.style.display = running ? 'none' : ''; scPlayBtn.disabled = !!running; }
      if (scPauseBtn) { scPauseBtn.style.display = running ? '' : 'none'; scPauseBtn.disabled = !running; }
    }
  } catch (_) {}
}
function gtTick() {
  if (!gtRunning) return;
  // If server-provided anchor exists, compute remaining from anchor
  if (typeof gtAnchorTs === 'number' && typeof gtRemainingAtAnchor === 'number') {
    const nowMs = Date.now();
    gtRemaining = Math.max(0, gtRemainingAtAnchor - ((nowMs - Number(gtAnchorTs)) / 1000));
    gtRender();
    if (gtRemaining <= 0) {
      gtRunning = false; gtLastTick = null;
      clearInterval(gtInterval); gtInterval = null;
      try { applyTimerButtonState('game', false); } catch(_){}
      if (gtPlayBtn) gtPlayBtn.disabled = true; if (gtPauseBtn) gtPauseBtn.disabled = true;
      flashTitle('\u23F0 GAME OVER!', 8, 450);
    }
    return;
  }
  const now = performance.now();
  const dt  = (now - gtLastTick) / 1000;
  gtLastTick = now;
  gtRemaining = Math.max(0, gtRemaining - dt);
  gtRender();
  if (gtRemaining <= 0) {
    gtRunning = false;
    clearInterval(gtInterval); gtInterval = null;
    try { applyTimerButtonState('game', false); } catch(_){}
    gtPlayBtn.disabled = true; gtPauseBtn.disabled = true;
    flashTitle('\u23F0 GAME OVER!', 8, 450);
  }
}
function gtPlay() {
  if (gtRunning || gtRemaining <= 0) return;
  gtRunning = true;
  gtLastTick = null; // mainLoop will stamp on first frame, preventing stale-gap jumps
  applyTimerButtonState('game', true);
  gtRender();
}
function gtPause() {
  if (!gtRunning) return;
  // Compute live remaining from server anchor (if present) before stopping
  try {
    if (typeof gtAnchorTs === 'number' && typeof gtRemainingAtAnchor === 'number') {
      gtRemaining = Math.max(0, gtRemainingAtAnchor - ((Date.now() - Number(gtAnchorTs)) / 1000));
    }
  } catch (_) {}
  // Clear anchor to prevent a stale anchor overwrite after pause
  try { gtAnchorTs = null; gtRemainingAtAnchor = null; } catch (_) {}

  gtRunning = false;
  gtLastTick = null; // discard timestamp so resume never inherits a stale gap
  // aggressively stop any scheduled loops/intervals for game timer
  if (gtInterval) { clearInterval(gtInterval); gtInterval = null; }
  applyTimerButtonState('game', false);
  gtRender();
}
function gtReset() {
  gtRunning = false;
  gtLastTick = null;
  gtRemaining = gtTotalSecs;
  // aggressively stop any scheduled loops/intervals for game timer
  if (gtInterval) { clearInterval(gtInterval); gtInterval = null; }

  // Clear server anchor so a stale anchor does not overwrite the just-reset value
  try { gtAnchorTs = null; gtRemainingAtAnchor = null; } catch(_) {}

  // Note: persistence is handled by the wrapped gtReset handler via immediatePersistControl
  applyTimerButtonState('game', false);
  gtRender();
}
function gtSetDuration() {
  const mins  = parseInt(document.getElementById('gtInputMin').value, 10) || 0;
  const secs  = parseInt(document.getElementById('gtInputSec').value, 10) || 0;
  const total = Math.max(1, mins * 60 + secs);
  gtTotalSecs = total;
  gtRemaining = total;
  gtRunning = false;
  gtLastTick = null;
  gtAnchorTs = null;
  gtRemainingAtAnchor = null;
  // stop gt loop when duration is set manually
  try { applyTimerButtonState('game', false); } catch(_){}
  gtRender();
}
gtRender();

// Initialize timers from server state (called after server state is loaded)
function initializeTimersFromServerState() {
  try {
    const mid = getMatchId();
    if (!mid || String(mid) === '0' || isNaN(parseInt(mid, 10))) return;

    // Fetch timer state from server
    fetch('timer.php?match_id=' + encodeURIComponent(mid))
      .then(r => r.json())
      .then(j => {
        if (j && j.success && j.payload) {
          const payload = j.payload;

          // Apply game timer state
          if (payload.gameTimer) {
            const g = payload.gameTimer;
            if (typeof g.total === 'number') gtTotalSecs = g.total;
            if (typeof g.remaining === 'number') gtRemaining = g.remaining;
            if (typeof g.running === 'boolean') {
              gtRunning = g.running;
              if (gtRunning && typeof g.ts === 'number') {
                gtAnchorTs = g.ts;
                gtRemainingAtAnchor = g.remaining;
              } else {
                gtAnchorTs = null;
                gtRemainingAtAnchor = null;
              }
            }
            gtRender();
            try { applyTimerButtonState('game', gtRunning); } catch(_){ }
            // Start/stop loops based on server state
          }

          // Apply shot clock state
          if (payload.shotClock) {
            const s = payload.shotClock;
            if (typeof s.total === 'number') {
              scTotal = s.total;
              scPresetVal = s.total;
            }
            if (typeof s.remaining === 'number') scRemaining = s.remaining;
            if (typeof s.running === 'boolean') {
              scRunning = s.running;
              if (scRunning && typeof s.ts === 'number') {
                scAnchorTs = s.ts;
                scRemainingAtAnchor = s.remaining;
              } else {
                scAnchorTs = null;
                scRemainingAtAnchor = null;
              }
            }
            scRenderFrame();
            try { applyTimerButtonState('shot', scRunning); } catch(_){ }
            // Start/stop loops based on server state
          }
        }
      })
      .catch(err => {
        console.warn('Failed to load timer state from server:', err);
        // Fall back to default timer initialization
        gtRender();
        scRenderFrame();
      });
  } catch (e) {
    console.error('Error initializing timers from server state:', e);
    // Fall back to default timer initialization
    gtRender();
    scRenderFrame();
  }
}
const SC_CIRCUMFERENCE    = 2 * Math.PI * 52;
const SC_DANGER_THRESHOLD = 5;
let scPresetVal = 24, scTotal = 24, scRemaining = 24.0;
let scRunning = false, scInterval = null, scLastTick = null;
let scAnchorTs = null; // server start_timestamp (ms) when running
let scRemainingAtAnchor = null; // remaining (secs) corresponding to scAnchorTs

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
  if (scTimeEl) {
    scTimeEl.textContent = expired ? '0' : secs;
    scTimeEl.className = 'sc-time' + (expired ? ' expired' : scRemaining <= SC_DANGER_THRESHOLD ? ' danger' : '');
  }
  if (scTenthEl) {
    scTenthEl.textContent = (!expired && scRemaining < 10) ? tenths : '';
  }
  if (scRingEl) {
    const pct = Math.max(0, scRemaining / scTotal);
    const offset = SC_CIRCUMFERENCE * (1 - pct);
    scRingEl.style.strokeDashoffset = offset;
    scRingEl.style.stroke = expired ? '#e74c3c'
      : scRemaining <= SC_DANGER_THRESHOLD ? '#e74c3c'
      : scRemaining <= scTotal * 0.5 ? '#e67e22' : '#F5C518';
  }
  if (scBlock) {
    scBlock.className = 'shot-clock-block' +
      (expired ? ' sc-expired' : scRunning && scRemaining <= SC_DANGER_THRESHOLD ? ' sc-danger' : scRunning ? ' sc-running' : '');
  }
}
function scTick() {
  if (!scRunning) return;
  // Anchor to server start_timestamp when available
  if (typeof scAnchorTs === 'number' && typeof scRemainingAtAnchor === 'number') {
    const nowMs = Date.now();
    scRemaining = Math.max(0, scRemainingAtAnchor - ((nowMs - Number(scAnchorTs)) / 1000));
    scRenderFrame();
    if (scRemaining <= 0) {
      scRunning = false; scLastTick = null;
      clearInterval(scInterval); scInterval = null;
      try { applyTimerButtonState('shot', false); } catch(_){}
      if (scPlayBtn) scPlayBtn.disabled = true; if (scPauseBtn) scPauseBtn.disabled = true;
      flashTitle('\uD83D\uDD34 SHOT CLOCK!', 6, 400);
    }
    return;
  }
  const now = performance.now();
  const dt = (now - scLastTick) / 1000;
  scLastTick = now;
  scRemaining = Math.max(0, scRemaining - dt);
  scRenderFrame();
  if (scRemaining <= 0) {
    scRunning = false;
    clearInterval(scInterval); scInterval = null;
    scPlayBtn.disabled = true; scPauseBtn.disabled = true;
    flashTitle('\uD83D\uDD34 SHOT CLOCK!', 6, 400);
  }
}
function scPlay() {
  if (scRunning || scRemaining <= 0) return;
  scRunning = true;
  scLastTick = null; // mainLoop will stamp on first frame, preventing stale-gap jumps
  applyTimerButtonState('shot', true);
  scRenderFrame();
}
function scPause() {
  if (!scRunning) return;
  // Compute live remaining from server anchor (if present) before stopping
  try {
    if (typeof scAnchorTs === 'number' && typeof scRemainingAtAnchor === 'number') {
      scRemaining = Math.max(0, scRemainingAtAnchor - ((Date.now() - Number(scAnchorTs)) / 1000));
    }
  } catch (_) {}
  // Clear anchor to prevent a stale anchor overwrite after pause
  try { scAnchorTs = null; scRemainingAtAnchor = null; } catch (_) {}

  scRunning = false;
  scLastTick = null; // discard timestamp so resume never inherits a stale gap
  // aggressively stop any scheduled loops/intervals for shot clock
  if (scInterval) { clearInterval(scInterval); scInterval = null; }
  applyTimerButtonState('shot', false);
  scRenderFrame();
}
function scReset() {
  scRunning = false;
  scLastTick = null;
  scRemaining = scTotal;
  // aggressively stop any scheduled loops/intervals for shot clock
  if (scInterval) { clearInterval(scInterval); scInterval = null; }

  // Clear server anchor so a stale anchor does not overwrite the just-reset value
  try { scAnchorTs = null; scRemainingAtAnchor = null; } catch(_) {}

  // Note: persistence is handled by the wrapped scReset handler via immediatePersistControl
  applyTimerButtonState('shot', false);
  scRenderFrame();
}
function refreshScPresetActive() {
  try {
    const btn24 = document.getElementById('preset24');
    const btn14 = document.getElementById('preset14');
    if (btn24) btn24.classList.toggle('active', scPresetVal === 24);
    if (btn14) btn14.classList.toggle('active', scPresetVal === 14);
  } catch (_) {}
}

function scPreset(secs) {
  scPresetVal = secs;
  scTotal = secs;
  refreshScPresetActive();
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
  tbody.querySelectorAll('.bbPlayerCb').forEach(cb => {
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
  try { markRosterDirty(); } catch(_) {}
  try { broadcastState(); } catch(_) {}
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
  const btn     = document.getElementById('bbViewToggleBtn');
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
    const rA_f = document.getElementById('bbRightTsbAFoul');
    const rA_t = document.getElementById('bbRightTsbATimeout');
    const rB_f = document.getElementById('bbRightTsbBFoul');
    const rB_t = document.getElementById('bbRightTsbBTimeout');
    const qEl  = document.getElementById('bbQuarterVal');
    const perQ = document.getElementById('bbPerQuarterVal');
    const tsbA_f = document.getElementById('bbTsbAFoul');
    const tsbA_t = document.getElementById('bbTsbATimeout');
    const tsbB_f = document.getElementById('bbTsbBFoul');
    const tsbB_t = document.getElementById('bbTsbBTimeout');
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
async function bbSaveFile() {
  const scoreA = state.teamA.players.reduce((s,p) => s + (p.pts || 0), 0) + (typeof state.teamA.manualScore === 'number' ? state.teamA.manualScore : 0);
  const scoreB = state.teamB.players.reduce((s,p) => s + (p.pts || 0), 0) + (typeof state.teamB.manualScore === 'number' ? state.teamB.manualScore : 0);
  const committee = document.getElementById('bbCommitteeInput')?.value?.trim() || '';
  const payload = {
    teamA: { ...state.teamA, score: scoreA },
    teamB: { ...state.teamB, score: scoreB },
    shared: state.shared,
    committee
  };

  // Include a live snapshot of the full client state (including timers)
  // so the server can persist canonical match_state for this saved match.
  try {
    const stateSnapshot = buildStatePayload();
    try { if (typeof gtAnchorTs === 'number') stateSnapshot.gameTimer.ts = Number(gtAnchorTs); } catch(_) {}
    try { if (typeof scAnchorTs === 'number') stateSnapshot.shotClock.ts = Number(scAnchorTs); } catch(_) {}
    payload.state = stateSnapshot;
    // Include current match id (when available) so server updates the existing match
    try {
      const curMid = getMatchId();
      if (curMid && String(curMid) !== '0' && !isNaN(parseInt(curMid,10)) && parseInt(curMid,10) > 0) payload.match_id = String(curMid);
    } catch(_) {}
  } catch (_) {}

  try {
    const res = await fetch('save_game.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data && data.success) {
      const reportUrl = 'report.php?match_id=' + data.match_id;
      try { sessionStorage.setItem('basketball_match_id', String(data.match_id)); } catch (e) {}
      try { sessionStorage.setItem('shouldClearPersistedOnBack:basketball', '1'); } catch (e) {}
      // Redirect to the report in the same tab (avoids popup-blocking issues)
      window.location.href = reportUrl;
      return;
    } else {
      showToast('❌ Save failed: ' + (data && data.error ? data.error : 'Unknown error'));
    }
  } catch (err) {
    showToast('❌ Network error: ' + (err && err.message ? err.message : String(err)));
  }
}

// Reset the current match: clear state, DOM, localStorage and broadcast reset.
function bbResetMatch(force, clearPlayers) {
  try {
    // default to false when not provided
    if (!force) force = false;
    // Backwards compatibility: callers that passed a single `true` (force)
    // historically expected a full reset that cleared players. When the
    // caller supplies an explicit second arg, use it; otherwise default
    // to the historical behaviour (force===true -> clear players).
    if (typeof clearPlayers === 'undefined') clearPlayers = !!force;
    if (!force) {
      const ok = confirm('Warning: data can be lost. Do you want to reset the match? (This will reset timers and scores; players will be preserved unless you chose a full reset.)');
      if (!ok) return;
    }

    // Reset timers locally to defaults and persist resets to server/WS
    try {
      // Defaults
      gtTotalSecs = 10 * 60;
      gtRemaining = gtTotalSecs;
      gtRunning = false;
      gtAnchorTs = null;
      gtRemainingAtAnchor = null;
      scPresetVal = 24;
      scTotal = 24;
      scRemaining = scTotal;
      scRunning = false;
      scAnchorTs = null;
      scRemainingAtAnchor = null;
      try { gtRender(); } catch(_){}
      try { scRenderFrame(); } catch(_){}
    } catch (e) {}

    // Clear in-memory state. When `clearPlayers` is false we perform a
    // soft reset: preserve the player rosters and team names while
    // zeroing counters, scores and timers. When `clearPlayers` is true
    // perform the historical full reset that also clears player lists.
    if (clearPlayers) {
      _lastStateResetTs = Date.now();
      savedState = null;
      clearRosterDirty();
      state.teamA = { name: 'TEAM A', players: [], foul: 0, timeout: 0, manualScore: 0 };
      state.teamB = { name: 'TEAM B', players: [], foul: 0, timeout: 0, manualScore: 0 };
      state.shared = { foul: 0, timeout: 0, quarter: 1 };
      pCount = { A: 0, B: 0 };
      // Clear DOM player rows and reset name inputs to defaults
      try { document.getElementById('tbodyA').innerHTML = ''; } catch (e) {}
      try { document.getElementById('tbodyB').innerHTML = ''; } catch (e) {}
      try { document.getElementById('teamAName').value = state.teamA.name; } catch (e) {}
      try { document.getElementById('teamBName').value = state.teamB.name; } catch (e) {}
      try { document.getElementById('labelA').textContent = state.teamA.name; } catch (e) {}
      try { document.getElementById('labelB').textContent = state.teamB.name; } catch (e) {}
    } else {
      // Soft reset: keep players and team names, clear counters and per-team scores
      try { state.teamA.foul = 0; state.teamA.timeout = 0; state.teamA.manualScore = 0; } catch(_) {}
      try { state.teamB.foul = 0; state.teamB.timeout = 0; state.teamB.manualScore = 0; } catch(_) {}
      state.shared = { foul: 0, timeout: 0, quarter: 1 };
      // Re-render player rows to ensure DOM reflects preserved rosters
      try { bbRenderRosterTable(); } catch(_) {}
      try { document.getElementById('teamAName').value = state.teamA.name; } catch(_) {}
      try { document.getElementById('teamBName').value = state.teamB.name; } catch(_) {}
      try { document.getElementById('labelA').textContent = state.teamA.name; } catch(_) {}
      try { document.getElementById('labelB').textContent = state.teamB.name; } catch(_) {}
    }

    // Reset displayed counters and scores
    try { document.getElementById('bbTsbAFoul').textContent = '0'; } catch (e) {}
    try { document.getElementById('bbTsbATimeout').textContent = '0'; } catch (e) {}
    try { document.getElementById('bbTsbBFoul').textContent = '0'; } catch (e) {}
    try { document.getElementById('bbTsbBTimeout').textContent = '0'; } catch (e) {}
    try { document.getElementById('scoreA').textContent = '0'; } catch (e) {}
    try { document.getElementById('scoreB').textContent = '0'; } catch (e) {}
    try { document.getElementById('bbQuarterVal').textContent = '1'; } catch (e) {}
    try { document.getElementById('bbPerQuarterVal').textContent = '1'; } catch (e) {}

    // Do not persist roster/counter state to localStorage; rely on broadcast/server updates.
    try {
      if (clearPlayers) {
        // no-op: local storage cleanup removed in favor of server-side state sync.
      }
    } catch (e) {}

    // Persist cleared state to server (persist-first) and broadcast
    try {
      const mid = getMatchId();
      const payload = buildStatePayload();
      // Attempt server persist when we have a valid match id
      if (mid && String(mid) !== '0' && !isNaN(parseInt(mid,10)) && parseInt(mid,10) > 0) {
        const body = JSON.stringify({ match_id: mid, payload: payload, meta: { action: 'reset_match', control: 'reset', clientId: CLIENT_ID }, confirmed: true });
        fetch('state.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body })
          .then(res => res.json())
          .then(j => {
            try {
              if (j && j.success) {
                // Apply server canonical payload when provided
                try { if (j.payload) applyIncomingState(j.payload); else applyIncomingState(payload); } catch(_) {}
                // Broadcast action via WS for low-latency cross-device sync
                try { if (_ws && _ws.readyState === WebSocket.OPEN) _ws.send(JSON.stringify({ type: 'basketball_state', sport: 'basketball', match_id: mid, payload: j.payload || payload, ts: Date.now() })); } catch(_) {}
                try { showToast('Match reset — server updated'); } catch(_) {}
              } else {
                // fallback: local broadcast
                try { broadcastState(); } catch(_) {}
                localStorage.setItem('basketball_state', JSON.stringify(state));
                try { showToast('Match reset locally; server persist failed'); } catch(_) {}
              }
            } catch (e) { console.error('resetMatch apply error', e); try { broadcastState(); } catch(_) {}; localStorage.setItem('basketball_state', JSON.stringify(state)); try { showToast('Match reset locally'); } catch(_) {} }
          }).catch(err => { console.error('resetMatch persist error', err); try { broadcastState(); } catch(_) {}; localStorage.setItem('basketball_state', JSON.stringify(state)); try { showToast('Match reset locally; server persist failed'); } catch(_) {} });
      } else {
        // No valid match id: broadcast local cleared state only
        try { broadcastState(); } catch(_) {}
        localStorage.setItem('basketball_state', JSON.stringify(state));
        try { showToast('Match reset locally'); } catch(_) {}
      }
    } catch (e) {
      try { broadcastState(); } catch(_) {}
      localStorage.setItem('basketball_state', JSON.stringify(state));
      try { showToast('Match reset locally'); } catch(_) {}
    }
  } catch (err) {
    console.error('resetMatch error', err);
    showToast('Error resetting match');
  }
}

function confirmReset(clearPlayers) {
  try {
    const ok = confirm('Warning: this will clear all match data and reset the game. Do you want to continue?');
    if (!ok) return;
    bbResetMatch(true, clearPlayers);
  } catch (err) {
    console.error('confirmReset error', err);
  }
}

// Delete saved match rows from server DB. Calls delete_match.php.
async function bbDeleteSavedMatch() {
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
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ match_id: String(matchId) })
    });
    const data = await res.json();
    if (data && data.success) {
      showToast('Saved match deleted from server.');
      // also clear local live state and DOM to avoid confusion
      bbResetMatch();
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
//  Uses BroadcastChannel for same-browser tabs and WebSocket relay for
//  real-time synchronization. State persistence happens through state.php.
//  Called automatically after every state-changing action.
// ═══════════════════════════════════════════════════════
const _BK_TIMER_KEY    = 'basketballTimerState';
const _BK_CHANNEL_NAME = 'basketball_live';
let   _bkBC = null;
let   _lastOutgoingTimerSerialized = null;
let   _lastTimerControlTs = 0;
// Timer persist debounce
let _timerPersistTimeout = null;
const TIMER_PERSIST_DEBOUNCE_MS = 600;
// Hydration guard: when true, client is fetching canonical server state
// and must not emit local timer/state writes that could overwrite SSOT.
let _hydrationPending = false;
// Initial hydration complete flag: block automatic debounced writes to
// `state.php` until the client has attempted server-first hydration.
let _initialHydrationDone = false;
try { _bkBC = new BroadcastChannel(_BK_CHANNEL_NAME); } catch (_) {}
if (_bkBC) {
  _bkBC.onmessage = function (e) {
    try {
      const msg = e.data && typeof e.data === 'object' ? e.data : JSON.parse(e.data);
      console.log('BC received:', msg.type || 'state', 'match_id:', msg.match_id);
      if (!msg) return;
      if (msg.type === 'new_match') {
        try { adoptBasketballMatch({ match_id: msg.match_id || (msg.payload && msg.payload.match_id), payload: msg.payload }); } catch (_) {}
        return;
      }
      if (msg.type === 'timer') {
        // Timer updates (throttled lightweight updates)
        const payload = msg.payload;
        if (!payload) return;
        if (typeof msg.ts === 'number' && msg.ts <= _lastTimerControlTs) return;
        try {
          const s = JSON.stringify(payload);
          if (s === _lastOutgoingTimerSerialized) return;
        } catch(_){ }
        try { const incomingMid = msg.match_id ? String(msg.match_id) : null; if (incomingMid && String(incomingMid) !== String(getMatchId())) return; } catch(_) {}
        applyIncomingState(payload);
        return;
      }
      if (msg.type === 'state_changed') {
        console.log('BC received state_changed signal for match_id:', msg.match_id);
        try {
          const payload = msg.payload;
          if (payload) {
            try { const s = JSON.stringify(payload); if (s === _lastOutgoingSerialized) return; } catch(_) {}
            try { const incomingMid = msg.match_id ? String(msg.match_id) : null; if (incomingMid && String(incomingMid) !== String(getMatchId())) return; } catch(_) {}
            applyIncomingState(payload);
            return;
          }
          const mid = msg.match_id || getMatchId();
          console.log('Fetching latest state from server for match_id:', mid);
          loadStateFromServerIfMissing(true).then(() => {
            console.log('State fetch completed');
          }).catch((err) => {
            console.error('State fetch failed:', err);
          });
        } catch (_) {
          console.error('state_changed handler error:', _);
        }
        return;
      }
      // Default: full state update (for compatibility)
      const incomingMid = msg.match_id || (msg.payload && msg.payload.match_id) || null;
      try { if (incomingMid && String(incomingMid) !== String(getMatchId())) return; } catch(_) {}
      const payload = msg.payload || msg.state || msg;
      if (!payload) return;
      if (typeof msg.ts === 'number' && msg.ts <= _lastTimerControlTs) return;
      try {
        const s = JSON.stringify(payload);
        if (s === _lastOutgoingSerialized || s === _lastOutgoingTimerSerialized) return;
      } catch(_){ }
      applyIncomingState(payload);
    } catch (_) {}
  };
}

// also listen to storage events (cross-tab fallback)
window.addEventListener('storage', function (e) {
  try {
    if (!IS_BASKETBALL_PAGE) return;
    // Special-case: another admin created a NEW MATCH. Use a dedicated
    // storage key so all tabs can adopt the new match_id and reset.
    if (e.key === 'basketball_new_match') {
      if (!e.newValue) return;
      let info = null;
      try { info = JSON.parse(e.newValue); } catch (_) { info = e.newValue; }
      try { adoptBasketballMatch({ match_id: info && info.match_id ? String(info.match_id) : String(info || ''), payload: info && info.payload ? info.payload : null }); } catch (_) {}
      return;
    }
    // Timer-only updates (lightweight) — apply only for same match
    if (e.key === _BK_TIMER_KEY) {
      if (!e.newValue) return;
      let wrapper = null;
      try { wrapper = JSON.parse(e.newValue); } catch(_) { return; }
      if (!wrapper) return;
      if (typeof wrapper.ts === 'number' && wrapper.ts <= _lastTimerControlTs) return;
      const payload = wrapper && wrapper.payload ? wrapper.payload : wrapper;
      if (!payload) return;
      try {
        const s = JSON.stringify(payload);
        if (s === _lastOutgoingTimerSerialized) return;
      } catch(_) {}
      try { const incomingMid = wrapper && wrapper.match_id ? String(wrapper.match_id) : null; if (incomingMid && String(incomingMid) !== String(getMatchId())) return; } catch(_) {}
      applyIncomingState(payload);
      return;
    }
  } catch (_) {}
});

async function loadStateFromServerIfMissing() {
  try {
    // If caller explicitly forces a server refresh, ignore any localStorage.
    const force = (arguments && arguments.length > 0 && arguments[0]) ? true : false;
    console.log('loadStateFromServerIfMissing called, force:', force);
    // Always prefer fetching server canonical state first; do not hydrate roster/counter state from localStorage.
    const mid = getMatchId();
    console.log('Match ID:', mid);
    // skip when no valid numeric match id available (avoid match_id=0)
    if (!mid || String(mid) === '0' || isNaN(parseInt(mid, 10))) {
      console.warn('Invalid match_id, skipping load');
      return;
    }
    console.log('Fetching state from server for match_id:', mid);
    const res = await fetch('state.php?match_id=' + encodeURIComponent(mid) + '&t=' + Date.now(), { cache: 'no-store', credentials: 'include' });
    const j = await res.json();
    console.log('Server response:', j);
    if (j && j.success) {
      const serverPayload = j.payload;
      console.log('Server payload received:', serverPayload);
      if (!serverPayload) {
        console.log('No server payload and no local persisted roster state; skipping hydration');
        return;
      }

      // Detect whether server payload actually contains roster players
      const hasPlayersA = serverPayload.teamA && Array.isArray(serverPayload.teamA.players) && serverPayload.teamA.players.length > 0;
      const hasPlayersB = serverPayload.teamB && Array.isArray(serverPayload.teamB.players) && serverPayload.teamB.players.length > 0;
      console.log('Has players - Team A:', hasPlayersA, 'Team B:', hasPlayersB);

      let appliedPayload = serverPayload;

      // Server payload is authoritative for hydration; do not merge local drafts.

      // Apply server/merged payload. If there's an authoritative timer store, prefer its values
      try {
        let _usedTimerPayload = false;
        try {
          console.log('Fetching timer state for match_id:', mid);
          const tRes = await fetch('timer.php?match_id=' + encodeURIComponent(mid));
          const tj = await tRes.json();
          console.log('Timer response:', tj);
          if (tj && tj.success && tj.payload) {
            _usedTimerPayload = true;
            console.log('Using timer payload');
            try {
              const now = Date.now();
              const tPayload = tj.payload || {};
              const newApplied = JSON.parse(JSON.stringify(appliedPayload || {}));

              // Helper to read timer data supporting snake_case or camelCase
              const readTimer = (src) => {
                if (!src) return null;
                // prefer explicit nested keys
                const gt = src.game_timer || src.gameTimer || src;
                return gt;
              };

              // GAME
              const gtSrc = readTimer(tPayload);
              if (gtSrc) {
                const hasMs = typeof gtSrc.remaining_ms === 'number' || typeof gtSrc.paused_remaining_ms === 'number' || typeof gtSrc.total_ms === 'number';
                const remainingAtStart = (typeof gtSrc.remaining_ms === 'number') ? (gtSrc.remaining_ms / 1000.0) : (typeof gtSrc.remaining === 'number' ? gtSrc.remaining : (typeof gtSrc.total_ms === 'number' ? (gtSrc.total_ms / 1000.0) : (typeof gtSrc.total === 'number' ? gtSrc.total : 0)));
                const startTs = (typeof gtSrc.start_timestamp === 'number') ? gtSrc.start_timestamp : (typeof gtSrc.ts === 'number' ? gtSrc.ts : null);
                newApplied.gameTimer = newApplied.gameTimer || {};
                newApplied.gameTimer.total = (typeof gtSrc.total_ms === 'number') ? (gtSrc.total_ms / 1000.0) : ((typeof gtSrc.total === 'number') ? gtSrc.total : (newApplied.gameTimer.total || 0));
                if (gtSrc.running && startTs) {
                  // keep remaining referenced to the original start timestamp
                  newApplied.gameTimer.remaining = remainingAtStart;
                  newApplied.gameTimer.running = !!gtSrc.running;
                  newApplied.gameTimer.ts = startTs;
                } else {
                  // paused/stopped — prefer paused_remaining_ms, then remaining
                  const paused = (typeof gtSrc.paused_remaining_ms === 'number') ? (gtSrc.paused_remaining_ms / 1000.0) : (typeof gtSrc.remaining_ms === 'number' ? (gtSrc.remaining_ms / 1000.0) : (typeof gtSrc.remaining === 'number' ? gtSrc.remaining : (newApplied.gameTimer.remaining || 0)));
                  newApplied.gameTimer.remaining = paused;
                  newApplied.gameTimer.running = !!gtSrc.running;
                  newApplied.gameTimer.ts = null;
                }
              }

              // SHOT
              const scSrc = readTimer(tPayload && tPayload.shotClock ? tPayload : (tPayload && tPayload.shot_clock ? tPayload : null));
              // try explicit shotClock / shot_clock
              let shotSrc = null;
              if (tPayload && (tPayload.shotClock || tPayload.shot_clock)) {
                shotSrc = tPayload.shot_clock || tPayload.shotClock;
              }
              if (shotSrc) {
                const remainingAtStart = (typeof shotSrc.remaining_ms === 'number') ? (shotSrc.remaining_ms / 1000.0) : (typeof shotSrc.remaining === 'number' ? shotSrc.remaining : (typeof shotSrc.total_ms === 'number' ? (shotSrc.total_ms / 1000.0) : (typeof shotSrc.total === 'number' ? shotSrc.total : 0)));
                const startTs = (typeof shotSrc.start_timestamp === 'number') ? shotSrc.start_timestamp : (typeof shotSrc.ts === 'number' ? shotSrc.ts : null);
                newApplied.shotClock = newApplied.shotClock || {};
                newApplied.shotClock.total = (typeof shotSrc.total_ms === 'number') ? (shotSrc.total_ms / 1000.0) : ((typeof shotSrc.total === 'number') ? shotSrc.total : (newApplied.shotClock.total || 0));
                if (shotSrc.running && startTs) {
                  newApplied.shotClock.remaining = remainingAtStart;
                  newApplied.shotClock.running = !!shotSrc.running;
                  newApplied.shotClock.ts = startTs;
                } else {
                  const paused = (typeof shotSrc.paused_remaining_ms === 'number') ? (shotSrc.paused_remaining_ms / 1000.0) : (typeof shotSrc.remaining_ms === 'number' ? (shotSrc.remaining_ms / 1000.0) : (typeof shotSrc.remaining === 'number' ? shotSrc.remaining : (newApplied.shotClock.remaining || 0)));
                  newApplied.shotClock.remaining = paused;
                  newApplied.shotClock.running = !!shotSrc.running;
                  newApplied.shotClock.ts = null;
                }
              }

              appliedPayload = newApplied;
            } catch (e) { /* ignore timer merge errors */ }
          }
        } catch (e) { /* ignore timer fetch errors */ }

        // If timer.php did not provide an authoritative payload, try to
        // compute live remaining from the server `match_state` payload
        // (serverPayload) when it contains a timestamped timer snapshot.
        if (!_usedTimerPayload) {
          try {
            const now = Date.now();
            const newApplied = JSON.parse(JSON.stringify(appliedPayload || {}));
            // prefer snake_case game_timer if present, else camelCase gameTimer
            const srvGT = serverPayload && (serverPayload.game_timer || serverPayload.gameTimer) ? (serverPayload.game_timer || serverPayload.gameTimer) : null;
            if (srvGT) {
              const remainingAtStart = (typeof srvGT.remaining_ms === 'number') ? (srvGT.remaining_ms / 1000.0) : (typeof srvGT.remaining === 'number' ? srvGT.remaining : (typeof srvGT.total_ms === 'number' ? (srvGT.total_ms / 1000.0) : (typeof srvGT.total === 'number' ? srvGT.total : 0)));
              const startTs = (typeof srvGT.start_timestamp === 'number') ? srvGT.start_timestamp : (typeof srvGT.ts === 'number' ? srvGT.ts : null);
              newApplied.gameTimer = newApplied.gameTimer || {};
              newApplied.gameTimer.total = (typeof srvGT.total_ms === 'number') ? (srvGT.total_ms / 1000.0) : ((typeof srvGT.total === 'number') ? srvGT.total : (newApplied.gameTimer.total || 0));
              if (srvGT.running && startTs) {
                newApplied.gameTimer.remaining = remainingAtStart;
                newApplied.gameTimer.running = !!srvGT.running;
                newApplied.gameTimer.ts = startTs;
              } else {
                const paused = (typeof srvGT.paused_remaining_ms === 'number') ? (srvGT.paused_remaining_ms / 1000.0) : (typeof srvGT.remaining_ms === 'number' ? (srvGT.remaining_ms / 1000.0) : (typeof srvGT.remaining === 'number' ? srvGT.remaining : (newApplied.gameTimer.remaining || 0)));
                newApplied.gameTimer.remaining = paused;
                newApplied.gameTimer.running = !!srvGT.running;
                newApplied.gameTimer.ts = null;
              }
            }
            const srvSC = serverPayload && (serverPayload.shot_clock || serverPayload.shotClock) ? (serverPayload.shot_clock || serverPayload.shotClock) : null;
            if (srvSC) {
              const remainingAtStart = (typeof srvSC.remaining_ms === 'number') ? (srvSC.remaining_ms / 1000.0) : (typeof srvSC.remaining === 'number' ? srvSC.remaining : (typeof srvSC.total_ms === 'number' ? (srvSC.total_ms / 1000.0) : (typeof srvSC.total === 'number' ? srvSC.total : 0)));
              const startTs = (typeof srvSC.start_timestamp === 'number') ? srvSC.start_timestamp : (typeof srvSC.ts === 'number' ? srvSC.ts : null);
              newApplied.shotClock = newApplied.shotClock || {};
              newApplied.shotClock.total = (typeof srvSC.total_ms === 'number') ? (srvSC.total_ms / 1000.0) : ((typeof srvSC.total === 'number') ? srvSC.total : (newApplied.shotClock.total || 0));
              if (srvSC.running && startTs) {
                newApplied.shotClock.remaining = remainingAtStart;
                newApplied.shotClock.running = !!srvSC.running;
                newApplied.shotClock.ts = startTs;
              } else {
                const paused = (typeof srvSC.paused_remaining_ms === 'number') ? (srvSC.paused_remaining_ms / 1000.0) : (typeof srvSC.remaining_ms === 'number' ? (srvSC.remaining_ms / 1000.0) : (typeof srvSC.remaining === 'number' ? srvSC.remaining : (newApplied.shotClock.remaining || 0)));
                newApplied.shotClock.remaining = paused;
                newApplied.shotClock.running = !!srvSC.running;
                newApplied.shotClock.ts = null;
              }
            }
            appliedPayload = newApplied;
          } catch (e) { /* ignore fallback errors */ }
        }
      } catch (e) {}

      try {
        // Ensure payload has required structure to prevent timer-only payloads from wiping a live roster
        if (!appliedPayload.teamA) {
          appliedPayload.teamA = { players: state.teamA.players || [] };
        } else if (!Array.isArray(appliedPayload.teamA.players) || appliedPayload.teamA.players.length === 0) {
          if (state.teamA.players && state.teamA.players.length > 0) {
            appliedPayload.teamA.players = state.teamA.players;
          }
        }
        if (!appliedPayload.teamB) {
          appliedPayload.teamB = { players: state.teamB.players || [] };
        } else if (!Array.isArray(appliedPayload.teamB.players) || appliedPayload.teamB.players.length === 0) {
          if (state.teamB.players && state.teamB.players.length > 0) {
            appliedPayload.teamB.players = state.teamB.players;
          }
        }
        if (!appliedPayload.shared) appliedPayload.shared = {};
        
        const tmp = JSON.parse(JSON.stringify(appliedPayload));
        // record canonical saved state so drafts can be reverted
        try { savedState = JSON.parse(JSON.stringify(tmp)); } catch(_) { savedState = tmp; }
        try { clearRosterDirty(); } catch(_) {}
        console.log('Applying incoming state with players:', tmp.teamA ? (tmp.teamA.players ? tmp.teamA.players.length : 0) : 0, tmp.teamB ? (tmp.teamB.players ? tmp.teamB.players.length : 0) : 0);
        applyIncomingState(tmp);
        console.log('State successfully applied');
        // If server reports timers running, start local loops so reloading
        // admins resume the running timers immediately.
      } catch (e) {
        console.error('Error applying state:', e);
      }

      // If server provided a real payload we successfully applied it.
      console.log('loadStateFromServerIfMissing returning true');
      return true;
    } else {
      console.warn('Server response failed:', j);
    }
  } catch (e) {
    console.error('loadStateFromServerIfMissing error:', e);
  }
  console.log('loadStateFromServerIfMissing returning false');
  return false;
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
    _ws.addEventListener('open', () => { console.info('Sportssync WS connected'); _setWSStatus('connected'); try { const role = (window && window.__role) ? String(window.__role) : 'unknown'; console.log('Joining match_id:', getMatchId()); _ws.send(JSON.stringify({ type: 'join', match_id: getMatchId(), role: role })); 
      // Rebroadcast current state for late-joining clients
      _ws.send(JSON.stringify({
        type: 'basketball:state-sync',
        payload: {
          players:  { teamA: state.teamA.players, teamB: state.teamB.players },
          foul:     { teamA: state.teamA.foul,    teamB: state.teamB.foul },
          timeout:  { teamA: state.teamA.timeout, teamB: state.teamB.timeout },
          quarter:  state.shared.quarter,
        }
      }));
    } catch (e) {} });
    _ws.addEventListener('close', () => { console.info('Sportssync WS closed'); _setWSStatus('disconnected'); });
    _ws.addEventListener('error', () => { _setWSStatus('error'); /* ignore */ });
    // inbound messages from ws-server: apply remote state/actions into admin UI
    _ws.addEventListener('message', function (ev) {
      try {
        const msg = JSON.parse(ev.data);
        console.log('WS received:', msg.type, 'match_id:', msg.match_id);
        if (!msg) return;
        if (msg.sport && msg.sport !== 'basketball') return;
        // ignore actions originated from this client (if meta included)
        // Exception: allow processing of our own 'new_match' messages so the
        // creating admin adopts canonical state the same way other clients do.
        // Ignore our own local 'action' messages but allow processing of
        // timer_update and other control events so the creating client
        // receives the same applyIncomingState path as remote clients.
        if (msg.meta && msg.meta.clientId && msg.meta.clientId === CLIENT_ID && msg.type === 'action') return;
            if (msg.type === 'new_match') {
              try { adoptBasketballMatch({ match_id: msg.match_id || (msg.payload && msg.payload.match_id), payload: msg.payload }); } catch (_) {}
                return;
            }
        if (msg.type === 'timer_update') {
          try {
            // Ignore timer updates originating from a client unload/reload.
            // These are ephemeral and should not affect other admins' loops.
            if (msg.meta && msg.meta.unload) return;
            const incomingTs = (typeof msg.ts === 'number') ? msg.ts : ((msg.meta && typeof msg.meta.ts === 'number') ? msg.meta.ts : null);
            const remoteControl = msg.meta && msg.meta.control ? msg.meta.control : null;
            // Ignore passive timer updates older than the last explicit control.
            if (remoteControl === null && incomingTs !== null && incomingTs <= _lastTimerControlTs) return;
            // Record explicit control ordering so stale updates cannot override it.
            if (remoteControl !== null && incomingTs !== null) {
              _lastTimerControlTs = Math.max(_lastTimerControlTs, incomingTs);
            }
            if (msg.gameTimer) {
              try {
                if (typeof msg.gameTimer.total === 'number') gtTotalSecs = msg.gameTimer.total;
                // running may be explicitly true/false or undefined; treat undefined as "no-op"
                const runningFlag = (typeof msg.gameTimer.running === 'boolean') ? msg.gameTimer.running : (typeof msg.gameTimer.is_running === 'boolean' ? msg.gameTimer.is_running : null);
                // If server provided timestamp anchor, use anchor semantics
                const tsVal = (typeof msg.gameTimer.ts === 'number') ? msg.gameTimer.ts : (typeof msg.gameTimer.start_timestamp === 'number' ? msg.gameTimer.start_timestamp : null);
                if (runningFlag === true && tsVal !== null && typeof msg.gameTimer.remaining === 'number') {
                  const incomingRemaining = Math.max(0, msg.gameTimer.remaining - ((Date.now() - Number(tsVal)) / 1000));
                  const diff = Math.abs((typeof gtRemaining === 'number' ? gtRemaining : 0) - incomingRemaining);
                  if (!gtRunning || diff > 0.5) {
                    gtRemainingAtAnchor = msg.gameTimer.remaining;
                    gtAnchorTs = Number(tsVal);
                    gtRemaining = incomingRemaining;
                    gtLastTick = null;
                  } else if (diff > 0.1) {
                    gtRemaining = incomingRemaining;
                  }
                } else if (runningFlag === null) {
                  if (typeof msg.gameTimer.remaining === 'number') {
                    const diff = Math.abs((typeof gtRemaining === 'number' ? gtRemaining : 0) - msg.gameTimer.remaining);
                    if (diff > 0.1) gtRemaining = msg.gameTimer.remaining;
                  }
                } else {
                  gtAnchorTs = null; gtRemainingAtAnchor = null;
                  if (typeof msg.gameTimer.paused_remaining === 'number') gtRemaining = msg.gameTimer.paused_remaining; else if (typeof msg.gameTimer.remaining === 'number') gtRemaining = msg.gameTimer.remaining;
                }
                if (runningFlag === true) gtRunning = true;
                else if (runningFlag === false) gtRunning = false;
                gtRender();
                try { applyTimerButtonState('game', gtRunning); } catch(_){ }
              } catch(_){ }
            }
            if (msg.shotClock) {
              try {
                if (typeof msg.shotClock.total === 'number') scTotal = msg.shotClock.total, scPresetVal = msg.shotClock.total;
                const scRunningFlag = (typeof msg.shotClock.running === 'boolean') ? msg.shotClock.running : (typeof msg.shotClock.is_running === 'boolean' ? msg.shotClock.is_running : null);
                const scTsVal = (typeof msg.shotClock.ts === 'number') ? msg.shotClock.ts : (typeof msg.shotClock.start_timestamp === 'number' ? msg.shotClock.start_timestamp : null);
                if (scRunningFlag === true && scTsVal !== null && typeof msg.shotClock.remaining === 'number') {
                  const incomingRemaining = Math.max(0, msg.shotClock.remaining - ((Date.now() - Number(scTsVal)) / 1000));
                  const diff = Math.abs((typeof scRemaining === 'number' ? scRemaining : 0) - incomingRemaining);
                  if (!scRunning || diff > 0.5) {
                    scRunning = true;
                    scRemainingAtAnchor = msg.shotClock.remaining;
                    scAnchorTs = Number(scTsVal);
                    scRemaining = incomingRemaining;
                    scLastTick = null;
                  } else {
                    if (diff > 0.1) {
                      scRemaining = incomingRemaining;
                    }
                  }
                } else if (scRunningFlag === null) {
                  if (typeof msg.shotClock.remaining === 'number') {
                    const diff = Math.abs((typeof scRemaining === 'number' ? scRemaining : 0) - msg.shotClock.remaining);
                    if (diff > 0.1) scRemaining = msg.shotClock.remaining;
                  }
                } else {
                  scAnchorTs = null; scRemainingAtAnchor = null;
                  if (typeof msg.shotClock.paused_remaining === 'number') scRemaining = msg.shotClock.paused_remaining; else if (typeof msg.shotClock.remaining === 'number') scRemaining = msg.shotClock.remaining;
                }
                if (scRunningFlag === true) scRunning = true;
                else if (scRunningFlag === false) scRunning = false;
                scRenderFrame();
                try { applyTimerButtonState('shot', scRunning); } catch(_){ }
              } catch(_){}
            }
          } catch (_) {}
          return;
        }
        if (msg.type === 'state_changed') {
          console.log('WS received state_changed signal for match_id:', msg.match_id);
          try {
            const payload = msg.payload;
            if (payload) {
              try { const s = JSON.stringify(payload); if (s === _lastOutgoingSerialized) return; } catch(_) {}
              try { const incomingMid = msg.match_id ? String(msg.match_id) : null; if (incomingMid && String(incomingMid) !== String(getMatchId())) return; } catch(_) {}
              applyIncomingState(payload);
              return;
            }
            const mid = msg.match_id || getMatchId();
            console.log('Fetching latest state from server for match_id:', mid);
            loadStateFromServerIfMissing(true).then(() => {
              console.log('State fetch completed from WS trigger');
            }).catch((err) => {
              console.error('State fetch failed from WS trigger:', err);
            });
          } catch (_) {
            console.error('WS state_changed handler error:', _);
          }
          return;
        }
        // Handle basketball state sync requests and updates
        if (msg.type === 'basketball:request-sync') {
          _ws.send(JSON.stringify({
            type: 'basketball:state-sync',
            payload: {
              players:  { teamA: state.teamA.players, teamB: state.teamB.players },
              foul:     { teamA: state.teamA.foul,    teamB: state.teamB.foul },
              timeout:  { teamA: state.teamA.timeout, teamB: state.teamB.timeout },
              quarter:  state.shared.quarter,
            }
          }));
          return;
        }
        if (msg.type === 'basketball:state-sync') {
          state.teamA.players  = msg.payload.players.teamA;
          state.teamB.players  = msg.payload.players.teamB;
          state.teamA.foul     = msg.payload.foul.teamA;
          state.teamB.foul     = msg.payload.foul.teamB;
          state.teamA.timeout  = msg.payload.timeout.teamA;
          state.teamB.timeout  = msg.payload.timeout.teamB;
          state.shared.quarter = msg.payload.quarter;
          localStorage.setItem('basketball_state', JSON.stringify(state));
          bbRenderRosterTable();
          syncRightPanelCounters();
          return;
        }
        const payload = msg.payload || (msg.type === 'basketball_state' ? msg.payload : null);
        if (payload) {
          try { const s = JSON.stringify(payload); if (s === _lastOutgoingSerialized) return; } catch(_){}
          applyIncomingState(payload);
        }
      } catch (_) {}
    });
  }
} catch (_) { _ws = null; }

function getMatchId() {
  try {
    const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';
    // Check URL parameters first
    try {
      const urlParams = new URLSearchParams(window.location.search);
      const urlMid = urlParams.get('match_id');
      if (urlMid && !isNaN(parseInt(urlMid, 10)) && parseInt(urlMid, 10) > 0) {
        const mid = String(urlMid);
        try { sessionStorage.setItem('basketball_match_id', mid); localStorage.setItem('basketball_match_id', mid); } catch (_) {}
        return mid;
      }
    } catch (_) {}
    if (window.MATCH_DATA && MATCH_DATA.match_id) return String(MATCH_DATA.match_id);
    if (window.__matchId) return String(window.__matchId);
    // Check persisted storage
    try {
      const sess = sessionStorage.getItem('basketball_match_id');
      if (sess) return String(sess);
      const loc = localStorage.getItem('basketball_match_id');
      if (loc) return String(loc);
    } catch (_) {}
    const el = document.getElementById('matchId'); if (el) return String(el.value || el.textContent || '').trim() || DEFAULT_ROOM_ID;
    return DEFAULT_ROOM_ID;
  } catch (e) { return '0'; }
}

function adoptBasketballMatch(event) {
  try {
    if (!event || !event.match_id) return false;
    const newId = String(event.match_id);
    const currentId = getMatchId();
    if (newId === currentId) return false;
    try { sessionStorage.setItem('basketball_match_id', newId); localStorage.setItem('basketball_match_id', newId); } catch (_) {}
    try { window.__matchId = newId; } catch (_) {}
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        const role = (window && window.__role) ? String(window.__role) : 'unknown';
        _ws.send(JSON.stringify({ type: 'join', match_id: newId, role: role }));
      }
    } catch (_) {}
    try { resetMatch(true); } catch (_) {}
    try { showToast('New match adopted: ' + newId); } catch (_) {}
    if (event.payload && typeof event.payload === 'object') {
      try { applyIncomingState(event.payload); } catch (_) {}
    } else {
      try { loadStateFromServerIfMissing(); } catch (_) {}
    }
    return true;
  } catch (_) {
    return false;
  }
}

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

// Generic loop management helpers
function _ensureLoop(loopVar, loopFn, isRunning) {
  if (typeof loopVar !== 'object' || loopVar === null) return null;
  
  if (loopVar.id) {
    cancelAnimationFrame(loopVar.id);
    loopVar.id = null;
  }
  if (isRunning) {
    loopVar.id = requestAnimationFrame(loopFn);
  }
  return loopVar;
}

function syncBasketballState(payload, options) {
  try {
    const mid = getMatchId();
    if (!mid || String(mid) === '0' || isNaN(parseInt(mid,10)) || parseInt(mid,10) <= 0) return null;
    const statePayload = payload || buildStatePayload();

    try {
      _lastOutgoingSerialized = JSON.stringify(statePayload);
    } catch (_) {
      _lastOutgoingSerialized = null;
    }

    const message = {
      type: 'basketball_state',
      match_id: mid,
      payload: statePayload,
      meta: { clientId: CLIENT_ID, ts: Date.now(), source: 'admin' }
    };

    if (_bkBC) {
      try { _bkBC.postMessage(message); } catch (_) { console.warn('BroadcastChannel send failed', _); }
    }
    if (_ws && _ws.readyState === WebSocket.OPEN) {
      try { _ws.send(JSON.stringify(message)); } catch (_) { console.warn('WebSocket send failed', _); }
    }

    if (!options || options.forceServer !== false) {
      persistStateImmediately(statePayload);
    }

    return statePayload;
  } catch (e) {
    console.error('syncBasketballState error:', e);
    return null;
  }
}

function broadcastState(options) {
  return syncBasketballState(null, options);
}

// Apply an incoming remote payload into the admin UI without re-broadcasting.
function applyIncomingState(payload) {
  if (!payload || _appApplyingRemote) return;
  if (typeof payload.resetAt === 'number' && payload.resetAt < _lastStateResetTs) return;
  _appApplyingRemote = true;
  try {
    // Preserve active input to restore focus after render
    let activeInputInfo = null;
    const activeEl = document.activeElement;
    if (activeEl && activeEl.tagName === 'INPUT' && activeEl.closest('#tbodyA, #tbodyB')) {
      const tr = activeEl.closest('tr[data-player-id]');
      if (tr) {
        activeInputInfo = {
          playerId: tr.dataset.playerId,
          className: activeEl.className,
          value: activeEl.value
        };
      }
    }
    if (typeof payload.resetAt === 'number' && payload.resetAt > _lastStateResetTs) {
      _lastStateResetTs = payload.resetAt;
    }
    // Teams and names
    if (payload.teamA && payload.teamA.name !== undefined) {
      state.teamA.name = payload.teamA.name || state.teamA.name;
      const el = document.getElementById('teamAName'); if (el) el.value = state.teamA.name;
      const label = document.getElementById('labelA'); if (label) label.textContent = state.teamA.name || 'TEAM A';
    }
    if (payload.teamB && payload.teamB.name !== undefined) {
      state.teamB.name = payload.teamB.name || state.teamB.name;
      const el = document.getElementById('teamBName'); if (el) el.value = state.teamB.name;
      const label = document.getElementById('labelB'); if (label) label.textContent = state.teamB.name || 'TEAM B';
    }

    // Shared
    if (payload.shared && typeof payload.shared === 'object') {
      state.shared = Object.assign({}, state.shared, payload.shared);
      const q = document.getElementById('bbQuarterVal'); if (q) q.textContent = state.shared.quarter;
      const perQ = document.getElementById('bbPerQuarterVal'); if (perQ) perQ.textContent = state.shared.quarter;
      const f = document.getElementById('foulVal'); if (f) f.textContent = state.shared.foul;
      const t = document.getElementById('timeoutVal'); if (t) t.textContent = state.shared.timeout;
    }

    // Team-level counters
    if (payload.teamA) {
      state.teamA.foul = typeof payload.teamA.foul === 'number' ? payload.teamA.foul : state.teamA.foul;
      state.teamA.timeout = typeof payload.teamA.timeout === 'number' ? payload.teamA.timeout : state.teamA.timeout;
      state.teamA.manualScore = typeof payload.teamA.manualScore === 'number' ? payload.teamA.manualScore : state.teamA.manualScore;
      const elF = document.getElementById('bbTsbAFoul'); if (elF) elF.textContent = state.teamA.foul;
      const elT = document.getElementById('bbTsbATimeout'); if (elT) elT.textContent = state.teamA.timeout;
      const elRF = document.getElementById('bbRightTsbAFoul'); if (elRF) elRF.textContent = state.teamA.foul;
      const elRT = document.getElementById('bbRightTsbATimeout'); if (elRT) elRT.textContent = state.teamA.timeout;
    }
    if (payload.teamB) {
      state.teamB.foul = typeof payload.teamB.foul === 'number' ? payload.teamB.foul : state.teamB.foul;
      state.teamB.timeout = typeof payload.teamB.timeout === 'number' ? payload.teamB.timeout : state.teamB.timeout;
      state.teamB.manualScore = typeof payload.teamB.manualScore === 'number' ? payload.teamB.manualScore : state.teamB.manualScore;
      const elF = document.getElementById('bbTsbBFoul'); if (elF) elF.textContent = state.teamB.foul;
      const elT = document.getElementById('bbTsbBTimeout'); if (elT) elT.textContent = state.teamB.timeout;
      const elRF = document.getElementById('bbRightTsbBFoul'); if (elRF) elRF.textContent = state.teamB.foul;
      const elRT = document.getElementById('bbRightTsbBTimeout'); if (elRT) elRT.textContent = state.teamB.timeout;
    }

    // Rosters — replace and render without triggering user-input handlers
    try {
      const isTimerOnly = payload &&
        (payload.gameTimer || payload.shotClock || payload.game_timer || payload.shot_clock) &&
        !payload.teamA && !payload.teamB;
      if (!isTimerOnly) {
        // Team A
        if (payload.teamA && Array.isArray(payload.teamA.players) && payload.teamA.players.length > 0) {
          state.teamA.players = payload.teamA.players.map(function(p){
            return Object.assign({ id: p.id || null, no: p.no || '', name: p.name || '', pts: p.pts || 0, foul: p.foul || 0, reb: p.reb || 0, ast: p.ast || 0, blk: p.blk || 0, stl: p.stl || 0, techFoul: p.techFoul || 0, techReason: p.techReason || ''}, p);
          });
          // ensure pCount roughly matches
          pCount.A = Math.max(0, state.teamA.players.length || 0);
          const tA = document.getElementById('tbodyA');
          try {
            if (tA) { bbRenderRosterTable(); }
          } catch (e) { if (tA) { bbRenderRosterTable(); } }
        }
        // Team B
        if (payload.teamB && Array.isArray(payload.teamB.players) && payload.teamB.players.length > 0) {
          state.teamB.players = payload.teamB.players.map(function(p){
            return Object.assign({ id: p.id || null, no: p.no || '', name: p.name || '', pts: p.pts || 0, foul: p.foul || 0, reb: p.reb || 0, ast: p.ast || 0, blk: p.blk || 0, stl: p.stl || 0, techFoul: p.techFoul || 0, techReason: p.techReason || ''}, p);
          });
          pCount.B = Math.max(0, state.teamB.players.length || 0);
          const tB = document.getElementById('tbodyB');
          try {
            if (tB) { bbRenderRosterTable(); }
          } catch (e) { if (tB) { bbRenderRosterTable(); } }
        }
      }
    } catch (e) { /* ignore roster render errors */ }

    // Update scores display directly
    try {
      const sA = (payload.teamA && typeof payload.teamA.score === 'number') ? payload.teamA.score : (payload.teamA && payload.teamA.manualScore ? payload.teamA.manualScore : null);
      const sB = (payload.teamB && typeof payload.teamB.score === 'number') ? payload.teamB.score : (payload.teamB && payload.teamB.manualScore ? payload.teamB.manualScore : null);
      if (sA !== null) { const sc = document.getElementById('scoreA'); if (sc) sc.textContent = sA; }
      if (sB !== null) { const sc = document.getElementById('scoreB'); if (sc) sc.textContent = sB; }
    } catch (e) {}

    // Timers (update numeric/display values only). When a payload includes
    // a timestamp (`ts`) for a running timer, recalculate the live remaining
    // value as: remaining_at_start - (now - ts). Do NOT auto-start loops
    // here — explicit remote control messages drive loop start/stop.
    if (payload.gameTimer) {
      try {
        const g = payload.gameTimer;
        if (typeof g.total === 'number') gtTotalSecs = g.total;
        // Interpret explicit boolean running only when provided; otherwise do not override local loop state
        const serverRunning = (typeof g.running === 'boolean') ? g.running : (typeof g.is_running === 'boolean' ? g.is_running : null);
        const tsVal = (typeof g.ts === 'number') ? g.ts : (typeof g.start_timestamp === 'number' ? g.start_timestamp : null);

        // Complete state synchronization for server control updates
        if (serverRunning === true && tsVal !== null && typeof g.remaining === 'number') {
          const incomingRemaining = Math.max(0, g.remaining - ((Date.now() - Number(tsVal)) / 1000));
          const diff = Math.abs((typeof gtRemaining === 'number' ? gtRemaining : 0) - incomingRemaining);
          if (!gtRunning || diff > 0.5) {
            gtRunning = true;
            gtRemainingAtAnchor = g.remaining;
            gtAnchorTs = Number(tsVal);
            gtRemaining = incomingRemaining;
            gtLastTick = null;
          } else {
            if (diff > 0.1) {
              gtRemaining = incomingRemaining;
            }
          }
        } else if (serverRunning === false) {
          // Only stop if this is an explicit timer control signal
          // (play/pause/reset from immediatePersistControl).
          const _isExplicit = !!(
            (payload._timerControl === true) ||
            (payload.meta && payload.meta.control)
          );
          if (_isExplicit) {
            gtRunning = false;
            gtAnchorTs = null;
            gtRemainingAtAnchor = null;
            gtRemaining = (typeof g.paused_remaining === 'number') ? g.paused_remaining : ((typeof g.remaining === 'number') ? g.remaining : gtRemaining);
            gtLastTick = null;
          }
        } else {
          // Server omitted explicit running flag — update numeric values only, do not change running/loops
          if (typeof g.remaining === 'number') gtRemaining = g.remaining;
        }
        gtRender();
        try { applyTimerButtonState('game', gtRunning); } catch(_){ }
      } catch (e) {}
    }
    if (payload.shotClock) {
      try {
        const s = payload.shotClock;
        if (typeof s.total === 'number') { scTotal = s.total; scPresetVal = s.total; refreshScPresetActive(); }
        const serverRunning = (typeof s.running === 'boolean') ? s.running : (typeof s.is_running === 'boolean' ? s.is_running : null);
        const scTsVal = (typeof s.ts === 'number') ? s.ts : (typeof s.start_timestamp === 'number' ? s.start_timestamp : null);
        if (serverRunning === true && scTsVal !== null && typeof s.remaining === 'number') {
          const incomingRemaining = Math.max(0, s.remaining - ((Date.now() - Number(scTsVal)) / 1000));
          const diff = Math.abs((typeof scRemaining === 'number' ? scRemaining : 0) - incomingRemaining);
          if (!scRunning || diff > 0.5) {
            scRunning = true;
            scRemainingAtAnchor = s.remaining;
            scAnchorTs = Number(scTsVal);
            scRemaining = incomingRemaining;
            scLastTick = null;
          } else {
            if (diff > 0.1) {
              scRemaining = incomingRemaining;
            }
          }
        } else if (serverRunning === false) {
          const _isExplicitSc = !!(
            (payload._timerControl === true) ||
            (payload.meta && payload.meta.control)
          );
          if (_isExplicitSc) {
            scRunning = false;
            scAnchorTs = null;
            scRemainingAtAnchor = null;
            scRemaining = (typeof s.paused_remaining === 'number') ? s.paused_remaining : ((typeof s.remaining === 'number') ? s.remaining : scRemaining);
            scLastTick = null;
          }
        } else {
          if (typeof s.remaining === 'number') {
            const diff = Math.abs((typeof scRemaining === 'number' ? scRemaining : 0) - s.remaining);
            if (diff > 0.1) scRemaining = s.remaining;
          }
        }
        scRenderFrame();
        try { applyTimerButtonState('shot', scRunning); } catch(_){ }
      } catch (e) {}
    }

    // Committee
    if (payload.committee !== undefined) {
      try { const ci = document.getElementById('bbCommitteeInput'); if (ci) ci.value = payload.committee || ''; } catch(e){}
    }

    // Safety: ensure roster and team inputs remain editable for admins
    try {
      // Default to editable when role is not injected; otherwise honor role.
      const isAdmin = (typeof window.__role === 'undefined') || (window.__role === 'admin' || window.__role === 'superadmin');
      if (isAdmin) {
        const selectors = ['#teamAName', '#teamBName', '#bbCommitteeInput', '#tbodyA input', '#tbodyB input', '.team-name-input'];
        selectors.forEach(function(sel) {
          try { document.querySelectorAll(sel).forEach(function(el) { try { el.disabled = false; el.readOnly = false; } catch(_){} }); } catch(_) {}
        });
      }
      // Ensure loops respect running flags: if incoming payload indicates
      // timers are stopped, make sure local loops are not running.
      try {
        const _isExplicitTimerControl = !!(
          (payload && payload._timerControl === true) ||
          (payload && payload.meta && payload.meta.control)
        );
        if (_isExplicitTimerControl) {
          // Only explicit control signals (play/pause/reset from immediatePersistControl)
          // may stop timers. Passive echoes and roster/counter updates must not.
          if (payload.gameTimer && typeof payload.gameTimer.running === 'boolean' && payload.gameTimer.running === false) {
            gtRunning = false; gtLastTick = null;
            try { applyTimerButtonState('game', false); } catch(_){ }
          }
          if (payload.shotClock && typeof payload.shotClock.running === 'boolean' && payload.shotClock.running === false) {
            scRunning = false; scLastTick = null;
            try { applyTimerButtonState('shot', false); } catch(_){ }
          }
        }
      } catch(_) {}
    } catch(_) {}

    // Restore focus to previously active input if it was in roster
    if (activeInputInfo) {
      setTimeout(() => {
        const tbody = document.getElementById('tbodyA') || document.getElementById('tbodyB');
        if (tbody) {
          const tr = tbody.querySelector(`tr[data-player-id="${activeInputInfo.playerId}"]`);
          if (tr) {
            const inp = tr.querySelector(`input.${activeInputInfo.className.split(' ').join('.')}`);
            if (inp) {
              inp.value = activeInputInfo.value;
              inp.focus();
              // Set cursor at end
              inp.setSelectionRange(activeInputInfo.value.length, activeInputInfo.value.length);
            }
          }
        }
      }, 0);
    }

  } finally {
    _appApplyingRemote = false;
  }
}

// Build the canonical full-state payload used for broadcasts and caching
function buildStatePayload() {
  const committee = document.getElementById('bbCommitteeInput')?.value?.trim() || '';
  const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0) + (typeof state.teamA.manualScore === 'number' ? state.teamA.manualScore : 0);
  const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0) + (typeof state.teamB.manualScore === 'number' ? state.teamB.manualScore : 0);
  return {
    teamA: {
      name: state.teamA.name,
      score: scoreA,
      foul: state.teamA.foul,
      timeout: state.teamA.timeout,
      manualScore: typeof state.teamA.manualScore === 'number' ? state.teamA.manualScore : 0,
      quarter: state.shared.quarter,
      players: state.teamA.players.map(p => ({ id: p.id, no: p.no, name: p.name, pts: p.pts, foul: p.foul, reb: p.reb, ast: p.ast, blk: p.blk, stl: p.stl, techFoul: p.techFoul, techReason: p.techReason }))
    },
    teamB: {
      name: state.teamB.name,
      score: scoreB,
      foul: state.teamB.foul,
      timeout: state.teamB.timeout,
      manualScore: typeof state.teamB.manualScore === 'number' ? state.teamB.manualScore : 0,
      quarter: state.shared.quarter,
      players: state.teamB.players.map(p => ({ id: p.id, no: p.no, name: p.name, pts: p.pts, foul: p.foul, reb: p.reb, ast: p.ast, blk: p.blk, stl: p.stl, techFoul: p.techFoul, techReason: p.techReason }))
    },
    shared: { ...state.shared },
    resetAt: _lastStateResetTs,
    committee,
  };
}

// Ensure viewers are notified if admin unloads/reloads — write synchronously
// NOTE: flush any pending roster/state persistence that was debounced,
// and send a final timer notification to viewers.
function flushStateOnUnload() {
  try {
    // Flush any pending debounced persist to ensure roster is saved before unload
    if (_persistTimer) {
      clearTimeout(_persistTimer);
      _persistTimer = null;
      try {
        const mid = getMatchId();
        if (mid && String(mid) !== '0') {
          // Build full payload and persist immediately (synchronously)
          const payload = buildStatePayload();
          const extraHeaders = { 'Content-Type': 'application/json', 'X-Unload-Flush': '1' };
          try {
            if (window && window.__userId) extraHeaders['X-SS-UID'] = String(window.__userId);
            if (window && window.__role) extraHeaders['X-SS-ROLE'] = String(window.__role);
          } catch (_) {}
          // Strip timers so we don't overwrite server timers; preserve roster
          const outgoing = JSON.parse(JSON.stringify(payload || {}));
          try { delete outgoing.gameTimer; } catch(_){}
          try { delete outgoing.shotClock; } catch(_){}
          // Use fetch with keepalive so the request survives page unload
          try {
            fetch('state.php', {
              method: 'POST',
              headers: extraHeaders,
              credentials: 'include',
              body: JSON.stringify({ match_id: mid, payload: outgoing }),
              keepalive: true
            }).catch(()=>{});
          } catch (_) {}
        }
      } catch (_) {}
    }

    // Also send timer snapshot to WS relay for viewers
    try {
      const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
      const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
      const minimal = {
        teamA: { score: scoreA, manualScore: typeof state.teamA.manualScore === 'number' ? state.teamA.manualScore : 0, foul: state.teamA.foul, timeout: state.teamA.timeout, quarter: state.shared.quarter },
        teamB: { score: scoreB, manualScore: typeof state.teamB.manualScore === 'number' ? state.teamB.manualScore : 0, foul: state.teamB.foul, timeout: state.teamB.timeout, quarter: state.shared.quarter },
        shared: { ...state.shared },
        gameTimer: { remaining: typeof gtRemaining !== 'undefined' ? gtRemaining : 0, total: typeof gtTotalSecs !== 'undefined' ? gtTotalSecs : 600, running: !!gtRunning },
        shotClock: { remaining: typeof scRemaining !== 'undefined' ? scRemaining : 24, total: typeof scTotal !== 'undefined' ? scTotal : 24, running: !!scRunning }
      };
      const mid = getMatchId();
      const wsOut = { type: 'timer_update', match_id: mid, gameTimer: { total: minimal.gameTimer.total, remaining: minimal.gameTimer.remaining, running: !!minimal.gameTimer.running, ts: Date.now() }, shotClock: { total: minimal.shotClock.total, remaining: minimal.shotClock.remaining, running: !!minimal.shotClock.running, ts: Date.now() }, ts: Date.now(), meta: { clientId: CLIENT_ID, unload: true } };
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        try { _ws.send(JSON.stringify(wsOut)); } catch(_){}
      }
    } catch (_) {}
  } catch (_) {}
}

// Send final state when page is being unloaded so viewers don't keep running stale timers
window.addEventListener('pagehide', function () { flushStateOnUnload(); });
window.addEventListener('beforeunload', function () { flushStateOnUnload(); });

// If we returned from a save->report redirect, some browsers may restore the previous
// admin page from bfcache. Detect that case and clear persisted admin snapshot so
// the prior match is not reused when the user navigates back.
window.addEventListener('pageshow', function (e) {
  try {
    if (sessionStorage.getItem('shouldClearPersistedOnBack:basketball') === '1') {
      sessionStorage.removeItem('shouldClearPersistedOnBack:basketball');
      try { localStorage.removeItem('basketball_viewMode'); } catch (err) {}
      // Force a reload when the page was restored from bfcache to ensure
      // a clean session. For normal navigations (back button), attempt a
      // server-first rehydration so the admin rejoins the canonical state
      // and remains synchronized with other admins.
      if (e && e.persisted) {
        window.location.reload();
      } else {
        try { loadStateFromServerIfMissing(true).then(function(applied){ if (!applied) { try { broadcastState(); } catch(_){} } }).catch(function(){}); } catch (_) {}
      }
    }
  } catch (err) {}
});

// ----- persist to server (debounced) -----
let _persistTimer = null;
let _rosterTypingDebounce = null; // debounce handle for name/no/techReason input
function schedulePersistToServer(payload) {
  try {
    // Do not perform automatic server persists until initial server-first
    // hydration has completed. This prevents a newly-loading admin from
    // writing default/local state into the canonical `match_state` row.
    if (!_initialHydrationDone) return;
    // Don't attempt server persist if we don't have a valid match id yet
    const mid = getMatchId();
    // Avoid sending match_id=0 (state.php treats it as missing/invalid)
    if (!mid || String(mid) === '0' || isNaN(parseInt(mid,10)) || parseInt(mid,10) <= 0) return;
    if (_persistTimer) clearTimeout(_persistTimer);
    _persistTimer = setTimeout(() => {
      _persistTimer = null;
      try {
        const extraHeaders = { 'Content-Type': 'application/json' };
        try {
          if (window && window.__userId) extraHeaders['X-SS-UID'] = String(window.__userId);
          if (window && window.__role)   extraHeaders['X-SS-ROLE'] = String(window.__role);
        } catch (_) {}

        // Do not overwrite server timers here — timers are canonical in `timer.php`.
        // Strip timer fields from automatic debounced persists so a reloading
        // client cannot clobber running timers saved in the timer store.
        // The server-side preserves existing timers when no control is present.
        try {
          const outgoing = JSON.parse(JSON.stringify(payload || {}));
          try { delete outgoing.gameTimer; } catch(_){}
          try { delete outgoing.shotClock; } catch(_){}
          fetch('state.php', {
            method: 'POST',
            headers: extraHeaders,
            credentials: 'include',
            body: JSON.stringify({ match_id: mid, payload: outgoing }),
            keepalive: true
          }).then(r => r.json()).catch(()=>{});
        } catch (_) {
          // Fallback: send payload as-is if serialization fails (very unlikely)
          fetch('state.php', { method: 'POST', headers: extraHeaders, credentials: 'include', body: JSON.stringify({ match_id: mid, payload }), keepalive: true }).catch(()=>{});
        }
      } catch (_) {}
    }, 400);
  } catch (_) {}
}

// Persist state immediately to server (for critical updates like shared counters)
function persistStateImmediately(payload) {
  try {
    const mid = getMatchId();
    const extraHeaders = { 'Content-Type': 'application/json' };
    try {
      if (window && window.__userId) extraHeaders['X-SS-UID'] = String(window.__userId);
      if (window && window.__role)   extraHeaders['X-SS-ROLE'] = String(window.__role);
    } catch (_) {}

    // Strip timer fields from immediate persists so a reloading
    // client cannot clobber running timers saved in the timer store.
    try {
      const outgoing = JSON.parse(JSON.stringify(payload || {}));
      try { delete outgoing.gameTimer; } catch(_){}
      try { delete outgoing.shotClock; } catch(_){}
      fetch('state.php', {
        method: 'POST',
        headers: extraHeaders,
        credentials: 'include',
        body: JSON.stringify({ match_id: mid, payload: outgoing }),
        keepalive: true
      }).then(r => r.json()).catch(()=> {});
    } catch (_) {
      // Fallback: send payload as-is if serialization fails (very unlikely)
      fetch('state.php', { method: 'POST', headers: extraHeaders, credentials: 'include', body: JSON.stringify({ match_id: mid, payload }), keepalive: true }).catch(()=> {});
    }
  } catch (_) {}
}

// Persist an explicit control (start/pause/reset) immediately to server
// control: 'start' | 'pause' | 'reset'
// timerType: 'game' | 'shot' | undefined (when omitted, use current local state)
function immediatePersistControl(control, timerType) {
  // Return a Promise that resolves when the canonical timer.php write completes.
  return new Promise(async (resolve, reject) => {
    try {
      const mid = getMatchId();
      if (!mid || String(mid) === '0' || isNaN(parseInt(mid,10)) || parseInt(mid,10) <= 0) return resolve({ success: false, error: 'invalid match_id' });
      const extraHeaders = { 'Content-Type': 'application/json' };
      try {
        if (window && window.__userId) extraHeaders['X-SS-UID'] = String(window.__userId);
        if (window && window.__role)   extraHeaders['X-SS-ROLE'] = String(window.__role);
      } catch (_) {}

      const nowMs = Date.now();
      const computeCurrentGameRemaining = () => {
        try {
          if (typeof gtRemainingAtAnchor === 'number' && typeof gtAnchorTs === 'number') {
            return Math.max(0, gtRemainingAtAnchor - ((nowMs - Number(gtAnchorTs)) / 1000));
          }
        } catch (_) {}
        return Math.max(0, typeof gtRemaining === 'number' ? gtRemaining : 0);
      };
      const computeCurrentShotRemaining = () => {
        try {
          if (typeof scRemainingAtAnchor === 'number' && typeof scAnchorTs === 'number') {
            return Math.max(0, scRemainingAtAnchor - ((nowMs - Number(scAnchorTs)) / 1000));
          }
        } catch (_) {}
        return Math.max(0, typeof scRemaining === 'number' ? scRemaining : 0);
      };
      const makeGameTimerPayload = (applyControl) => {
        const currentRemaining = computeCurrentGameRemaining();
        const total = Math.round(typeof gtTotalSecs === 'number' ? gtTotalSecs : 600);
        if (applyControl === 'start') {
          return { total: total, remaining: currentRemaining, running: true, ts: nowMs };
        } else if (applyControl === 'pause') {
          return { total: total, remaining: currentRemaining, running: false, ts: null };
        } else if (applyControl === 'reset') {
          return { total: total, remaining: total, running: false, ts: null };
        }
        return { total: total, remaining: currentRemaining, running: !!gtRunning, ts: gtRunning ? nowMs : null };
      };
      const makeShotClockPayload = (applyControl) => {
        const currentRemaining = computeCurrentShotRemaining();
        const total = Math.round(typeof scTotal === 'number' ? scTotal : 24);
        if (applyControl === 'start') {
          return { total: total, remaining: currentRemaining, running: true, ts: nowMs };
        } else if (applyControl === 'pause') {
          return { total: total, remaining: currentRemaining, running: false, ts: null };
        } else if (applyControl === 'reset') {
          return { total: total, remaining: total, running: false, ts: null };
        }
        return { total: total, remaining: currentRemaining, running: !!scRunning, ts: scRunning ? nowMs : null };
      };

      const applyTimerPayloadToLocalState = (latestPayload) => {
        if (!latestPayload) return;
        try {
          const g = latestPayload.gameTimer || latestPayload.game_timer || null;
          if (g) {
            if (typeof g.total === 'number') gtTotalSecs = g.total;
            if (typeof g.remaining === 'number') gtRemaining = g.remaining;
            const serverRunning = (typeof g.running === 'boolean') ? g.running : null;
            const tsVal = (typeof g.ts === 'number') ? g.ts : (typeof g.start_timestamp === 'number' ? g.start_timestamp : null);
            if (serverRunning === true && tsVal !== null) {
              gtRunning = true;
              gtRemainingAtAnchor = gtRemaining;
              gtAnchorTs = Number(tsVal);
              gtLastTick = null;
            } else if (serverRunning === false) {
              gtRunning = false;
              gtAnchorTs = null;
              gtRemainingAtAnchor = null;
              gtLastTick = null;
            }
            gtRender();
            try { applyTimerButtonState('game', gtRunning); } catch(_){}
          }
          const s = latestPayload.shotClock || latestPayload.shot_clock || null;
          if (s) {
            if (typeof s.total === 'number') { scTotal = s.total; scPresetVal = s.total; refreshScPresetActive(); }
            if (typeof s.remaining === 'number') scRemaining = s.remaining;
            const serverRunning = (typeof s.running === 'boolean') ? s.running : null;
            const tsVal = (typeof s.ts === 'number') ? s.ts : (typeof s.start_timestamp === 'number' ? s.start_timestamp : null);
            if (serverRunning === true && tsVal !== null) {
              scRunning = true;
              scRemainingAtAnchor = scRemaining;
              scAnchorTs = Number(tsVal);
              scLastTick = null;
            } else if (serverRunning === false) {
              scRunning = false;
              scAnchorTs = null;
              scRemainingAtAnchor = null;
              scLastTick = null;
            }
            scRenderFrame();
            try { applyTimerButtonState('shot', scRunning); } catch(_){}
          }
        } catch (_) {}
      };
      const gameTimerPayload = makeGameTimerPayload(timerType === 'game' ? String(control) : undefined);
      const shotClockPayload = makeShotClockPayload(timerType === 'shot' ? String(control) : undefined);
      const metaObj = { control: String(control), clientId: CLIENT_ID };
      try { if (typeof timerType === 'string' && timerType) metaObj.timer = timerType; } catch(_) {}
      const body = JSON.stringify({ match_id: mid, gameTimer: gameTimerPayload, shotClock: shotClockPayload, meta: metaObj });
      // Debug: log outgoing timer control request
      try { console.debug('[immediatePersistControl] POST body:', gameTimerPayload, shotClockPayload, 'meta control=', control, 'timerType=', timerType); } catch(_) {}
      fetch('timer.php', { method: 'POST', headers: extraHeaders, credentials: 'include', body, keepalive: true })
        .then(res => res.json())
        .then(j => {
          try { console.debug('[immediatePersistControl] timer.php response:', j); } catch(_) {}
          if (j && j.success) {
            try { if (j.payload) applyTimerPayloadToLocalState(j.payload); } catch(_) {}
            try { postImmediateTimerUpdate({ control: control }); } catch(_) {}
            resolve(j);
          } else {
            // If server returned failure, try starting locally as fallback
            try { console.warn('[immediatePersistControl] server persist failed, attempting local fallback', j); } catch(_) {}
            try {
              if (control === 'start') {
                if (timerType === 'game') try { _origGtPlay(); } catch(_) {}
                if (timerType === 'shot') try { _origScPlay(); } catch(_) {}
              } else if (control === 'pause') {
                if (timerType === 'game') try { _origGtPause(); } catch(_) {}
                if (timerType === 'shot') try { _origScPause(); } catch(_) {}
              } else if (control === 'reset') {
                if (timerType === 'game') try { _origGtReset(); } catch(_) {}
                if (timerType === 'shot') try { _origScReset(); } catch(_) {}
              }
            } catch(_){}
            reject(j || { success:false, error: 'timer persist failed' });
          }
        }).catch(err => {
          try { console.error('[immediatePersistControl] fetch error:', err); } catch(_){}
          // Network error: try local fallback so UX isn't blocked
          try {
            if (control === 'start') {
              if (timerType === 'game') try { _origGtPlay(); } catch(_) {}
              if (timerType === 'shot') try { _origScPlay(); } catch(_) {}
            } else if (control === 'pause') {
              if (timerType === 'game') try { _origGtPause(); } catch(_) {}
              if (timerType === 'shot') try { _origScPause(); } catch(_) {}
            } else if (control === 'reset') {
              if (timerType === 'game') try { _origGtReset(); } catch(_) {}
              if (timerType === 'shot') try { _origScReset(); } catch(_) {}
            }
          } catch(_){}
          reject(err);
        });
    } catch (e) { reject(e); }
  });
}

// Hook broadcastState into every state-mutating function
const _origRecalcScore  = recalcScore;
recalcScore = function (team) { _origRecalcScore(team); try { markRosterDirty(); } catch(_) {} };

const _origAdjustShared = adjustShared;
/* Note: `adjustShared` and `adjustTsb` already call `broadcastState()`
  internally. Removing redundant wrapper broadcasts to avoid duplicate
  broadcasts when these helpers are invoked. */

const _origOnTeamName = onTeamName;
onTeamName = function (team) { _origOnTeamName(team); try { markRosterDirty(); } catch(_) {} };

// Committee input broadcasting is attached in init with a dataset guard
// to ensure a single listener is present (see bottom of file).

// Broadcast timer ticks (throttled — every 250ms max to avoid spam)
// For realtime viewers: post high-frequency timer updates immediately
// over BroadcastChannel (if available), but keep full localStorage
// + full-state broadcast throttled to 250ms to avoid excessive writes.
let _bkTimerThrottle = null;
function broadcastTimerStateThrottled() {
  if (_bkTimerThrottle) return;
  _bkTimerThrottle = setTimeout(function () {
    _bkTimerThrottle = null;
    // While timers are running, avoid sending the full state (which
    // contains player rosters) to prevent frequent re-renders that
    // steal focus and block typing in other admin tabs. Send only the
    // lightweight timer payload instead.
    try { postImmediateTimerUpdate(); } catch(_) { /* fallback */ }
  }, 250);
}

function postImmediateTimerUpdate(opts) {
  // send minimal payload containing just timers and scores for viewers
  try {
    const scoreA = state.teamA.players.reduce((s, p) => s + (p.pts || 0), 0);
    const scoreB = state.teamB.players.reduce((s, p) => s + (p.pts || 0), 0);
    const eventTs = Date.now();
    const meta = (opts && opts.control) ? { clientId: CLIENT_ID, control: opts.control, ts: eventTs } : null;
    if (opts && opts.control && eventTs > _lastTimerControlTs) {
      _lastTimerControlTs = eventTs;
    }
    const payload = {
      teamA: { score: scoreA, manualScore: typeof state.teamA.manualScore === 'number' ? state.teamA.manualScore : 0, foul: state.teamA.foul, timeout: state.teamA.timeout, quarter: state.shared.quarter },
      teamB: { score: scoreB, manualScore: typeof state.teamB.manualScore === 'number' ? state.teamB.manualScore : 0, foul: state.teamB.foul, timeout: state.teamB.timeout, quarter: state.shared.quarter },
      shared: { ...state.shared },
      gameTimer: {
        // When anchored to a server timestamp, expose the remaining_at_start
        // and the original start timestamp so receivers compute live values.
        remaining: (typeof gtRemainingAtAnchor === 'number') ? gtRemainingAtAnchor : (typeof gtRemaining !== 'undefined' ? gtRemaining : 0),
        total: typeof gtTotalSecs !== 'undefined' ? gtTotalSecs : 600,
        running: typeof gtRunning !== 'undefined' ? gtRunning : false,
        ts: gtRunning ? ((typeof gtAnchorTs === 'number') ? gtAnchorTs : Date.now()) : null
      },
      shotClock: {
        remaining: (typeof scRemainingAtAnchor === 'number') ? scRemainingAtAnchor : (typeof scRemaining !== 'undefined' ? scRemaining : 24),
        total: typeof scTotal !== 'undefined' ? scTotal : 24,
        running: typeof scRunning !== 'undefined' ? scRunning : false,
        ts: scRunning ? ((typeof scAnchorTs === 'number') ? scAnchorTs : Date.now()) : null
      }
    };
    // If we are currently hydrating canonical server state, avoid emitting
    // any local timer/state writes that could overwrite the SSOT. These
    // clients will apply the server payload and then resume normal
    // broadcasts once hydration completes.
    try {
      if (_hydrationPending) {
        try { _lastOutgoingTimerSerialized = JSON.stringify(payload); } catch(_){}
        return;
      }
    } catch (_) {}
    // Write minimal payload synchronously to localStorage so other tabs
    // (which may not support BroadcastChannel) receive the update via
    // the storage event immediately. This is a small payload and
    // should not block noticeably. Keep BroadcastChannel post for
    // fastest same-browser updates.
    try {
      const s = JSON.stringify(payload);
      try { _lastOutgoingTimerSerialized = s; } catch(_){ }
      try {
          const wrapper = JSON.stringify({ match_id: getMatchId(), payload: payload, ts: eventTs, meta: meta });
          const prev = localStorage.getItem(_BK_TIMER_KEY);
          if (prev !== wrapper) localStorage.setItem(_BK_TIMER_KEY, wrapper);
        } catch (_) { try { localStorage.setItem(_BK_TIMER_KEY, JSON.stringify({ match_id: getMatchId(), payload: payload, ts: eventTs, meta: meta })); } catch(_){} }
      } catch (_) { /* ignore storage errors */ }
    // Post to BroadcastChannel if available (fast same-browser updates)
    try { if (_bkBC) _bkBC.postMessage({ match_id: getMatchId(), payload: payload, ts: eventTs, meta: meta }); } catch (_) {}
    try {
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        const out = { type: 'timer_update', sport: 'basketball', match_id: getMatchId(), gameTimer: payload.gameTimer, shotClock: payload.shotClock, ts: eventTs };
        if (meta) out.meta = meta;
        _ws.send(JSON.stringify(out));
      }
    } catch (_) {}
    // Debounced persist to server-side timer endpoint (best-effort)
    try {
      scheduleTimerPersist(opts && opts.control ? opts.control : null);
    } catch (_) {}
  } catch (_) { /* ignore */ }
}

function scheduleTimerPersist(control) {
  try {
    if (_timerPersistTimeout) clearTimeout(_timerPersistTimeout);
    _timerPersistTimeout = setTimeout(function () {
      try { persistTimersToServer(control); } catch(_){}
      _timerPersistTimeout = null;
    }, TIMER_PERSIST_DEBOUNCE_MS);
  } catch (_) {}
}

function persistTimersToServer(control) {
  try {
    const mid = getMatchId();
    if (!mid || String(mid) === '0') return;
    const nowMs = Date.now();
    const currentGameRemaining = (typeof gtRemainingAtAnchor === 'number' && typeof gtAnchorTs === 'number')
      ? Math.max(0, gtRemainingAtAnchor - ((nowMs - Number(gtAnchorTs)) / 1000))
      : Math.max(0, typeof gtRemaining === 'number' ? gtRemaining : 0);
    const currentShotRemaining = (typeof scRemainingAtAnchor === 'number' && typeof scAnchorTs === 'number')
      ? Math.max(0, scRemainingAtAnchor - ((nowMs - Number(scAnchorTs)) / 1000))
      : Math.max(0, typeof scRemaining === 'number' ? scRemaining : 0);
    const body = {
      match_id: mid,
      gameTimer: {
        total: typeof gtTotalSecs === 'number' ? gtTotalSecs : 0,
        remaining: currentGameRemaining,
        running: !!gtRunning,
        ts: gtRunning ? nowMs : null
      },
      shotClock: {
        total: typeof scTotal === 'number' ? scTotal : 0,
        remaining: currentShotRemaining,
        running: !!scRunning,
        ts: scRunning ? nowMs : null
      }
    };
    if (control) body.meta = { control: control, clientId: CLIENT_ID };
    try {
      fetch('timer.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).catch(function(){});
    } catch (_) {}
  } catch (_) {}
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

// Helper: apply server timer response (from state.php immediate persist)
function applyServerTimerResponse(timerType, j) {
  try {
    if (!j || !j.payload) return;
    if (timerType === 'game') {
      const g = j.payload && (j.payload.gameTimer || j.payload.game_timer) ? (j.payload.gameTimer || j.payload.game_timer) : null;
      if (g && g.running && (typeof g.ts === 'number' || typeof g.start_timestamp === 'number')) {
        gtRemainingAtAnchor = (typeof g.remaining === 'number') ? g.remaining : (typeof g.remaining_ms === 'number' ? g.remaining_ms / 1000 : gtRemaining);
        gtAnchorTs = Number(g.ts || g.start_timestamp);
      } else {
        gtAnchorTs = null; gtRemainingAtAnchor = null;
      }
    } else if (timerType === 'shot') {
      const s = j.payload && (j.payload.shotClock || j.payload.shot_clock) ? (j.payload.shotClock || j.payload.shot_clock) : null;
      if (s && s.running && (typeof s.ts === 'number' || typeof s.start_timestamp === 'number')) {
        scRemainingAtAnchor = (typeof s.remaining === 'number') ? s.remaining : (typeof s.remaining_ms === 'number' ? s.remaining_ms / 1000 : scRemaining);
        scAnchorTs = Number(s.ts || s.start_timestamp);
      } else {
        scAnchorTs = null; scRemainingAtAnchor = null;
      }
    }
  } catch (_) {}
}

// Helper: flash document title temporarily
function flashTitle(msg, times, intervalMs) {
  try {
    const _times = typeof times === 'number' ? times : 8;
    const _interval = typeof intervalMs === 'number' ? intervalMs : 450;
    const orig = document.title;
    let i = 0;
    const id = setInterval(() => {
      try { document.title = i++ % 2 === 0 ? msg : orig; } catch(_) {}
      if (i >= _times) { clearInterval(id); try { document.title = orig; } catch(_) {} }
    }, _interval);
  } catch(_) {}
}

// Hook into game timer and shot clock tick functions
const _origGtTick = gtTick;
gtTick = function () { _origGtTick(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };

const _origScTick = scTick;
scTick = function () { _origScTick(); postImmediateTimerUpdate(); broadcastTimerStateThrottled(); };

// Generic timer control handler to reduce duplication
function _makeTimerControlHandler(origFn, control, timerType, timerLabel) {
  return function () {
    try { origFn(); } catch (_) {}
    try {
      postImmediateTimerUpdate({ control: control });
      broadcastTimerStateThrottled();
    } catch(_) {}
    immediatePersistControl(control, timerType).then((j) => {
      try {
        if (control === 'start') {
          applyServerTimerResponse(timerType, j);
        }
      } catch(_) {}
      try { postImmediateTimerUpdate({ control: control }); } catch(_) {}
      try { broadcastTimerStateThrottled(); } catch(_) {}
    }).catch(err => {
      console.error('immediatePersistControl failed', err);
      try { showToast(`Failed to save ${timerLabel} ${control} — broadcast skipped`); } catch(_){}
    });
  };
}

// Apply consolidated timer control handlers
const _origGtPlay = gtPlay;
gtPlay = _makeTimerControlHandler(_origGtPlay, 'start', 'game', 'game timer');

const _origGtPause = gtPause;
gtPause = _makeTimerControlHandler(_origGtPause, 'pause', 'game', 'game timer');

const _origGtReset = gtReset;
gtReset = _makeTimerControlHandler(_origGtReset, 'reset', 'game', 'game timer');

const _origScPlay = scPlay;
scPlay = _makeTimerControlHandler(_origScPlay, 'start', 'shot', 'shot clock');

const _origScPause = scPause;
scPause = _makeTimerControlHandler(_origScPause, 'pause', 'shot', 'shot clock');

const _origScReset = scReset;
scReset = _makeTimerControlHandler(_origScReset, 'reset', 'shot', 'shot clock');

// Handle preset and duration changes
const _origScPreset = scPreset;
scPreset = function (s) {
  // Apply preset values and render locally without triggering the wrapped scReset
  scPresetVal = s;
  scTotal = s;
  refreshScPresetActive();
  try { _origScReset(); } catch (_) {}
  try { postImmediateTimerUpdate({ control: 'reset' }); } catch (_) {}
  try { broadcastTimerStateThrottled(); } catch (_) {}
  immediatePersistControl('reset', 'shot').then((j) => {
    try { applyServerTimerResponse('shot', j); } catch (_) {}
    try { postImmediateTimerUpdate({ control: 'reset' }); } catch (_) {}
    try { broadcastTimerStateThrottled(); } catch (_) {}
  }).catch(err => {
    console.error('immediatePersistControl shot preset failed', err);
  });
};

const _origGtSetDuration = gtSetDuration;
gtSetDuration = function () {
  try { _origGtSetDuration(); } catch (_) {}
  try { postImmediateTimerUpdate({ control: 'reset' }); broadcastTimerStateThrottled(); } catch (_) {}
  immediatePersistControl('reset', 'game').then((j) => {
    try { applyServerTimerResponse('game', j); } catch (_) {}
    try { postImmediateTimerUpdate({ control: 'reset' }); } catch (_) {}
    try { broadcastTimerStateThrottled(); } catch (_) {}
  }).catch(err => {
    console.error('immediatePersistControl game set failed', err);
  });
};

// Backwards compatible alias for renderRosterTable
window.renderRosterTable = bbRenderRosterTable;

// Create a new match via server and reset local UI to canonical fresh state
async function bbNewMatch() {
  try {
    if (!confirm('Create a new match and reset live state for all admins?')) return;
    const res = await fetch('new_match.php', { method: 'POST', credentials: 'include' });
    const j = await res.json();
    if (j && j.success) {
      // adopt new match id and reset locally without confirmation
      try { sessionStorage.setItem('basketball_match_id', String(j.match_id)); } catch (_) {}
      try { localStorage.setItem('basketball_match_id', String(j.match_id)); } catch (_) {}
      try { window.__matchId = String(j.match_id); } catch (_) {}
      try { resetMatch(true); } catch (_) {}
      // Apply canonical payload returned by server so the creating admin
      // adopts the exact same state as other clients receiving the broadcast.
      try { if (j.payload) applyIncomingState(j.payload); } catch (_) {}
      // Ensure timers are explicitly stopped locally and UI reflects stopped state
      try {
        gtRunning = false; scRunning = false;
        try { applyTimerButtonState('game', false); } catch(_){ }
        try { applyTimerButtonState('shot', false); } catch(_){ }
      } catch(_){}
      try { showToast('New match created: ' + String(j.match_id)); } catch (_) {}
      // Broadcast new match event via BroadcastChannel for immediate cross-tab sync
      try { if (_bkBC) _bkBC.postMessage({ type: 'new_match', match_id: String(j.match_id), payload: j.payload, ts: Date.now(), meta: { clientId: CLIENT_ID } }); } catch (_) {}
      // broadcast our cleared state so other tabs/devices receive it
      try { broadcastState(); } catch (_) {}
        // Write a dedicated localStorage key so other tabs adopt new match id
        try { localStorage.setItem('basketball_new_match', JSON.stringify({ match_id: j.match_id, payload: j.payload, ts: Date.now() })); } catch (_) {}
      // Ensure the WS relay notifies all connected clients: rejoin the new
      // match room and send a 'new_match' event (server treats this as
      // global and will broadcast to all clients). This mirrors the
      // report.php behavior (which reloads the page) but keeps UX live.
      try {
        if (_ws && _ws.readyState === WebSocket.OPEN) {
          console.log('Re-joining match_id:', j.match_id);
          try { const role = (window && window.__role) ? String(window.__role) : 'unknown'; _ws.send(JSON.stringify({ type: 'join', match_id: String(j.match_id), role: role })); } catch(_){}
          try {
            // Ensure outgoing new_match payload forces timers to be stopped
            const safePayload = j.payload && typeof j.payload === 'object' ? JSON.parse(JSON.stringify(j.payload)) : {};
            safePayload.gameTimer = safePayload.gameTimer || {};
            safePayload.gameTimer.running = false;
            safePayload.shotClock = safePayload.shotClock || {};
            safePayload.shotClock.running = false;
            _ws.send(JSON.stringify({ type: 'new_match', sport: 'basketball', match_id: String(j.match_id), payload: safePayload, ts: Date.now(), meta: { clientId: CLIENT_ID } }));
          } catch(_){ }
        }
      } catch (_) {}
      // Adopt server-supplied payload locally (no reload). applyIncomingState
      // is the shared single codepath used by other clients when they
      // receive canonical state, so reuse it here to avoid special-casing.
      try { if (j.payload) applyIncomingState(j.payload); } catch (_) {}
      return;
    }
    showToast('Failed to create new match');
  } catch (e) { console.error('newMatch error', e); showToast('Error creating new match'); }
}

// addPlayer uses bbRenderRosterTable and event delegation for inputs

// Load persisted state (if any) then broadcast on page load so viewer sees state immediately


// Helper: ensure timer control buttons are bound to current functions
function rebindTimerControls() {
  try {
    const p = document.getElementById('gtPlayBtn');
    const pa = document.getElementById('gtPauseBtn');
    const r = document.getElementById('gtResetBtn');
    if (p) p.onclick = function () { try { gtPlay(); } catch (e) {} };
    if (pa) pa.onclick = function () { try { gtPause(); } catch (e) {} };
    if (r) r.onclick = function () { try { showResetWarning('game'); } catch (e) {} };

    const sp = document.getElementById('scPlayBtn');
    const spa = document.getElementById('scPauseBtn');
    const sr = document.getElementById('scResetBtn');
    if (sp) sp.onclick = function () { try { scPlay(); } catch (e) {} };
    if (spa) spa.onclick = function () { try { scPause(); } catch (e) {} };
    if (sr) sr.onclick = function () { try { showResetWarning('shot'); } catch (e) {} };
    } catch (_) {}
}

// Bind controls, then hydrate from server (server-first). If the server

(async function() {
  try {
    // STEP A: Restore from localStorage FIRST
    const saved = localStorage.getItem('basketball_state');
    if (saved) {
      Object.assign(state, JSON.parse(saved));
      if (!Array.isArray(state.teamA.players)) state.teamA.players = [];
      if (!Array.isArray(state.teamB.players)) state.teamB.players = [];
    }
    // STEP B: Render DOM with restored state
    bbRenderRosterTable();
    syncRightPanelCounters();
    // STEP C: Then hydrate from server async
    // Mark hydration in progress to prevent this client's initial
    // local writes from stomping the server SSOT while we fetch it.
    _hydrationPending = true;
    const applied = await loadStateFromServerIfMissing(true);
    _hydrationPending = false;
    // mark hydration complete so automatic debounced writes are allowed
    _initialHydrationDone = true;
    if (!applied) {
      // No local persisted roster/counter state on reload; defer to server/broadcast.
    }

    // Initialize timers from server state after server state is loaded
    initializeTimersFromServerState();

  } catch (e) {
    _hydrationPending = false;
    _initialHydrationDone = true;
    // Do NOT use local persisted roster/counter state on hydration error.
  }
})();
// Announce sport selection so viewers can auto-switch to this sport
function broadcastSportChange(sport) {
  try {
    const mid = getMatchId();
    const payload = { sport: sport };
    if (_bkBC) try { _bkBC.postMessage({ type: 'sport_change', match_id: mid, sport, payload }); } catch (_) {}
    try { if (_ws && _ws.readyState === WebSocket.OPEN) _ws.send(JSON.stringify({ type: 'sport_change', match_id: mid, sport: sport, payload })); } catch (_) {}
    try { localStorage.setItem('_last_sport', JSON.stringify({ match_id: mid, sport })); } catch (_) {}
  } catch (_) {}
}
try { broadcastSportChange('basketball'); } catch (_) {}
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
    // Role-aware: make team name inputs editable for admins only
    try {
      // If role isn't explicitly injected on the page, assume editable
      // (legacy pages may not set window.__role). If role exists, honor it.
      const isAdmin = (typeof window.__role === 'undefined') || (window.__role === 'admin' || window.__role === 'superadmin');
      const tA = document.getElementById('teamAName');
      const tB = document.getElementById('teamBName');
      if (tA) { tA.readOnly = !isAdmin; tA.tabIndex = isAdmin ? 0 : -1; }
      if (tB) { tB.readOnly = !isAdmin; tB.tabIndex = isAdmin ? 0 : -1; }
      // Ensure committee input broadcasts state when edited
      try {
        const ci = document.getElementById('bbCommitteeInput');
        if (ci && !ci.dataset._bk) {
          ci.addEventListener('input', function () { try { broadcastState(); } catch(_) {} });
          ci.dataset._bk = '1';
        }
      } catch(_) {}
    } catch(_) {}
} catch (e) { /* ignore early load errors */ }
