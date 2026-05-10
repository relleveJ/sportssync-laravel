// ============================================================
// badminton_viewer.js â€” Read-only live score viewer
//
// HOW IT STAYS LIVE (no page refresh needed):
//   badminton_admin.js already calls saveLocalState() after
//   every action, which writes to localStorage under
//   'badmintonMatchState'.  This viewer listens via TWO
//   channels so updates arrive instantly:
//
//   1. BroadcastChannel('badminton_live')
//      â†’ instant push to other tabs in the SAME browser
//        (requires the one-line patch at the bottom of
//         badminton_admin.js â€” see PATCH block below)
//
//   2. window 'storage' event
//      â†’ fires in every OTHER tab whenever localStorage
//        changes; no admin-side patch required for this one
//
//   Either channel alone is enough for same-device use.
//   Both together give sub-100 ms updates across all tabs.
// ============================================================

const STORAGE_KEY  = 'badmintonMatchState';
const CHANNEL_NAME = 'badminton_live';

// Track previous sets length to detect new sets
let _prevSetsLength = 0;

// Track previous winner team to detect match winner
let _prevWinnerTeam = null;

// â”€â”€ Apply a remote reset on the viewer: clear state and reload â”€â”€
function _buildViewerResetState(payload) {
  payload = payload || {};
  return {
    _resetApplied: true,
    match_id: payload.match_id || null,
    sets: [],
    swapped: false,
    teamAName: 'TEAM A',
    teamBName: 'TEAM B',
    scoreA: 0,
    scoreB: 0,
    gamesA: 0,
    gamesB: 0,
    bestOf: 3,
    currentSet: 1,
    timeoutA: 0,
    timeoutB: 0,
    servingTeam: 'A',
    committee: '',
    matchType: 'singles',
    teamAPlayer1: '',
    teamAPlayer2: '',
    teamBPlayer1: '',
    teamBPlayer2: '',
    manualWinners: {}
  };
}

function _applyViewerReset(payload) {
  try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
  try { sessionStorage.removeItem('badminton_match_id'); } catch (_) {}
  render(_buildViewerResetState(payload));
}

function _resetIfLocalStateWasActive() {
  try {
    if (localStorage.getItem(STORAGE_KEY)) _applyViewerReset();
  } catch (_) {}
}

// â”€â”€ 1. BroadcastChannel listener (instant, same browser) â”€â”€â”€â”€
let _bc = null;
try {
  _bc = new BroadcastChannel(CHANNEL_NAME);
  _bc.onmessage = function (e) {
    if (!e.data || typeof e.data !== 'object') return;
    // Reset signal from another tab
    if (e.data._reset === true) { _applyViewerReset(e.data); return; }
    // âœ… SSOT NEW MATCH â€” fresh match broadcast via BroadcastChannel (same browser)
    // When admin creates a new match on the same device, the BroadcastChannel
    // delivers the fresh state here. Wipe localStorage and render immediately.
    if (e.data.match_id && e.data._newMatch === true) {
      try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
      try { sessionStorage.setItem('badminton_match_id', String(e.data.match_id)); } catch (_) {}
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.assign({}, e.data, { _savedAt: new Date().toISOString() }))); } catch (_) {}
      render(e.data);
      return;
    }
    // âœ… SSOT NEW MATCH â€” end
    render(e.data);
  };
} catch (_) { /* BroadcastChannel not supported â€” storage event covers it */ }

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
          // â”€â”€ Remote reset broadcast â”€â”€
          if (m.type === 'new_match' && m.payload && m.payload._reset === true) {
            _applyViewerReset(m.payload);
            return;
          }
          // âœ… SSOT NEW MATCH â€” handle fresh match broadcast from admin
          // When admin creates a new match, a new_match message arrives with
          // a full freshViewerState payload (has match_id but no _reset flag).
          // Apply it immediately so viewers see the new blank slate in real-time.
          if (m.type === 'new_match' && m.payload && m.payload.match_id && !m.payload._reset) {
            try {
              const freshState = m.payload;
              // Wipe stale localStorage so no previous match data bleeds in
              try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
              try { sessionStorage.setItem('badminton_match_id', String(freshState.match_id)); } catch (_) {}
              // Cache and render the fresh state
              try { localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.assign({}, freshState, { _savedAt: new Date().toISOString() }))); } catch (_) {}
              render(freshState);
            } catch (_) {}
            return;
          }
          // âœ… SSOT NEW MATCH â€” end
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

