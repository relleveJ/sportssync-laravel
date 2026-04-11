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

// ── BroadcastChannel listener (instant, same browser) ───────────
let bc = null;
try {
  bc = new BroadcastChannel(CHANNEL_NAME);
  bc.onmessage = function(e) {
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
          }
        }
      } catch (_) {}
    });
    _ws.addEventListener('close',  function () { setTimeout(initWS, 2000); });
    _ws.addEventListener('error',  function () {});
  } catch (_) {}
})();

// ── localStorage 'storage' event (cross-tab) ────────────────────
window.addEventListener('storage', function(e) {
  if (e.key !== STORAGE_KEY) return;
  try {
    const state = e.newValue ? JSON.parse(e.newValue) : null;
    if (state) render(state);
  } catch (_) {}
});

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

  // Committee
  const committee = (tA.committee != null ? '' : '') || (state.committee || '').trim();
  setText('committeeDisplay', committee || '—');

  // Match type tabs
  const typeRaw = (state.matchType || 'singles').toLowerCase();
  const typeKey = typeRaw.indexOf('mixed')  !== -1 ? 'mixed'
                : typeRaw.indexOf('double') !== -1 ? 'doubles'
                : 'singles';
  setMatchTypeDisplay(typeKey);

  // Players — build from nested teamA.players[] array
  renderPlayers('playersA', buildPlayers(state, 'A'));
  renderPlayers('playersB', buildPlayers(state, 'B'));

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
}

// ── Initial load from localStorage (restore if admin was already open) ──
(function init() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) render(JSON.parse(raw));
  } catch (_) {}
})();
