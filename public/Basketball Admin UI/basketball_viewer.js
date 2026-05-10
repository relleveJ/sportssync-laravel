// ============================================================
// basketball_viewer.js — Read-only live viewer
//
// HOW IT STAYS LIVE:
//   app.js (patched) broadcasts the full game state on every
//   action via two channels simultaneously:
//
//   1. BroadcastChannel('basketball_live')
//      → instant push to other tabs in the same browser
//
//   2. localStorage key 'basketballLiveState'
//      → window 'storage' event fires instantly in any
//        other tab that is listening
//
//   On page load, init() reads whatever is already in
//   localStorage so the viewer shows current state even if
//   it was opened after the admin tab was already running.
// ============================================================

const STORAGE_KEY  = 'basketballLiveState';
const CHANNEL_NAME = 'basketball_live';
const SC_CIRCUMFERENCE = 2 * Math.PI * 52;  // matches app.js

// ── 1. BroadcastChannel (instant, same browser) ──────────────
let _bc = null;
try {
  _bc = new BroadcastChannel(CHANNEL_NAME);
  _bc.onmessage = function (e) {
    // Accept either direct state object or { state: ... }
    const payload = e.data && typeof e.data === 'object' ? (e.data.state || e.data) : null;
    if (payload) scheduleRender(payload);
  };
} catch (_) {}

// WebSocket relay client — receive cross-device updates from ws-server
// Keep references to remote WS objects so we can rejoin rooms on new_match
let _wsRemote = null;
let _ws = null;

// Adopt a newly-created match: persist session id, fetch/apply canonical state,
// rejoin WS rooms and render payload. Hoisted function so WS handlers can call it.
function adoptNewMatch(msg) {
  try {
    const newId = msg && (msg.match_id || (msg.payload && msg.payload.match_id)) ? String(msg.match_id || (msg.payload && msg.payload.match_id)) : null;
    if (!newId) return;
    try { sessionStorage.setItem('basketball_match_id', String(newId)); } catch(_) {}
    try { window.__matchId = String(newId); } catch(_) {}

    // If payload present apply immediately; otherwise fetch canonical state
    if (msg && msg.payload) {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(msg.payload)); } catch(_) {}
      try { scheduleRender(msg.payload); } catch(_) {}
    } else {
      (async function(){
        try {
          const res = await fetch('state.php?match_id=' + encodeURIComponent(newId));
          const j = await res.json();
          if (j && j.success && j.payload) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(j.payload)); } catch(_) {}
            try { scheduleRender(j.payload); } catch(_) {}
          }
        } catch(_) {}
      })();
    }

    // Rejoin rooms on any open WS connections
    try {
      const joinMsg = JSON.stringify({ type: 'join', match_id: String(newId) });
      if (_ws && _ws.readyState === WebSocket.OPEN) try { _ws.send(joinMsg); } catch(_) {}
      if (_wsRemote && _wsRemote.readyState === WebSocket.OPEN) try { _wsRemote.send(joinMsg); } catch(_) {}
    } catch(_) {}

    // Small transient UI hint
    try {
      const id = 'viewer_new_match_info';
      let el = document.getElementById(id);
      if (!el) { el = document.createElement('div'); el.id = id; el.style.position = 'fixed'; el.style.left = '12px'; el.style.top = '12px'; el.style.zIndex = 99999; el.style.background = 'rgba(0,0,0,0.7)'; el.style.color = '#fff'; el.style.padding = '8px 10px'; el.style.borderRadius = '6px'; document.body.appendChild(el); }
      el.textContent = 'Switched to new match ' + String(newId);
      setTimeout(() => { try { if (el && el.parentNode) el.parentNode.removeChild(el); } catch(_) {} }, 3500);
    } catch(_) {}
  } catch(_) {}
}
try {
  if (location && location.hostname) {
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let url = proto + '//' + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _wsRemote = new WebSocket(url);
    _wsRemote.addEventListener('open', function () {
      try {
        const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';
        const mid = (window.MATCH_DATA && MATCH_DATA.match_id) ? String(MATCH_DATA.match_id) : (window.__matchId || (new URLSearchParams(location.search).get('match_id')) || DEFAULT_ROOM_ID);
        _wsRemote.send(JSON.stringify({ type: 'join', match_id: String(mid) }));
      } catch(_) {}
    });
    _wsRemote.addEventListener('message', function (ev) {
      try {
        const msg = JSON.parse(ev.data);
        // handle global new_match events so viewers adopt the new room
        if (msg && msg.type === 'new_match') {
          try { adoptNewMatch(msg); } catch(_) {}
          return;
        }
        // server rebroadcasts the original `{ type, payload }` messages
        if (msg && msg.payload) scheduleRender(msg.payload);
      } catch (_) { /* ignore parse errors */ }
    });
    _wsRemote.addEventListener('error', function () { /* ignore */ });
  }
} catch (_) { /* ignore ws client errors */ }

