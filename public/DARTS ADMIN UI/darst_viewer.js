const PLAYER_COLORS = ['#CC0000','#003399','#d4aa00','#E65C00'];

let lastState       = null;
let lastWinnerShown = null;
let lastEventId     = null;
let lastEventLeg    = null;
let lastStateJson   = null;
let winnerHideTimer = null;
let newLegClearedAt = null;

/* ---- helpers ---- */
function esc(s) {
  return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function setTxt(id, val) {
  const el = document.getElementById(id);
  if (el && el.textContent !== String(val)) el.textContent = String(val);
}
function flashScore(el) {
  el.classList.remove('score-updated');
  void el.offsetWidth;
  el.classList.add('score-updated');
}

/* Show winner overlay and auto-hide after a short duration. */
function showWinnerOverlay(name) {
  try {
    var el = document.getElementById('winner-overlay');
    document.getElementById('winner-name-display').textContent = String(name || 'Winner!');
    el.classList.add('show');
    // clear any existing timeout
    if (winnerHideTimer) { clearTimeout(winnerHideTimer); winnerHideTimer = null; }
    // auto-hide after 2500ms
    winnerHideTimer = setTimeout(function() {
      try { hideWinnerOverlay(); } catch (e) {}
    }, 2500);
  } catch (e) {}
}

function hideWinnerOverlay() {
  try {
    var el = document.getElementById('winner-overlay');
    if (el) el.classList.remove('show');
  } catch (e) {}
  if (winnerHideTimer) { clearTimeout(winnerHideTimer); winnerHideTimer = null; }
  lastEventId = null; lastEventLeg = null; lastWinnerShown = null;
}

/* ---- build a brand-new player card (first time a slot appears) ---- */
function buildCard(i, colIdx) {
  const card = document.createElement('div');
  card.className = 'pcard';
  card.id = 'pcard-' + i;
  card.innerHTML =
    '<div class="pcard-header color-' + colIdx + '" id="pcard-hdr-' + i + '">' +
      '<div class="pcard-name" id="pcard-name-' + i + '"></div>' +
      '<div class="pcard-team" id="pcard-team-' + i + '"></div>' +
    '</div>' +
    '<div class="pcard-score-area">' +
      '<div class="pcard-score" id="pcard-score-' + i + '"></div>' +
      '<div class="pcard-score-label">REMAINING</div>' +
    '</div>' +
    '<div class="pcard-legs" id="pcard-legs-' + i + '">' +
      '<div>' +
        '<div class="pcard-legs-label">LEGS WON</div>' +
        '<div class="pcard-legs-count" id="pcard-legcount-' + i + '">0</div>' +
      '</div>' +
      '<div class="pcard-pips" id="pcard-pips-' + i + '"></div>' +
    '</div>' +
    '<div class="pcard-history" id="pcard-hist-' + i + '">' +
      '<span style="color:#444;font-size:.75rem;letter-spacing:1px">NO THROWS YET</span>' +
    '</div>';
  return card;
}

/* ---- patch every field of an existing card in-place ---- */
function patchCard(i, p, isActive, isWinner, legsToWin, gameType) {
  const card = document.getElementById('pcard-' + i);
  if (!card) return;

  // active / winner class
  const cls = 'pcard' + (isWinner ? ' match-winner' : (isActive ? ' active-turn' : ''));
  if (card.className !== cls) card.className = cls;

  // name
  const nameEl = document.getElementById('pcard-name-' + i);
  const nameVal = esc(p.name || 'PLAYER ' + p.player_number);
  if (nameEl && nameEl.innerHTML !== nameVal) nameEl.innerHTML = nameVal;

  // team
  const teamEl = document.getElementById('pcard-team-' + i);
  const teamVal = esc(p.team || '');
  if (teamEl && teamEl.innerHTML !== teamVal) teamEl.innerHTML = teamVal;

  // score
  const scoreEl  = document.getElementById('pcard-score-' + i);
  const score     = typeof p.score === 'number' ? p.score : gameType;
  const scoreCls  = score <= 50 ? 'pcard-score score-low' : 'pcard-score';
  if (scoreEl) {
    if (scoreEl.textContent !== String(score)) {
      scoreEl.textContent = String(score);
      flashScore(scoreEl);
    }
    if (scoreEl.className !== scoreCls) scoreEl.className = scoreCls;
  }

  // legs won count
  const legsWon    = p.legs || 0;
  const legCountEl = document.getElementById('pcard-legcount-' + i);
  if (legCountEl && legCountEl.textContent !== String(legsWon)) {
    legCountEl.textContent = String(legsWon);
  }

  // pips — only rebuild when legsToWin or legsWon changed
  const pipsEl = document.getElementById('pcard-pips-' + i);
  if (pipsEl) {
    const total = legsToWin || 3;
    if (pipsEl.children.length !== total ||
        pipsEl.querySelectorAll('.pip.won').length !== legsWon) {
      pipsEl.innerHTML = Array.from({length: total}, function(_, k) {
        return '<div class="pip ' + (k < legsWon ? 'won' : '') + '"></div>';
      }).join('');
    }
  }

  // throw history — rebuild only when content fingerprint changes
  const histEl = document.getElementById('pcard-hist-' + i);
  if (histEl) {
    var hist  = p.hist || [];
    var shown = hist.slice(-8);
    var newKey = shown.map(function(t){ return t.bust ? 'B'+t.v : t.v; }).join(',') + (isActive ? '*' : '');

    // If a new leg has just started, briefly clear previous-leg throws so viewers
    // don't see stale data. After a short grace period we resume normal rendering.
    if (newLegClearedAt && (Date.now() - newLegClearedAt) < 2500) {
      if (histEl.dataset.key !== 'cleared') {
        histEl.dataset.key = 'cleared';
        histEl.innerHTML = '<span style="color:#444;font-size:.75rem;letter-spacing:1px">NO THROWS YET</span>';
      }
    } else {
      if (histEl.dataset.key !== newKey) {
        histEl.dataset.key = newKey;
        if (shown.length === 0) {
          histEl.innerHTML = '<span style="color:#444;font-size:.75rem;letter-spacing:1px">NO THROWS YET</span>';
        } else {
          histEl.innerHTML = '';
          shown.forEach(function(t, ti) {
            var chip = document.createElement('div');
            var isRecent = ti === shown.length - 1 && isActive;
            chip.className = 'throw-chip' + (t.bust ? ' bust' : (isRecent ? ' recent' : ''));
            chip.textContent = t.bust ? 'BUST(' + t.v + ')' : t.v;
            histEl.appendChild(chip);
          });
        }
      }
    }
  }
}

/* ---- main render entry point — called on every state push ---- */
function renderState(st) {
  if (!st || !Array.isArray(st.players)) return;

  // Lightweight dedupe: avoid re-rendering identical canonical states to prevent flicker.
  try {
    // fingerprint only tracks the essential numeric/structural state used for rendering
    // (scores, legs, recent throw history, current player/leg). This avoids re-rendering
    // on volatile fields like timestamps while still updating on real gameplay changes.
    const fingerprint = JSON.stringify({
      players: (st.players || []).map(p => ({
        score: typeof p.score === 'number' ? p.score : (p.v || 0),
        legs: typeof p.legs === 'number' ? p.legs : (p.legsWon || 0),
        hist: (p.hist || []).slice(-4).map(h => (h.bust ? 'B' : '') + (h.v || h.value || 0)).join(',')
      })),
      currentPlayer: st.currentPlayer,
      currentLeg: st.currentLeg,
      gameType: st.gameType,
      legsToWin: st.legsToWin,
      legs_history: st.legs_history || [],
      _last_event: st._last_event || null
    });
    if (lastStateJson === fingerprint) {
      // identical render — still process overlays (last_event) but skip full patch
      const ev = st._last_event || null;
      if (!(ev && ev.id && ev.id !== lastEventId)) return;
    } else {
      lastStateJson = fingerprint;
    }
  } catch (e) { /* ignore fingerprint errors */ }

  var gameType      = parseInt(st.gameType) || 301;
  var legsToWin     = st.legsToWin || 3;
  var activePlayers = st.players.filter(function(p){ return p.player_number <= 4; });
  // A match is "live" the moment the admin has published any state at all.
  // We detect this by: any player has a custom name/team, any score differs,
  // any leg won, any throw history, OR updated_at is present (admin published).
  // This ensures name/team changes show instantly without needing a throw first.
  var DEFAULT_NAMES = ['PLAYER 1','PLAYER 2','PLAYER 3','PLAYER 4'];
  var hasMatch = !!st.updated_at || activePlayers.some(function(p, i) {
    return (p.name && p.name !== DEFAULT_NAMES[i]) ||
           (p.team && p.team !== '' && p.team !== 'TEAM') ||
           (p.score !== gameType) ||
           (p.legs || 0) > 0 ||
           (p.hist || []).length > 0;
  });

  /* top-bar meta pills */
  setTxt('meta-game', st.gameType || '—');
  setTxt('meta-leg',  st.currentLeg || '—');
  setTxt('meta-ltw',  legsToWin);

  /* scoreboard header */
  setTxt('game-type-display',   st.gameType || '—');
  setTxt('leg-num',             st.currentLeg || '—');
  setTxt('legs-to-win-display', legsToWin);

  /* waiting / grid */
  var waiting = document.getElementById('waiting-screen');
  var grid    = document.getElementById('players-grid');
  waiting.style.display = hasMatch ? 'none' : '';
  grid.style.display    = hasMatch ? '' : 'none';

  if (!hasMatch) { lastState = st; return; }

  /* If the leg number changed, invalidate all hist keys so throw history re-renders clean */
  var newLeg = String(st.currentLeg || 1);
  if (grid.dataset.leg !== newLeg) {
    grid.dataset.leg = newLeg;
    grid.querySelectorAll('.pcard-history').forEach(function(el) { el.dataset.key = ''; });
    // mark the time so viewers can momentarily hide previous-leg throws
    try { newLegClearedAt = Date.now(); } catch (e) { newLegClearedAt = null; }
  }

  /* Clear "Now Throwing" indicator from every card before re-assigning it.
     Without this, a card that received active-turn in a previous render cycle
     can retain the indicator when the active player changes. */
  grid.querySelectorAll('.pcard.active-turn').forEach(function(c) {
    c.classList.remove('active-turn');
  });

  /* player cards — create missing slots, patch all existing */
  activePlayers.forEach(function(p, i) {
    var colIdx   = (p.player_number - 1) % 4;
    var isActive = i === st.currentPlayer;
    var isWinner = !!p.is_winner;

    if (!document.getElementById('pcard-' + i)) {
      grid.appendChild(buildCard(i, colIdx));
    }
    patchCard(i, p, isActive, isWinner, legsToWin, gameType);
  });

  /* remove cards for slots that no longer exist */
  Array.from(grid.querySelectorAll('.pcard')).forEach(function(card) {
    var idx = parseInt(card.id.replace('pcard-', ''));
    if (idx >= activePlayers.length) card.remove();
  });

  /* leg history strip — diff by key */
  var legsHistory = st.legs_history || [];
  var strip = document.getElementById('leg-history-strip');
  var items = document.getElementById('leg-history-items');
  var stripKey = legsHistory.join(',');
  if (legsHistory.length > 0) {
    strip.style.display = '';
    if (items.dataset.key !== stripKey) {
      items.dataset.key = stripKey;
      items.innerHTML   = '';
      legsHistory.forEach(function(pNum, idx) {
        var pl   = activePlayers.find(function(p){ return p.player_number === pNum; }) || activePlayers[pNum-1] || null;
        var pill = document.createElement('div');
        pill.className = 'leg-pill';
        pill.innerHTML = 'LEG ' + (idx+1) + ': <span class="lp-winner">' + (pl ? esc(pl.name) : 'P'+pNum) + '</span>';
        items.appendChild(pill);
      });
    }
  } else {
    strip.style.display = 'none';
  }

  /* last updated */
  var ts = st.updated_at ? new Date(st.updated_at).toLocaleTimeString() : '';
  setTxt('last-updated', ts ? 'Last updated: ' + ts : '');

  /* match/leg winner overlay — prefer event-driven _last_event, fallback to is_winner */
  var ev = st._last_event || null;
  if (ev && ev.id && ev.id !== lastEventId) {
    lastEventId = ev.id;
    lastEventLeg = ev.leg_number || null;
    var pl = activePlayers.find(function(p){ return p.player_number === ev.player_number; }) || activePlayers[(ev.player_number||1)-1] || null;
    var name = pl ? esc(pl.name) : 'Winner!';
    try { showWinnerOverlay(name); } catch (e) { document.getElementById('winner-name-display').textContent = name; document.getElementById('winner-overlay').classList.add('show'); }
  } else {
    var winner = activePlayers.find(function(p){ return p.is_winner; });
    if (winner && winner.name !== lastWinnerShown) {
      lastWinnerShown = winner.name;
      try { showWinnerOverlay(winner.name || 'Winner!'); } catch (e) { document.getElementById('winner-name-display').textContent = winner.name || 'Winner!'; document.getElementById('winner-overlay').classList.add('show'); }
    }
  }

  // Cross-device reload handling: server/admin sets `_reload_seq` to force a one-time reload
  try {
    var seq = parseInt(st._reload_seq || 0, 10);
    if (seq > 0) {
      var key = 'darts_reload_seq_v1_' + (st.match_id || 0);
      var last = parseInt(localStorage.getItem(key) || '0', 10);
      if (seq > last) {
        try { localStorage.setItem(key, String(seq)); } catch (e) {}
        try { location.reload(true); } catch (_) { location.reload(); }
        return;
      }
    }
  } catch (e) {}

  lastState = st;
  // ensure we remember the active match id and request WS join for it
  try {
    window.__matchId = st.match_id != null ? String(st.match_id) : null;
    if (window.__matchId) {
      try {
        if (ws && ws.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({ type: 'join', match_id: String(window.__matchId) }));
        } else {
          // store pending join — connectWS will pick it up on open
          window.__pendingJoin = String(window.__matchId);
          try { connectWS(); } catch(e) {}
        }
      } catch (e) {}
    }
  } catch(e) {}
}

