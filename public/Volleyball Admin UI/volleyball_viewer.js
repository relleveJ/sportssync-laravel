// ============================================================
// volleyball_viewer.js — Read-only live viewer
// Two-channel live update: BroadcastChannel + localStorage
// WebSocket for cross-device live sync
// ============================================================

const STORAGE_KEY  = 'volleyballLiveState';
const CHANNEL_NAME = 'volleyball_live';
// Active lineup slots per team (must match admin)
const ACTIVE_LINEUP_SIZE = 6;
// runtime state
let hasReceivedData = false;
// ✅ SSOT SAFE ADD START — Patch E: persistent flag so "live" badge never reverts to "waiting"
let _ssotViewerConfirmedLive = false;
// ✅ SSOT SAFE ADD END
// ✅ SSOT SAFE ADD START — Patch F: last-write-wins timestamp for multi-admin conflict resolution
let _ssotLastAppliedTs = 0;
// Pagination state for roster tables (6 players per page)
let _page = { A: 0, B: 0 };
function _ssotAcceptPayload(payload) {
  try {
    const incoming = payload && payload._ssot_ts ? payload._ssot_ts : null;
    if (incoming !== null && incoming < _ssotLastAppliedTs) return false; // stale — skip
    if (incoming !== null) _ssotLastAppliedTs = incoming;
    return true;
  } catch(_) { return true; } // fail-open: always accept on unexpected error
}

function _isValidStatePayload(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return false;
  return Boolean(payload.teamA && payload.teamB);
}

function _hasMeaningfulState(payload) {
  if (!_isValidStatePayload(payload)) return false;
  if (Array.isArray(payload.teamA.players) || Array.isArray(payload.teamB.players)) return true;
  if (typeof payload.teamA.score === 'number' || typeof payload.teamB.score === 'number') return true;
  if (payload.shared && typeof payload.shared.set === 'number') return true;
  return false;
}

function _applyStateWrapper(wrapper) {
  if (!wrapper || !wrapper.payload || !_isValidStatePayload(wrapper.payload)) return false;
  const actualMid = (wrapper.match_id != null && String(wrapper.match_id).trim() !== '') ? String(wrapper.match_id) : _resolveViewerMatchId();
  const payload = wrapper.payload;
  const canonical = { match_id: actualMid, payload };
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(canonical)); } catch (_) {}
  try { _bc && _bc.postMessage(canonical); } catch (_) {}
  _syncViewerMatchId(actualMid);
  if (_ws && _ws.readyState === WebSocket.OPEN) _joinRoom(actualMid);
  // ✅ FIX: record the server payload's own timestamp so subsequent stale WS messages
  // (with an older _ssot_ts) are correctly rejected by _ssotAcceptPayload.
  // Previously this was reset to 0, which zeroed the guard and allowed any racing
  // WS event to overwrite the freshly-fetched authoritative server state.
  _ssotLastAppliedTs = (payload && payload._ssot_ts != null) ? payload._ssot_ts : _ssotLastAppliedTs;
  hasReceivedData = true;
  // ✅ FIX: use immediateRender (synchronous) instead of scheduleRender (rAF-deferred).
  // scheduleRender defers the merge into _currentState by ~16 ms.  If a WebSocket
  // message arrives in that window, immediateRender sets _pendingState = null which
  // silently cancels the rAF callback, dropping the server payload entirely.
  // With immediateRender the server state is merged into _currentState right away, so
  // any subsequent WS merge builds on the correct base instead of an empty object.
  immediateRender(payload);
  _setViewerStatus('live');
  return true;
}
// Viewer connection flags
let _wsConnected = false;
// Timestamp of last WebSocket-driven render (ms since epoch)
let _lastWSRenderAt = 0;
// ✅ SSOT SAFE ADD END

// Helper: accept either a wrapper { match_id, payload } or a raw payload
function _extractWrapper(raw) {
  try {
    if (!raw) return { match_id: null, payload: null };
    // If it's a JSON string, try parse it
    if (typeof raw === 'string') {
      try { raw = JSON.parse(raw); } catch (_) { /* leave as string */ }
    }
    // Determine match_id if present
    var match_id = null;
    if (raw && typeof raw === 'object' && raw.match_id != null) match_id = String(raw.match_id);

    // Candidate payload: prefer raw.payload if present, otherwise raw itself
    var candidate = (raw && typeof raw === 'object' && raw.payload !== undefined) ? raw.payload : raw;
    if (typeof candidate === 'string') {
      try { candidate = JSON.parse(candidate); } catch (_) { /* leave as string */ }
    }

    // Unwrap common relay wrappers: { volleyball: {...} } or { volleyball_state: {...} }
    if (candidate && typeof candidate === 'object') {
      if (candidate.volleyball !== undefined) return { match_id: match_id, payload: candidate.volleyball };
      if (candidate.volleyball_state !== undefined) return { match_id: match_id, payload: candidate.volleyball_state };
      // Some relays double-wrap under payload
      if (candidate.payload && typeof candidate.payload === 'object') {
        var inner = candidate.payload;
        if (inner.volleyball !== undefined) return { match_id: match_id, payload: inner.volleyball };
        if (inner.volleyball_state !== undefined) return { match_id: match_id, payload: inner.volleyball_state };
      }
      // If it already looks like the canonical state shape, return it
      if (candidate.teamA || candidate.teamB || candidate.shared || candidate.committee) {
        return { match_id: match_id, payload: candidate };
      }
    }

    return { match_id: match_id, payload: null };
  } catch (_) { return { match_id: null, payload: null }; }
}
// Default shared room id
const DEFAULT_ROOM_ID = (typeof window.__defaultRoomId !== 'undefined') ? String(window.__defaultRoomId) : '0';

