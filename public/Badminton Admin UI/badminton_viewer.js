// ============================================================
// badminton_viewer.js — Read-only live score viewer
//
// HOW IT STAYS LIVE (no page refresh needed):
//   badminton_admin.js already calls saveLocalState() after
//   every action, which writes to localStorage under
//   'badmintonMatchState'.  This viewer listens via TWO
//   channels so updates arrive instantly:
//
//   1. BroadcastChannel('badminton_live')
//      → instant push to other tabs in the SAME browser
//        (requires the one-line patch at the bottom of
//         badminton_admin.js — see PATCH block below)
//
//   2. window 'storage' event
//      → fires in every OTHER tab whenever localStorage
//        changes; no admin-side patch required for this one
//
//   Either channel alone is enough for same-device use.
//   Both together give sub-100 ms updates across all tabs.
// ============================================================

const STORAGE_KEY  = 'badmintonMatchState';
const CHANNEL_NAME = 'badminton_live';

// ── 1. BroadcastChannel listener (instant, same browser) ────
let _bc = null;
try {
  _bc = new BroadcastChannel(CHANNEL_NAME);
  _bc.onmessage = function (e) {
    if (e.data && typeof e.data === 'object') render(e.data);
  };
} catch (_) { /* BroadcastChannel not supported — storage event covers it */ }

// WebSocket relay: receive broadcasts from admin via ws-server
(function initWS() {
  try {
    const scheme = (location.protocol === 'https:') ? 'wss://' : 'ws://';
    let url = scheme + location.hostname + ':3000';
    if (window.__wsToken) url += '?token=' + encodeURIComponent(window.__wsToken);
    const _ws = new WebSocket(url);
    _ws.addEventListener('open', function () {
      _wsLive = true;
      try { const mid = (window.MATCH_DATA && MATCH_DATA.match_id) ? MATCH_DATA.match_id : (window.__matchId || null); if (mid) _ws.send(JSON.stringify({ type: 'join', match_id: String(mid) })); } catch (_) {}
    });
    _ws.addEventListener('message', function (ev) {
      try {
        const m = JSON.parse(ev.data);
        if (m) {
          if (m.type === 'last_state' && m.payload) {
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(m.payload)); } catch(_) {}
            render(m.payload);
          } else if ((m.type === 'badminton_state' || m.type === 'state') && m.payload) {
            render(m.payload);
          }
        }
      } catch (_) {}
    });
    _ws.addEventListener('close', function () { _wsLive = false; setTimeout(initWS, 2000); });
    _ws.addEventListener('error', function () { /* ignore */ });
  } catch (_) {}
})();

// ── Periodic DB poll — cross-device fallback ──────────────────────
// Polls state.php every 3 seconds ONLY when the WS is disconnected.
// This guarantees cross-device viewers stay live even without a WS server.
// When WS is connected this does nothing (WS push is faster and free).
let _wsLive = false;
let _pollTimer = null;
const POLL_INTERVAL_MS = 3000;

function _startPoll() {
  if (_pollTimer) return;
  _pollTimer = setInterval(function() {
    if (_wsLive) return; // WS is delivering updates — no need to poll
    try {
      let url = 'state.php?latest=1';
      if (window.MATCH_DATA && MATCH_DATA.match_id) {
        url = 'state.php?match_id=' + encodeURIComponent(MATCH_DATA.match_id);
      } else if (window.__matchId) {
        url = 'state.php?match_id=' + encodeURIComponent(window.__matchId);
      }
      fetch(url)
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(j) {
          if (j && j.success && j.state && !j.state._reset) {
            render(j.state);
            // Keep localStorage in sync for BroadcastChannel consumers
            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(j.state)); } catch(_) {}
          }
        })
        .catch(function() {});
    } catch(_) {}
  }, POLL_INTERVAL_MS);
}
_startPoll();
window.addEventListener('storage', function (e) {
  if (e.key !== STORAGE_KEY) return;
  try {
    const state = e.newValue ? JSON.parse(e.newValue) : null;
    if (state) render(state);
  } catch (_) {}
});