// Server-Sent Events support removed. Viewer relies on BroadcastChannel
// and localStorage `storage` events for cross-tab updates.

// ── WebSocket relay (cross-device live updates) ──────────────
(function initWS() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      try { const s = JSON.parse(raw); if (s) scheduleRender(s); } catch (_) {}
    }
    // Try fetching canonical server-side state for this match_id to ensure correct restore
    (async function fetchServerState() {
      try {
        const urlParams = new URLSearchParams(location.search);
        const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';
        const mid = (window.MATCH_DATA && MATCH_DATA.match_id) ? String(MATCH_DATA.match_id) : (window.__matchId || urlParams.get('match_id') || DEFAULT_ROOM_ID);
        // Always attempt to fetch server state for the chosen room
        const res = await fetch('state.php?match_id=' + encodeURIComponent(mid));
        const j = await res.json();
        if (j && j.success && j.payload) {
          try { localStorage.setItem(STORAGE_KEY, JSON.stringify(j.payload)); } catch (e) {}
          scheduleRender(j.payload);
        }
      } catch (e) {}
    })();
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let url = proto + '//' + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    _ws = new WebSocket(url);
    _ws.addEventListener('open', function () {
      try {
        const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';
        const urlParams = new URLSearchParams(location.search);
        const mid = (window.MATCH_DATA && MATCH_DATA.match_id) ? String(MATCH_DATA.match_id) : (window.__matchId || urlParams.get('match_id') || DEFAULT_ROOM_ID);
        _ws.send(JSON.stringify({ type: 'join', match_id: String(mid) }));
      } catch (_) {}
    });
    
      _ws.addEventListener('message', function (ev) {
        try {
          const m = JSON.parse(ev.data);
          if (!m) return;
          if (m.type === 'last_state' && m.payload) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(m.payload)); } catch(_) {}
            scheduleRender(m.payload);
          } else if (m.type === 'applied_action' && m.payload) {
            // applied_action contains a partial payload (delta) to merge
            scheduleRender(m.payload);
          } else if (m.type === 'new_match') {
            try { adoptNewMatch(m); } catch(_) {}
          } else if (m.type === 'room_state' && m.payload) {
            // room_state may contain multiple sports; prefer basketball payload
            const p = m.payload.basketball_state || m.payload.basketball || m.payload['basketball_state'] || m.payload['basketball'];
            if (p) scheduleRender(p);
          } else if ((m.type === 'basketball_state' || m.type === 'state') && m.payload) {
            scheduleRender(m.payload);
          }
        } catch (_) {}
      });
    _ws.addEventListener('close', function () { setTimeout(initWS, 2000); });
    _ws.addEventListener('error', function () { /* ignore */ });
  } catch (_) {}
})();

// ── 2. localStorage storage event (cross-tab) ────────────────
window.addEventListener('storage', function (e) {
  try {
    if (e.key === STORAGE_KEY) {
      const s = e.newValue ? JSON.parse(e.newValue) : null;
      if (s) scheduleRender(s);
      return;
    }
    // Special-case: adopt new match signal from admin tabs
    if (e.key === 'basketball_new_match') {
      if (!e.newValue) return;
      try {
        const info = JSON.parse(e.newValue);
        const newId = info && (info.match_id || info.matchId) ? String(info.match_id || info.matchId) : null;
        if (newId) adoptNewMatch({ match_id: newId });
      } catch (_) {}
      return;
    }
  } catch (_) {}
});

// Removed fallback polling and postMessage listeners to reduce unnecessary
// event handlers. Viewer relies on BroadcastChannel + `storage` event.

// ── DOM refs ─────────────────────────────────────────────────
// Cache frequently-used DOM refs in a lightweight lookup to avoid repeated queries
const _els = {};
function getEl(id) {
  if (!id) return null;
  if (_els[id] === undefined) _els[id] = document.getElementById(id) || null;
  return _els[id];
}