// Ensure WS reconnects when page is restored from BFCache or becomes visible
window.addEventListener('pageshow', function(e) {
  try { connectWS(); if (lastState && lastState.match_id) { window.__pendingJoin = String(lastState.match_id); } } catch(e) {}
});
document.addEventListener('visibilitychange', function() {
  try { if (document.visibilityState === 'visible') connectWS(); } catch(e) {}
});

/* ============================================================
   CONNECTION LAYER — BroadcastChannel, WebSocket, HTTP polling
   ============================================================ */

var connDot   = document.getElementById('conn-dot');
var connLabel = document.getElementById('conn-label');

function setConnStatus(status, label) {
  connDot.className = '';
  connDot.id = 'conn-dot';
  connDot.classList.add(status);
  connLabel.textContent = label;
}

/* 1. BroadcastChannel — same browser, instant */
try {
  var bc = new BroadcastChannel('darts_live');
  bc.onmessage = function(e) {
    try {
      var data = e.data;
      if (!data) return;
      if (data.reload) { try { location.reload(true); } catch (_) { location.reload(); } return; }
      // support wrapper { match_id, state } or { match_id, payload } or bare state
      var mid = null;
      var payload = null;
      if (data.match_id !== undefined) mid = String(data.match_id);
      if (data.state) payload = data.state;
      else if (data.payload) payload = data.payload;
      else payload = data;

      // FIX: A new-match reset signal always uses match_id=0 (admin matchId is null).
      // The old filter `window.__matchId !== mid` was blocking these messages, leaving
      // viewers frozen on the completed match. Allow match_id=0 and explicit _new_match
      // flags to bypass the filter and treat them as a full viewer reset.
      var isNewMatchSignal = data._new_match || (payload && payload._new_match) || mid === '0';

      if (!isNewMatchSignal && window.__matchId && mid && String(window.__matchId) !== String(mid)) return;

      if (isNewMatchSignal) {
        // Reset tracking state so the viewer re-renders cleanly and polling
        // switches to match_id=0 (picks up the new match once DB is assigned).
        window.__matchId = null;
        lastState = null;
        lastStateJson = null; // clear dedup cache so the clean state renders fully
        lastEventId = null;
        lastWinnerShown = null;
        try { hideWinnerOverlay(); } catch(e) {}
      }

      if (payload) {
        renderState(payload);
        setConnStatus('live', 'Live (same-browser)');
      }
    } catch(_) {}
  };
} catch(e) {}