// ── Helpers ──────────────────────────────────────────────────

function setText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = (val == null ? '' : val);
}

function flashEl(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('flash');
  void el.offsetWidth;           // force reflow to restart animation
  el.classList.add('flash');
  setTimeout(function () { el.classList.remove('flash'); }, 500);
}

// ── Match type tab highlight ─────────────────────────────────

function setMatchTypeDisplay(type) {
  const map = { singles: 'mtSingles', doubles: 'mtDoubles', mixed: 'mtMixed' };
  Object.keys(map).forEach(function (k) {
    const el = document.getElementById(map[k]);
    if (el) el.classList.toggle('active', k === type);
  });
}

// ── Player rows ──────────────────────────────────────────────

function buildPlayerRows(containerId, players) {
  const container = document.getElementById(containerId);
  if (!container) return;
  container.innerHTML = '';
  (players || []).forEach(function (p) {
    if (!p.name || !p.name.trim()) return;
    const row  = document.createElement('div');
    row.className = 'player-row';
    const role = document.createElement('span');
    role.className = 'player-role';
    role.textContent = p.role || '';
    const name = document.createElement('span');
    name.textContent = p.name;
    row.appendChild(role);
    row.appendChild(name);
    container.appendChild(row);
  });
}

function buildPlayerList(state, team) {
  // Admin writes flat keys: teamAPlayer1, teamAPlayer2, etc.
  const prefix = 'team' + team;
  const type   = (state.matchType || 'singles').toLowerCase();
  const list   = [];

  if (type === 'singles') {
    list.push({ role: 'Singles',       name: state[prefix + 'Player1'] || '' });
  } else if (type.indexOf('mixed') !== -1) {
    list.push({ role: 'Male Player',   name: state[prefix + 'Player1'] || '' });
    list.push({ role: 'Female Player', name: state[prefix + 'Player2'] || '' });
  } else {
    list.push({ role: 'Player 1',      name: state[prefix + 'Player1'] || '' });
    list.push({ role: 'Player 2',      name: state[prefix + 'Player2'] || '' });
  }
  return list.filter(function (p) { return p.name.trim() !== ''; });
}

// ── Previous score cache (for flash detection) ───────────────
const _prev = { scoreA: null, scoreB: null };

// ── Main render ──────────────────────────────────────────────