const gtTimeEl  = getEl('gtTime');
const gtBlock   = getEl('gtBlock');
const scTimeEl  = getEl('scTime');
const scTenthEl = getEl('scTenth');
const scRingEl  = getEl('scRing');
const scBlock   = getEl('scBlock');

// Row cache for incremental updates (avoid full tbody rebuilds)
const _rowMap = { A: {}, B: {} };
const _lastRoster = { A: null, B: null };

// ── Helpers ──────────────────────────────────────────────────
function setText(id, val) {
  const el = getEl(id);
  if (!el) return;
  const newStr = (val == null ? '' : String(val));
  if (el.textContent === newStr) return;
  el.textContent = newStr;
}

function flash(el) {
  if (!el) return;
  el.classList.remove('flash');
  void el.offsetWidth;
  el.classList.add('flash');
  setTimeout(() => el.classList.remove('flash'), 400);
}

function gtFmt(secs) {
  const m = Math.floor(secs / 60);
  const s = Math.floor(secs % 60);
  return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}

// ── Previous state cache (for flash detection) ────────────────
const _prev = { scoreA: null, scoreB: null };

// ── Render roster table for one team ─────────────────────────
function _createRowObj(team, p) {
  const tr = document.createElement('tr'); tr.className = 'player-main-row'; tr.dataset.playerId = String(p.id);
  const tdNo = document.createElement('td'); tdNo.className = 'td-no'; tdNo.textContent = p.no || '';
  const tdNm = document.createElement('td'); tdNm.className = 'td-name'; tdNm.textContent = p.name || '—';
  tr.appendChild(tdNo); tr.appendChild(tdNm);
  const stats = {};
  ['pts','foul','reb','ast','blk','stl'].forEach(function (stat) {
    const td = document.createElement('td');
    if (stat === 'pts') td.className = 'pts-cell';
    const span = document.createElement('span'); span.className = 'stat-val'; span.textContent = p[stat] != null ? p[stat] : 0;
    td.appendChild(span); tr.appendChild(td);
    stats[stat] = span;
  });
  const tdTF = document.createElement('td'); const tfSpan = document.createElement('span'); tfSpan.className = 'stat-val tf-val'; tfSpan.textContent = p.techFoul || 0; tdTF.appendChild(tfSpan); tr.appendChild(tdTF);

  let techTr = null;
  if (p.techFoul > 0 || p.techReason) {
    techTr = document.createElement('tr'); techTr.className = 'player-tech-row';
    const techTd = document.createElement('td'); techTd.colSpan = 9;
    const inner = document.createElement('div'); inner.className = 'tech-inner';
    const lbl = document.createElement('span'); lbl.className = 'tech-label'; lbl.textContent = 'Tech Foul:';
    const val = document.createElement('span'); val.className = 'tech-count-val'; val.textContent = p.techFoul || 0;
    const reason = document.createElement('span'); reason.className = 'tech-reason-display'; reason.textContent = p.techReason || '';
    inner.appendChild(lbl); inner.appendChild(val); inner.appendChild(reason); techTd.appendChild(inner); techTr.appendChild(techTd);
  }

  return { id: String(p.id), main: tr, tech: techTr, elems: { no: tdNo, name: tdNm, stats: stats, tf: tfSpan, techReasonEl: techTr ? techTr.querySelector('.tech-reason-display') : null, techCountEl: techTr ? techTr.querySelector('.tech-count-val') : null } };
}

function _updateRowObj(rowObj, p) {
  if (!rowObj || !p) return;
  const setIfChanged = (el, v) => {
    const s = (v == null ? '' : String(v));
    if (el && el.textContent !== s) el.textContent = s;
  };
  setIfChanged(rowObj.elems.no, p.no || '');
  setIfChanged(rowObj.elems.name, p.name || '—');
  ['pts','foul','reb','ast','blk','stl'].forEach(stat => setIfChanged(rowObj.elems.stats[stat], p[stat] != null ? p[stat] : 0));
  setIfChanged(rowObj.elems.tf, p.techFoul || 0);
  if (rowObj.tech) {
    setIfChanged(rowObj.elems.techCountEl, p.techFoul || 0);
    setIfChanged(rowObj.elems.techReasonEl, p.techReason || '');
  }
}