/* 2. WebSocket — cross-device */
var ws = null;
var wsRetryTimer = null;
var wsRetries = 0;
var WS_MAX_RETRIES = 10;
var WS_RETRY_DELAYS = [1000, 2000, 3000, 5000, 8000];

function connectWS() {
  if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
  var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
  var meta = document.querySelector('meta[name="ws-token"]');
  var wsToken = meta ? meta.getAttribute('content') : '';
  var url   = proto + '//' + location.hostname + ':3000' + (wsToken ? ('?token=' + encodeURIComponent(wsToken)) : '');
  try { ws = new WebSocket(url); } catch(e) { scheduleWsRetry(); return; }

  ws.addEventListener('open', function() {
    wsRetries = 0;
    setConnStatus('live', 'Live (WebSocket)');
    var mid = lastState && lastState.match_id ? String(lastState.match_id) : '0';
    try { ws.send(JSON.stringify({ type: 'join', match_id: mid })); } catch(e) {}
    if (mid !== '0') try { ws.send(JSON.stringify({ type: 'join', match_id: '0' })); } catch(e) {}
    // also honor any pending join requested by renderState
    if (window.__pendingJoin) {
      try { ws.send(JSON.stringify({ type: 'join', match_id: String(window.__pendingJoin) })); } catch(e) {}
      window.__pendingJoin = null;
    }
    stopPolling();
  });
  ws.addEventListener('message', function(e) {
    try {
      var msg = JSON.parse(e.data);
      if (msg.type === 'reload') {
        try { location.reload(true); } catch (_) { location.reload(); }
        return;
      }
      // New match broadcast — transition viewers to new match instantly
      if (msg.type === 'new_match' && msg.payload) {
        // FIX: Clear dedup cache and polling target so the viewer re-renders
        // from scratch and starts polling match_id=0 (the new pending match).
        // Without this, lastStateJson could suppress the render and pollOnce()
        // would keep hitting the old match_id in the DB.
        lastStateJson = null;
        lastState = null;
        window.__matchId = null;
        lastEventId = null;
        lastWinnerShown = null;
        try { hideWinnerOverlay(); } catch(e) {}
        try { renderState(msg.payload); setConnStatus('live', 'Live (new match)'); } catch (e) {}
        return;
      }
      if (msg.type === 'state' && msg.payload) { renderState(msg.payload); return; }
      if (msg.state) { renderState(msg.state); return; }
    } catch(_) {}
  });
  ws.addEventListener('close', function() {
    setConnStatus('poll', 'Reconnecting…');
    scheduleWsRetry();
    startPolling();
  });
  ws.addEventListener('error', function() {
    setConnStatus('poll', 'WS Error — polling');
    startPolling();
  });
}

