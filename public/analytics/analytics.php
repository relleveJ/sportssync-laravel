<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SportSync — Analytics</title>
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
  --success:    #22c55e;
  --danger:     #ef4444;
  --radius:     10px;
  --font-head:  'Bebas Neue', sans-serif;
  --font-body:  'DM Sans', sans-serif;
  --font-mono:  'JetBrains Mono', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { background: var(--bg); color: var(--text); font-family: var(--font-body); min-height: 100vh; overflow-x: hidden; }
body::before {
  content: ''; position: fixed; inset: 0;
  background-image: linear-gradient(rgba(245,196,0,.03) 1px, transparent 1px), linear-gradient(90deg, rgba(245,196,0,.03) 1px, transparent 1px);
  background-size: 40px 40px; pointer-events: none; z-index: 0;
}

/* ── NAV ── */
nav {
  position: sticky; top: 0; z-index: 100;
  background: rgba(10,10,12,.92); backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 16px; padding: 0 28px; height: 60px;
}
.nav-logo { font-family: var(--font-head); font-size: 1.6rem; letter-spacing: 2px; color: var(--yellow); text-decoration: none; flex-shrink: 0; }
.nav-logo span { color: var(--text); }
.nav-back { font-size: .82rem; color: var(--muted); text-decoration: none; border: 1px solid var(--border); padding: 6px 14px; border-radius: 6px; transition: all .2s; }
.nav-back:hover { color: var(--yellow); border-color: var(--yellow); }

/* ── LAYOUT ── */
.page { position: relative; z-index: 1; max-width: 1400px; margin: 0 auto; padding: 32px 24px 80px; }

/* ── SECTION HEADING ── */
.section-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 24px; }
.section-head h2 { font-family: var(--font-head); font-size: 2rem; letter-spacing: 3px; color: var(--text); }
.section-head .accent-line { flex: 1; height: 1px; background: linear-gradient(90deg, var(--yellow), transparent); }

/* ── MAIN NAV TABS (top-level: Players / Teams / Sports) ── */
.main-tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 1px solid var(--border); }
.main-tab {
  padding: 13px 22px; background: none; border: none;
  border-bottom: 3px solid transparent;
  color: var(--muted); font-family: var(--font-body); font-size: .85rem;
  font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
  cursor: pointer; transition: all .2s;
  display: flex; align-items: center; gap: 7px;
}
.main-tab:hover { color: var(--text); }
.main-tab.active { color: var(--yellow); border-bottom-color: var(--yellow); }

/* ── SPORT SELECTOR TABS ── */
.sport-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; }
.sport-tab {
  display: flex; align-items: center; gap: 8px; padding: 9px 18px;
  background: var(--surface2); border: 1px solid var(--border); border-radius: 8px;
  cursor: pointer; font-family: var(--font-body); font-size: .85rem; font-weight: 600;
  color: var(--muted); text-transform: uppercase; letter-spacing: 1px; transition: all .2s;
}
.sport-tab .tab-icon { font-size: 1.1rem; }
.sport-tab:hover { border-color: var(--yellow-dim); color: var(--text); }
.sport-tab.active { background: var(--yellow); border-color: var(--yellow); color: #000; }

/* ── OVERVIEW CARDS ── */
.overview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 40px; }
.overview-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 20px; position: relative; overflow: hidden; transition: border-color .2s, transform .2s;
}
.overview-card::before { content: attr(data-sport-icon); position: absolute; right: 12px; bottom: 8px; font-size: 3rem; opacity: .07; pointer-events: none; }
.overview-card:hover { border-color: var(--yellow); transform: translateY(-2px); }
.overview-card .card-sport { font-size: .72rem; text-transform: uppercase; letter-spacing: 2px; color: var(--muted); margin-bottom: 6px; }
.overview-card .card-num { font-family: var(--font-head); font-size: 2.4rem; color: var(--yellow); line-height: 1; }
.overview-card .card-label { font-size: .78rem; color: var(--muted); margin-top: 4px; }
.overview-card .card-top { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); font-size: .8rem; color: var(--text); }
.overview-card .card-top span { color: var(--yellow); font-weight: 600; }

/* ── PLAYER PANEL LAYOUT ── */
.analytics-grid { display: grid; grid-template-columns: 320px 1fr; gap: 24px; align-items: start; }
@media(max-width:900px) { .analytics-grid { grid-template-columns: 1fr; } }

