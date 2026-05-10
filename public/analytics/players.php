<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SportSync — Player Profiles</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:         #0a0a0c;
  --surface:    #111116;
  --surface2:   #18181f;
  --border:     #22222e;
  --yellow:     #f5c400;
  --yellow-dim: #b89000;
  --blue:       #1e6aff;
  --blue-dim:   #1248bb;
  --green:      #22c55e;
  --orange:     #f97316;
  --purple:     #a855f7;
  --red:        #ef4444;
  --text:       #e8e8f0;
  --muted:      #6a6a80;
  --radius:     12px;
  --font-head:  'Bebas Neue', sans-serif;
  --font-body:  'DM Sans', sans-serif;
  --font-mono:  'JetBrains Mono', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  min-height: 100vh;
  overflow-x: hidden;
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(245,196,0,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(245,196,0,.025) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none; z-index: 0;
}

/* ── NAV ── */
nav {
  position: sticky; top: 0; z-index: 100;
  background: rgba(10,10,12,.93);
  backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 16px;
  padding: 0 28px; height: 60px;
}
.nav-logo { font-family: var(--font-head); font-size: 1.6rem; letter-spacing: 2px; color: var(--yellow); text-decoration: none; }
.nav-logo span { color: var(--text); }
.nav-links { display: flex; gap: 8px; flex: 1; }
.nav-link {
  font-size: .82rem; color: var(--muted); text-decoration: none;
  border: 1px solid transparent; padding: 6px 14px; border-radius: 6px;
  transition: all .2s; font-weight: 500;
}
.nav-link:hover { color: var(--text); border-color: var(--border); }
.nav-link.active { color: var(--yellow); border-color: var(--yellow-dim); }
.nav-back { font-size: .82rem; color: var(--muted); text-decoration: none; border: 1px solid var(--border); padding: 6px 14px; border-radius: 6px; transition: all .2s; }
.nav-back:hover { color: var(--yellow); border-color: var(--yellow); }

/* ── PAGE ── */
.page { position: relative; z-index: 1; max-width: 1400px; margin: 0 auto; padding: 32px 24px 100px; }

/* ── SECTION HEAD ── */
.section-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 24px; }
.section-head h2 { font-family: var(--font-head); font-size: 2rem; letter-spacing: 3px; }
.accent-line { flex: 1; height: 1px; background: linear-gradient(90deg, var(--yellow), transparent); }