function scheduleWsRetry() {
  if (wsRetryTimer) return;
  if (wsRetries >= WS_MAX_RETRIES) { setConnStatus('off', 'Offline — polling only'); startPolling(); return; }
  var delay = WS_RETRY_DELAYS[Math.min(wsRetries, WS_RETRY_DELAYS.length - 1)];
  wsRetries++;
  wsRetryTimer = setTimeout(function(){ wsRetryTimer = null; connectWS(); }, delay);
}

/* 3. HTTP Polling fallback */
var pollTimer = null;
var isPolling = false;

function startPolling() {
  if (isPolling) return;
  isPolling = true;
  setConnStatus('poll', 'Polling…');
  pollOnce();
}
// Listen for localStorage updates as a fallback path for cross-tab updates
window.addEventListener('storage', function(evt) {
  try {
    if (!evt.key) return;
    if (evt.key === 'darts_admin_state_v1') {
      try {
        var raw = evt.newValue;
        if (!raw) return;
        var parsed = JSON.parse(raw);
        if (parsed && parsed.state) {
          renderState(parsed.state);
          setConnStatus('live', 'Live (localStorage)');
        }
      } catch (e) {}
    }
  } catch (e) {}
});
function stopPolling() {
  isPolling = false;
  if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
}
function pollOnce() {
  if (!isPolling) return;
  var mid = lastState && lastState.match_id ? lastState.match_id : 0;
  var url = 'state.php?match_id=' + encodeURIComponent(mid) + '&t=' + Date.now();
  fetch(url, { cache: 'no-store' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      var st = data && data.state ? data.state : (data && data.payload ? data.payload : null);
      if (st) renderState(st);
      setConnStatus('poll', 'Polling (every 1s)');
    })
    .catch(function(){ setConnStatus('off', 'Cannot reach server'); })
    .finally(function(){ if (isPolling) pollTimer = setTimeout(pollOnce, 1000); });
}

/* Initial load — fetch server state immediately, then connect WS */
(function init() {
  setConnStatus('poll', 'Loading…');
  fetch('state.php?match_id=0&t=' + Date.now(), { cache: 'no-store' })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      var st = data && data.state ? data.state : (data && data.payload ? data.payload : null);
      if (st) {
        // record reload sequence (avoid reloading immediately on first load)
        try {
          var seq = parseInt(st._reload_seq || 0, 10);
          if (seq > 0) {
            var key = 'darts_reload_seq_v1_' + (st.match_id || 0);
            localStorage.setItem(key, String(seq));
          }
        } catch (e) {}
        renderState(st);
      }
    })
    .catch(function(){})
    .finally(function() {
      connectWS();
      startPolling();
    });
})();