function _resolveViewerMatchId() {
  try {
    const mid = window.__matchId != null ? String(window.__matchId).trim() : '';
    if (mid !== '') return mid;
    try {
      const stored = sessionStorage.getItem('volleyball_match_id');
      const storedMid = stored != null ? String(stored).trim() : '';
      if (storedMid !== '') return storedMid;
    } catch (_) {}
    return DEFAULT_ROOM_ID;
  } catch (_) {
    return DEFAULT_ROOM_ID;
  }
}

function _joinRoom(matchId) {
  try {
    if (matchId == null || String(matchId).trim() === '') return;
    if (!_ws || _ws.readyState !== WebSocket.OPEN) return;
    _ws.send(JSON.stringify({ type: 'join', match_id: String(matchId) }));
  } catch (_) {}
}

function _syncViewerMatchId(matchId) {
  try {
    if (matchId == null || String(matchId).trim() === '') return;
    const mid = String(matchId);
    window.__matchId = mid;
    try { sessionStorage.setItem('volleyball_match_id', mid); } catch (_) {}
    if (_ws && _ws.readyState === WebSocket.OPEN) {
      _joinRoom(mid);
    }
  } catch (_) {}
}

// ── 1. BroadcastChannel (instant, same browser) ──────────────
let _bc = null;
try {
  _bc = new BroadcastChannel(CHANNEL_NAME);
  _bc.onmessage = function (e) {
    try {
      const data = e && e.data;
      if (!data) return;
      // Handle new_match events from admin (automatic match switch for viewers)
      if (data.type === 'new_match' && data.match_id) {
        const newMid = String(data.match_id);
        // Persist new match ID to sessionStorage so page reloads pick it up
        try { sessionStorage.setItem('volleyball_match_id', newMid); } catch (_) {}
        _syncViewerMatchId(newMid);
        _ssotLastAppliedTs = 0; // reset SSOT guard to accept new match state
        hasReceivedData = false; // mark as waiting for new match payload
        // CRITICAL: Force clear stale state before rendering reset/new match payload
        _currentState = {};
        _lastRoster = { A: null, B: null };
        _rowMap = { A: {}, B: {} };
        _lastLineupSerialized = {};
        _page = { A: 0, B: 0 };
        if (data.payload && _hasMeaningfulState(data.payload)) {
          // Admin included the new state — render it immediately
          immediateRender(data.payload);
          hasReceivedData = true;
        } else {
          // No payload included — fetch fresh state from server
          _fetchAndRenderNewMatch(newMid);
        }
        return;
      }
      var pack = _extractWrapper(data);
      var mid = pack.match_id;
      var payload = pack.payload;
      if (!payload || !_isValidStatePayload(payload)) return;
      var viewerMid = window.__matchId || DEFAULT_ROOM_ID;
      if (mid != null && viewerMid != null && String(mid) !== '0' && String(viewerMid) !== '0' && String(viewerMid) !== String(mid)) return;
      if (!_ssotAcceptPayload(payload)) return;
      immediateRender(payload);
    } catch (_) {}
  };
} catch (_) {}

// ── 2. localStorage storage event (cross-tab, instant) ───────
window.addEventListener('storage', function (e) {
  if (e.key !== STORAGE_KEY) return;
  try {
    var pack = _extractWrapper(e.newValue);
    var payload = pack.payload;
    var mid = pack.match_id;
    // If both this viewer and the message specify non-zero match ids and they differ, ignore.
    var viewerMid = window.__matchId || DEFAULT_ROOM_ID;
    if (mid != null && viewerMid != null && String(mid) !== '0' && String(viewerMid) !== '0' && String(viewerMid) !== String(mid)) return;
    if (!payload || !_isValidStatePayload(payload)) return;
    if (mid != null) {
      _syncViewerMatchId(mid);
      if (_ws && _ws.readyState === WebSocket.OPEN) {
        _joinRoom(mid);
      }
    }
    if (!_ssotAcceptPayload(payload)) return;
    immediateRender(payload);
  } catch (_) {}
});

// ── 2b. Listen for new_match events in localStorage ─────────
window.addEventListener('storage', function (e) {
  if (e.key !== 'volleyball_new_match') return;
  try {
    const obj = e.newValue ? JSON.parse(e.newValue) : null;
    if (obj && obj.match_id) {
      const newMid = String(obj.match_id);
      _syncViewerMatchId(newMid);
      _ssotLastAppliedTs = 0; // reset SSOT guard to accept new match state
      hasReceivedData = false;
      // CRITICAL: Force clear stale state before rendering reset payload
      _currentState = {};
      _lastRoster = { A: null, B: null };
      _rowMap = { A: {}, B: {} };
      _lastLineupSerialized = {};
      _page = { A: 0, B: 0 };
      if (obj.payload && _hasMeaningfulState(obj.payload)) {
        immediateRender(obj.payload);
        hasReceivedData = true;
      } else {
        _fetchAndRenderNewMatch(newMid);
      }
    }
  } catch (_) {}
});