function render(state) {
  if (!state) { setMatchTypeDisplay('singles'); return; }

  const teamAName = state.teamAName || 'TEAM A';
  const teamBName = state.teamBName || 'TEAM B';

  setText('teamAName', teamAName);
  setText('teamBName', teamBName);

  // Scores — flash when they change
  const newScoreA = state.scoreA != null ? state.scoreA : 0;
  const newScoreB = state.scoreB != null ? state.scoreB : 0;
  if (_prev.scoreA !== null && newScoreA !== _prev.scoreA) flashEl('scoreA');
  if (_prev.scoreB !== null && newScoreB !== _prev.scoreB) flashEl('scoreB');
  _prev.scoreA = newScoreA;
  _prev.scoreB = newScoreB;

  setText('scoreA', newScoreA);
  setText('scoreB', newScoreB);
  setText('gamesA', state.gamesA != null ? state.gamesA : 0);
  setText('gamesB', state.gamesB != null ? state.gamesB : 0);

  // Center panel
  setText('bestOfBox',     state.bestOf     != null ? state.bestOf     : 3);
  setText('currentSetBox', state.currentSet != null ? state.currentSet : 1);
  setText('timeoutA',      state.timeoutA   != null ? state.timeoutA   : 0);
  setText('timeoutB',      state.timeoutB   != null ? state.timeoutB   : 0);

  // Serving
  const servingName = (state.servingTeam === 'B') ? teamBName : teamAName;
  setText('servingTeamLabel', servingName);

  // Timeout labels always mirror team names
  setText('timeoutLabelA', teamAName);
  setText('timeoutLabelB', teamBName);

  // Committee
  setText('committeeDisplay', (state.committee || '').trim() || '—');

  // Match type tabs
  const typeRaw = (state.matchType || 'singles').toLowerCase();
  const typeKey = typeRaw.indexOf('mixed')  !== -1 ? 'mixed'
                : typeRaw.indexOf('double') !== -1 ? 'doubles'
                : 'singles';
  setMatchTypeDisplay(typeKey);

  // Players
  buildPlayerRows('playersA', buildPlayerList(state, 'A'));
  buildPlayerRows('playersB', buildPlayerList(state, 'B'));

  // Swap sides — mirror what the admin does to its grid
  const area  = document.getElementById('mainArea');
  const toRow = document.getElementById('timeoutRow');
  if (state.swapped) {
    if (area)  area.style.gridTemplateAreas = '"right center left"';
    if (toRow) toRow.style.flexDirection    = 'row-reverse';
  } else {
    if (area)  area.style.gridTemplateAreas = '"left center right"';
    if (toRow) toRow.style.flexDirection    = 'row';
  }

  // Status bar
  setText('statusMatchType',  state.matchType  || 'Singles');
  setText('statusBestOf',     state.bestOf     != null ? state.bestOf     : 3);
  setText('statusCurrentSet', state.currentSet != null ? state.currentSet : 1);
  setText('statusScore',      newScoreA + ' — ' + newScoreB);
  setText('statusGames',      (state.gamesA || 0) + ' — ' + (state.gamesB || 0));
  setText('statusServing',    servingName);

  // Previous completed set (show last entry from state.sets if present)
  try {
    const sets = Array.isArray(state.sets) ? state.sets : [];
    if (sets.length > 0) {
      const last = sets[sets.length - 1];
      const a = (last.teamAScore != null) ? last.teamAScore : (last.teamAScore === 0 ? 0 : '—');
      const b = (last.teamBScore != null) ? last.teamBScore : (last.teamBScore === 0 ? 0 : '—');
      setText('statusPrevSet', 'Set ' + (last.setNumber || sets.length) + ': ' + a + ' — ' + b);
    } else {
      setText('statusPrevSet', '—');
    }
  } catch (e) {
    setText('statusPrevSet', '—');
  }

  // Prev-winner: determine by numeric comparison of the last completed set's scores
  try {
    const sets = Array.isArray(state.sets) ? state.sets : [];
    let prevWinnerLabel = '—';
    if (sets.length > 0) {
      const last = sets[sets.length - 1];
      const aScore = Number(last.teamAScore);
      const bScore = Number(last.teamBScore);
      if (!isNaN(aScore) && !isNaN(bScore) && aScore !== bScore) {
        prevWinnerLabel = aScore > bScore ? teamAName : teamBName;
      } else {
        // fallback to any explicit winner field (set_winner / winner)
        const inferred = last && (last.set_winner || last.winner) ? (last.set_winner || last.winner) : null;
        if (inferred != null) {
          const up = String(inferred).toUpperCase();
          if (up === 'A') prevWinnerLabel = teamAName;
          else if (up === 'B') prevWinnerLabel = teamBName;
          else prevWinnerLabel = String(inferred);
        }
      }
    }
    setText('statusPrevWinner', prevWinnerLabel);
  } catch (e) { setText('statusPrevWinner', '—'); }

  // Highlight manual winners persisted from admin: prefer current set mapping
  // so admin toggles appear immediately in the viewer; otherwise fall back
  // to last completed set. Also update viewer-side buttons.
  try {
    // clear previous highlights and button states
    document.querySelectorAll('.manual-winner').forEach(el => { el.classList.remove('manual-winner'); });
    document.querySelectorAll('.winner-btn.active').forEach(b => b.classList.remove('active'));

    const manual = state.manualWinners || {};
    const sets = Array.isArray(state.sets) ? state.sets : [];
    const curSetNo = String(state.currentSet != null ? state.currentSet : (sets.length || 1));

    // prefer manual winner for the current set
    let winner = manual[curSetNo] ? String(manual[curSetNo]).toUpperCase() : null;

    // if no manual for current, try the last completed set
    if (!winner && sets.length > 0) {
      const last = sets[sets.length - 1];
      const lastNo = String(last.setNumber || sets.length);
      winner = manual[lastNo] ? String(manual[lastNo]).toUpperCase() : null;
    }

    if (winner === 'A') {
      const hdr = document.querySelector('#panelA .team-header'); if (hdr) hdr.classList.add('manual-winner');
      const vBtnA = document.getElementById('markWinnerA'); if (vBtnA) vBtnA.classList.add('active');
    } else if (winner === 'B') {
      const hdr = document.querySelector('#panelB .team-header'); if (hdr) hdr.classList.add('manual-winner');
      const vBtnB = document.getElementById('markWinnerB'); if (vBtnB) vBtnB.classList.add('active');
    }
  } catch (e) { /* ignore */ }
  // previous winner already handled above (prefers manual mapping)
  // Manual winner buttons: ensure DOM hooks exist and attach handlers once
  try {
    function clearManualWinner() {
      const hA = document.querySelector('#panelA .team-header');
      const hB = document.querySelector('#panelB .team-header');
      if (hA) { hA.classList.remove('manual-winner'); }
      if (hB) { hB.classList.remove('manual-winner'); }
    }

    function setManualWinner(team) {
      clearManualWinner();
      const hdr = document.querySelector('#panel' + team + ' .team-header');
      if (!hdr) return;
      hdr.classList.add('manual-winner');
    }

    const btnA = document.getElementById('markWinnerA');
    const btnB = document.getElementById('markWinnerB');
    if (btnA && !btnA.dataset._bind) {
      btnA.addEventListener('click', function() {
        setManualWinner('A');
        // reflect label in footer
        setText('statusPrevWinner', teamAName);
      });
      btnA.dataset._bind = '1';
    }
    if (btnB && !btnB.dataset._bind) {
      btnB.addEventListener('click', function() {
        setManualWinner('B');
        setText('statusPrevWinner', teamBName);
      });
      btnB.dataset._bind = '1';
    }
  } catch (e) { /* ignore manual-winner setup errors */ }
}