/* ── PANEL ── */
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.panel-header { padding: 16px 20px; background: var(--surface2); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.panel-header h3 { font-family: var(--font-head); font-size: 1.1rem; letter-spacing: 2px; color: var(--text); }
.player-count { font-family: var(--font-mono); font-size: .75rem; color: var(--muted); background: var(--bg); padding: 3px 8px; border-radius: 4px; }

.player-search { padding: 12px 16px; border-bottom: 1px solid var(--border); }
.player-search input { width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; color: var(--text); font-family: var(--font-body); font-size: .85rem; outline: none; transition: border-color .2s; }
.player-search input:focus { border-color: var(--yellow); }
.player-search input::placeholder { color: var(--muted); }

.player-list { max-height: 520px; overflow-y: auto; }
.player-list::-webkit-scrollbar { width: 4px; }
.player-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* ── PLAYER LIST ITEMS ── */
.player-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 20px; cursor: pointer; border-bottom: 1px solid var(--border);
  transition: background .15s; position: relative;
}
.player-item:hover { background: var(--surface2); }
.player-item.active { background: rgba(245,196,0,.08); }
.player-item.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--yellow); }
.player-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--yellow), var(--yellow-dim)); display: flex; align-items: center; justify-content: center; font-family: var(--font-head); font-size: 1rem; color: #000; flex-shrink: 0; }
.player-avatar.blue { background: linear-gradient(135deg, var(--blue), var(--blue-dim)); color: #fff; }
.player-info { flex: 1; min-width: 0; }
.player-name { font-weight: 600; font-size: .88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.player-meta { font-size: .74rem; color: var(--muted); margin-top: 2px; }
.player-pts { font-family: var(--font-mono); font-size: .85rem; color: var(--yellow); font-weight: 600; }

/* ── TEAM LIST ITEMS ── */
.team-item {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 20px; cursor: pointer; border-bottom: 1px solid var(--border);
  transition: background .15s; position: relative;
}
.team-item:hover { background: var(--surface2); }
.team-item.active { background: rgba(245,196,0,.08); }
.team-item.active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--yellow); }
.team-avatar { width: 38px; height: 38px; border-radius: 8px; background: linear-gradient(135deg, #333, #222); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; border: 1px solid var(--border); }
.team-info { flex: 1; min-width: 0; }
.team-name-text { font-weight: 600; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-meta-text { font-size: .74rem; color: var(--muted); margin-top: 2px; }
.team-wins-badge { font-family: var(--font-mono); font-size: .82rem; color: var(--green); font-weight: 600; white-space: nowrap; }
.team-loss-badge { font-family: var(--font-mono); font-size: .75rem; color: var(--red);   font-weight: 600; white-space: nowrap; }

/* ── DETAIL PANEL ── */
.detail-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; min-height: 500px; }
.detail-inner { padding: 24px; }
.detail-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.detail-player-name { font-family: var(--font-head); font-size: 2rem; letter-spacing: 2px; color: var(--text); }
.detail-sport-badge { padding: 4px 12px; background: var(--yellow); border-radius: 20px; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #000; }

/* ── STAT CARDS ── */
.stat-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; margin-bottom: 28px; }
.stat-card { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
.stat-card .s-val { font-family: var(--font-head); font-size: 1.8rem; color: var(--yellow); line-height: 1; }
.stat-card .s-label { font-size: .68rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-top: 4px; }

/* ── WIN/LOSS INDICATOR ── */
.wl-indicator { display: flex; gap: 8px; align-items: center; }
.wl-bar { flex: 1; height: 10px; background: var(--surface2); border-radius: 5px; overflow: hidden; position: relative; border: 1px solid var(--border); }
/* Losses fill from the LEFT (red), wins fill from the RIGHT (green) */
.wl-fill-w { position: absolute; right: 0; top: 0; bottom: 0; background: var(--green); border-radius: 0 5px 5px 0; }
.wl-fill-l { position: absolute; left:  0; top: 0; bottom: 0; background: var(--red);   border-radius: 5px 0 0 5px; }
.wl-label-w { font-family: var(--font-mono); font-size: .78rem; color: var(--green); font-weight: 600; }
.wl-label-l { font-family: var(--font-mono); font-size: .78rem; color: var(--red); font-weight: 600; }

/* ── BAR CHARTS ── */
.chart-section { margin-bottom: 28px; }
.chart-title { font-family: var(--font-head); font-size: 1rem; letter-spacing: 2px; color: var(--muted); margin-bottom: 14px; text-transform: uppercase; }
.chart-wrap { background: var(--surface2); border: 1px solid var(--border); border-radius: 8px; padding: 16px; overflow-x: auto; }
.bar-chart { display: flex; align-items: flex-end; gap: 10px; min-height: 140px; padding-top: 8px; }
.bar-group { display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 40px; flex: 1; }
.bar-col { width: 100%; position: relative; }
.bar-fill { width: 100%; background: linear-gradient(180deg, var(--yellow), var(--yellow-dim)); border-radius: 4px 4px 0 0; transition: height .6s cubic-bezier(.34,1.56,.64,1); position: relative; min-height: 3px; }
.bar-fill.blue { background: linear-gradient(180deg, var(--blue), var(--blue-dim)); }
.bar-fill.green { background: linear-gradient(180deg, var(--green), #166534); }
.bar-fill.orange { background: linear-gradient(180deg, var(--orange), #9a3412); }
.bar-fill.purple { background: linear-gradient(180deg, var(--purple), #6b21a8); }
.bar-fill.green { background: linear-gradient(180deg, var(--green), #166534); }
.bar-fill:hover::after { content: attr(data-val); position: absolute; top: -24px; left: 50%; transform: translateX(-50%); background: var(--yellow); color: #000; font-size: .7rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; white-space: nowrap; }
.bar-label { font-size: .65rem; color: var(--muted); text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 56px; }
.multi-bar-chart { display: flex; flex-direction: column; gap: 10px; }
.mbar-row { display: flex; align-items: center; gap: 10px; }
.mbar-label { width: 80px; font-size: .75rem; color: var(--muted); text-align: right; flex-shrink: 0; }
.mbar-track { flex: 1; height: 22px; background: var(--bg); border-radius: 4px; overflow: hidden; }
.mbar-fill { height: 100%; border-radius: 4px; display: flex; align-items: center; padding-left: 8px; font-size: .7rem; font-weight: 600; color: #000; transition: width .7s cubic-bezier(.34,1.56,.64,1); min-width: 24px; }
.mbar-val { width: 40px; font-family: var(--font-mono); font-size: .78rem; color: var(--text); text-align: right; }

/* ── GAME TABLE ── */
.game-table-wrap { overflow-x: auto; }
.game-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.game-table th { background: var(--surface2); padding: 10px 12px; text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); font-weight: 600; white-space: nowrap; border-bottom: 2px solid var(--border); }
.game-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.game-table tr:hover td { background: var(--surface2); }
.game-table .win  { color: var(--success); font-weight: 600; }
.game-table .loss { color: var(--danger);  font-weight: 600; }
.val-yellow { color: var(--yellow); font-family: var(--font-mono); font-weight: 600; }
.val-blue   { color: #5b9bff; font-family: var(--font-mono); }
.val-green  { color: var(--green); font-family: var(--font-mono); font-weight: 600; }
.val-red    { color: var(--red); font-family: var(--font-mono); font-weight: 600; }

/* ── ROSTER TABLE ── */
.roster-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.roster-table th { background: var(--bg); padding: 9px 12px; font-size: .68rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); border-bottom: 1px solid var(--border); text-align: left; }
.roster-table td { padding: 9px 12px; border-bottom: 1px solid var(--border); }
.roster-table tr:hover td { background: var(--surface2); }
.player-initial-badge { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, var(--yellow), var(--yellow-dim)); font-family: var(--font-head); font-size: .8rem; color: #000; margin-right: 8px; }

/* ── TOP PERFORMERS TABLE ── */
.top-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
.top-table th { padding: 10px 14px; font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); border-bottom: 1px solid var(--border); text-align: left; }
.top-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); }
.top-table tr:hover td { background: var(--surface2); }
.rank-badge { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; font-size: .72rem; font-weight: 700; background: var(--border); color: var(--muted); }
.rank-badge.gold   { background: var(--yellow); color: #000; }
.rank-badge.silver { background: #b0b0b0; color: #000; }
.rank-badge.bronze { background: #cd7f32; color: #000; }

/* ── LOADING / EMPTY ── */
.loading-state, .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 60px 20px; color: var(--muted); }
.loading-spinner { width: 36px; height: 36px; border: 3px solid var(--border); border-top-color: var(--yellow); border-radius: 50%; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty-icon { font-size: 3rem; opacity: .4; }
.empty-text { font-size: .9rem; }

/* ── MAIN PAGES ── */
.main-page { display: none; }
.main-page.visible { display: block; }

/* ── ANIMATIONS ── */
@keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
.fade-up { animation: fadeUp .4s ease both; }

@media(max-width:600px) { .hide-mobile { display: none; } }
</style>
</head>
<body>

<nav>
  <a class="nav-logo" href="/">SPORT<span>SYNC</span></a>
  <div style="display:flex;gap:8px;flex:1">
    <a style="font-size:.82rem;color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:6px 14px;border-radius:6px;transition:all .2s;font-weight:500" href="players.php">👤 Players</a>
    <a style="font-size:.82rem;color:var(--yellow);text-decoration:none;border:1px solid var(--yellow-dim);padding:6px 14px;border-radius:6px;font-weight:500" href="analytics.php">📊 Analytics</a>
  </div>
  <a class="nav-back" href="/">← Back to Dashboard</a>
</nav>

<div class="page">

  <!-- HERO -->
  <div style="margin-bottom:32px;padding-top:8px">
    <div style="font-family:var(--font-head);font-size:clamp(2.4rem,6vw,4rem);letter-spacing:4px;line-height:1.05">
      PLAYER &amp; SPORTS<br><span style="color:var(--yellow)">ANALYTICS</span>
    </div>
    <div style="color:var(--muted);margin-top:8px;font-size:.9rem">Performance insights across all SportSync sports</div>
  </div>

  <!-- OVERVIEW -->
  <div class="section-head"><h2>OVERVIEW</h2><div class="accent-line"></div></div>
  <div class="overview-grid" id="overviewGrid">
    <div class="loading-state"><div class="loading-spinner"></div></div>
  </div>

  <!-- MAIN TABS -->
  <div class="main-tabs" style="margin-bottom:28px">
    <button class="main-tab active" id="tabPlayerBtn" onclick="switchMainTab('player')">👤 Player Analytics</button>
    <button class="main-tab" id="tabTeamBtn"   onclick="switchMainTab('team')">🏆 Team Analytics</button>
    <button class="main-tab" id="tabSportsBtn" onclick="switchMainTab('sports')">📈 Sports Trends</button>
  </div>

  <!-- ────────────────────────────────────────────────────── -->
  <!-- PAGE: PLAYER ANALYTICS                                  -->
  <!-- ────────────────────────────────────────────────────── -->
  <div class="main-page visible" id="pagePlayer">
    <div class="sport-tabs" id="sportTabs">
      <button class="sport-tab active" data-sport="basketball" onclick="setSport('basketball',this)"><span class="tab-icon">🏀</span> Basketball</button>
      <button class="sport-tab" data-sport="volleyball" onclick="setSport('volleyball',this)"><span class="tab-icon">🏐</span> Volleyball</button>
      <button class="sport-tab" data-sport="badminton"  onclick="setSport('badminton',this)"><span class="tab-icon">🏸</span> Badminton</button>
      <button class="sport-tab" data-sport="table_tennis" onclick="setSport('table_tennis',this)"><span class="tab-icon">🏓</span> Table Tennis</button>
      <button class="sport-tab" data-sport="darts" onclick="setSport('darts',this)"><span class="tab-icon">🎯</span> Darts</button>
    </div>
    <div class="analytics-grid">
      <div class="panel">
        <div class="panel-header"><h3>PLAYERS</h3><span class="player-count" id="playerCount">0</span></div>
        <div class="player-search"><input type="text" id="searchInput" placeholder="Search player…" oninput="filterPlayers(this.value)"></div>
        <div class="player-list" id="playerList"><div class="loading-state"><div class="loading-spinner"></div></div></div>
      </div>
      <div class="detail-panel" id="detailPanel">
        <div class="loading-state" style="min-height:500px">
          <div class="empty-icon">📊</div><div class="empty-text">Select a player to view analytics</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ────────────────────────────────────────────────────── -->
  <!-- PAGE: TEAM ANALYTICS                                    -->
  <!-- ────────────────────────────────────────────────────── -->
  <div class="main-page" id="pageTeam">
    <div class="sport-tabs" id="teamSportTabs">
      <button class="sport-tab active" data-sport="basketball" onclick="setTeamSport('basketball',this)"><span class="tab-icon">🏀</span> Basketball</button>
      <button class="sport-tab" data-sport="volleyball" onclick="setTeamSport('volleyball',this)"><span class="tab-icon">🏐</span> Volleyball</button>
      <button class="sport-tab" data-sport="badminton"  onclick="setTeamSport('badminton',this)"><span class="tab-icon">🏸</span> Badminton</button>
      <button class="sport-tab" data-sport="table_tennis" onclick="setTeamSport('table_tennis',this)"><span class="tab-icon">🏓</span> Table Tennis</button>
      <button class="sport-tab" data-sport="darts" onclick="setTeamSport('darts',this)"><span class="tab-icon">🎯</span> Darts</button>
    </div>
    <div class="analytics-grid">
      <!-- Team List -->
      <div class="panel">
        <div class="panel-header"><h3>TEAMS</h3><span class="player-count" id="teamCount">0</span></div>
        <div class="player-search"><input type="text" id="teamSearch" placeholder="Search team…" oninput="filterTeams(this.value)"></div>
        <div class="player-list" id="teamList"><div class="loading-state"><div class="loading-spinner"></div></div></div>
      </div>
      <!-- Team Detail -->
      <div class="detail-panel" id="teamDetailPanel">
        <div class="loading-state" style="min-height:500px">
          <div class="empty-icon">🏆</div><div class="empty-text">Select a team to view analytics</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ────────────────────────────────────────────────────── -->
  <!-- PAGE: SPORTS TRENDS                                     -->
  <!-- ────────────────────────────────────────────────────── -->
  <div class="main-page" id="pageSports">
    <div class="sport-tabs" id="sportTabs2">
      <button class="sport-tab active" data-sport="basketball" onclick="setSportAnalytics('basketball',this)"><span class="tab-icon">🏀</span> Basketball</button>
      <button class="sport-tab" data-sport="volleyball" onclick="setSportAnalytics('volleyball',this)"><span class="tab-icon">🏐</span> Volleyball</button>
      <button class="sport-tab" data-sport="badminton"  onclick="setSportAnalytics('badminton',this)"><span class="tab-icon">🏸</span> Badminton</button>
      <button class="sport-tab" data-sport="table_tennis" onclick="setSportAnalytics('table_tennis',this)"><span class="tab-icon">🏓</span> Table Tennis</button>
      <button class="sport-tab" data-sport="darts" onclick="setSportAnalytics('darts',this)"><span class="tab-icon">🎯</span> Darts</button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px" id="sportsAnalyticsGrid">
      <div class="panel" id="topPerformersPanel">
        <div class="panel-header"><h3>TOP PERFORMERS</h3></div>
        <div style="padding:16px" id="topPerformersContent"><div class="loading-state"><div class="loading-spinner"></div></div></div>
      </div>
      <div class="panel" id="sportTrendsPanel">
        <div class="panel-header"><h3>GAME TRENDS</h3></div>
        <div style="padding:16px" id="sportTrendsContent"><div class="loading-state"><div class="loading-spinner"></div></div></div>
      </div>
    </div>
  </div>

</div><!-- /page -->

<script>
const API = 'analytics_api.php';

let currentSport          = 'basketball';
let currentTeamSport      = 'basketball';
let currentSportAnalytics = 'basketball';
let allPlayers            = [];
let allTeams              = [];
let selectedPlayer        = null;
let selectedTeam          = null;
let overviewData          = null;

const SPORT_META = {
  basketball:  { icon: '🏀', color: '#f5c400', label: 'Basketball' },
  volleyball:  { icon: '🏐', color: '#1e6aff', label: 'Volleyball' },
  badminton:   { icon: '🏸', color: '#22c55e', label: 'Badminton' },
  table_tennis:{ icon: '🏓', color: '#f97316', label: 'Table Tennis' },
  darts:       { icon: '🎯', color: '#a855f7', label: 'Darts' },
};

const STAT_META = {
  basketball: [
    { key: 'total_pts', label: 'PTS', color: '#f5c400' },
    { key: 'total_reb', label: 'REB', color: '#1e6aff' },
    { key: 'total_ast', label: 'AST', color: '#22c55e' },
    { key: 'total_blk', label: 'BLK', color: '#f97316' },
    { key: 'total_stl', label: 'STL', color: '#a855f7' },
  ],
  volleyball: [
    { key: 'total_pts',   label: 'PTS', color: '#f5c400' },
    { key: 'total_spike', label: 'SPK', color: '#1e6aff' },
    { key: 'total_ace',   label: 'ACE', color: '#22c55e' },
    { key: 'total_set',   label: 'SET', color: '#f97316' },
    { key: 'total_dig',   label: 'DIG', color: '#a855f7' },
  ],
  badminton:    [{ key: 'wins', label: 'WINS', color: '#22c55e' }, { key: 'games_played', label: 'PLAYED', color: '#166534' }],
  table_tennis: [{ key: 'wins', label: 'WINS', color: '#f97316' }, { key: 'games_played', label: 'PLAYED', color: '#9a3412' }],
  darts: [
    { key: 'leg_wins',     label: 'LEG W',  color: '#a855f7' },
    { key: 'total_throws', label: 'THROWS', color: '#1e6aff' },
    { key: 'throw_avg',    label: 'AVG',    color: '#22c55e' },
    { key: 'games_played', label: 'PLAYED', color: '#6b21a8' },
  ],
};

// ============================================================
//  INIT
// ============================================================
async function init() {
  await loadOverview();
  await loadPlayers('basketball');
  loadSportAnalytics('basketball');
}

// ============================================================
//  MAIN TAB SWITCH
// ============================================================
function switchMainTab(tab) {
  document.querySelectorAll('.main-page').forEach(p => p.classList.remove('visible'));
  document.querySelectorAll('.main-tab').forEach(b => b.classList.remove('active'));

  if (tab === 'player') {
    document.getElementById('pagePlayer').classList.add('visible');
    document.getElementById('tabPlayerBtn').classList.add('active');
  } else if (tab === 'team') {
    document.getElementById('pageTeam').classList.add('visible');
    document.getElementById('tabTeamBtn').classList.add('active');
    if (!allTeams.length) loadTeams('basketball');
  } else {
    document.getElementById('pageSports').classList.add('visible');
    document.getElementById('tabSportsBtn').classList.add('active');
  }
}

// ============================================================
//  OVERVIEW
// ============================================================
async function loadOverview() {
  const data = await apiFetch({ action: 'sports_overview' });
  if (!data?.success) return;
  overviewData = data.data;
  renderOverview(data.data);
}

function renderOverview(d) {
  const sports = [
    { key:'basketball',   label:'Basketball',   icon:'🏀' },
    { key:'volleyball',   label:'Volleyball',   icon:'🏐' },
    { key:'badminton',    label:'Badminton',    icon:'🏸' },
    { key:'table_tennis', label:'Table Tennis', icon:'🏓' },
    { key:'darts',        label:'Darts',        icon:'🎯' },
  ];
  document.getElementById('overviewGrid').innerHTML = sports.map(s => {
    const sd = d[s.key] || {};
    const players  = sd.top_players || [];
    const statLbl  = (s.key === 'basketball' || s.key === 'volleyball') ? 'pts' : 'wins';

    // Find the max stat value among all top players
    const getStatVal = p => +(p.total_pts ?? p.wins ?? 0);
    const maxStat = players.length ? getStatVal(players[0]) : 0;

    // Collect ALL players tied at the top stat value
    const topTied = players.filter(p => getStatVal(p) === maxStat);

    // Build display — first name only, comma-separated if tied
    const topLine = topTied.length
      ? topTied.map(p => (p.player_name || p.winner_name || '—').split(' ')[0]).join(', ')
      : null;

    return `<div class="overview-card fade-up" data-sport-icon="${s.icon}">
      <div class="card-sport">${s.icon} ${s.label}</div>
      <div class="card-num">${sd.total_matches ?? 0}</div>
      <div class="card-label">Completed Matches</div>
      ${topLine ? `<div class="card-top">Top: <span>${escHtml(topLine)}</span> — ${maxStat} ${statLbl}</div>` : ''}
    </div>`;
  }).join('');
}

// ============================================================
//  PLAYER ANALYTICS
// ============================================================
function setSport(sport, btn) {
  currentSport = sport;
  selectedPlayer = null;
  document.querySelectorAll('#sportTabs .sport-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('detailPanel').innerHTML = `
    <div class="loading-state" style="min-height:500px">
      <div class="empty-icon">📊</div><div class="empty-text">Select a player to view analytics</div>
    </div>`;
  loadPlayers(sport);
}

async function loadPlayers(sport) {
  const listEl = document.getElementById('playerList');
  listEl.innerHTML = '<div class="loading-state"><div class="loading-spinner"></div></div>';
  const data = await apiFetch({ action: 'players', sport });
  if (!data?.success) {
    listEl.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">Could not load players</div></div>';
    return;
  }
  allPlayers = data.data || [];
  renderPlayerList(allPlayers);
}

function renderPlayerList(players) {
  const listEl = document.getElementById('playerList');
  document.getElementById('playerCount').textContent = players.length;
  if (!players.length) {
    listEl.innerHTML = '<div class="empty-state"><div class="empty-icon">👤</div><div class="empty-text">No players found</div></div>';
    return;
  }
  const meta = STAT_META[currentSport] || [];
  const primaryStat = meta[0] || {};
  listEl.innerHTML = players.map(p => {
    const name = p.player_name || p.winner_name || 'Unknown';
    const initials = name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
    // FIX: for badminton/TT team is actual team name now, not 'A'/'B'
    const teamStr  = p.team ? `${p.team}` : '';
    const avatarClass = 'player-avatar';
    // FIX: primary stat — use the key directly (leg_wins for darts, wins for bd/tt)
    const statVal  = p[primaryStat.key] ?? p.wins ?? 0;
    const gamesVal = p.games_played ? `${p.games_played} game${p.games_played != 1 ? 's' : ''}` : '';
    // For badminton/TT show match type if available
    const typeStr  = p.match_type ? p.match_type : '';
    const metaParts = [teamStr, gamesVal, typeStr].filter(Boolean).join(' · ');
    return `<div class="player-item ${selectedPlayer === name ? 'active' : ''}" onclick="selectPlayer('${escHtml(name)}')">
      <div class="${avatarClass}">${initials}</div>
      <div class="player-info">
        <div class="player-name">${escHtml(name)}</div>
        ${metaParts ? `<div class="player-meta">${escHtml(metaParts)}</div>` : ''}
      </div>
      <div class="player-pts">${statVal}</div>
    </div>`;
  }).join('');
}

function filterPlayers(q) {
  const filtered = allPlayers.filter(p => (p.player_name || p.winner_name || '').toLowerCase().includes(q.toLowerCase()));
  renderPlayerList(filtered);
}

async function selectPlayer(name) {
  selectedPlayer = name;
  document.querySelectorAll('.player-item').forEach(el => {
    el.classList.toggle('active', el.querySelector('.player-name')?.textContent === name);
  });
  const detail = document.getElementById('detailPanel');
  detail.innerHTML = '<div class="loading-state" style="min-height:500px"><div class="loading-spinner"></div></div>';
  const data = await apiFetch({ action: 'player_detail', sport: currentSport, player: name });
  // FIX: check data.data exists and has items (all sports now return game arrays)
  if (!data?.success || !Array.isArray(data.data) || !data.data.length) {
    detail.innerHTML = renderPlayerDetailFromList(name);
    return;
  }
  detail.innerHTML = renderPlayerDetail(name, data.data);
}

function renderPlayerDetailFromList(name) {
  const player = allPlayers.find(p => (p.player_name || p.winner_name) === name);
  if (!player) return '<div class="empty-state" style="min-height:500px"><div class="empty-icon">⚠️</div><div class="empty-text">Player not found</div></div>';
  const meta = STAT_META[currentSport] || [];
  const statCards = meta.map(m => `<div class="stat-card"><div class="s-val">${player[m.key] ?? 0}</div><div class="s-label">${m.label}</div></div>`).join('');
  const maxVal = Math.max(...meta.map(m => +(player[m.key] ?? 0)), 1);
  const mbarRows = meta.map(m => {
    const val = +(player[m.key] ?? 0);
    const pct = (val / maxVal * 100).toFixed(1);
    return `<div class="mbar-row"><div class="mbar-label">${m.label}</div>
      <div class="mbar-track"><div class="mbar-fill" style="width:${pct}%;background:${m.color}">${val}</div></div>
      <div class="mbar-val">${val}</div></div>`;
  }).join('');
  const sportMeta = SPORT_META[currentSport];
  return `<div class="detail-inner fade-up">
    <div class="detail-header">
      <div><div class="detail-player-name">${escHtml(name)}</div>
      <div style="color:var(--muted);font-size:.83rem;margin-top:4px">${player.games_played ?? 0} games${player.team ? ` · Team ${player.team}` : ''}</div></div>
      <div class="detail-sport-badge">${sportMeta.icon} ${sportMeta.label}</div>
    </div>
    <div class="stat-cards">${statCards}</div>
    <div class="chart-section"><div class="chart-title">Career Stats Overview</div>
      <div class="chart-wrap"><div class="multi-bar-chart">${mbarRows}</div></div>
    </div>
  </div>`;
}

function renderPlayerDetail(name, games) {
  const sportMeta = SPORT_META[currentSport];
  const meta = STAT_META[currentSport] || [];

  // ── BADMINTON / TABLE TENNIS ───────────────────────────────
  // API now returns all matches the player appeared in with is_win flag.
  if (currentSport === 'badminton' || currentSport === 'table_tennis') {
    const wins  = games.filter(g => +g.is_win === 1).length;
    const total = games.length;
    const winPct = total > 0 ? ((wins/total)*100).toFixed(0) : 0;
    const label = currentSport === 'badminton' ? 'Badminton' : 'Table Tennis';
    const cls   = currentSport === 'badminton' ? 'bar-fill green' : 'bar-fill orange';

    // Wins per month bar chart
    const winsByMonth = {};
    games.forEach(g => {
      if (!g.created_at || +g.is_win !== 1) return;
      const key = new Date(g.created_at).toLocaleDateString('en-US',{month:'short',year:'2-digit'});
      winsByMonth[key] = (winsByMonth[key] || 0) + 1;
    });
    const maxW = Math.max(...Object.values(winsByMonth), 1);
    const wBars = Object.entries(winsByMonth).map(([mo, w]) => {
      const h = Math.max((w/maxW*120), 3).toFixed(0);
      return `<div class="bar-group"><div class="bar-col" style="height:${h}px">
        <div class="${cls}" style="height:100%" data-val="${w}"></div></div>
        <div class="bar-label">${mo}</div></div>`;
    }).join('');

    const tableRows = games.map(g => {
      const date    = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      const isWin   = +g.is_win === 1;
      const myTeam  = escHtml(g.player_team_name || '—');
      const oppTeam = escHtml((g.player_team_name === g.team_a_name ? g.team_b_name : g.team_a_name) || '—');
      return `<tr>
        <td>${date}</td>
        <td>${myTeam}</td>
        <td>${oppTeam}</td>
        <td>${escHtml(g.match_type||'—')}</td>
        <td><span class="${isWin?'win':'loss'}">${isWin?'WIN':'LOSS'}</span></td>
      </tr>`;
    }).join('');

    return `<div class="detail-inner fade-up">
      <div class="detail-header">
        <div><div class="detail-player-name">${escHtml(name)}</div>
        <div style="color:var(--muted);font-size:.83rem;margin-top:4px">${wins}W · ${total-wins}L · ${total} match${total!==1?'es':''}</div></div>
        <div class="detail-sport-badge">${sportMeta.icon} ${sportMeta.label}</div>
      </div>
      <div class="stat-cards">
        <div class="stat-card"><div class="s-val">${total}</div><div class="s-label">PLAYED</div></div>
        <div class="stat-card"><div class="s-val" style="color:var(--success)">${wins}</div><div class="s-label">WINS</div></div>
        <div class="stat-card"><div class="s-val" style="color:var(--danger)">${total-wins}</div><div class="s-label">LOSSES</div></div>
        <div class="stat-card"><div class="s-val">${winPct}%</div><div class="s-label">WIN RATE</div></div>
      </div>
      ${Object.keys(winsByMonth).length >= 1 ? `
      <div class="chart-section"><div class="chart-title">Wins Per Month</div>
        <div class="chart-wrap"><div class="bar-chart">${wBars}</div></div>
      </div>` : ''}
      <div class="chart-section"><div class="chart-title">Match History</div>
        <div class="chart-wrap game-table-wrap">
          <table class="game-table">
            <thead><tr><th>Date</th><th>My Team</th><th>Opponent</th><th>Type</th><th>Result</th></tr></thead>
            <tbody>${tableRows}</tbody>
          </table>
        </div>
      </div>
    </div>`;
  }

  // ── DARTS ─────────────────────────────────────────────────
  if (currentSport === 'darts') {
    const totalLegWins   = games.reduce((s,g) => s + (+(g.leg_wins||0)), 0);
    const totalThrows    = games.reduce((s,g) => s + (+(g.total_throws||0)), 0);
    const matchWins      = games.filter(g => +g.is_winner === 1).length;
    // Weighted average of throw_avg across matches
    const throwAvg = games.length > 0
      ? (games.reduce((s,g) => s + (+(g.throw_avg||0)), 0) / games.length).toFixed(1)
      : 0;
    const winPct = games.length > 0 ? ((matchWins/games.length)*100).toFixed(0) : 0;

    // Leg wins per match bar chart
    const maxLegs = Math.max(...games.map(g => +(g.leg_wins||0)), 1);
    const legBars = games.map((g, i) => {
      const val = +(g.leg_wins||0);
      const h = Math.max((val/maxLegs*120), 3).toFixed(0);
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : `G${i+1}`;
      return `<div class="bar-group"><div class="bar-col" style="height:${h}px">
        <div class="bar-fill purple" style="height:100%" data-val="${val}"></div></div>
        <div class="bar-label">${date}</div></div>`;
    }).join('');

    const tableRows = games.map(g => {
      const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
      return `<tr>
        <td>${date}</td>
        <td>${escHtml(g.game_type||'—')}</td>
        <td class="val-yellow">${+(g.leg_wins||0)}</td>
        <td>${+(g.total_throws||0)}</td>
        <td>${+(g.throw_avg||0)}</td>
        <td><span class="${+g.is_winner?'win':'loss'}">${+g.is_winner?'WIN':'LOSS'}</span></td>
      </tr>`;
    }).join('');

    return `<div class="detail-inner fade-up">
      <div class="detail-header">
        <div><div class="detail-player-name">${escHtml(name)}</div>
        <div style="color:var(--muted);font-size:.83rem;margin-top:4px">${games.length} game${games.length!==1?'s':''} · ${games[0]?.team_name ? 'Team '+games[0].team_name : ''}</div></div>
        <div class="detail-sport-badge">${sportMeta.icon} ${sportMeta.label}</div>
      </div>
      <div class="stat-cards">
        <div class="stat-card"><div class="s-val">${games.length}</div><div class="s-label">MATCHES</div></div>
        <div class="stat-card"><div class="s-val" style="color:var(--success)">${matchWins}</div><div class="s-label">WINS</div></div>
        <div class="stat-card"><div class="s-val">${totalLegWins}</div><div class="s-label">LEG W</div></div>
        <div class="stat-card"><div class="s-val">${totalThrows}</div><div class="s-label">THROWS</div></div>
        <div class="stat-card"><div class="s-val">${throwAvg}</div><div class="s-label">AVG</div></div>
        <div class="stat-card"><div class="s-val">${winPct}%</div><div class="s-label">WIN %</div></div>
      </div>
      <div class="chart-section"><div class="chart-title">Leg Wins Per Match</div>
        <div class="chart-wrap"><div class="bar-chart">${legBars}</div></div>
      </div>
      <div class="chart-section"><div class="chart-title">Match History</div>
        <div class="chart-wrap game-table-wrap">
          <table class="game-table">
            <thead><tr><th>Date</th><th>Game Type</th><th>Leg W</th><th>Throws</th><th>Avg</th><th>Result</th></tr></thead>
            <tbody>${tableRows}</tbody>
          </table>
        </div>
      </div>
    </div>`;
  }

  // ── BASKETBALL & VOLLEYBALL (generic with totals) ──────────
  const totals = {};
  meta.forEach(m => {
    const rawKey = m.key.replace('total_', '');
    totals[m.key] = games.reduce((s, g) => s + (+(g[rawKey] ?? g[m.key] ?? 0)), 0);
  });
  totals.games_played = games.length;

  const statCards = meta.map(m => `
    <div class="stat-card"><div class="s-val">${totals[m.key] ?? 0}</div><div class="s-label">${m.label}</div></div>`).join('');

  // FIX: basketball uses created_at which is now saved_at aliased in API
  const primaryRaw = (meta[0]?.key || 'pts').replace('total_', '');
  const maxGame = Math.max(...games.map(g => +(g[primaryRaw] ?? 0)), 1);
  const barCols = games.map((g, i) => {
    const val = +(g[primaryRaw] ?? 0);
    const h = maxGame > 0 ? Math.max((val / maxGame * 120), 3) : 3;
    const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric'}) : `G${i+1}`;
    return `<div class="bar-group"><div class="bar-col" style="height:${h}px">
      <div class="bar-fill" style="height:100%" data-val="${val}"></div></div>
      <div class="bar-label">${date}</div></div>`;
  }).join('');

  const maxTot = Math.max(...meta.map(m => totals[m.key] ?? 0), 1);
  const mbarRows = meta.map(m => {
    const val = totals[m.key] ?? 0;
    const pct = maxTot > 0 ? (val / maxTot * 100).toFixed(1) : 0;
    return `<div class="mbar-row"><div class="mbar-label">${m.label}</div>
      <div class="mbar-track"><div class="mbar-fill" style="width:${pct}%;background:${m.color}">${val}</div></div>
      <div class="mbar-val">${val}</div></div>`;
  }).join('');

  const tableRows = games.map(g => {
    const opp = g.team === 'A' ? (g.team_b_name||'OPP') : (g.team_a_name||'OPP');
    const result = g.match_result || '';
    const rc = (result.includes('A') && g.team==='A') || (result.includes('B') && g.team==='B') ? 'win' : 'loss';
    const date = g.created_at ? new Date(g.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
    const statsRow = meta.map(m => { const rawKey = m.key.replace('total_',''); return `<td class="val-yellow">${g[rawKey]??0}</td>`; }).join('');
    return `<tr><td>${date}</td><td>${escHtml(opp)}</td><td><span class="${rc}">${rc==='win'?'WIN':'LOSS'}</span></td>${statsRow}</tr>`;
  }).join('');

  const tableHeaders = meta.map(m => `<th>${m.label}</th>`).join('');
  const teamLabel = games[0]?.team ? `Team ${games[0].team}` : (games[0]?.team_name || '');
  return `<div class="detail-inner fade-up">
    <div class="detail-header">
      <div><div class="detail-player-name">${escHtml(name)}</div>
      <div style="color:var(--muted);font-size:.83rem;margin-top:4px">${games.length} game${games.length!==1?'s':''}${teamLabel?' · '+teamLabel:''}</div></div>
      <div class="detail-sport-badge">${sportMeta.icon} ${sportMeta.label}</div>
    </div>
    <div class="stat-cards">${statCards}
      <div class="stat-card"><div class="s-val">${games.length}</div><div class="s-label">GAMES</div></div>
    </div>
    <div class="chart-section"><div class="chart-title">${meta[0]?.label||'PTS'} Per Game</div>
      <div class="chart-wrap"><div class="bar-chart">${barCols}</div></div>
    </div>
    <div class="chart-section"><div class="chart-title">Career Stats Breakdown</div>
      <div class="chart-wrap"><div class="multi-bar-chart">${mbarRows}</div></div>
    </div>
    <div class="chart-section"><div class="chart-title">Game History</div>
      <div class="chart-wrap game-table-wrap">
        <table class="game-table">
          <thead><tr><th>Date</th><th>Opponent</th><th>Result</th>${tableHeaders}</tr></thead>
          <tbody>${tableRows}</tbody>
        </table>
      </div>
    </div>
  </div>`;
}

// ============================================================
//  TEAM ANALYTICS
// ============================================================
function setTeamSport(sport, btn) {
  currentTeamSport = sport;
  selectedTeam = null;
  allTeams = [];
  document.querySelectorAll('#teamSportTabs .sport-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('teamDetailPanel').innerHTML = `
    <div class="loading-state" style="min-height:500px">
      <div class="empty-icon">🏆</div><div class="empty-text">Select a team to view analytics</div>
    </div>`;
  loadTeams(sport);
}

async function loadTeams(sport) {
  const listEl = document.getElementById('teamList');
  listEl.innerHTML = '<div class="loading-state"><div class="loading-spinner"></div></div>';
  const data = await apiFetch({ action: 'team_analytics', sport });
  if (!data?.success) {
    listEl.innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">No team data available</div></div>';
    return;
  }
  allTeams = data.data || [];
  renderTeamList(allTeams);
}

function renderTeamList(teams) {
  const listEl = document.getElementById('teamList');
  document.getElementById('teamCount').textContent = teams.length;
  if (!teams.length) {
    listEl.innerHTML = '<div class="empty-state"><div class="empty-icon">🏆</div><div class="empty-text">No teams found</div></div>';
    return;
  }
  const meta = SPORT_META[currentTeamSport];
  listEl.innerHTML = teams.map((t, i) => {
    const wins   = +(t.wins  || 0);
    const losses = +(t.losses || 0);
    const total  = +(t.matches_played || 0);
    const pct    = total > 0 ? ((wins/total)*100).toFixed(0) : 0;
    const rankClass = i === 0 ? 'rank-badge gold' : i === 1 ? 'rank-badge silver' : i === 2 ? 'rank-badge bronze' : 'rank-badge';
    return `<div class="team-item ${selectedTeam === t.team_name ? 'active' : ''}" onclick="selectTeam('${escHtml(t.team_name)}')">
      <div class="team-avatar">${meta?.icon || '🏆'}</div>
      <div class="team-info">
        <div class="team-name-text">${escHtml(t.team_name)}</div>
        <div class="team-meta-text">${total} match${total!==1?'es':''} · ${pct}% win rate</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px">
        <div class="team-wins-badge">${wins}W</div>
        <div class="team-loss-badge">${losses}L</div>
      </div>
    </div>`;
  }).join('');
}

function filterTeams(q) {
  const filtered = allTeams.filter(t => t.team_name.toLowerCase().includes(q.toLowerCase()));
  renderTeamList(filtered);
}

async function selectTeam(teamName) {
  selectedTeam = teamName;
  document.querySelectorAll('.team-item').forEach(el => {
    el.classList.toggle('active', el.querySelector('.team-name-text')?.textContent === teamName);
  });
  const panel = document.getElementById('teamDetailPanel');
  panel.innerHTML = '<div class="loading-state" style="min-height:500px"><div class="loading-spinner"></div></div>';
  const data = await apiFetch({ action: 'team_info', sport: currentTeamSport, team_name: teamName });
  if (!data?.success) {
    panel.innerHTML = '<div class="empty-state" style="min-height:500px"><div class="empty-icon">⚠️</div><div class="empty-text">Failed to load team data</div></div>';
    return;
  }
  panel.innerHTML = renderTeamDetail(data.data);
}

function renderTeamDetail(d) {
  const meta = SPORT_META[d.sport];
  const wins   = +(d.wins   || 0);
  const losses = +(d.losses || 0);
  const total  = wins + losses;
  const winPct = total > 0 ? ((wins/total)*100).toFixed(0) : 0;

  // W/L bar
  const wPct = total > 0 ? (wins/total*100).toFixed(1) : 0;
  const lPct = total > 0 ? (losses/total*100).toFixed(1) : 0;

  // Stat cards — order: MATCHES | LOSSES (left) | WINS (right) | WIN RATE
  // This mirrors the W/L bar where losses fill from the right and wins from the left.
  let statCardsHtml = `
    <div class="stat-card"><div class="s-val">${total}</div><div class="s-label">MATCHES</div></div>
    <div class="stat-card"><div class="s-val" style="color:var(--red)">${losses}</div><div class="s-label">LOSSES</div></div>
    <div class="stat-card"><div class="s-val" style="color:var(--green)">${wins}</div><div class="s-label">WINS</div></div>
    <div class="stat-card"><div class="s-val">${winPct}%</div><div class="s-label">WIN RATE</div></div>`;

  if (d.avg_pts_for) {
    statCardsHtml += `
      <div class="stat-card"><div class="s-val">${d.avg_pts_for}</div><div class="s-label">AVG PTS FOR</div></div>
      <div class="stat-card"><div class="s-val" style="color:var(--muted)">${d.avg_pts_against}</div><div class="s-label">AVG PTS AGAINST</div></div>`;
  }

  // Roster table
  const rosterRows = (d.roster || []).map((p, i) => {
    const initials = p.player_name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
    const statsHtml = buildRosterStatCells(d.sport, p);
    return `<tr>
      <td><span class="rank-badge ${i===0?'gold':i===1?'silver':i===2?'bronze':''}">${i+1}</span></td>
      <td><span class="player-initial-badge">${initials}</span>${escHtml(p.player_name)}</td>
      <td class="val-yellow">${p.games || 0}</td>
      ${statsHtml}
    </tr>`;
  }).join('');

  const rosterHeaders = buildRosterHeaders(d.sport);

  // Match history — sport-aware W/L detection
  // Basketball/Volleyball: match_result prefix 'A' or 'B' indicates the winner's side.
  // Badminton/TT: no match_result; winner_name holds the player name or team name that won.
  // Darts: winner_name holds the winning player's name; check against our roster.
  const rosterNames = new Set((d.roster || []).map(p => p.player_name));

  const matchRows = (d.matches || []).map(m => {
    const date = m.created_at ? new Date(m.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
    let won = false;
    let opponentHtml = '';
    let scoreHtml = '';

    if (d.sport === 'basketball' || d.sport === 'volleyball') {
      const isHome = m.team_a_name === d.team_name;
      const opponent = isHome ? m.team_b_name : m.team_a_name;
      const res = m.match_result || '';
      won = (isHome && res.startsWith('A')) || (!isHome && res.startsWith('B'));
      const scoreFor = isHome ? (m.team_a_score ?? '—') : (m.team_b_score ?? '—');
      const scoreAga = isHome ? (m.team_b_score ?? '—') : (m.team_a_score ?? '—');
      opponentHtml = escHtml(opponent || '—');
      scoreHtml = `<td class="val-yellow">${scoreFor}</td><td class="val-blue">${scoreAga}</td>`;
    } else if (d.sport === 'badminton' || d.sport === 'table_tennis') {
      const isHome  = m.team_a_name === d.team_name;
      const opponent = isHome ? m.team_b_name : m.team_a_name;
      // winner_name may be a player name or team name
      const p1 = isHome ? (m.team_a_player1||'') : (m.team_b_player1||'');
      const p2 = isHome ? (m.team_a_player2||'') : (m.team_b_player2||'');
      const wn = m.winner_name || '';
      won = wn === d.team_name || wn === p1 || wn === p2;
      opponentHtml = escHtml(opponent || '—');
      scoreHtml = `<td colspan="2" style="color:var(--muted);font-size:.75rem">${escHtml(m.match_type||'—')}</td>`;
    } else if (d.sport === 'darts') {
      // Darts match: players field is a CSV of all players; opponent = non-team players
      const wn = m.winner_name || '';
      won = rosterNames.has(wn);
      opponentHtml = escHtml(m.players || '—');
      scoreHtml = `<td colspan="2" style="color:var(--muted);font-size:.75rem">${escHtml(m.game_type||'—')}</td>`;
    }

    return `<tr>
      <td>${date}</td>
      <td>${opponentHtml}</td>
      <td><span class="${won?'win':'loss'}">${won?'WIN':'LOSS'}</span></td>
      ${scoreHtml}
    </tr>`;
  }).join('');

  return `<div class="detail-inner fade-up">
    <div class="detail-header">
      <div>
        <div class="detail-player-name">${escHtml(d.team_name)}</div>
        <div style="color:var(--muted);font-size:.83rem;margin-top:4px">${meta?.icon} ${meta?.label} Team</div>
      </div>
      <div class="detail-sport-badge">${meta?.icon} ${meta?.label}</div>
    </div>

    <div class="stat-cards">${statCardsHtml}</div>

    <div class="chart-section">
      <div class="chart-title">Win / Loss Record</div>
      <div class="chart-wrap">
        <div style="margin-bottom:8px;display:flex;align-items:center;gap:12px">
          <span style="color:var(--red);font-weight:600;font-family:var(--font-mono)">${losses}L</span>
          <div class="wl-bar" style="flex:1">
            <div class="wl-fill-w" style="width:${wPct}%"></div>
            <div class="wl-fill-l" style="width:${lPct}%"></div>
          </div>
          <span style="color:var(--green);font-weight:600;font-family:var(--font-mono)">${wins}W</span>
        </div>
        <div style="color:var(--muted);font-size:.78rem;text-align:center">${winPct}% win rate over ${total} match${total!==1?'es':''}</div>
      </div>
    </div>

    ${d.roster?.length ? `
    <div class="chart-section">
      <div class="chart-title">Roster</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="roster-table">
          <thead><tr><th>#</th><th>Player</th><th>Games</th>${rosterHeaders}</tr></thead>
          <tbody>${rosterRows}</tbody>
        </table>
      </div>
    </div>` : ''}

    ${d.matches?.length ? `
    <div class="chart-section">
      <div class="chart-title">Recent Match History</div>
      <div class="chart-wrap" style="overflow-x:auto">
        <table class="game-table">
          <thead><tr><th>Date</th><th>Opponent</th><th>Result</th><th>Score</th><th>Against</th></tr></thead>
          <tbody>${matchRows}</tbody>
        </table>
      </div>
    </div>` : ''}
  </div>`;
}

function buildRosterHeaders(sport) {
  if (sport === 'basketball') return '<th>PTS</th><th>REB</th><th>AST</th>';
  if (sport === 'volleyball') return '<th>PTS</th><th>SPK</th><th>ACE</th>';
  if (sport === 'badminton' || sport === 'table_tennis') return '<th>Wins</th>';
  if (sport === 'darts') return '<th>Wins</th>';
  return '';
}

function buildRosterStatCells(sport, p) {
  if (sport === 'basketball') return `<td class="val-yellow">${p.total_pts||0}</td><td>${p.total_reb||0}</td><td>${p.total_ast||0}</td>`;
  if (sport === 'volleyball') return `<td class="val-yellow">${p.total_pts||0}</td><td>${p.total_spike||0}</td><td>${p.total_ace||0}</td>`;
  if (sport === 'badminton' || sport === 'table_tennis') return `<td class="val-yellow">${p.wins||0}</td>`;
  if (sport === 'darts') return `<td class="val-yellow">${p.wins||0}</td>`;
  return '';
}

// ============================================================
//  SPORTS TRENDS
// ============================================================
function setSportAnalytics(sport, btn) {
  currentSportAnalytics = sport;
  document.querySelectorAll('#sportTabs2 .sport-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadSportAnalytics(sport);
}

async function loadSportAnalytics(sport) {
  document.getElementById('topPerformersContent').innerHTML = '<div class="loading-state"><div class="loading-spinner"></div></div>';
  document.getElementById('sportTrendsContent').innerHTML   = '<div class="loading-state"><div class="loading-spinner"></div></div>';
  const [playersData, trendsData] = await Promise.all([
    apiFetch({ action: 'players', sport }),
    apiFetch({ action: 'sport_trends', sport }),
  ]);
  renderTopPerformers(sport, playersData?.data || []);
  renderSportTrends(sport, trendsData?.data || []);
}

function renderTopPerformers(sport, players) {
  const el = document.getElementById('topPerformersContent');
  const top5 = players.slice(0, 5);
  if (!top5.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">👤</div><div class="empty-text">No data yet</div></div>'; return; }
  const meta = STAT_META[sport] || [];
  const rankClasses = ['gold','silver','bronze','',''];
  const headers = meta.slice(0,3).map(m => `<th>${m.label}</th>`).join('');
  const rows = top5.map((p,i) => {
    const name = p.player_name || p.winner_name || '—';
    const statCells = meta.slice(0,3).map(m => `<td class="val-yellow">${p[m.key]??p.wins??0}</td>`).join('');
    return `<tr>
      <td><span class="rank-badge ${rankClasses[i]}">${i+1}</span></td>
      <td><strong>${escHtml(name)}</strong></td>${statCells}</tr>`;
  }).join('');
  el.innerHTML = `<table class="top-table"><thead><tr><th>#</th><th>Player</th>${headers}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderSportTrends(sport, trends) {
  const el = document.getElementById('sportTrendsContent');
  if (!trends.length) { el.innerHTML = '<div class="empty-state"><div class="empty-icon">📈</div><div class="empty-text">No trend data yet</div></div>'; return; }
  const maxGames = Math.max(...trends.map(t => +(t.games??0)), 1);
  const maxA = Math.max(...trends.map(t => +(t.avg_a??t.wins??0)), 1);
  const hasScores = trends[0]?.avg_a !== undefined;
  const bars = trends.slice(0,10).reverse().map(t => {
    const gPct = (+t.games / maxGames * 90).toFixed(0);
    const date = t.game_date ? new Date(t.game_date+'T00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'}) : '—';
    return `<div class="bar-group"><div class="bar-col" style="height:${gPct}px">
      <div class="bar-fill blue" style="height:100%" data-val="${t.games} game(s)"></div></div>
      <div class="bar-label">${date}</div></div>`;
  }).join('');
  const tableRows = trends.slice(0,8).map(t => {
    const date = t.game_date ? new Date(t.game_date+'T00:00').toLocaleDateString('en-US',{month:'short',day:'numeric',year:'2-digit'}) : '—';
    const extra = t.avg_a !== undefined
      ? `<td class="val-yellow">${(+t.avg_a).toFixed(0)}</td><td class="val-blue">${(+t.avg_b).toFixed(0)}</td>`
      : t.match_type ? `<td colspan="2" class="val-yellow">${t.match_type||t.game_type||'—'}</td>`
      : '<td colspan="2">—</td>';
    return `<tr><td>${date}</td><td class="val-yellow">${t.games}</td>${extra}</tr>`;
  }).join('');
  const hasTeams = trends[0]?.avg_a !== undefined;
  const extraHead = hasTeams ? '<th>Avg A</th><th>Avg B</th>' : '<th colspan="2">Type</th>';
  el.innerHTML = `
    <div class="chart-wrap" style="margin-bottom:16px"><div class="bar-chart">${bars}</div></div>
    <table class="game-table">
      <thead><tr><th>Date</th><th>Games</th>${extraHead}</tr></thead>
      <tbody>${tableRows}</tbody>
    </table>`;
}

// ============================================================
//  UTILS
// ============================================================
async function apiFetch(params) {
  try {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(`${API}?${qs}`);
    const txt = await res.text();
    if (!res.ok) { try { return JSON.parse(txt); } catch(e) { return { success:false, error:`HTTP ${res.status}` }; } }
    try { return JSON.parse(txt); } catch(e) { return { success:false, error:'Invalid JSON' }; }
  } catch (e) { return null; }
}
function escHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

init();
</script>
</body>
</html>