// ── 3. WebSocket relay (cross-device) ────────────────────────
let _ws = null;
let _wsBackoff = 2000;
function onWSMessage(ev) {
  try {
    // Parse incoming safely (some relays may double-stringify payloads)
    var m = null;
    try { m = JSON.parse(ev.data); } catch (e) { m = ev.data; }
    if (!m) return;

    // Handle new_match events from admin broadcast relay (automatic match switch for viewers)
    if (m.type === 'new_match' && m.match_id) {
      const newMid = String(m.match_id);
      _syncViewerMatchId(newMid);
      _ssotLastAppliedTs = 0; // reset SSOT guard to accept new match state
      hasReceivedData = false; // mark as waiting for new match payload
      // CRITICAL: Force clear stale state before rendering reset/new match payload
      _currentState = {};
      _lastRoster = { A: null, B: null };
      _rowMap = { A: {}, B: {} };
      _lastLineupSerialized = {};
      _page = { A: 0, B: 0 };
      if (m.payload && _hasMeaningfulState(m.payload)) {
        _applyStateWrapper({ match_id: newMid, payload: m.payload });
      } else {
        _fetchAndRenderNewMatch(newMid);
      }
      _lastWSRenderAt = Date.now();
      return;
    }

    // Normalize and unwrap common envelope shapes to the canonical volleyball payload
    var pack = _extractWrapper(m);
    var p = pack.payload;

    // If wrapper didn't yield a payload, try top-level shapes as a fallback
    if (!p && m && typeof m === 'object') {
      if (m.payload) {
        var cand = m.payload;
        if (typeof cand === 'string') {
          try { cand = JSON.parse(cand); } catch (_) {}
        }
        if (cand && typeof cand === 'object') p = cand.volleyball || cand.volleyball_state || cand;
      } else {
        p = m.volleyball || m.volleyball_state || null;
      }
    }

    if (!p || !_isValidStatePayload(p)) return;
    var viewerMid = window.__matchId || DEFAULT_ROOM_ID;
    if (pack.match_id != null && viewerMid != null && String(pack.match_id) !== '0' && String(viewerMid) !== '0' && String(pack.match_id) !== String(viewerMid)) return;
    if (!_ssotAcceptPayload(p)) return;

    // Immediate authoritative application for zero-perceived delay
    immediateRender(p);
    hasReceivedData = true;
    _lastWSRenderAt = Date.now();

    // Persist merged state for other tabs/viewers so they render instantly
    try {
      const midNow = window.__matchId || (m && m.match_id != null ? String(m.match_id) : DEFAULT_ROOM_ID);
      const wrapper = { match_id: midNow, payload: _currentState };
      try { _syncViewerMatchId(midNow); } catch (_) {}
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(wrapper)); } catch(_) {}
      try { _bc && _bc.postMessage(wrapper); } catch(_) {}
    } catch (_) {}

    _wsConnected = true;
    _setViewerStatus('live');
  } catch (_) { /* ignore parse errors */ }
}

function initWS() {
  try {
    if (_ws && (_ws.readyState === WebSocket.OPEN || _ws.readyState === WebSocket.CONNECTING)) return;
    const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    const ws = new WebSocket(url);
    _ws = ws;
    ws.addEventListener('open', function () {
      try {
        const mid = _resolveViewerMatchId();
        if (mid) {
          _syncViewerMatchId(mid);
          ws.send(JSON.stringify({ type: 'join', match_id: String(mid) }));
          ws.send(JSON.stringify({ type: 'get_state', match_id: String(mid) }));
        }
        _wsConnected = true;
        _setViewerStatus('live');
        _wsBackoff = 2000;
      } catch (_) {}
    });
    ws.addEventListener('message', onWSMessage);
    ws.addEventListener('close', function () {
      try { _ws = null; } catch(_) {}
      _wsConnected = false;
      _setViewerStatus('waiting');
      setTimeout(initWS, _wsBackoff);
      _wsBackoff = Math.min(30000, _wsBackoff + 2000);
    });
    ws.addEventListener('error', function () {
      _wsConnected = false;
      try { ws.close(); } catch(_) {}
      setTimeout(initWS, _wsBackoff);
      _wsBackoff = Math.min(30000, _wsBackoff + 2000);
    });
  } catch (_) {
    _wsConnected = false;
    setTimeout(initWS, _wsBackoff);
    _wsBackoff = Math.min(30000, _wsBackoff + 2000);
  }
}
initWS();