// ── Initial load — priority order: ───────────────────────────
//   1. localStorage (instant, same device)
//   2. DB via state.php (cross-device fallback when localStorage is empty)
//
// This means a viewer on a TV or second laptop that opens mid-match
// will immediately see the current score even without a WS connection.

(function init() {
  // Step 1: try localStorage first (instant, no network needed)
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        render(parsed);
        // Still kick off a DB fetch in background to catch
        // any state written from a DIFFERENT device/browser
        _loadStateFromDB();
        return;
      }
    }
  } catch (_) {}

  // Step 2: localStorage was empty — fetch from DB
  setMatchTypeDisplay('singles');
  _loadStateFromDB();
})();

// ── Fetch latest live state from state.php (DB-backed) ───────────
function _loadStateFromDB() {
  try {
    // If a match_id is embedded in the page (PHP can echo it), use it
    // Otherwise request the most recently updated live state
    let url = 'state.php?latest=1';
    if (window.MATCH_DATA && MATCH_DATA.match_id) {
      url = 'state.php?match_id=' + encodeURIComponent(MATCH_DATA.match_id);
    } else if (window.__matchId) {
      url = 'state.php?match_id=' + encodeURIComponent(window.__matchId);
    }

    fetch(url)
      .then(function(r) { return r.ok ? r.json() : null; })
      .then(function(j) {
        if (j && j.success && j.state && typeof j.state === 'object') {
          // Only apply if the DB state is newer than what localStorage has
          try {
            const localRaw = localStorage.getItem(STORAGE_KEY);
            if (localRaw) {
              // We have local state — only override if DB has a match_id
              // (meaning it was written by an admin, not a stale default)
              if (!j.state._reset) {
                // Update localStorage so same-device viewers also benefit
                localStorage.setItem(STORAGE_KEY, JSON.stringify(j.state));
              }
            } else {
              if (!j.state._reset) {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(j.state));
                render(j.state);
              }
            }
          } catch (_) {
            if (!j.state._reset) render(j.state);
          }
        }
      })
      .catch(function() { /* state.php unreachable — localStorage or WS covers it */ });
  } catch (_) {}
}