function renderRoster(team, players) {
  const tbody = getEl('tbody' + team);
  if (!tbody) return;

  // quick equality short-circuit
  try {
    const raw = JSON.stringify(players || []);
    if (_lastRoster[team] === raw) return;
    _lastRoster[team] = raw;
  } catch (e) { _lastRoster[team] = null; }

  const map = _rowMap[team] || (_rowMap[team] = {});
  const desiredIds = [];
  (players || []).forEach(function (p) {
    const id = String(p.id);
    desiredIds.push(id);
    if (!map[id]) {
      map[id] = _createRowObj(team, p);
    }
    _updateRowObj(map[id], p);
  });

  // remove rows no longer present
  for (const existingId in Object.assign({}, map)) {
    if (desiredIds.indexOf(existingId) === -1) {
      const r = map[existingId];
      if (r.main && r.main.parentNode) r.main.parentNode.removeChild(r.main);
      if (r.tech && r.tech.parentNode) r.tech.parentNode.removeChild(r.tech);
      delete map[existingId];
    }
  }

  // reorder / append nodes to match incoming order without recreating elements
  const frag = document.createDocumentFragment();
  desiredIds.forEach(id => {
    const r = map[id];
    if (!r) return;
    frag.appendChild(r.main);
    if (r.tech) frag.appendChild(r.tech);
  });
  // append moves nodes rather than recreating them
  tbody.appendChild(frag);
}

// ── Render game timer display ─────────────────────────────────
function renderGameTimer(gt) {
  if (!gt || !gtTimeEl) return;
  const remaining = Math.max(0, Number(gt.remaining) || 0);
  // display in whole seconds (floor). Prevent small network increases from raising the displayed seconds while running.
  let dispSec = Math.floor(remaining);
  if (_prevDisplay.gtSec != null && _prevDisplay.gtSec !== null && gt.running && dispSec > _prevDisplay.gtSec) {
    dispSec = _prevDisplay.gtSec; // clamp up-jump
  }
  _prevDisplay.gtSec = dispSec;
  const m = Math.floor(dispSec / 60);
  const s = dispSec % 60;
  gtTimeEl.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  const expired = remaining <= 0;
  gtTimeEl.className = 'gt-time' + (expired ? ' expired' : remaining <= 60 ? ' danger' : '');
  gtBlock.className  = 'game-timer-block' +
    (expired ? ' gt-expired' : gt.running && remaining <= 60 ? ' gt-danger' : gt.running ? ' gt-running' : '');
}

// ── Render shot clock display ─────────────────────────────────
function renderShotClock(sc) {
  if (!sc || !scTimeEl) return;
  const remaining = Math.max(0, Number(sc.remaining) || 0);
  // shot clock commonly shows seconds rounded up; compute candidate display
  let candidate = Math.ceil(remaining - 1e-6);
  if (_prevDisplay.scSec != null && sc.running && candidate > _prevDisplay.scSec) {
    candidate = _prevDisplay.scSec; // prevent small upward jump
  }
  _prevDisplay.scSec = candidate;
  const tenths  = (remaining % 1).toFixed(1).slice(1);
  const expired = remaining <= 0;
  scTimeEl.textContent  = expired ? '0' : candidate;
  scTenthEl.textContent = (!expired && remaining < 10) ? tenths : '';
  scTimeEl.className = 'sc-time' + (expired ? ' expired' : remaining <= 5 ? ' danger' : '');

  const pct    = sc.total > 0 ? Math.max(0, sc.remaining / sc.total) : 0;
  const offset = SC_CIRCUMFERENCE * (1 - pct);
  scRingEl.style.strokeDashoffset = offset;
  scRingEl.style.stroke = expired ? '#e74c3c'
    : sc.remaining <= 5 ? '#e74c3c'
    : sc.remaining <= sc.total * 0.5 ? '#e67e22' : '#F5C518';

  scBlock.className = 'shot-clock-block' +
    (expired ? ' sc-expired' : sc.running && sc.remaining <= 5 ? ' sc-danger' : sc.running ? ' sc-running' : '');

  // Update preset label
  const presetLbl = document.getElementById('presetLabel');
  if (presetLbl) presetLbl.textContent = (sc.total || 24) + 's';
}

// ── Local countdown loops to keep timers smooth and consistent ──
const _localGT = { lastKnownRemaining: 0, total: 600, running: false, lastSync: 0, loopId: null };
const _localSC = { lastKnownRemaining: 24, total: 24, running: false, lastSync: 0, loopId: null };
const _prevDisplay = { gtSec: null, scSec: null };