// ── Connection status indicator ───────────────────────────
function _setViewerStatus(s) {
  try {
    let el = document.getElementById('viewerSyncStatus');
    if (!el) {
      el = document.createElement('div');
      el.id = 'viewerSyncStatus';
      Object.assign(el.style, {
        position: 'fixed', right: '12px', bottom: '12px',
        padding: '4px 10px', borderRadius: '5px', fontSize: '11px',
        zIndex: '9999', fontFamily: 'sans-serif', transition: 'background 0.3s'
      });
      document.body.appendChild(el);
    }
    if (s === 'live') {
      el.style.background = '#dff0d8'; el.style.color = '#155724';
      el.textContent = '✓ Receiving live data';
      // ✅ SSOT SAFE ADD START — Patch E: lock in live status so badge never reverts
      _ssotViewerConfirmedLive = true;
      // ✅ SSOT SAFE ADD END
    } else if (s === 'waiting') {
      // ✅ SSOT SAFE ADD START — Patch E: skip "waiting" once live data has been confirmed
      // ✅ FIX: also guard on hasReceivedData so the badge never reverts to "waiting"
      // when WS closes before the HTTP fetch resolves but localStorage already has state.
      if (_ssotViewerConfirmedLive || hasReceivedData) return;
      // ✅ SSOT SAFE ADD END
      el.style.background = '#fff3cd'; el.style.color = '#856404';
      el.textContent = '⏳ Waiting for admin to start…';
    } else {
      el.style.background = '#f8d7da'; el.style.color = '#721c24';
      el.textContent = '✗ No data yet — retrying…';
    }
  } catch (_) {}
}


function setText(id, val) {
  const el = document.getElementById(id);
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
  setTimeout(function () { el.classList.remove('flash'); }, 400);
}

// ── Previous score cache ──────────────────────────────────────
const _prev = { scoreA: null, scoreB: null };
// Cache last rendered roster JSON to avoid full rebuilds when unchanged
const _lastRoster = { A: null, B: null };
// Row cache for incremental updates
const _rowMap = { A: {}, B: {} };
// Lineup SVG cache to avoid rebuilding identical SVGs
const _lastLineupSerialized = {};

// ── Render roster table ───────────────────────────────────────
const VB_STATS = ['pts', 'spike', 'ace', 'exSet', 'exDig', 'blk'];

// Normalize a player object coming from any source (admin JS payload uses camelCase;
// state.php DB fallback uses snake_case ex_set / ex_dig). Also ensures every player
// has a stable string id so the row-cache key never collides or goes missing.
function _normalizePlayer(p, teamLetter, index) {
  if (!p || typeof p !== 'object') return null;
  var out = Object.assign({}, p);
  // Map snake_case DB fields → camelCase viewer fields
  if (out.exSet == null && out.ex_set != null) out.exSet = out.ex_set;
  if (out.exDig == null && out.ex_dig != null) out.exDig = out.ex_dig;
  // Ensure numeric defaults for all stat fields
  VB_STATS.forEach(function (stat) { if (out[stat] == null) out[stat] = 0; });
  // Guarantee a stable string id
  if (!out.id) out.id = (teamLetter || 'X') + '_auto_' + (index != null ? index : '0');
  out.id = String(out.id);
  out.__originalId = out.__originalId || (p.id != null ? String(p.id) : out.id);
  return out;
}

// Normalize an entire team's player array before rendering
function _normalizePlayers(players, teamLetter) {
  if (!Array.isArray(players)) return [];
  var seen = Object.create(null);
  var dupCount = Object.create(null);
  var out = [];
  players.forEach(function (p, idx) {
    var n = _normalizePlayer(p, teamLetter, idx);
    if (!n) return;
    var baseId = n.id;
    if (seen[baseId]) {
      dupCount[baseId] = (dupCount[baseId] || 1) + 1;
      n = Object.assign({}, n, { id: baseId + '_dup_' + dupCount[baseId] });
    } else {
      dupCount[baseId] = 1;
    }
    seen[n.id] = true;
    out.push(n);
  });
  return out;
}

function _unionRosterAndLineup(team, teamLetter) {
  var allPlayers = [];
  var seenIds = Object.create(null);

  function addPlayer(player) {
    if (!player || typeof player !== 'object') return;
    var id = player.id != null ? String(player.id) : null;
    if (!id) return;
    if (seenIds[id]) return;
    seenIds[id] = true;
    allPlayers.push(player);
  }

  if (Array.isArray(team.players)) {
    team.players.forEach(addPlayer);
  }
  if (Array.isArray(team.lineupPlayers)) {
    team.lineupPlayers.forEach(addPlayer);
  } else if (Array.isArray(team.lineup)) {
    team.lineup.forEach(function (slotId) {
      if (slotId == null) return;
      addPlayer({ id: slotId });
    });
  }
  return allPlayers;
}

function _createVBRowObj(team, p) {
  const tr = document.createElement('tr'); tr.className = 'player-row'; tr.dataset.playerId = String(p.id);
  const tdNo = document.createElement('td'); tdNo.className = 'td-no'; tdNo.textContent = p.no || '';
  const tdNm = document.createElement('td'); tdNm.className = 'td-name'; tdNm.textContent = p.name || '—';
  tr.appendChild(tdNo); tr.appendChild(tdNm);
  const statsMap = {};
  VB_STATS.forEach(function (stat) {
    const td = document.createElement('td'); if (stat === 'pts') td.className = 'pts-cell';
    const span = document.createElement('span'); span.className = 'stat-val'; span.textContent = p[stat] != null ? p[stat] : 0;
    td.appendChild(span); tr.appendChild(td);
    statsMap[stat] = span;
  });
  return { id: String(p.id), main: tr, elems: { no: tdNo, name: tdNm, stats: statsMap } };
}