// â”€â”€ Periodic DB poll â€” cross-device fallback â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Polls state.php frequently ONLY when the WS is disconnected.
// This guarantees cross-device viewers stay live even without a WS server.
// When WS is connected this does nothing (WS push is faster and free).
let _wsLive = false;
let _pollTimer = null;
const POLL_INTERVAL_MS = 500;

function _startPoll() {
  if (_pollTimer) return;
  _pollTimer = setInterval(function() {
    if (_wsLive) return; // WS is delivering updates â€” no need to poll
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
          } else if (j && !j.success) {
            _resetIfLocalStateWasActive();
          }
        })
        .catch(function() {});
    } catch(_) {}
  }, POLL_INTERVAL_MS);
}
_startPoll();
window.addEventListener('storage', function (e) {
  if (e.key !== STORAGE_KEY) return;
  // Key removed = reset was broadcast via localStorage.removeItem()
  if (e.newValue === null) { _applyViewerReset(); return; }
  try {
    const state = JSON.parse(e.newValue);
    if (state && state._reset === true) { _applyViewerReset(state); return; }
    if (state) render(state);
  } catch (_) {}
});

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

// â”€â”€ Match type tab highlight â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function setMatchTypeDisplay(type) {
  const map = { singles: 'mtSingles', doubles: 'mtDoubles', mixed: 'mtMixed' };
  Object.keys(map).forEach(function (k) {
    const el = document.getElementById(map[k]);
    if (el) el.classList.toggle('active', k === type);
  });
}

// â”€â”€ Player rows â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

// â”€â”€ Previous score cache (for flash detection) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const _prev = { scoreA: null, scoreB: null };

// â”€â”€ Main render â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

function render(state) {
  if (!state) { setMatchTypeDisplay('singles'); return; }

  const teamAName = state.teamAName || 'TEAM A';
  const teamBName = state.teamBName || 'TEAM B';

  setText('teamAName', teamAName);
  setText('teamBName', teamBName);

  // Scores â€” flash when they change
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
  setText('committeeDisplay', (state.committee || '').trim() || 'â€”');

  // Match type tabs
  const typeRaw = (state.matchType || 'singles').toLowerCase();
  const typeKey = typeRaw.indexOf('mixed')  !== -1 ? 'mixed'
                : typeRaw.indexOf('double') !== -1 ? 'doubles'
                : 'singles';
  setMatchTypeDisplay(typeKey);

  // Check for new set winner
  if (state.sets && state.sets.length > _prevSetsLength) {
    const newSet = state.sets[state.sets.length - 1];
    if (newSet && newSet.winner) {
      const winnerName = newSet.winner === 'A' ? teamAName : teamBName;
      showWinnerModal('ðŸ† SET WINNER', `${winnerName} wins Set ${newSet.setNumber}!`);
    }
  }
  _prevSetsLength = state.sets ? state.sets.length : 0;

  // Check for match winner
  if (state.winner_team && state.winner_team !== _prevWinnerTeam) {
    const winnerName = state.winner_team === 'A' ? teamAName : teamBName;
    showWinnerModal('ðŸ† MATCH WINNER', `${winnerName} wins the match!`);
    _prevWinnerTeam = state.winner_team;
  }

  // Players
  buildPlayerRows('bd-playersA', buildPlayerList(state, 'A'));
  buildPlayerRows('bd-playersB', buildPlayerList(state, 'B'));

  // Swap sides â€” mirror what the admin does to its grid
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
  setText('statusScore',      newScoreA + ' â€” ' + newScoreB);
  setText('statusGames',      (state.gamesA || 0) + ' â€” ' + (state.gamesB || 0));
  setText('statusServing',    servingName);

  // Previous completed set (show last entry from state.sets if present)
  try {
    const sets = Array.isArray(state.sets) ? state.sets : [];
    if (sets.length > 0) {
      const last = sets[sets.length - 1];
      const a = (last.teamAScore != null) ? last.teamAScore : (last.teamAScore === 0 ? 0 : 'â€”');
      const b = (last.teamBScore != null) ? last.teamBScore : (last.teamBScore === 0 ? 0 : 'â€”');
      setText('statusPrevSet', 'Set ' + (last.setNumber || sets.length) + ': ' + a + ' â€” ' + b);
    } else {
      setText('statusPrevSet', 'â€”');
    }
  } catch (e) {
    setText('statusPrevSet', 'â€”');
  }

  // Prev-winner: determine by numeric comparison of the last completed set's scores
  try {
    const sets = Array.isArray(state.sets) ? state.sets : [];
    let prevWinnerLabel = 'â€”';
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
  } catch (e) { setText('statusPrevWinner', 'â€”'); }

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
      const vBtnA = document.getElementById('markWinnerA'); if (vBtnA) { vBtnA.classList.add('active'); flashEl('markWinnerA'); }
    } else if (winner === 'B') {
      const hdr = document.querySelector('#panelB .team-header'); if (hdr) hdr.classList.add('manual-winner');
      const vBtnB = document.getElementById('markWinnerB'); if (vBtnB) { vBtnB.classList.add('active'); flashEl('markWinnerB'); }
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

    // âœ… SSOT FIX START â€” Patch 5
    // Viewer winner buttons are READ-ONLY display indicators that mirror the
    // admin's manualWinners state (already applied above via the manual[] mapping).
    // We intentionally do NOT attach click handlers that mutate local DOM here â€”
    // those mutations were overwritten on every render() call and never synced
    // back to the SSOT, causing phantom highlights that disappeared immediately.
    // The trophy buttons remain visible so spectators can see who the admin marked.
    // âœ… SSOT FIX END
  } catch (e) { /* ignore manual-winner setup errors */ }
}