/* ── SEARCH & FILTER BAR ── */
.filter-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 28px; }
.search-box { position: relative; flex: 1; min-width: 220px; }
.search-box input {
  width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
  padding: 10px 16px 10px 40px; color: var(--text); font-family: var(--font-body);
  font-size: .88rem; outline: none; transition: border-color .2s;
}
.search-box input:focus { border-color: var(--yellow); }
.search-box::before { content: '🔍'; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: .9rem; pointer-events: none; }
.filter-btn {
  padding: 10px 16px; background: var(--surface2); border: 1px solid var(--border);
  border-radius: 8px; color: var(--muted); font-size: .82rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: .5px; cursor: pointer; transition: all .2s;
}
.filter-btn:hover { border-color: var(--yellow-dim); color: var(--text); }
.filter-btn.active { background: var(--yellow); border-color: var(--yellow); color: #000; }

/* ── PLAYER GRID ── */
.players-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px; }
.player-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 20px; cursor: pointer; transition: border-color .2s, transform .2s, box-shadow .2s;
  position: relative; overflow: hidden;
}
.player-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: var(--yellow); transform: scaleX(0); transform-origin: left; transition: transform .3s;
}
.player-card:hover { border-color: var(--yellow-dim); transform: translateY(-3px); box-shadow: 0 8px 32px rgba(245,196,0,.08); }
.player-card:hover::after { transform: scaleX(1); }
.player-card:active { transform: translateY(-1px); }
.card-top-row { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.player-avatar {
  width: 52px; height: 52px; border-radius: 50%;
  background: linear-gradient(135deg, var(--yellow), var(--yellow-dim));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-size: 1.3rem; color: #000; flex-shrink: 0;
}
.player-avatar.team-b { background: linear-gradient(135deg, var(--blue), var(--blue-dim)); color: #fff; }
.player-avatar.no-team { background: linear-gradient(135deg, #444, #222); color: var(--muted); }
.player-info .player-name { font-weight: 700; font-size: 1rem; line-height: 1.2; }
.player-info .player-team { font-size: .75rem; color: var(--muted); margin-top: 3px; display: flex; align-items: center; gap: 5px; }
.team-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--yellow); display: inline-block; }
.team-dot.b { background: var(--blue); }

/* ── SPORT TAGS ── */
.sport-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.sport-tag {
  padding: 3px 9px; border-radius: 20px; font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .5px; border: 1px solid;
}
.sport-tag.basketball { color: #f5c400; border-color: rgba(245,196,0,.3); background: rgba(245,196,0,.08); }
.sport-tag.volleyball { color: #1e6aff; border-color: rgba(30,106,255,.3); background: rgba(30,106,255,.08); }
.sport-tag.badminton  { color: #22c55e; border-color: rgba(34,197,94,.3);  background: rgba(34,197,94,.08); }
.sport-tag.table_tennis { color: #f97316; border-color: rgba(249,115,22,.3); background: rgba(249,115,22,.08); }
.sport-tag.darts      { color: #a855f7; border-color: rgba(168,85,247,.3); background: rgba(168,85,247,.08); }

/* ── STAT CHIPS ── */
.stat-mini { display: flex; gap: 8px; flex-wrap: wrap; }
.stat-chip { background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; padding: 5px 10px; font-size: .72rem; }
.stat-chip .sc-val { font-family: var(--font-mono); font-weight: 600; color: var(--yellow); }
.stat-chip .sc-lbl { color: var(--muted); margin-left: 3px; }
.sports-count { position: absolute; top: 16px; right: 16px; font-family: var(--font-mono); font-size: .7rem; color: var(--muted); background: var(--surface2); border: 1px solid var(--border); padding: 2px 7px; border-radius: 10px; }

/* ── MODAL OVERLAY ── */
.modal-overlay {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(0,0,0,.75); backdrop-filter: blur(6px);
  display: flex; align-items: flex-start; justify-content: center;
  padding: 20px; overflow-y: auto;
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
  width: 100%; max-width: 920px; margin: auto;
  transform: translateY(20px); transition: transform .25s; overflow: hidden;
}
.modal-overlay.open .modal { transform: translateY(0); }

/* ── MODAL HEADER ── */
.modal-header {
  background: var(--surface2); border-bottom: 1px solid var(--border);
  padding: 24px 28px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
}
.modal-player-info { flex: 1; }
.modal-name { font-family: var(--font-head); font-size: 2.2rem; letter-spacing: 3px; line-height: 1; color: var(--text); }
.modal-team { color: var(--muted); font-size: .88rem; margin-top: 6px; }
.modal-close {
  background: none; border: 1px solid var(--border); border-radius: 8px;
  color: var(--muted); width: 36px; height: 36px; cursor: pointer; font-size: 1.1rem;
  display: flex; align-items: center; justify-content: center; transition: all .2s; flex-shrink: 0;
}
.modal-close:hover { border-color: var(--red); color: var(--red); }

/* ── MODAL NAV TABS (top level) ── */
.modal-nav {
  display: flex; border-bottom: 1px solid var(--border);
  background: var(--surface2);
}
.modal-nav-btn {
  flex: 1; padding: 14px 8px; background: none; border: none; border-bottom: 2px solid transparent;
  color: var(--muted); font-family: var(--font-body); font-size: .82rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: .8px; cursor: pointer; transition: all .2s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.modal-nav-btn:hover { color: var(--text); }
.modal-nav-btn.active { color: var(--yellow); border-bottom-color: var(--yellow); background: rgba(245,196,0,.04); }

.modal-body { padding: 24px 28px; }

/* ── MODAL PAGE VIEWS ── */
.modal-page { display: none; }
.modal-page.visible { display: block; }

/* ── SPORTS PLAYED GRID ── */
.sports-played-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-bottom: 28px; }
.sport-played-card {
  background: var(--surface2); border: 1px solid var(--border); border-radius: 10px;
  padding: 14px; text-align: center; cursor: pointer; transition: border-color .2s, transform .15s;
}
.sport-played-card:hover { transform: translateY(-2px); }
.sport-played-card.basketball:hover { border-color: #f5c400; }
.sport-played-card.volleyball:hover  { border-color: #1e6aff; }
.sport-played-card.badminton:hover   { border-color: #22c55e; }
.sport-played-card.table_tennis:hover{ border-color: #f97316; }
.sport-played-card.darts:hover       { border-color: #a855f7; }
.sport-played-card .spc-icon { font-size: 1.6rem; margin-bottom: 6px; }
.sport-played-card .spc-name { font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 8px; }
.sport-played-card .spc-val  { font-family: var(--font-head); font-size: 1.8rem; color: var(--yellow); line-height: 1; }
.sport-played-card .spc-lbl  { font-size: .65rem; color: var(--muted); margin-top: 2px; }

/* ── PROFILE CHART SECTION ── */
.profile-section-title {
  font-family: var(--font-head); font-size: 1rem; letter-spacing: 2px;
  color: var(--muted); text-transform: uppercase; margin-bottom: 14px; margin-top: 24px;
}
.chart-wrap { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 20px; overflow-x: auto; }

/* ── MULTI BAR ── */
.mbar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.mbar-label { width: 70px; font-size: .74rem; color: var(--muted); text-align: right; flex-shrink: 0; }
.mbar-track { flex: 1; height: 22px; background: var(--bg); border-radius: 4px; overflow: hidden; }
.mbar-fill { height: 100%; border-radius: 4px; display: flex; align-items: center; padding-left: 8px; font-size: .7rem; font-weight: 600; color: #000; transition: width .8s cubic-bezier(.34,1.56,.64,1); min-width: 24px; }
.mbar-val { width: 36px; font-family: var(--font-mono); font-size: .78rem; color: var(--text); text-align: right; }

/* ── PER-GAME BARS ── */
.bar-chart { display: flex; align-items: flex-end; gap: 8px; min-height: 120px; padding-top: 8px; }
.bar-group { display: flex; flex-direction: column; align-items: center; gap: 5px; min-width: 36px; flex: 1; }
.bar-col { width: 100%; position: relative; }
.bar-fill { width: 100%; background: linear-gradient(180deg, var(--yellow), var(--yellow-dim)); border-radius: 4px 4px 0 0; transition: height .6s cubic-bezier(.34,1.56,.64,1); min-height: 3px; position: relative; }
.bar-fill.blue-bar   { background: linear-gradient(180deg, var(--blue), var(--blue-dim)); }
.bar-fill.green-bar  { background: linear-gradient(180deg, var(--green), #166534); }
.bar-fill.orange-bar { background: linear-gradient(180deg, var(--orange), #9a3412); }
.bar-fill.purple-bar { background: linear-gradient(180deg, var(--purple), #6b21a8); }
.bar-fill:hover::after {
  content: attr(data-val); position: absolute; top: -24px; left: 50%; transform: translateX(-50%);
  background: var(--yellow); color: #000; font-size: .68rem; font-weight: 700;
  padding: 2px 6px; border-radius: 4px; white-space: nowrap;
}
.bar-label { font-size: .62rem; color: var(--muted); text-align: center; }

/* ── GAME HISTORY TABLE ── */
.game-table { width: 100%; border-collapse: collapse; font-size: .81rem; }
.game-table th { background: var(--bg); padding: 9px 12px; font-size: .68rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); border-bottom: 1px solid var(--border); text-align: left; }
.game-table td { padding: 9px 12px; border-bottom: 1px solid var(--border); }
.game-table tr:hover td { background: var(--surface2); }
.win  { color: var(--green); font-weight: 600; font-size: .75rem; }
.loss { color: var(--red);   font-weight: 600; font-size: .75rem; }
.val-y { color: var(--yellow); font-family: var(--font-mono); font-weight: 600; }

/* ── SPORT TABS IN MODAL ── */
.modal-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.modal-tab {
  padding: 7px 14px; background: var(--surface2); border: 1px solid var(--border); border-radius: 6px;
  font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
  color: var(--muted); cursor: pointer; transition: all .2s;
}
.modal-tab:hover { color: var(--text); border-color: var(--yellow-dim); }
.modal-tab.active { background: var(--yellow); border-color: var(--yellow); color: #000; }
.modal-tab-content { display: none; }
.modal-tab-content.visible { display: block; }

/* ── TEAM HISTORY SECTION ── */
.team-timeline { display: flex; flex-direction: column; gap: 12px; }
.team-entry {
  display: flex; align-items: flex-start; gap: 16px;
  background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; padding: 16px;
  position: relative; transition: border-color .2s;
}
.team-entry:hover { border-color: var(--yellow-dim); }
.team-entry-icon {
  width: 40px; height: 40px; border-radius: 8px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; background: var(--bg); border: 1px solid var(--border);
}
.team-entry-info { flex: 1; }
.team-entry-name { font-weight: 700; font-size: .95rem; margin-bottom: 4px; }
.team-entry-sport { font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.team-entry-dates { font-size: .75rem; color: var(--muted); margin-bottom: 8px; }
.team-entry-stats { display: flex; gap: 8px; flex-wrap: wrap; }
.team-stat-chip { background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 4px 10px; font-size: .72rem; }
.team-stat-chip .tsv { font-family: var(--font-mono); font-weight: 600; color: var(--yellow); }
.team-stat-chip .tsl { color: var(--muted); margin-left: 3px; }
.current-badge {
  position: absolute; top: 12px; right: 12px;
  background: var(--green); color: #000; font-size: .62rem; font-weight: 700;
  padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: .5px;
}

/* ── STATES ── */
.loading-state, .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 60px 20px; color: var(--muted); }
.loading-spinner { width: 32px; height: 32px; border: 3px solid var(--border); border-top-color: var(--yellow); border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-icon { font-size: 2.4rem; opacity: .4; }
.empty-text  { font-size: .88rem; }

/* ── RESULTS COUNT ── */
.results-count { color: var(--muted); font-size: .82rem; margin-bottom: 16px; }
.results-count b { color: var(--yellow); }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.fade-up { animation: fadeUp .35s ease both; }

/* ── RESPONSIVE ── */
@media(max-width:600px) {
  .modal { border-radius: 12px; }
  .modal-header, .modal-body { padding: 16px 18px; }
  .modal-name { font-size: 1.6rem; }
  .modal-nav-btn { font-size: .72rem; padding: 12px 4px; }
}
</style>
</head>
<body>

<nav>
  <a class="nav-logo" href="/">SPORT<span>SYNC</span></a>
  <div class="nav-links">
    <a class="nav-link active" href="players.php">👤 Players</a>
    <a class="nav-link" href="analytics.php">📊 Analytics</a>
  </div>
  <a class="nav-back" href="/">← Back to Dashboard</a>
</nav>

<div class="page">

  <div style="margin-bottom:36px;padding-top:8px">
    <div style="font-family:var(--font-head);font-size:clamp(2.2rem,5vw,3.6rem);letter-spacing:4px;line-height:1.05">
      PLAYER <span style="color:var(--yellow)">PROFILES</span>
    </div>
    <div style="color:var(--muted);margin-top:8px;font-size:.9rem">
      Universal identities across Basketball · Volleyball · Badminton · Table Tennis · Darts
    </div>
  </div>

  <div class="filter-bar">
    <div class="search-box">
      <input type="text" id="searchInput" placeholder="Search player name or team…" oninput="filterCards(this.value)">
    </div>
    <button class="filter-btn active" data-sport="all" onclick="setSportFilter('all',this)">All Sports</button>
    <button class="filter-btn" data-sport="basketball" onclick="setSportFilter('basketball',this)">🏀</button>
    <button class="filter-btn" data-sport="volleyball" onclick="setSportFilter('volleyball',this)">🏐</button>
    <button class="filter-btn" data-sport="badminton"  onclick="setSportFilter('badminton',this)">🏸</button>
    <button class="filter-btn" data-sport="table_tennis" onclick="setSportFilter('table_tennis',this)">🏓</button>
    <button class="filter-btn" data-sport="darts"      onclick="setSportFilter('darts',this)">🎯</button>
  </div>

  <div class="results-count" id="resultsCount"></div>

  <div class="players-grid" id="playersGrid">
    <div class="loading-state" style="grid-column:1/-1">
      <div class="loading-spinner"></div>
      <div class="empty-text">Loading player registry…</div>
    </div>
  </div>

</div>

<!-- ── PLAYER PROFILE MODAL ── -->
<div class="modal-overlay" id="modalOverlay" onclick="closeModalOnBg(event)">
  <div class="modal" id="profileModal">

    <!-- Header -->
    <div class="modal-header" id="modalHeader">
      <div class="modal-player-info">
        <div class="modal-name" id="modalName">—</div>
        <div class="modal-team" id="modalTeam">—</div>
        <div class="sport-tags" id="modalSportTags" style="margin-top:10px"></div>
      </div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>

    <!-- Top-level nav: Analytics / Team Info -->
    <div class="modal-nav">
      <button class="modal-nav-btn active" id="navAnalytics" onclick="switchModalPage('analytics')">
        📊 Analytics
      </button>
      <button class="modal-nav-btn" id="navTeams" onclick="switchModalPage('teams')">
        🏷️ Team History
      </button>
    </div>

    <!-- Analytics page -->
    <div class="modal-body modal-page visible" id="pageAnalytics">
      <div class="loading-state"><div class="loading-spinner"></div></div>
    </div>

    <!-- Team History page -->
    <div class="modal-body modal-page" id="pageTeams">
      <div class="loading-state"><div class="loading-spinner"></div></div>
    </div>

  </div>
</div>

<script>
const API = 'analytics_api.php';

let allPlayers   = [];
let sportFilter  = 'all';
let searchQuery  = '';
let currentPid   = null;
let profileCache = {};

const SPORT_META = {
  basketball:   { icon:'🏀', color:'#f5c400', label:'Basketball',   barClass:'',          primaryStat:'bball_pts',      statLabel:'PTS' },
  volleyball:   { icon:'🏐', color:'#1e6aff', label:'Volleyball',   barClass:'blue-bar',  primaryStat:'vball_pts',      statLabel:'PTS' },
  badminton:    { icon:'🏸', color:'#22c55e', label:'Badminton',    barClass:'green-bar', primaryStat:'badminton_wins', statLabel:'Wins' },
  table_tennis: { icon:'🏓', color:'#f97316', label:'Table Tennis', barClass:'orange-bar',primaryStat:'tt_wins',        statLabel:'Wins' },
  darts:        { icon:'🎯', color:'#a855f7', label:'Darts',        barClass:'purple-bar',primaryStat:'darts_wins',     statLabel:'Wins' },
};

// ============================================================
//  LOAD ALL PLAYERS
// ============================================================
async function loadPlayers() {
  const data = await apiFetch({ action: 'all_players' });
  if (!data?.success) {
    document.getElementById('playersGrid').innerHTML = `
      <div class="empty-state" style="grid-column:1/-1">
        <div class="empty-icon">⚠️</div>
        <div class="empty-text">Could not load players. Check API connection.</div>
      </div>`;
    return;
  }
  allPlayers = data.data || [];
  renderGrid();
}

// ============================================================
//  RENDER GRID
// ============================================================
function renderGrid() {
  const q = searchQuery.toLowerCase();
  let filtered = allPlayers.filter(p => {
    const nameMatch = p.full_name.toLowerCase().includes(q) || p.team_name.toLowerCase().includes(q);
    if (!nameMatch) return false;
    if (sportFilter === 'all') return true;
    return playerHasSport(p, sportFilter);
  });

  const grid = document.getElementById('playersGrid');
  document.getElementById('resultsCount').innerHTML = `Showing <b>${filtered.length}</b> of <b>${allPlayers.length}</b> players`;

  if (!filtered.length) {
    grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1">
      <div class="empty-icon">👤</div><div class="empty-text">No players match your search</div></div>`;
    return;
  }
  grid.innerHTML = filtered.map((p, i) => buildCard(p, i)).join('');
}

function playerHasSport(p, sport) {
  if (sport === 'basketball')   return +p.bball_games > 0;
  if (sport === 'volleyball')   return +p.vball_games > 0;
  // FIX: use badminton_games (all matches) not just wins
  if (sport === 'badminton')    return +(p.badminton_games || 0) > 0;
  if (sport === 'table_tennis') return +(p.tt_games || 0) > 0;
  if (sport === 'darts')        return +p.darts_games > 0;
  return true;
}

function buildCard(p, i) {
  const initials = p.full_name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
  const avatarClass = p.team_name ? '' : 'no-team';
  const teamDotClass = '';
  const activeSports = Object.keys(SPORT_META).filter(s => playerHasSport(p, s));
  const sportTags = activeSports.map(s =>
    `<span class="sport-tag ${s}">${SPORT_META[s].icon} ${SPORT_META[s].label}</span>`).join('');

  const chips = [];
  if (+p.bball_games > 0)     chips.push({ val: p.bball_pts,      lbl: 'BB PTS' });
  if (+p.vball_games > 0)     chips.push({ val: p.vball_pts,      lbl: 'VB PTS' });
  // FIX: show games played for badminton/TT (not just wins), fall back to wins if games not available
  const bdGames = +(p.badminton_games || 0);
  const ttGames = +(p.tt_games || 0);
  if (bdGames > 0)  chips.push({ val: p.badminton_wins || 0, lbl: 'BD W' });
  if (ttGames > 0)  chips.push({ val: p.tt_wins || 0,        lbl: 'TT W' });
  if (+p.darts_games > 0)     chips.push({ val: p.darts_wins,     lbl: 'DT W' });
  const chipHtml = chips.slice(0,4).map(c =>
    `<div class="stat-chip"><span class="sc-val">${c.val}</span><span class="sc-lbl">${c.lbl}</span></div>`).join('');

  const teamLabel = p.team_name
    ? `<span class="team-dot"></span> ${escHtml(p.team_name)}`
    : '<span style="color:var(--muted)">No team registered</span>';

  return `<div class="player-card fade-up" style="animation-delay:${Math.min(i*30,300)}ms" onclick="openProfile(${p.id})">
    <div class="sports-count">${activeSports.length} sport${activeSports.length!==1?'s':''}</div>
    <div class="card-top-row">
      <div class="player-avatar ${avatarClass}">${initials}</div>
      <div class="player-info">
        <div class="player-name">${escHtml(p.full_name)}</div>
        <div class="player-team">${teamLabel}</div>
      </div>
    </div>
    <div class="sport-tags" style="margin-bottom:12px">${sportTags || '<span style="color:var(--muted);font-size:.75rem">No matches recorded</span>'}</div>
    <div class="stat-mini">${chipHtml}</div>
  </div>`;
}

// ============================================================
//  FILTERS
// ============================================================
function setSportFilter(sport, btn) {
  sportFilter = sport;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderGrid();
}
function filterCards(q) { searchQuery = q; renderGrid(); }

// ============================================================
//  MODAL OPEN / CLOSE
// ============================================================
async function openProfile(pid) {
  currentPid = pid;
  const overlay = document.getElementById('modalOverlay');
  overlay.classList.add('open');

  // Reset nav to analytics tab
  switchModalPage('analytics', false);

  // Quick header from local data
  document.getElementById('modalName').textContent = '…';
  document.getElementById('modalTeam').textContent = '';
  document.getElementById('modalSportTags').innerHTML = '';
  document.getElementById('pageAnalytics').innerHTML = '<div class="loading-state"><div class="loading-spinner"></div></div>';
  document.getElementById('pageTeams').innerHTML     = '<div class="loading-state"><div class="loading-spinner"></div></div>';

  const local = allPlayers.find(p => p.id == pid);
  if (local) {
    document.getElementById('modalName').textContent = local.full_name;
    document.getElementById('modalTeam').textContent = (local.current_team || local.team_name) ? `Team ${escHtml(local.current_team || local.team_name)}` : 'No team registered';
    const acts = Object.keys(SPORT_META).filter(s => playerHasSport(local, s));
    document.getElementById('modalSportTags').innerHTML =
      acts.map(s => `<span class="sport-tag ${s}">${SPORT_META[s].icon} ${SPORT_META[s].label}</span>`).join('');
  }

  // Use cache if available
  if (profileCache[pid]) {
    renderProfile(profileCache[pid]);
    return;
  }

  const data = await apiFetch({ action: 'player_profile', player_id: pid });
  if (!data?.success) {
    document.getElementById('pageAnalytics').innerHTML =
      `<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">${data?.error || 'Failed to load profile'}</div></div>`;
    return;
  }
  profileCache[pid] = data.data;
  renderProfile(data.data);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  currentPid = null;
}
function closeModalOnBg(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ============================================================
//  MODAL PAGE SWITCHER
// ============================================================
function switchModalPage(page, scroll = true) {
  document.querySelectorAll('.modal-page').forEach(el => el.classList.remove('visible'));
  document.querySelectorAll('.modal-nav-btn').forEach(b => b.classList.remove('active'));

  if (page === 'analytics') {
    document.getElementById('pageAnalytics').classList.add('visible');
    document.getElementById('navAnalytics').classList.add('active');
  } else {
    document.getElementById('pageTeams').classList.add('visible');
    document.getElementById('navTeams').classList.add('active');
  }
  if (scroll) document.getElementById('profileModal').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ============================================================
//  RENDER FULL PROFILE
// ============================================================
function renderProfile(data) {
  const { player, sports, team_history } = data;
  const sportKeys = Object.keys(sports);

  // ── Analytics page ──
  if (!sportKeys.length) {
    document.getElementById('pageAnalytics').innerHTML =
      `<div class="empty-state"><div class="empty-icon">📋</div><div class="empty-text">No match history found</div></div>`;
  } else {
    const sportsCardsHtml = sportKeys.map(s => {
      const sd = sports[s];
      const m  = SPORT_META[s];
      const mainVal = sd.total_pts ?? sd.wins ?? sd.games ?? 0;
      const mainLbl = (s === 'basketball' || s === 'volleyball') ? 'Total PTS' : 'Wins';
      const gamesLbl = sd.games ? `${sd.games} games` : '';
      return `<div class="sport-played-card ${s}" onclick="switchSportTab('${s}')">
        <div class="spc-icon">${m.icon}</div>
        <div class="spc-name">${m.label}</div>
        <div class="spc-val">${mainVal}</div>
        <div class="spc-lbl">${mainLbl}</div>
        ${gamesLbl ? `<div class="spc-lbl" style="margin-top:4px">${gamesLbl}</div>` : ''}
      </div>`;
    }).join('');

    const radarRows = buildGlobalRadar(sports);

    const tabBtns = sportKeys.map((s, i) =>
      `<button class="modal-tab ${i===0?'active':''}" data-sport="${s}" onclick="switchSportTab('${s}')">${SPORT_META[s].icon} ${SPORT_META[s].label}</button>`
    ).join('');

    const tabContents = sportKeys.map((s, i) =>
      `<div class="modal-tab-content ${i===0?'visible':''}" id="tab_${s}">${buildSportDetail(s, sports[s])}</div>`
    ).join('');

    document.getElementById('pageAnalytics').innerHTML = `
      <div class="section-head" style="margin-bottom:16px"><h2 style="font-size:1.2rem">SPORTS PLAYED</h2><div class="accent-line"></div></div>
      <div class="sports-played-grid">${sportsCardsHtml}</div>
      <div class="section-head" style="margin-bottom:16px"><h2 style="font-size:1.2rem">OVERALL PERFORMANCE</h2><div class="accent-line"></div></div>
      <div class="chart-wrap" style="margin-bottom:28px"><div class="multi-bar-chart">${radarRows}</div></div>
      <div class="section-head" style="margin-bottom:16px"><h2 style="font-size:1.2rem">BY SPORT</h2><div class="accent-line"></div></div>
      <div class="modal-tabs">${tabBtns}</div>
      <div id="sportTabContents">${tabContents}</div>
    `;
  }

  // ── Update modal header with the authoritative current_team from API ──
  // This overwrites the quick-load value (which used allPlayers[].team_name) with the
  // fully resolved team, including any admin override from player_profiles.
  const resolvedTeam = player?.current_team || player?.team_name;
  document.getElementById('modalTeam').textContent = resolvedTeam ? `Team ${escHtml(resolvedTeam)}` : 'No team registered';

  // ── Team History page ──
  renderTeamHistory(player, team_history || []);
}

// ============================================================
//  TEAM HISTORY PAGE
// ============================================================
function renderTeamHistory(player, history) {
  const container = document.getElementById('pageTeams');

  if (!history.length) {
    container.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">🏷️</div>
        <div class="empty-text">No team history found for this player</div>
      </div>`;
    return;
  }

  const fmtDate = d => d ? new Date(d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';

  // Sort by last_game descending for fallback detection
  const sorted = [...history].sort((a,b) => new Date(b.last_game) - new Date(a.last_game));

  // ── FIXED: Current team is the player's ASSIGNED team from universal_players / player_profiles.
  // player.current_team is the authoritative field set by analytics_api.php.
  // We never derive it from history rows, because a player who played basketball, volleyball,
  // and badminton under "DICT" would produce multiple is_current=1 rows — one per sport —
  // and picking the first one would show a sport-specific entry (e.g. "🏀 Basketball · Side A")
  // instead of the team name itself.
  const assignedTeam = player?.current_team || player?.team_name || null;

  // Find the most-recent history entry that matches the assigned team, for the "last played" date.
  // Fall back to the most recent history row of any team if none match.
  const matchingEntry = assignedTeam
    ? (sorted.find(h => h.team_name === assignedTeam) || sorted[0])
    : sorted[0];

  // Group by sport
  const bySport = {};
  history.forEach(h => {
    if (!bySport[h.sport]) bySport[h.sport] = [];
    bySport[h.sport].push(h);
  });

  let html = '';

  // ── Current team highlight — shows the assigned team, not a history entry ──
  if (assignedTeam) {
    const lastPlayed = matchingEntry?.last_game ?? null;
    html += `
      <div style="background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.2);border-radius:10px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:14px">
        <div style="font-size:2rem">🏷️</div>
        <div>
          <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:var(--green);font-weight:600;margin-bottom:4px">Current Team</div>
          <div style="font-family:var(--font-head);font-size:1.5rem;letter-spacing:2px">${escHtml(assignedTeam)}</div>
          ${lastPlayed ? `<div style="color:var(--muted);font-size:.78rem;margin-top:2px">Last played: ${fmtDate(lastPlayed)}</div>` : ''}
        </div>
      </div>`;
  }

  // ── Per-sport timeline ──
  Object.entries(bySport).forEach(([sport, entries]) => {
    const m = SPORT_META[sport];
    html += `
      <div class="section-head" style="margin-bottom:14px;margin-top:20px">
        <h2 style="font-size:1.1rem">${m?.icon} ${m?.label || sport}</h2>
        <div class="accent-line"></div>
      </div>
      <div class="team-timeline">`;

    entries.sort((a,b) => new Date(a.first_game) - new Date(b.first_game)).forEach(h => {
      const isCurrent = +h.is_current === 1;
      const sideLabel = h.side ? `Side ${h.side} · ` : '';
      const statChips = [];
      if (+h.games_played > 0) statChips.push({ val: h.games_played, lbl: 'Games' });

      html += `
        <div class="team-entry">
          ${isCurrent ? '<div class="current-badge">Current</div>' : ''}
          <div class="team-entry-icon">${m?.icon || '🏆'}</div>
          <div class="team-entry-info">
            <div class="team-entry-name">${escHtml(h.team_name || '—')}</div>
            <div class="team-entry-sport">
              <span class="sport-tag ${sport}" style="font-size:.62rem">${m?.label || sport}</span>
              ${h.side ? `<span style="font-size:.68rem;color:var(--muted);margin-left:4px">Side ${h.side}</span>` : ''}
            </div>
            <div class="team-entry-dates">
              ${fmtDate(h.first_game)} → ${fmtDate(h.last_game)}
            </div>
            <div class="team-entry-stats">
              ${statChips.map(c => `<div class="team-stat-chip"><span class="tsv">${c.val}</span><span class="tsl"> ${c.lbl}</span></div>`).join('')}
            </div>
          </div>
        </div>`;
    });

    html += `</div>`;
  });

  // ── Full chronological table ──
  html += `
    <div class="section-head" style="margin-bottom:14px;margin-top:28px">
      <h2 style="font-size:1.1rem">FULL TIMELINE</h2>
      <div class="accent-line"></div>
    </div>
    <div class="chart-wrap" style="overflow-x:auto">
      <table class="game-table">
        <thead><tr><th>Sport</th><th>Team</th><th>Side</th><th>Games</th><th>First</th><th>Last</th><th>Status</th></tr></thead>
        <tbody>`;

  sorted.forEach(h => {
    const m = SPORT_META[h.sport];
    const isCurrent = +h.is_current === 1;
    html += `<tr>
      <td>${m?.icon} ${m?.label || h.sport}</td>
      <td><strong>${escHtml(h.team_name || '—')}</strong></td>
      <td style="color:var(--muted)">${h.side || '—'}</td>
      <td class="val-y">${h.games_played}</td>
      <td>${fmtDate(h.first_game)}</td>
      <td>${fmtDate(h.last_game)}</td>
      <td>${isCurrent ? '<span class="win">Current</span>' : '<span style="color:var(--muted);font-size:.75rem">Previous</span>'}</td>
    </tr>`;
  });

  html += `</tbody></table></div>`;
  container.innerHTML = html;
}

// ============================================================
//  BUILD SPORT DETAIL TABS
// ============================================================
function switchSportTab(sport) {
  document.querySelectorAll('.modal-tab').forEach(b => b.classList.toggle('active', b.dataset.sport === sport));
  document.querySelectorAll('.modal-tab-content').forEach(el => el.classList.toggle('visible', el.id === `tab_${sport}`));
}

function buildGlobalRadar(sports) {
  const stats = [];
  const bball = sports.basketball;
  const vball  = sports.volleyball;
  const bd     = sports.badminton;
  const tt     = sports.table_tennis;
  const darts  = sports.darts;
  if (bball) {
    stats.push({ label:'BB Points',   val: bball.total_pts,  color:'#f5c400' });
    stats.push({ label:'BB Rebounds', val: bball.total_reb,  color:'#f5c400' });
    stats.push({ label:'BB Assists',  val: bball.total_ast,  color:'#f5c400' });
  }
  if (vball) {
    stats.push({ label:'VB Points', val: vball.total_pts,   color:'#1e6aff' });
    stats.push({ label:'VB Spikes', val: vball.total_spike, color:'#1e6aff' });
    stats.push({ label:'VB Aces',   val: vball.total_ace,   color:'#1e6aff' });
  }
  if (bd)    stats.push({ label:'BD Wins',    val: bd.wins,     color:'#22c55e' });
  if (tt)    stats.push({ label:'TT Wins',    val: tt.wins,     color:'#f97316' });
  if (darts) stats.push({ label:'Darts Wins', val: darts.wins,  color:'#a855f7' });
  if (!stats.length) return '<div class="empty-text" style="color:var(--muted);padding:12px">No stats</div>';
  const globalMax = Math.max(...stats.map(s => s.val), 1);
  return stats.map(s => {
    const pct = ((s.val / globalMax) * 100).toFixed(1);
    return `<div class="mbar-row">
      <div class="mbar-label">${s.label}</div>
      <div class="mbar-track"><div class="mbar-fill" style="width:${pct}%;background:${s.color}">${s.val}</div></div>
      <div class="mbar-val">${s.val}</div>
    </div>`;
  }).join('');
}

function buildSportDetail(sport, sd) {
  // FIX: basketball — API returns saved_at aliased as created_at; this now works correctly.
  if (sport === 'basketball') {
    const mbarHtml = [
      { label:'Points',   val: sd.total_pts, color:'#f5c400' },
      { label:'Rebounds', val: sd.total_reb, color:'#1e6aff' },
      { label:'Assists',  val: sd.total_ast, color:'#22c55e' },
      { label:'Blocks',   val: sd.total_blk, color:'#f97316' },
      { label:'Steals',   val: sd.total_stl, color:'#a855f7' },
    ].map(r => {
      const pct = ((r.val / Math.max(sd.total_pts||1, r.val, 1)) * 100).toFixed(1);
      return `<div class="mbar-row"><div class="mbar-label">${r.label}</div>
        <div class="mbar-track"><div class="mbar-fill" style="width:${pct}%;background:${r.color}">${r.val}</div></div>
        <div class="mbar-val">${r.val}</div></div>`;
    }).join('');

    const maxPts = Math.max(...sd.history.map(g => +(g.pts||0)), 1);
    const gameBars = sd.history.map((g, i) => {
      const val = +(g.pts||0);
      const h = Math.max((val/maxPts*110), 3).toFixed(0);
      // created_at is now saved_at from the API (fixed alias)
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : `G${i+1}`;
      return `<div class="bar-group">
        <div class="bar-col" style="height:${h}px"><div class="bar-fill" style="height:100%" data-val="${val}"></div></div>
        <div class="bar-label">${date}</div></div>`;
    }).join('');

    const tableRows = sd.history.map(g => {
      const opp = g.team === 'A' ? g.team_b_name : g.team_a_name;
      const res = g.match_result || '';
      const won = (res.includes('A') && g.team === 'A') || (res.includes('B') && g.team === 'B');
      // FIX: use created_at (which the API now sets from saved_at)
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      return `<tr><td>${date}</td><td>${escHtml(opp||'—')}</td>
        <td><span class="${won?'win':'loss'}">${won?'WIN':'LOSS'}</span></td>
        <td class="val-y">${g.pts||0}</td><td>${g.reb||0}</td><td>${g.ast||0}</td><td>${g.blk||0}</td><td>${g.stl||0}</td></tr>`;
    }).join('');

    return `
      <div class="profile-section-title">Career Totals</div>
      <div class="chart-wrap"><div class="multi-bar-chart">${mbarHtml}</div></div>
      <div class="profile-section-title">Points Per Game</div>
      <div class="chart-wrap"><div class="bar-chart">${gameBars}</div></div>
      <div class="profile-section-title">Game History</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="game-table">
          <thead><tr><th>Date</th><th>Opponent</th><th>Result</th><th>PTS</th><th>REB</th><th>AST</th><th>BLK</th><th>STL</th></tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>`;
  }

  if (sport === 'volleyball') {
    const mbarHtml = [
      { label:'Points', val: sd.total_pts,   color:'#1e6aff' },
      { label:'Spikes', val: sd.total_spike, color:'#f5c400' },
      { label:'Aces',   val: sd.total_ace,   color:'#22c55e' },
      { label:'Sets',   val: sd.total_set,   color:'#f97316' },
      { label:'Digs',   val: sd.total_dig,   color:'#a855f7' },
    ].map(r => {
      const pct = ((r.val / Math.max(sd.total_pts||1, r.val, 1)) * 100).toFixed(1);
      return `<div class="mbar-row"><div class="mbar-label">${r.label}</div>
        <div class="mbar-track"><div class="mbar-fill" style="width:${pct}%;background:${r.color}">${r.val}</div></div>
        <div class="mbar-val">${r.val}</div></div>`;
    }).join('');

    const maxPts = Math.max(...sd.history.map(g => +(g.pts||0)), 1);
    const gameBars = sd.history.map((g, i) => {
      const val = +(g.pts||0);
      const h = Math.max((val/maxPts*110), 3).toFixed(0);
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : `G${i+1}`;
      return `<div class="bar-group">
        <div class="bar-col" style="height:${h}px"><div class="bar-fill blue-bar" style="height:100%" data-val="${val}"></div></div>
        <div class="bar-label">${date}</div></div>`;
    }).join('');

    const tableRows = sd.history.map(g => {
      const opp = g.team === 'A' ? g.team_b_name : g.team_a_name;
      const res = g.match_result || '';
      const won = (res.includes('A') && g.team === 'A') || (res.includes('B') && g.team === 'B');
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      return `<tr><td>${date}</td><td>${escHtml(opp||'—')}</td>
        <td><span class="${won?'win':'loss'}">${won?'WIN':'LOSS'}</span></td>
        <td class="val-y">${g.pts||0}</td><td>${g.spike||0}</td><td>${g.ace||0}</td><td>${g.ex_set||0}</td><td>${g.ex_dig||0}</td></tr>`;
    }).join('');

    return `
      <div class="profile-section-title">Career Totals</div>
      <div class="chart-wrap"><div class="multi-bar-chart">${mbarHtml}</div></div>
      <div class="profile-section-title">Points Per Game</div>
      <div class="chart-wrap"><div class="bar-chart">${gameBars}</div></div>
      <div class="profile-section-title">Game History</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="game-table">
          <thead><tr><th>Date</th><th>Opponent</th><th>Result</th><th>PTS</th><th>SPK</th><th>ACE</th><th>SET</th><th>DIG</th></tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>`;
  }

  // FIX: Badminton & Table Tennis — all matches the player participated in,
  // with correct WIN/LOSS using case-insensitive comparison on winner_name.
  if (sport === 'badminton' || sport === 'table_tennis') {
    const label = sport === 'badminton' ? 'Badminton' : 'Table Tennis';
    // sd.wins is the authoritative server-computed win count (strcasecmp fixed in API).
    const wins  = +(sd.wins || 0);
    const total = +(sd.games || sd.history.length || 0);

    // Case-insensitive helper — DB may store winner_name in any case.
    const eqCI = (a, b) => a && b && a.toLowerCase() === b.toLowerCase();

    // Per-match result rows
    const tableRows = sd.history.map(g => {
      const date    = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      // player_team is the team name for this player's side (aliased from SQL CASE WHEN)
      const myTeam  = g.player_team || g.player_team_name || '—';
      const oppTeam = eqCI(myTeam, g.team_a_name) ? g.team_b_name : g.team_a_name;
      // Win if winner_name matches our team name OR matches the player's own name directly
      const isWin   = eqCI(g.winner_name, myTeam) ||
                      (!eqCI(g.winner_name, g.team_a_name) && !eqCI(g.winner_name, g.team_b_name) &&
                       !eqCI(g.winner_name, oppTeam) && g.winner_name);
      return `<tr>
        <td>${date}</td>
        <td>${escHtml(myTeam)}</td>
        <td>${escHtml(oppTeam||'—')}</td>
        <td>${escHtml(g.match_type||'—')}</td>
        <td><span class="${isWin?'win':'loss'}">${isWin?'WIN':'LOSS'}</span></td>
      </tr>`;
    }).join('');

    // Bar: wins per month — use same case-insensitive eqCI helper
    const winsByMonth = {};
    sd.history.forEach(g => {
      const myTeam2  = g.player_team || g.player_team_name || '';
      const oppTeam2 = eqCI(myTeam2, g.team_a_name) ? g.team_b_name : g.team_a_name;
      const isWin2   = eqCI(g.winner_name, myTeam2) ||
                       (!eqCI(g.winner_name, g.team_a_name) && !eqCI(g.winner_name, g.team_b_name) &&
                        !eqCI(g.winner_name, oppTeam2) && g.winner_name);
      if (!g.created_at || !isWin2) return;
      const key = new Date(g.created_at).toLocaleDateString('en-US',{month:'short',year:'2-digit'});
      winsByMonth[key] = (winsByMonth[key] || 0) + 1;
    });
    const maxW = Math.max(...Object.values(winsByMonth), 1);
    const cls = sport === 'badminton' ? 'green-bar' : 'orange-bar';
    const wBars = Object.entries(winsByMonth).map(([mo, w]) => {
      const h = Math.max((w/maxW*110), 3).toFixed(0);
      return `<div class="bar-group">
        <div class="bar-col" style="height:${h}px"><div class="bar-fill ${cls}" style="height:100%" data-val="${w}"></div></div>
        <div class="bar-label">${mo}</div></div>`;
    }).join('');

    const winPct = total > 0 ? ((wins/total)*100).toFixed(0) : 0;
    return `
      <div class="profile-section-title">${label} — ${wins}W / ${total - wins}L in ${total} match${total!==1?'es':''} (${winPct}% win rate)</div>
      ${Object.keys(winsByMonth).length >= 1 ? `<div class="chart-wrap"><div class="bar-chart">${wBars}</div></div>` : ''}
      <div class="profile-section-title">Match History</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="game-table">
          <thead><tr><th>Date</th><th>My Team</th><th>Opponent</th><th>Type</th><th>Result</th></tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>`;
  }

  // FIX: Darts — show leg wins, total throws, throw average per match.
  if (sport === 'darts') {
    const winPct = sd.games ? ((sd.wins/sd.games)*100).toFixed(0) : 0;

    // Summary stat chips
    const summaryChips = [
      { val: sd.games,           lbl: 'Matches' },
      { val: sd.wins,            lbl: 'Match Wins' },
      { val: sd.leg_wins || 0, lbl: 'Leg Wins' },
      { val: sd.total_throws || 0,   lbl: 'Total Throws' },
      { val: sd.throw_avg || 0,      lbl: 'Throw Avg' },
    ].map(c => `<div class="stat-chip"><span class="sc-val">${c.val}</span><span class="sc-lbl">${c.lbl}</span></div>`).join('');

    const tableRows = sd.history.map(g => {
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      return `<tr>
        <td>${date}</td>
        <td>${escHtml(g.game_type||'—')}</td>
        <td class="val-y">${+(g.leg_wins||0)}</td>
        <td>${+(g.total_throws||0)}</td>
        <td>${+(g.throw_avg||0)}</td>
        <td><span class="${+g.is_winner?'win':'loss'}">${+g.is_winner?'WIN':'LOSS'}</span></td>
      </tr>`;
    }).join('');

    return `
      <div class="profile-section-title">Darts — ${sd.wins} Win${sd.wins!==1?'s':''} in ${sd.games} game${sd.games!==1?'s':''} (${winPct}% win rate)</div>
      <div class="stat-mini" style="margin-bottom:16px">${summaryChips}</div>
      <div class="profile-section-title">Per-Match Breakdown</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="game-table">
          <thead><tr><th>Date</th><th>Game Type</th><th>Leg W</th><th>Throws</th><th>Avg</th><th>Result</th></tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>`;
  }

  return `<div class="empty-state"><div class="empty-text">No detail available for this sport</div></div>`;
}

// ============================================================
//  UTILS
// ============================================================
async function apiFetch(params) {
  try {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(`${API}?${qs}`);
    const ct = res.headers.get('content-type') || '';
    const txt = await res.text();
    if (!res.ok) { try { return JSON.parse(txt); } catch(e) { return { success:false, error: `HTTP ${res.status}` }; } }
    try { return JSON.parse(txt); } catch(e) { return { success:false, error: 'Invalid JSON' }; }
  } catch (e) { return null; }
}
function escHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

loadPlayers();
</script>
</body>
</html>