function _updateVBRowObj(rowObj, p) {
  if (!rowObj || !p) return;
  const setIfChanged = function (el, v) {
    const s = (v == null ? '' : String(v));
    if (el && el.textContent !== s) el.textContent = s;
  };
  setIfChanged(rowObj.elems.no, p.no || '');
  setIfChanged(rowObj.elems.name, p.name || '—');
  VB_STATS.forEach(function (stat) { setIfChanged(rowObj.elems.stats[stat], p[stat] != null ? p[stat] : 0); });
}

function renderRoster(team, fullPlayers) {
  const tbody = document.getElementById('tbody' + team);
  if (!tbody) return;

  const perPage = 6;
  let page = _page[team];
  const totalPlayers = fullPlayers.length;
  const totalPages = Math.ceil(totalPlayers / perPage);

  // Reset page if out of range
  if (page >= totalPages && totalPages > 0) {
    page = _page[team] = 0;
  }

  const start = page * perPage;
  const end = Math.min(start + perPage, totalPlayers);
  const players = fullPlayers.slice(start, end);

  // Compute the canonical serialized form of the incoming player list.
  var incomingRaw;
  try { incomingRaw = JSON.stringify(players || []); } catch(_) { incomingRaw = null; }

  // If the player ID set has changed (e.g. server returned a completely different
  // match after refresh) purge the row-object cache entirely so stale DOM nodes
  // from the previous render don't bleed into the new one.
  var incomingIds = (players || []).map(function(p){ return String(p.id); }).join(',');
  var cachedIds   = Object.keys(_rowMap[team] || {}).sort().join(',');
  if (cachedIds && incomingIds && cachedIds !== incomingIds) {
    // Wipe all cached row elements and force a full rebuild
    _rowMap[team] = {};
    _lastRoster[team] = null;
    tbody.innerHTML = '';
  }

  try {
    if (incomingRaw !== null && _lastRoster[team] === incomingRaw) {
      // Players didn't change, just update pagination
    } else {
      _lastRoster[team] = incomingRaw;
    }
  } catch (e) { _lastRoster[team] = null; }

  const map = _rowMap[team] || (_rowMap[team] = {});
  const desired = [];
  (players || []).forEach(function (p) {
    const id = String(p.id);
    desired.push(id);
    if (!map[id]) map[id] = _createVBRowObj(team, p);
    _updateVBRowObj(map[id], p);
  });

  // Remove obsolete rows
  for (const existing in Object.assign({}, map)) {
    if (desired.indexOf(existing) === -1) {
      const r = map[existing];
      if (r.main && r.main.parentNode) r.main.parentNode.removeChild(r.main);
      delete map[existing];
    }
  }

  // Reorder / append in desired order (append moves nodes)
  const frag = document.createDocumentFragment();
  desired.forEach(function (id) {
    const r = map[id]; if (!r) return; frag.appendChild(r.main);
  });
  tbody.appendChild(frag);

  // Add pagination row
  const existing = tbody.querySelector('.pagination-row');
  if (existing) existing.remove();

  const tr = document.createElement('tr');
  tr.className = 'pagination-row';
  const td = document.createElement('td');
  td.colSpan = 8; // 8 columns: No, Name, PTS, SPIKE, ACE, EX SET, EX DIG, BLK

  const prevBtn = document.createElement('button');
  prevBtn.textContent = 'Previous';
  prevBtn.disabled = page === 0;
  prevBtn.onclick = () => { _page[team]--; renderRoster(team, fullPlayers); };

  const pageSpan = document.createElement('span');
  pageSpan.textContent = ` Page ${page + 1} of ${totalPages || 1} `;

  const nextBtn = document.createElement('button');
  nextBtn.textContent = 'Next';
  nextBtn.disabled = page >= totalPages - 1 || totalPages <= 1;
  nextBtn.onclick = () => { _page[team]++; renderRoster(team, fullPlayers); };

  td.appendChild(prevBtn);
  td.appendChild(pageSpan);
  td.appendChild(nextBtn);
  tr.appendChild(td);
  tbody.appendChild(tr);
}