// â”€â”€ Initial load â€” priority order: â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

  // Step 2: localStorage was empty â€” fetch from DB
  setMatchTypeDisplay('singles');
  _loadStateFromDB();
})();

// â”€â”€ Fetch latest live state from state.php (DB-backed) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
          // âœ… SSOT FIX START â€” Patch 6
          // DB (state.php) is the SSOT. Always apply and cache its state when:
          //   a) it is not a reset signal, AND
          //   b) it carries a real match_id (was written by an admin, not a default).
          // Previously we skipped applying DB state when localStorage was non-empty,
          // which left viewers permanently stuck on stale local data after a page reload.
          if (!j.state._reset) {
            // Skip payloads that originated from this same tab (no-op anti-flicker)
            const localRaw = localStorage.getItem(STORAGE_KEY);
            let shouldApply = true;
            if (localRaw) {
              try {
                const localParsed = JSON.parse(localRaw);
                // If local and DB match_id are the same AND local is â‰¥ DB, skip to avoid flicker
                if (localParsed && localParsed.match_id && j.state.match_id &&
                    String(localParsed.match_id) === String(j.state.match_id) &&
                    j.updated_at && localParsed._savedAt && localParsed._savedAt >= j.updated_at) {
                  shouldApply = false;
                }
              } catch (_) {}
            }
            if (shouldApply) {
              try { localStorage.setItem(STORAGE_KEY, JSON.stringify(j.state)); } catch (_) {}
              render(j.state);
            }
          }
          // ✅ SSOT FIX END
        } else if (j && !j.success) {
          _resetIfLocalStateWasActive();
        }
      })
      .catch(function() { /* state.php unreachable — localStorage or WS covers it */ });
  } catch (_) {}
}

(function init() {
  // Step 1: try localStorage first (instant, no network needed)
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        render(parsed);
      }
    }
  } catch (_) {}

  // Step 2: also fetch the latest from DB; may override if fresher
  _loadStateFromDB();
})();
function showWinnerModal(title, msg) {
  document.getElementById('winnerModalTitle').textContent = title;
  document.getElementById('winnerModalMsg').textContent = msg;
  document.getElementById('winnerModal').style.display = 'flex';
}

function closeWinnerModal() {
  document.getElementById('winnerModal').style.display = 'none';
}