function _startLocalGT() {
  if (_localGT.loopId) return;
  function tick() {
    const now = performance.now();
    const elapsed = (now - _localGT.lastSync) / 1000;
    const rem = Math.max(0, _localGT.lastKnownRemaining - elapsed);
    renderGameTimer({ remaining: rem, running: _localGT.running });
    if (_localGT.running && rem > 0) {
      _localGT.loopId = requestAnimationFrame(tick);
    } else {
      _localGT.loopId = null;
    }
  }
  _localGT.loopId = requestAnimationFrame(tick);
}
function _stopLocalGT() {
  if (_localGT.loopId) cancelAnimationFrame(_localGT.loopId);
  _localGT.loopId = null;
}

function _syncGameTimer(incoming) {
  if (!incoming) return;
  const now = performance.now();
  _localGT.total = (incoming.total != null) ? incoming.total : _localGT.total;
  const incomingRem = (typeof incoming.remaining === 'number') ? incoming.remaining : _localGT.lastKnownRemaining;
  // compute current local remaining (account for elapsed since last sync)
  const elapsed = _localGT.lastSync ? (now - _localGT.lastSync) / 1000 : 0;
  const currentLocalRem = Math.max(0, _localGT.lastKnownRemaining - elapsed);
  // Interpret explicit running only when provided; otherwise treat as no-op
  const incomingRunning = (typeof incoming.running === 'boolean') ? incoming.running : (typeof incoming.is_running === 'boolean' ? incoming.is_running : null);
  const increase = incomingRem - currentLocalRem;
  if (_localGT.running && incomingRunning === true && increase > 0 && increase <= 1.0) {
    // small out-of-order jitter while both report running — ignore
  } else {
    // accept incoming numeric values
    _localGT.lastKnownRemaining = incomingRem;
    if (incomingRunning !== null) _localGT.running = incomingRunning;
    _localGT.lastSync = now;
  }
  // Start/stop loops only when server explicitly provides running flag.
  if (incomingRunning === true) {
    _startLocalGT();
  } else if (incomingRunning === false) {
    _stopLocalGT(); renderGameTimer({ remaining: _localGT.lastKnownRemaining, running: false });
  } else {
    // incomingRunning === null — server omitted running flag. Preserve existing loop state.
    if (_localGT.running) _startLocalGT(); else { _stopLocalGT(); renderGameTimer({ remaining: _localGT.lastKnownRemaining, running: false }); }
  }
}

function _startLocalSC() {
  if (_localSC.loopId) return;
  function tick() {
    const now = performance.now();
    const elapsed = (now - _localSC.lastSync) / 1000;
    const rem = Math.max(0, _localSC.lastKnownRemaining - elapsed);
    renderShotClock({ remaining: rem, total: _localSC.total, running: _localSC.running });
    if (_localSC.running && rem > 0) {
      _localSC.loopId = requestAnimationFrame(tick);
    } else {
      _localSC.loopId = null;
    }
  }
  _localSC.loopId = requestAnimationFrame(tick);
}
function _stopLocalSC() {
  if (_localSC.loopId) cancelAnimationFrame(_localSC.loopId);
  _localSC.loopId = null;
}

function _syncShotClock(incoming) {
  if (!incoming) return;
  const now = performance.now();
  const prevTotal = _localSC.total;
  _localSC.total = (incoming.total != null) ? incoming.total : _localSC.total;
  const incomingRem = (typeof incoming.remaining === 'number') ? incoming.remaining : _localSC.lastKnownRemaining;
  const elapsed = _localSC.lastSync ? (now - _localSC.lastSync) / 1000 : 0;
  const currentLocalRem = Math.max(0, _localSC.lastKnownRemaining - elapsed);
  const incomingRunning = (typeof incoming.running === 'boolean') ? incoming.running : (typeof incoming.is_running === 'boolean' ? incoming.is_running : null);
  const increase = incomingRem - currentLocalRem;
  // Allow increases when clock was reset (total changed) or clock is stopped (not running).
  // Only ignore small upward jitter when both local and incoming report running.
  if (_localSC.running && incomingRunning === true && increase > 0 && increase <= 0.9 && incoming.total === prevTotal) {
    // small jitter — ignore
  } else {
    _localSC.lastKnownRemaining = incomingRem;
    if (incomingRunning !== null) _localSC.running = incomingRunning;
    _localSC.lastSync = now;
  }
  if (incomingRunning === true) _startLocalSC(); else if (incomingRunning === false) { _stopLocalSC(); renderShotClock({ remaining: _localSC.lastKnownRemaining, total: _localSC.total, running: false }); } else { if (_localSC.running) _startLocalSC(); else { _stopLocalSC(); renderShotClock({ remaining: _localSC.lastKnownRemaining, total: _localSC.total, running: false }); } }
}