// ── Render lineup circle (read-only) ─────────────────────────
function renderLineupCircle(svgId, teamName, score, lineup, players) {
  const svg = document.getElementById(svgId);
  if (!svg) return;

  // Use the actual lineup length from the payload — do NOT cap to ACTIVE_LINEUP_SIZE.
  // This ensures the circle renders all slots the admin has configured.
  lineup = Array.isArray(lineup) ? lineup.slice(0) : [];
  // Only pad up to ACTIVE_LINEUP_SIZE minimum (fill empty slots); never truncate.
  while (lineup.length < ACTIVE_LINEUP_SIZE) lineup.push(null);
  // lineup.length is now >= ACTIVE_LINEUP_SIZE and reflects the full payload lineup.
  const effectiveSlots = lineup.length;

  // Skip rebuild if nothing meaningful changed (teamName, score, lineup ids, slot count)
  try {
    const ids = lineup.map(function (it) {
      if (!it) return null;
      if (typeof it === 'object') return it.id || it.no || it.name || null;
      return it;
    });
    const serialized = JSON.stringify({ teamName: teamName || '', score: score || 0, ids: ids, slots: effectiveSlots });
    if (_lastLineupSerialized[svgId] === serialized) return;
    _lastLineupSerialized[svgId] = serialized;
  } catch (e) { /* fall through and rebuild */ }

  while (svg.firstChild) svg.removeChild(svg.firstChild);

  const ns = 'http://www.w3.org/2000/svg';
  const cx = 70, cy = 70;

  // Outer ring
  const ring = document.createElementNS(ns, 'circle');
  ring.setAttribute('cx', cx); ring.setAttribute('cy', cy); ring.setAttribute('r', '54');
  ring.setAttribute('fill', 'none'); ring.setAttribute('stroke', '#F5C518');
  ring.setAttribute('stroke-width', '2.5'); ring.setAttribute('opacity', '0.7');
  svg.appendChild(ring);

  // Inner fill
  const inner = document.createElementNS(ns, 'circle');
  inner.setAttribute('cx', cx); inner.setAttribute('cy', cy); inner.setAttribute('r', '50');
  inner.setAttribute('fill', '#0c0c0c');
  svg.appendChild(inner);

  // Center team name
  const nameText = document.createElementNS(ns, 'text');
  nameText.setAttribute('x', cx); nameText.setAttribute('y', cy - 10);
  nameText.setAttribute('text-anchor', 'middle'); nameText.setAttribute('dominant-baseline', 'middle');
  nameText.setAttribute('font-family', 'Oswald, sans-serif'); nameText.setAttribute('font-size', '8');
  nameText.setAttribute('font-weight', '600'); nameText.setAttribute('fill', '#888');
  nameText.setAttribute('letter-spacing', '1');
  nameText.textContent = (teamName || '').slice(0, 6).toUpperCase();
  svg.appendChild(nameText);

  // Center score
  const scoreText = document.createElementNS(ns, 'text');
  scoreText.setAttribute('x', cx); scoreText.setAttribute('y', cy + 8);
  scoreText.setAttribute('text-anchor', 'middle'); scoreText.setAttribute('dominant-baseline', 'middle');
  scoreText.setAttribute('font-family', 'Oswald, sans-serif'); scoreText.setAttribute('font-size', '24');
  scoreText.setAttribute('font-weight', '700'); scoreText.setAttribute('fill', '#F5C518');
  scoreText.textContent = score != null ? score : 0;
  svg.appendChild(scoreText);

  // player chips — use effectiveSlots so all lineup positions are rendered
  const chipR = 46;
  var step = 360 / effectiveSlots;
  for (var i = 0; i < effectiveSlots; i++) {
    var angle = (i * step - 90) * (Math.PI / 180);
    var chipCx = cx + chipR * Math.cos(angle);
    var chipCy = cy + chipR * Math.sin(angle);

    var lineupEl = (lineup && lineup[i]) || null;
    var player = null;
    if (lineupEl && typeof lineupEl === 'object') {
      player = lineupEl;
    } else if (lineupEl) {
      player = (players || []).find(function (p) {
        return p.id === lineupEl || p.__originalId === lineupEl;
      }) || null;
    } else {
      player = null;
    }

    var chipW = 34, chipH = 14;
    var chipRect = document.createElementNS(ns, 'rect');
    chipRect.setAttribute('x', chipCx - chipW / 2); chipRect.setAttribute('y', chipCy - chipH / 2);
    chipRect.setAttribute('width', chipW); chipRect.setAttribute('height', chipH);
    chipRect.setAttribute('rx', '7');
    chipRect.setAttribute('fill', player ? '#1a3a1a' : '#1a1a1a');
    chipRect.setAttribute('stroke', player ? '#27ae60' : '#333');
    chipRect.setAttribute('stroke-width', '1');
    svg.appendChild(chipRect);

    var chipText = document.createElementNS(ns, 'text');
    chipText.setAttribute('x', chipCx); chipText.setAttribute('y', chipCy);
    chipText.setAttribute('text-anchor', 'middle'); chipText.setAttribute('dominant-baseline', 'middle');
    chipText.setAttribute('font-family', 'Barlow Condensed, sans-serif'); chipText.setAttribute('font-size', '7');
    chipText.setAttribute('fill', player ? '#f0f0f0' : '#444');
    if (player) {
      var no   = player.no   ? '#' + player.no + ' ' : '';
      var name = player.name ? String(player.name).slice(0, 7) : '';
      chipText.textContent = (no + name).trim() || '—';
    } else {
      chipText.textContent = String(i + 1);
    }
    svg.appendChild(chipText);
  }
}

