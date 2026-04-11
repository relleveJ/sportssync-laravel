// ============================================================
// volleyball_viewer.js — Read-only live viewer
// Three-channel live update: BroadcastChannel + localStorage
// storage event + WebSocket relay
// ============================================================

const STORAGE_KEY  = 'volleyballLiveState';
const CHANNEL_NAME = 'volleyball_live';
// Active lineup slots per team (must match admin)
const ACTIVE_LINEUP_SIZE = 6;

// ── 1. BroadcastChannel (instant, same browser) ──────────────
let _bc = null;
try {
  _bc = new BroadcastChannel(CHANNEL_NAME);
  _bc.onmessage = function (e) {
    if (e.data && typeof e.data === 'object') render(e.data);
  };
} catch (_) {}

// ── 2. localStorage storage event (cross-tab, instant) ───────
window.addEventListener('storage', function (e) {
  if (e.key !== STORAGE_KEY) return;
  try {
    const s = e.newValue ? JSON.parse(e.newValue) : null;
    if (s) render(s);
  } catch (_) {}
});

// ── 3. WebSocket relay (cross-device) ────────────────────────
(function initWS() {
  try {
    const scheme = location.protocol === 'https:' ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    const ws = new WebSocket(url);
    ws.addEventListener('open', function () {
      try {
        if (window.__matchId) ws.send(JSON.stringify({ type: 'join', match_id: String(window.__matchId) }));
      } catch (_) {}
    });
    ws.addEventListener('message', function (ev) {
      try {
        const m = JSON.parse(ev.data);
        if (!m) return;
        if (m.type === 'last_state' && m.payload) render(m.payload);
        else if ((m.type === 'volleyball_state' || m.type === 'state') && m.payload) render(m.payload);
      } catch (_) {}
    });
    ws.addEventListener('close', function () { setTimeout(initWS, 2000); });
    ws.addEventListener('error', function () { /* ignore */ });
  } catch (_) {}
})();

// ── Helpers ──────────────────────────────────────────────────
function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = (val == null ? '' : val);
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

// ── Render roster table ───────────────────────────────────────
const VB_STATS = ['pts', 'spike', 'ace', 'exSet', 'exDig'];

function renderRoster(team, players) {
  const tbody = document.getElementById('tbody' + team);
  if (!tbody) return;
  tbody.innerHTML = '';

  (players || []).forEach(function (p) {
    const tr = document.createElement('tr');
    tr.className = 'player-row';

    // No.
    const tdNo = document.createElement('td');
    tdNo.className = 'td-no';
    tdNo.textContent = p.no || '';
    tr.appendChild(tdNo);

    // Name
    const tdNm = document.createElement('td');
    tdNm.className = 'td-name';
    tdNm.textContent = p.name || '—';
    tr.appendChild(tdNm);

    // Stats (read-only spans)
    VB_STATS.forEach(function (stat) {
      const td = document.createElement('td');
      if (stat === 'pts') td.className = 'pts-cell';
      const span = document.createElement('span');
      span.className = 'stat-val';
      span.textContent = p[stat] != null ? p[stat] : 0;
      td.appendChild(span);
      tr.appendChild(td);
    });

    tbody.appendChild(tr);
  });
}

// ── Render lineup circle (read-only) ─────────────────────────
function renderLineupCircle(svgId, teamName, score, lineup, players) {
  const svg = document.getElementById(svgId);
  if (!svg) return;

  // Normalize lineup length to ACTIVE_LINEUP_SIZE
  lineup = Array.isArray(lineup) ? lineup.slice(0) : [];
  while (lineup.length < ACTIVE_LINEUP_SIZE) lineup.push(null);
  if (lineup.length > ACTIVE_LINEUP_SIZE) lineup.length = ACTIVE_LINEUP_SIZE;

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

  // player chips (dynamic count)
  const chipR = 46;
  var step = 360 / ACTIVE_LINEUP_SIZE;
  for (var i = 0; i < ACTIVE_LINEUP_SIZE; i++) {
    var angle = (i * step - 90) * (Math.PI / 180);
    var chipCx = cx + chipR * Math.cos(angle);
    var chipCy = cy + chipR * Math.sin(angle);

    var playerId = (lineup && lineup[i]) || null;
    var player   = playerId ? (players || []).find(function (p) { return p.id === playerId; }) : null;

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
      var name = player.name ? player.name.slice(0, 7) : '';
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
  setText('committeeValue', (s.committee || '').trim() || '—');

  // Lineup labels
  setText('viewerLineupLabelA', nameA.toUpperCase() + ' — ACTIVE LINEUP');
  setText('viewerLineupLabelB', nameB.toUpperCase() + ' — ACTIVE LINEUP');

  // Roster tables
  renderRoster('A', tA.players || []);
  renderRoster('B', tB.players || []);

  // Lineup circles
  renderLineupCircle('viewerLineupSvgA', nameA, newScoreA, tA.lineup, tA.players || []);
  renderLineupCircle('viewerLineupSvgB', nameB, newScoreB, tB.lineup, tB.players || []);
}

// ── Initial load from localStorage ───────────────────────────
(function init() {
  try {
    var raw = localStorage.getItem(STORAGE_KEY);
    if (raw) render(JSON.parse(raw));
  } catch (_) {}
})();