// ── Main render ──────────────────────────────────────────────
function render(s) {
  if (!s) return;

  const tA = s.teamA || {};
  const tB = s.teamB || {};

  const nameA = tA.name || 'TEAM A';
  const nameB = tB.name || 'TEAM B';

  // Nav team labels
  setText('labelA', nameA);
  setText('labelB', nameB);

  // Team name in column headers
  setText('teamANameDisplay', nameA);
  setText('teamBNameDisplay', nameB);

  // Scores — flash on change
  const newScoreA = tA.score != null ? tA.score : 0;
  const newScoreB = tB.score != null ? tB.score : 0;
  const elScA = getEl('scoreA');
  const elScB = getEl('scoreB');
  if (_prev.scoreA !== null && newScoreA !== _prev.scoreA) flash(elScA);
  if (_prev.scoreB !== null && newScoreB !== _prev.scoreB) flash(elScB);
  _prev.scoreA = newScoreA; _prev.scoreB = newScoreB;
  if (elScA) elScA.textContent = newScoreA;
  if (elScB) elScB.textContent = newScoreB;

  // Populate compact nav bar (phones) if present
  try {
    if (getEl('compactScoreA')) {
      setText('compactScoreA', newScoreA);
      setText('compactScoreB', newScoreB);
      // short team label (first word up to 6 chars)
      const shortA = (nameA || 'A').split(/\s+/)[0].slice(0,6);
      const shortB = (nameB || 'B').split(/\s+/)[0].slice(0,6);
      setText('compactLabelA', shortA);
      setText('compactLabelB', shortB);
    }
  } catch (e) { /* ignore */ }

  // Team stats bars — foul / timeout / quarter for each team
  ['foul', 'timeout', 'quarter'].forEach(function (key) {
    setText('tsbA_' + key, tA[key] != null ? tA[key] : 0);
    setText('tsbB_' + key, tB[key] != null ? tB[key] : 0);
  });

  // Shared counters (right panel)
  const sh = s.shared || {};
  setText('foulVal',    sh.foul    != null ? sh.foul    : 0);
  setText('timeoutVal', sh.timeout != null ? sh.timeout : 0);
  setText('bbQuarterVal', sh.quarter != null ? sh.quarter : 0);

  // Committee
  setText('bbCommitteeValue', (s.committee || '').trim() || '—');

  // Rosters — only rebuild if data changed
  renderRoster('A', tA.players || []);
  renderRoster('B', tB.players || []);

  // Timers
  // Timers — sync into local countdown loops for smooth, consistent UI
  if (s.gameTimer)  _syncGameTimer(s.gameTimer);
  if (s.shotClock)  _syncShotClock(s.shotClock);
}

// ── Render scheduling to coalesce rapid updates ────────────
let _pendingState = null;
let _scheduled = false;
// Keep a merged copy of the last applied state so partial/minimal payloads
// (e.g. timer-only updates) don't overwrite other fields with empty values.
let _currentState = {};
function mergeState(prev, incoming) {
  if (!incoming) return prev || {};
  const out = Object.assign({}, prev || {});
  // Merge top-level known keys shallowly
  ['teamA','teamB','shared','gameTimer','shotClock','committee'].forEach(k => {
    if (incoming[k] !== undefined) {
      if (typeof incoming[k] === 'object' && incoming[k] !== null) {
        out[k] = Object.assign({}, out[k] || {}, incoming[k]);
      } else {
        out[k] = incoming[k];
      }
    }
  });
  return out;
}

function scheduleRender(state) {
  _pendingState = state;
  if (_scheduled) return;
  _scheduled = true;
  requestAnimationFrame(function () {
    try {
      _currentState = mergeState(_currentState, _pendingState);
      render(_currentState);
    } catch (e) { /* ignore render errors */ }
    _pendingState = null; _scheduled = false;
  });
}

// ── Initial load from localStorage ───────────────────────────
(function init() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) scheduleRender(JSON.parse(raw));
  } catch (_) {}
})();