// ── Main render ───────────────────────────────────────────────
function render(s) {
  if (!s) return;

  // mark that we've received at least one payload and show live status
  if (!hasReceivedData) hasReceivedData = true;
  _setViewerStatus('live');

  var tA = s.teamA || {};
  var tB = s.teamB || {};
  var nameA = tA.name || 'TEAM A';
  var nameB = tB.name || 'TEAM B';

  // Team names — nav pills + column headers
  setText('labelA', nameA);
  setText('labelB', nameB);
  setText('teamANameDisplay', nameA);
  setText('teamBNameDisplay', nameB);

  // Scores with flash
  var newScoreA = tA.score != null ? tA.score : 0;
  var newScoreB = tB.score != null ? tB.score : 0;
  var elA = document.getElementById('scoreA');
  var elB = document.getElementById('scoreB');
  if (_prev.scoreA !== null && newScoreA !== _prev.scoreA) flash(elA);
  if (_prev.scoreB !== null && newScoreB !== _prev.scoreB) flash(elB);
  _prev.scoreA = newScoreA;
  _prev.scoreB = newScoreB;
  if (elA) elA.textContent = newScoreA;
  if (elB) elB.textContent = newScoreB;

  // Current set
  var currentSet = (s.shared && s.shared.set != null) ? s.shared.set : 1;
  setText('viewerSet', currentSet);
  setText('viewerSetA', currentSet);
  setText('viewerSetB', currentSet);

  // Timeouts
  setText('viewerTimeoutA', tA.timeout != null ? tA.timeout : 0);
  setText('viewerTimeoutB', tB.timeout != null ? tB.timeout : 0);
  setText('viewerToA', tA.timeout != null ? tA.timeout : 0);
  setText('viewerToB', tB.timeout != null ? tB.timeout : 0);
  setText('viewerToLabelA', nameA);
  setText('viewerToLabelB', nameB);

  // Committee
  setText('vbCommitteeValue', (s.committee || '').trim() || '—');

  // Lineup labels
  setText('viewerLineupLabelA', nameA.toUpperCase() + ' — ACTIVE LINEUP');
  setText('viewerLineupLabelB', nameB.toUpperCase() + ' — ACTIVE LINEUP');

  // Roster tables — show all players from the admin roster
  var playersA = _normalizePlayers(tA.players || [], 'A');
  var playersB = _normalizePlayers(tB.players || [], 'B');
  renderRoster('A', playersA);
  renderRoster('B', playersB);

  // Lineup circles — accept lineupPlayers (objects) when provided, else fallback to ids
  const lineupA = Array.isArray(tA.lineupPlayers) ? tA.lineupPlayers : (tA.lineup || []);
  const lineupB = Array.isArray(tB.lineupPlayers) ? tB.lineupPlayers : (tB.lineup || []);
  renderLineupCircle('viewerLineupSvgA', nameA, newScoreA, lineupA, playersA);
  renderLineupCircle('viewerLineupSvgB', nameB, newScoreB, lineupB, playersB);
}

// ── Render scheduling to coalesce rapid updates ────────────
let _pendingState = null;
let _scheduled = false;
// Keep a merged copy of the last applied state so partial/minimal payloads
// (e.g. lineup-only updates) don't overwrite other fields with empty values.
let _currentState = {};
function _mergeArrayById(prevArr, incomingArr) {
  if (!Array.isArray(incomingArr)) return prevArr ? JSON.parse(JSON.stringify(prevArr)) : [];
  const existingById = Object.create(null);
  (prevArr || []).forEach(function (item) {
    if (item && item.id != null) existingById[String(item.id)] = item;
  });
  const merged = [];
  incomingArr.forEach(function (item) {
    if (item && typeof item === 'object' && item.id != null) {
      const id = String(item.id);
      merged.push(Object.assign({}, existingById[id] || {}, JSON.parse(JSON.stringify(item))));
      delete existingById[id];
    } else {
      merged.push(JSON.parse(JSON.stringify(item)));
    }
  });
  Object.keys(existingById).forEach(function (id) {
    merged.push(JSON.parse(JSON.stringify(existingById[id])));
  });
  return merged;
}

function mergeState(prev, incoming) {
  if (!incoming) return prev || {};
  if (!prev) return JSON.parse(JSON.stringify(incoming));
  const out = JSON.parse(JSON.stringify(prev));
  ['teamA','teamB'].forEach(function (k) {
    if (incoming[k] !== undefined) {
      out[k] = out[k] || {};
      for (var prop in incoming[k]) {
        if (!Object.prototype.hasOwnProperty.call(incoming[k], prop)) continue;
        if (Array.isArray(incoming[k][prop])) {
          if (prop === 'players' || prop === 'lineupPlayers') {
            out[k][prop] = _mergeArrayById(out[k][prop], incoming[k][prop]);
          } else if (prop === 'lineup') {
            const existing = Array.isArray(out[k][prop]) ? out[k][prop].slice() : [];
            const incomingLineup = incoming[k][prop].slice();
            while (existing.length > incomingLineup.length) incomingLineup.push(null);
            out[k][prop] = incomingLineup;
          } else {
            out[k][prop] = JSON.parse(JSON.stringify(incoming[k][prop]));
          }
        } else {
          out[k][prop] = incoming[k][prop];
        }
      }
    }
  });
  if (incoming.shared !== undefined) out.shared = Object.assign({}, out.shared || {}, incoming.shared);
  if (incoming.committee !== undefined) out.committee = incoming.committee;
  return out;
}

