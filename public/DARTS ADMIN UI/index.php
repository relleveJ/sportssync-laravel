<?php
// ── SportSync Guards ──────────────────────────────────────────
$_ss_db_loaded = false;
try {
    $__dbf = file_exists(__DIR__.'/db.php') ? __DIR__.'/db.php' : (file_exists(__DIR__.'/../db.php') ? __DIR__.'/../db.php' : null);
    if ($__dbf) { require_once $__dbf; $_ss_db_loaded = true; }
    $__authf = file_exists(__DIR__.'/auth.php') ? __DIR__.'/auth.php' : (file_exists(__DIR__.'/../auth.php') ? __DIR__.'/../auth.php' : null);
    if ($__authf) require_once $__authf;
} catch (Throwable $e) {}
if ($_ss_db_loaded && !empty($pdo)) {
    $__sg=null;foreach([__DIR__,__DIR__.'/..',__DIR__.'/../..'] as $__d){if(file_exists($__d.'/system_guard.php')){$__sg=$__d.'/system_guard.php';break;}}if($__sg) require_once $__sg;
    $_ss_is_admin = function_exists('currentUser') && in_array((currentUser()['role'] ?? ''), ['admin','superadmin'], true);
    ss_check_maintenance($pdo, $_ss_is_admin);
    ss_check_sport($pdo, 'Darts', $_ss_is_admin);
    if ($_ss_is_admin) { ss_render_banners(); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__ws_token = getenv('WS_TOKEN') ?: ''; ?>
<meta name="ws-token" content="<?php echo htmlspecialchars($__ws_token, ENT_QUOTES); ?>">
<title>🎯 Darts Iskorsit</title>
<style>
  :root {
    --bg: #111;
    --card-bg: #1a1a1a;
    --text: #f0f0f0;
    --subtext: #aaa;
    --border: #333;
    --yellow: #FFE600;
    --green: #00cc44;
    --red: #CC0000;
    --blue: #003399;
    --orange: #E65C00;
    --input-bg: #222;
  }
  .light-mode {
    --bg: #f4f4f4;
    --card-bg: #ffffff;
    --text: #111;
    --subtext: #555;
    --border: #ccc;
    --input-bg: #eee;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: Arial, Helvetica, sans-serif; min-height: 100vh; transition: background .2s, color .2s; }

  /* NAV */
  #nav { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: #000; border-bottom: 2px solid var(--yellow); flex-wrap: wrap; gap: 8px; }
  #nav h1 { color: var(--yellow); font-size: 1.3rem; letter-spacing: 2px; }
  #nav-btns { display: flex; gap: 8px; flex-wrap: wrap; }
  .nav-btn { background: #222; color: var(--yellow); border: 1px solid var(--yellow); padding: 6px 14px; cursor: pointer; font-weight: bold; font-size: .85rem; border-radius: 3px; }
  .nav-btn:hover { background: var(--yellow); color: #000; }

  /* SETTINGS BAR */
  #settings { background: #0a0a0a; border-bottom: 1px solid #333; padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
  .light-mode #settings { background: #e8e8e8; }
  .setting-group { display: flex; align-items: center; gap: 6px; font-size: .85rem; color: var(--subtext); }
  .setting-group label { font-weight: bold; color: var(--text); }
  .seg-btn { background: #222; color: #aaa; border: 1px solid #444; padding: 5px 12px; cursor: pointer; font-size: .82rem; font-weight: bold; }
  .light-mode .seg-btn { background: #ddd; color: #555; border-color: #bbb; }
  .seg-btn.active { background: var(--yellow); color: #000; border-color: var(--yellow); }
  .seg-btn:first-child { border-radius: 3px 0 0 3px; }
  .seg-btn:last-child { border-radius: 0 3px 3px 0; }
  #legs-to-win-input { width: 48px; background: var(--input-bg); color: var(--text); border: 1px solid #555; padding: 5px; text-align: center; font-size: .9rem; font-weight: bold; border-radius: 3px; }
  .toggle-switch { position: relative; display: inline-block; width: 42px; height: 22px; }
  .toggle-switch input { display: none; }
  .toggle-slider { position: absolute; inset: 0; background: #444; border-radius: 22px; cursor: pointer; transition: .2s; }
  .toggle-slider:before { content:''; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
  input:checked + .toggle-slider { background: var(--yellow); }
  input:checked + .toggle-slider:before { transform: translateX(20px); background: #000; }

  /* PLAYER CARDS AREA */
  #cards-area { display: flex; gap: 10px; padding: 12px; flex-wrap: wrap; }
  .player-card { flex: 1 1 200px; min-width: 160px; background: var(--card-bg); border: 2px solid #333; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; position: relative; cursor: pointer; transition: box-shadow .15s; }
  .player-card.active-card { box-shadow: 0 0 0 3px #FFE600, 0 0 18px #FFE60088; border-color: var(--yellow); }
  .card-header { padding: 8px 10px; display: flex; justify-content: space-between; align-items: flex-start; }
  .player-names { flex: 1; }
  .player-name-edit { font-size: 1rem; font-weight: bold; color: #fff; background: transparent; border: none; outline: none; width: 100%; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; }
  .player-name-edit:focus { border-bottom: 1px dashed rgba(255,255,255,.5); cursor: text; }
  .team-name-edit { font-size: .75rem; color: rgba(255,255,255,.7); background: transparent; border: none; outline: none; width: 100%; cursor: pointer; margin-top: 2px; }
  .team-name-edit:focus { border-bottom: 1px dashed rgba(255,255,255,.4); cursor: text; }
  .save-checkbox-wrap { display: flex; align-items: center; gap: 4px; font-size: .7rem; color: rgba(255,255,255,.7); white-space: nowrap; }
  .save-checkbox-wrap input[type=checkbox] { accent-color: var(--yellow); width: 14px; height: 14px; cursor: pointer; }

  /* Score display */
  .score-area { background: #0d0d0d; text-align: center; padding: 14px 8px; }
  .score-number { font-size: 3rem; font-weight: bold; color: var(--yellow); letter-spacing: 2px; font-variant-numeric: tabular-nums; line-height: 1; }
  .score-label { font-size: .65rem; color: #666; margin-top: 3px; text-transform: uppercase; letter-spacing: 1px; }

  /* Leg won */
  .leg-won-area { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-top: 1px solid #2a2a2a; }
  .leg-won-label { font-size: .65rem; color: var(--subtext); text-transform: uppercase; letter-spacing: 1px; }
  .leg-won-counter { display: flex; align-items: center; gap: 6px; }
  .leg-won-count { font-size: 1.4rem; font-weight: bold; color: var(--yellow); border: 2px solid var(--yellow); min-width: 38px; text-align: center; padding: 2px 6px; border-radius: 3px; }
  .lw-btn { width: 26px; height: 26px; border: none; border-radius: 3px; font-size: 1rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; line-height: 1; }
  .lw-plus { background: var(--green); color: #fff; }
  .lw-minus { background: var(--red); color: #fff; }

  /* Last throws chips */
  .last-throws-area { padding: 6px 10px 10px; display: flex; gap: 5px; flex-wrap: wrap; min-height: 36px; }
  .throw-chip { background: var(--yellow); color: #000; font-weight: bold; font-size: .8rem; padding: 3px 8px; border-radius: 3px; }
  .throw-chip.bust { background: var(--red); color: #fff; }

  /* INPUT PANEL */
  #input-panel { padding: 14px 16px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
  #input-panel h2 { font-size: .8rem; color: var(--subtext); letter-spacing: 2px; text-transform: uppercase; }
  #throw-input-row { display: flex; align-items: center; gap: 8px; }
  #throw-display { font-size: 2rem; font-weight: bold; color: var(--yellow); background: #000; border: 2px solid #444; padding: 8px 20px; min-width: 120px; text-align: center; border-radius: 4px; letter-spacing: 4px; }
  .arrow-btn { background: #222; color: var(--yellow); border: 2px solid #555; width: 44px; height: 44px; font-size: 1.3rem; cursor: pointer; border-radius: 4px; font-weight: bold; display: flex; align-items: center; justify-content: center; }
  .arrow-btn:hover { background: #333; }
  .arrow-btn:disabled { opacity: .3; cursor: default; }
  #numpad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; max-width: 220px; width: 100%; }
  .num-btn { height: 56px; font-size: 1.1rem; font-weight: bold; border: none; border-radius: 4px; cursor: pointer; transition: filter .1s; user-select: none; -webkit-tap-highlight-color: transparent; }
  .num-btn:active { filter: brightness(1.3); }
  .num-digit { background: var(--red); color: #fff; }
  .num-clear { background: #555; color: #fff; }
  .num-enter { background: var(--green); color: #fff; font-size: .9rem; }

  /* Two-sided layout */
  #cards-area.two-sided { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  #cards-area.two-sided .side-group { display: flex; flex-direction: column; gap: 10px; }

  /* MODAL */
  #modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.85); z-index: 100; align-items: center; justify-content: center; }
  #modal-overlay.show { display: flex; }
  #modal-box { background: #1a1a1a; border: 2px solid var(--yellow); border-radius: 8px; padding: 30px; max-width: 400px; width: 90%; text-align: center; }
  #modal-box h2 { color: var(--yellow); font-size: 1.5rem; margin-bottom: 10px; }
  #modal-box p { color: #ccc; margin-bottom: 20px; }
  .modal-btn { background: var(--yellow); color: #000; border: none; padding: 12px 24px; font-size: 1rem; font-weight: bold; cursor: pointer; border-radius: 4px; margin: 5px; }
  .modal-btn.secondary { background: #333; color: var(--yellow); border: 1px solid var(--yellow); }
  .modal-btn:hover { filter: brightness(1.1); }

  /* TOAST */
  #toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #222; color: var(--yellow); border: 1px solid var(--yellow); padding: 10px 24px; border-radius: 4px; font-weight: bold; display: none; z-index: 200; font-size: .9rem; }
  #toast.show { display: block; animation: fadeInOut 2.5s forwards; }
  @keyframes fadeInOut { 0%{opacity:0} 10%{opacity:1} 70%{opacity:1} 100%{opacity:0} }

  /* REPORT MODAL removed — server-side report is used instead */

  /* Responsive */
  @media (max-width: 768px) {
    #cards-area { flex-direction: row; flex-wrap: wrap; }
    .player-card { flex: 1 1 calc(50% - 10px); min-width: 140px; }
    #cards-area.two-sided { grid-template-columns: 1fr; }
    .score-number { font-size: 2.4rem; }
    #numpad { max-width: 200px; }
    .num-btn { height: 52px; font-size: 1rem; }
  }
  @media (max-width: 420px) {
    .player-card { flex: 1 1 100%; }
  }
  .winner-badge { color: var(--green); font-weight: bold; font-size: .75rem; }
</style>
</head>
<body>

<!-- NAV -->
<div id="nav">
  <h1>🎯 DARTS ISKORSIT</h1>
  <div id="nav-btns">
    <button class="nav-btn" onclick="location.href='/'">← Back to Dashboard</button>
    <button class="nav-btn" onclick="saveCurrentLeg()">💾 Save File</button>
    <button class="nav-btn" onclick="openDeclareModal()">🏁 Declare Winner</button>
    <button class="nav-btn" onclick="location.href='history.html'">📋 History</button>
    <button class="nav-btn" onclick="newMatch()">🆕 New Match</button>
    <button class="nav-btn" style="border-color:#e65c00;color:#e65c00" onclick="resetMatch()">🔄 Reset Match</button>
  </div>
</div>

<!-- SETTINGS -->
<div id="settings">
  <div class="setting-group">
    <label>GAME:</label>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
      <div style="display:flex">
        <button class="seg-btn active" data-gt="301" onclick="setGameType('301',this)">301</button>
        <button class="seg-btn" data-gt="501" onclick="setGameType('501',this)">501</button>
        <button class="seg-btn" data-gt="701" onclick="setGameType('701',this)">701</button>
      </div>
      <input type="number" id="custom-game-input" min="1" max="9999"
             placeholder="Custom"
             style="width:80px;background:var(--input-bg);color:var(--text);border:1px solid #555;padding:5px 6px;font-size:.85rem;font-weight:bold;border-radius:3px;"
             onchange="applyCustomGameType(this.value)"
             title="Enter any custom game value">
    </div>
  </div>
  <div class="setting-group">
    <label>LEGS TO WIN:</label>
    <input type="number" id="legs-to-win-input" value="3" min="1" max="99" onchange="applyLegsToWin(+this.value)">
  </div>
  <div class="setting-group">
    <label>MODE:</label>
    <div style="display:flex">
      <button class="seg-btn active" onclick="setMode('one-sided',this)">One-Sided</button>
      <button class="seg-btn" onclick="setMode('two-sided',this)">Two-Sided</button>
    </div>
  </div>
  <div class="setting-group">
    <label>DARK MODE:</label>
    <label class="toggle-switch">
      <input type="checkbox" id="dark-mode-toggle" checked onchange="toggleDark(this.checked)">
      <span class="toggle-slider"></span>
    </label>
  </div>
</div>

<!-- CARDS AREA -->
<div id="cards-area"></div>

<!-- INPUT PANEL -->
<div id="input-panel">
  <h2>Enter Throw Value</h2>
  <div id="throw-input-row">
    <button class="arrow-btn" id="undo-btn" onclick="undoThrow()" title="Undo">↩</button>
    <div id="throw-display">0</div>
    <button class="arrow-btn" id="redo-btn" onclick="redoThrow()" title="Redo">↪</button>
  </div>
  <div id="numpad">
    <button class="num-btn num-digit" onclick="padPress('7')">7</button>
    <button class="num-btn num-digit" onclick="padPress('8')">8</button>
    <button class="num-btn num-digit" onclick="padPress('9')">9</button>
    <button class="num-btn num-digit" onclick="padPress('4')">4</button>
    <button class="num-btn num-digit" onclick="padPress('5')">5</button>
    <button class="num-btn num-digit" onclick="padPress('6')">6</button>
    <button class="num-btn num-digit" onclick="padPress('1')">1</button>
    <button class="num-btn num-digit" onclick="padPress('2')">2</button>
    <button class="num-btn num-digit" onclick="padPress('3')">3</button>
    <button class="num-btn num-clear" onclick="padClear()">C</button>
    <button class="num-btn num-digit" onclick="padPress('0')">0</button>
    <button class="num-btn num-enter" onclick="enterThrow()">ENTER</button>
  </div>
</div>

<!-- LEG/MATCH MODAL -->
<div id="modal-overlay">
  <div id="modal-box">
    <h2 id="modal-title">Title</h2>
    <p id="modal-body">Body</p>
    <div id="modal-actions"></div>
  </div>
</div>

<!-- REPORT MODAL -->
<!-- report modal removed; server-side report page used -->

<!-- TOAST -->
<div id="toast"></div>

<script src="darst_admin.js?<?php echo time(); ?>"></script>
</body>
</html>