// Immediate render path used for WS/Broadcast/localStorage to avoid rAF delay
function immediateRender(state) {
  try {
    // Merge into the current state and render synchronously for zero-perceived delay
    _currentState = mergeState(_currentState, state);
    render(_currentState);
  } catch (e) { /* ignore render errors */ }
  // Reset any pending scheduled work since we applied immediately
  _pendingState = null;
  _scheduled = false;
}

function scheduleRender(state) {
  // Merge into pending rather than replace — prevents a full server payload from being
  // overwritten by a racing BroadcastChannel or WS delta that arrives before the rAF fires.
  _pendingState = _pendingState ? mergeState(_pendingState, state) : state;
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
  _setViewerStatus('waiting');
  var mid = _resolveViewerMatchId();
  _syncViewerMatchId(mid);
  console.log('[VIEWER] init: resolved mid =', mid);

  // Show localStorage snapshot immediately ONLY if it belongs to the current match.
  // This prevents a stale snapshot from a different match flashing on screen.
  try {
    var raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      try {
        var pack = _extractWrapper(raw);
        var cacheMatchOk = !pack.match_id || !mid || String(pack.match_id) === String(mid) || String(mid) === '0';
        if (pack && pack.payload && cacheMatchOk && _hasMeaningfulState(pack.payload)) {
          console.log('[VIEWER] init: rendering cached snapshot for match_id =', pack.match_id);
          scheduleRender(pack.payload);
          hasReceivedData = true;
        }
      } catch (_) {
        // invalid JSON in storage — ignore
      }
    }
  } catch (_) {}

  // Always fetch from server on page load — this is the primary cross-device path.
  // Server response wins and will overwrite any localStorage snapshot.
  async function tryLoad() {
    try {
      console.log('[VIEWER] tryLoad: fetching from server with mid =', mid);
      const wrapperResp = await fetchServerPayloadWithFallback(mid);
      console.log('[VIEWER] tryLoad: server response:', wrapperResp);
      if (wrapperResp && _applyStateWrapper({ match_id: wrapperResp.match_id, payload: wrapperResp.payload })) {
        // no-op: state applied by helper
      } else if (!hasReceivedData) {
        console.log('[VIEWER] tryLoad: no valid server payload');
        _setViewerStatus('waiting');
      }
    } catch (e) {
      console.error('[VIEWER] tryLoad error:', e);
    }
  }

  if (document.readyState === 'complete') {
    tryLoad();
  } else {
    window.addEventListener('load', tryLoad);
  }

  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      console.log('[VIEWER] pageshow persisted, reloading latest state');
      hasReceivedData = false;
      tryLoad();
    }
  });
})();

// ── Server fetch support (cross-device initial load) ──
// Always refresh server state on page load so the viewer gets the latest payload.
async function fetchServerPayload(mid) {
  try {
    const requestedMid = (mid == null || String(mid).trim() === '') ? DEFAULT_ROOM_ID : String(mid);
    const url = new URL('state.php', location.href).href + '?match_id=' + encodeURIComponent(requestedMid) + '&t=' + Date.now();
    const res = await fetch(url, {
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
      // No credentials needed — state.php GET is public (no auth required for reads)
    });
    if (!res) return null;
    if (!res.ok) {
      console.error('[VIEWER] fetchServerPayload: bad status', res.status, res.statusText, url);
      return null;
    }
    const text = await res.text();
    try {
      const j = text ? JSON.parse(text) : null;
      if (j && j.success && j.payload) {
        return { payload: j.payload, match_id: j.match_id != null ? String(j.match_id) : null };
      }
      console.error('[VIEWER] fetchServerPayload: invalid json payload', j, url);
    } catch (e) {
      console.error('[VIEWER] fetchServerPayload: json parse failed', e, text, url);
    }
  } catch (e) {
    console.error('[VIEWER] fetchServerPayload error:', e);
  }
  return null;
}

async function fetchServerPayloadWithFallback(mid) {
  // Try the specific match first
  const p = await fetchServerPayload(mid);
  if (p) return p;
  // Only fall back to DEFAULT_ROOM_ID when a specific match was requested
  if (!mid || String(mid) === String(DEFAULT_ROOM_ID)) return null;
  // When a specific match_id was requested but the draft state has no live payload,
  // try DEFAULT_ROOM_ID to get any pending state from the admin
  return await fetchServerPayload(DEFAULT_ROOM_ID);
}

// Fetch and render state for a newly-switched match (called when admin creates new match)
async function _fetchAndRenderNewMatch(matchId) {
  try {
    const wrapper = await fetchServerPayloadWithFallback(matchId);
    if (wrapper && wrapper.payload && _hasMeaningfulState(wrapper.payload)) {
      // Cache new match state to localStorage for instant render on future loads
      const canonical = { match_id: matchId, payload: wrapper.payload };
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(canonical)); } catch (_) {}
      // Render the fresh state immediately
      if (!_ssotAcceptPayload(wrapper.payload)) return;
      immediateRender(wrapper.payload);
      hasReceivedData = true;
      _setViewerStatus('live');
    } else {
      _setViewerStatus('waiting');
    }
  } catch (e) {
    console.error('[VIEWER] _fetchAndRenderNewMatch error:', e);
    _setViewerStatus('waiting');
  }
}