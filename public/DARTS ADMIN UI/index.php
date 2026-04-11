<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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

  /* REPORT MODAL */
  #report-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 150; overflow-y: auto; padding: 20px; }
  #report-modal.show { display: block; }
  #report-content { max-width: 900px; margin: 0 auto; background: #1a1a1a; border: 2px solid var(--yellow); border-radius: 8px; padding: 24px; }
  #report-content h1 { color: var(--yellow); text-align: center; border-bottom: 2px solid var(--yellow); padding-bottom: 12px; margin-bottom: 20px; }
  #report-content h2 { color: var(--yellow); margin: 20px 0 10px; }
  #report-content table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: .88rem; }
  #report-content th { background: #222; color: var(--yellow); padding: 8px 10px; border: 1px solid #444; text-align: left; }
  #report-content td { padding: 6px 10px; border: 1px solid #333; }
  #report-content tr:nth-child(even) td { background: #111; }
  .report-export-btns { display: flex; gap: 10px; flex-wrap: wrap; margin: 16px 0; }
  .report-export-btns button { background: var(--red); color: #fff; border: none; padding: 10px 18px; cursor: pointer; font-weight: bold; border-radius: 4px; }
  .report-export-btns button:hover { filter: brightness(1.2); }
  .close-report-btn { background: #333; color: var(--yellow); border: 1px solid var(--yellow); padding: 10px 18px; cursor: pointer; font-weight: bold; border-radius: 4px; }

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
    <button class="nav-btn" onclick="location.href='../landingpage.php'">← Back to sports</button>
    <button class="nav-btn" onclick="saveCurrentLeg()">💾 Save File</button>
    <button class="nav-btn" onclick="location.href='history.html'">📋 History</button>
    <button class="nav-btn" onclick="newMatch()">🔄 New Match</button>
  </div>
</div>

<!-- SETTINGS -->
<div id="settings">
  <div class="setting-group">
    <label>GAME:</label>
    <div style="display:flex">
      <button class="seg-btn active" data-gt="301" onclick="setGameType('301',this)">301</button>
      <button class="seg-btn" data-gt="501" onclick="setGameType('501',this)">501</button>
      <button class="seg-btn" data-gt="701" onclick="setGameType('701',this)">701</button>
    </div>
  </div>
  <div class="setting-group">
    <label>LEGS TO WIN:</label>
    <input type="number" id="legs-to-win-input" value="3" min="1" max="9" onchange="legsToWin=+this.value">
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
<div id="report-modal">
  <div id="report-content">
    <div id="report-inner"></div>
    <div style="margin-top:16px">
      <button class="close-report-btn" onclick="closeReport()">✕ Close Report</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script>
// ================================================================
// STATE
// ================================================================
let gameType = 301;
let legsToWin = 3;
let mode = 'one-sided';
let currentPlayer = 0; // index 0-3
let inputStr = '';
let matchId = null;
let currentLeg = 1;

const COLORS = ['#CC0000','#003399','#FFE600','#E65C00'];
const TEXT_COLORS = ['#fff','#fff','#000','#fff'];
const DEFAULT_NAMES = ['PLAYER 1','PLAYER 2','PLAYER 3','PLAYER 4'];

let players = [0,1,2,3].map(i => ({
  playerNumber: i+1,
  name: DEFAULT_NAMES[i],
  team: 'TEAM',
  score: gameType,
  legsWon: 0,
  throws: [],        // [{value, scoreBefore, scoreAfter, isBust}]
  undoStack: [],
  redoStack: [],
  saveEnabled: true,
  dbPlayerId: null,
}));

// ================================================================
// RENDER
// ================================================================
function renderCards() {
  const area = document.getElementById('cards-area');
  area.innerHTML = '';
  area.className = mode === 'two-sided' ? 'two-sided' : '';

  if (mode === 'two-sided') {
    const left = document.createElement('div');
    left.className = 'side-group';
    const right = document.createElement('div');
    right.className = 'side-group';
    players.forEach((p, i) => {
      const card = buildCard(p, i);
      (i < 2 ? left : right).appendChild(card);
    });
    area.appendChild(left);
    area.appendChild(right);
  } else {
    players.forEach((p, i) => area.appendChild(buildCard(p, i)));
  }
}

function buildCard(p, i) {
  const card = document.createElement('div');
  card.className = 'player-card' + (i === currentPlayer ? ' active-card' : '');
  card.id = 'card-' + i;
  card.onclick = () => selectPlayer(i);

  // Header
  const hdr = document.createElement('div');
  hdr.className = 'card-header';
  hdr.style.background = COLORS[i];

  const nameWrap = document.createElement('div');
  nameWrap.className = 'player-names';

  const nameInp = document.createElement('input');
  nameInp.className = 'player-name-edit';
  nameInp.value = p.name;
  nameInp.style.color = TEXT_COLORS[i];
  nameInp.onclick = e => e.stopPropagation();
  nameInp.onchange = e => { p.name = e.target.value.toUpperCase(); };
  nameInp.onblur = e => { p.name = e.target.value.toUpperCase(); };

  const teamInp = document.createElement('input');
  teamInp.className = 'team-name-edit';
  teamInp.value = p.team;
  teamInp.style.color = TEXT_COLORS[i] === '#000' ? '#333' : 'rgba(255,255,255,.7)';
  teamInp.onclick = e => e.stopPropagation();
  teamInp.onchange = e => { p.team = e.target.value; };

  nameWrap.appendChild(nameInp);
  nameWrap.appendChild(teamInp);

  const saveWrap = document.createElement('div');
  saveWrap.className = 'save-checkbox-wrap';
  const saveChk = document.createElement('input');
  saveChk.type = 'checkbox';
  saveChk.checked = p.saveEnabled;
  saveChk.onclick = e => e.stopPropagation();
  saveChk.onchange = e => { p.saveEnabled = e.target.checked; };
  const saveLabel = document.createElement('span');
  saveLabel.textContent = 'SAVE';
  saveLabel.style.color = TEXT_COLORS[i];
  saveWrap.appendChild(saveChk);
  saveWrap.appendChild(saveLabel);

  hdr.appendChild(nameWrap);
  hdr.appendChild(saveWrap);
  card.appendChild(hdr);

  // Score
  const scoreArea = document.createElement('div');
  scoreArea.className = 'score-area';
  const scoreNum = document.createElement('div');
  scoreNum.className = 'score-number';
  scoreNum.id = 'score-' + i;
  scoreNum.textContent = p.score;
  const scoreLabel = document.createElement('div');
  scoreLabel.className = 'score-label';
  scoreLabel.textContent = 'LEG TRACKER';
  scoreArea.appendChild(scoreNum);
  scoreArea.appendChild(scoreLabel);
  card.appendChild(scoreArea);

  // Leg won
  const lwArea = document.createElement('div');
  lwArea.className = 'leg-won-area';
  const lwLabel = document.createElement('div');
  lwLabel.className = 'leg-won-label';
  lwLabel.textContent = 'LEG WON';
  const lwCounter = document.createElement('div');
  lwCounter.className = 'leg-won-counter';

  const minusBtn = document.createElement('button');
  minusBtn.className = 'lw-btn lw-minus';
  minusBtn.textContent = '−';
  minusBtn.onclick = e => { e.stopPropagation(); p.legsWon = Math.max(0, p.legsWon-1); updateCard(i); };

  const countSpan = document.createElement('div');
  countSpan.className = 'leg-won-count';
  countSpan.id = 'legs-won-' + i;
  countSpan.textContent = p.legsWon;

  const plusBtn = document.createElement('button');
  plusBtn.className = 'lw-btn lw-plus';
  plusBtn.textContent = '+';
  plusBtn.onclick = e => { e.stopPropagation(); p.legsWon++; updateCard(i); };

  lwCounter.appendChild(minusBtn);
  lwCounter.appendChild(countSpan);
  lwCounter.appendChild(plusBtn);
  lwArea.appendChild(lwLabel);
  lwArea.appendChild(lwCounter);
  card.appendChild(lwArea);

  // Last throws
  const ltArea = document.createElement('div');
  ltArea.className = 'last-throws-area';
  ltArea.id = 'throws-' + i;
  renderThrowChips(p, ltArea);
  card.appendChild(ltArea);

  return card;
}

function renderThrowChips(p, container) {
  container.innerHTML = '';
  const last4 = p.throws.slice(-4);
  last4.forEach(t => {
    const chip = document.createElement('span');
    chip.className = 'throw-chip' + (t.isBust ? ' bust' : '');
    chip.textContent = t.isBust ? 'BUST' : t.value;
    container.appendChild(chip);
  });
}

function updateCard(i) {
  const p = players[i];
  const scoreEl = document.getElementById('score-' + i);
  if (scoreEl) scoreEl.textContent = p.score;
  const lwEl = document.getElementById('legs-won-' + i);
  if (lwEl) lwEl.textContent = p.legsWon;
  const chipsEl = document.getElementById('throws-' + i);
  if (chipsEl) renderThrowChips(p, chipsEl);
  // active glow
  const card = document.getElementById('card-' + i);
  if (card) {
    card.className = 'player-card' + (i === currentPlayer ? ' active-card' : '');
  }
  updateArrowBtns();
}

function updateArrowBtns() {
  const p = players[currentPlayer];
  document.getElementById('undo-btn').disabled = p.throws.length === 0;
  document.getElementById('redo-btn').disabled = p.redoStack.length === 0;
}

// ================================================================
// PLAYER SELECTION
// ================================================================
function selectPlayer(i) {
  currentPlayer = i;
  players.forEach((_, idx) => {
    const c = document.getElementById('card-' + idx);
    if (c) c.className = 'player-card' + (idx === i ? ' active-card' : '');
  });
  updateArrowBtns();
}

// ================================================================
// NUMPAD
// ================================================================
function padPress(digit) {
  if (inputStr.length >= 3) return;
  inputStr += digit;
  document.getElementById('throw-display').textContent = inputStr || '0';
}

function padClear() {
  inputStr = '';
  document.getElementById('throw-display').textContent = '0';
}

function enterThrow() {
  const val = parseInt(inputStr, 10);
  if (isNaN(val) || val < 0 || val > 180) { padClear(); return; }
  padClear();

  const p = players[currentPlayer];
  const before = p.score;
  const after = before - val;

  let isBust = false;
  let finalScore = after;

  if (after < 0 || after === 1) {
    isBust = true;
    finalScore = before;
  }

  const throwEntry = { value: val, scoreBefore: before, scoreAfter: finalScore, isBust };
  p.throws.push(throwEntry);
  p.redoStack = []; // clear redo on new throw
  p.score = finalScore;
  updateCard(currentPlayer);

  if (!isBust && after === 0) {
    // LEG WON
    triggerLegWon(currentPlayer);
  }
}

// Keyboard support
document.addEventListener('keydown', e => {
  if (e.key >= '0' && e.key <= '9') padPress(e.key);
  else if (e.key === 'Enter') enterThrow();
  else if (e.key === 'Backspace' || e.key === 'Delete' || e.key.toLowerCase() === 'c') padClear();
  else if (e.key === 'ArrowLeft') undoThrow();
  else if (e.key === 'ArrowRight') redoThrow();
  else if (e.key >= '1' && e.key <= '4' && e.altKey) selectPlayer(parseInt(e.key)-1);
});

// ================================================================
// UNDO / REDO
// ================================================================
function undoThrow() {
  const p = players[currentPlayer];
  if (!p.throws.length) return;
  const last = p.throws.pop();
  p.redoStack.push(last);
  p.score = last.scoreBefore;
  updateCard(currentPlayer);
}

function redoThrow() {
  const p = players[currentPlayer];
  if (!p.redoStack.length) return;
  const t = p.redoStack.pop();
  p.throws.push(t);
  p.score = t.scoreAfter;
  updateCard(currentPlayer);
}

// ================================================================
// LEG WON
// ================================================================
function triggerLegWon(playerIdx) {
  const p = players[playerIdx];
  p.legsWon++;
  updateCard(playerIdx);

  autoSaveLeg(playerIdx, true, () => {
    if (p.legsWon >= legsToWin) {
      triggerMatchWon(playerIdx);
    } else {
      showModal(
        `🏆 ${p.name} Wins the Leg!`,
        `Leg ${currentLeg} complete. Total legs won: ${p.legsWon}`,
        [{label: 'Start Next Leg', cls: '', cb: startNextLeg}]
      );
    }
  });
}

function startNextLeg() {
  currentLeg++;
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
  });
  closeModal();
  renderCards();
}

// ================================================================
// MATCH WON
// ================================================================
function triggerMatchWon(playerIdx) {
  const p = players[playerIdx];
  autoSaveMatch(p);
  showModal(
    `🏆 ${p.name} Wins the Match!`,
    `Congratulations! ${p.name} has won ${p.legsWon} legs.`,
    [
      {label: 'View Report', cls: '', cb: () => { closeModal(); showReport(); }},
      {label: 'New Match', cls: 'secondary', cb: newMatch}
    ]
  );
}

// ================================================================
// SETTINGS
// ================================================================
function setGameType(gt, btn) {
  if (gameType === parseInt(gt)) return;
  const confirmed = players.some(p => p.throws.length > 0 || p.legsWon > 0)
    ? confirm(`Change game type to ${gt}? This will reset all scores and legs.`)
    : true;
  if (!confirmed) return;
  document.querySelectorAll('[data-gt]').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  gameType = parseInt(gt);
  resetAllScores();
}

function setMode(m, btn) {
  mode = m;
  btn.parentElement.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderCards();
}

function toggleDark(on) {
  document.body.classList.toggle('light-mode', !on);
  localStorage.setItem('darkMode', on ? '1' : '0');
}

function resetAllScores() {
  matchId = null;
  currentLeg = 1;
  players.forEach(p => {
    p.score = gameType;
    p.throws = [];
    p.undoStack = [];
    p.redoStack = [];
    p.legsWon = 0;
    p.dbPlayerId = null;
  });
  renderCards();
}

function newMatch() {
  if (!confirm('Start a new match? All current progress will be cleared.')) return;
  matchId = null;
  currentLeg = 1;
  players = [0,1,2,3].map(i => ({
    playerNumber: i+1,
    name: DEFAULT_NAMES[i],
    team: 'TEAM',
    score: gameType,
    legsWon: 0,
    throws: [],
    undoStack: [],
    redoStack: [],
    saveEnabled: true,
    dbPlayerId: null,
  }));
  closeModal();
  renderCards();
}

// ================================================================
// MODAL HELPERS
// ================================================================
function showModal(title, body, actions) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').textContent = body;
  const actDiv = document.getElementById('modal-actions');
  actDiv.innerHTML = '';
  actions.forEach(a => {
    const btn = document.createElement('button');
    btn.className = 'modal-btn ' + (a.cls || '');
    btn.textContent = a.label;
    btn.onclick = a.cb;
    actDiv.appendChild(btn);
  });
  document.getElementById('modal-overlay').classList.add('show');
}

function closeModal() {
  document.getElementById('modal-overlay').classList.remove('show');
}

// ================================================================
// TOAST
// ================================================================
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show';
  setTimeout(() => { t.className = ''; }, 2800);
}

// ================================================================
// SAVE LEG (auto + manual)
// ================================================================
function buildSavePayload(isCompleted, winnerIdx) {
  return {
    match_id: matchId,
    game_type: String(gameType),
    legs_to_win: legsToWin,
    mode: mode,
    leg_number: currentLeg,
    is_completed: isCompleted,
    players: players.map((p, i) => ({
      player_number: p.playerNumber,
      player_name: p.name,
      team_name: p.team,
      save_enabled: p.saveEnabled ? 1 : 0,
      is_winner: i === winnerIdx ? 1 : 0,
      throws: p.throws.map(t => ({
        throw_value: t.value,
        score_before: t.scoreBefore,
        score_after: t.scoreAfter,
        is_bust: t.isBust ? 1 : 0,
      }))
    }))
  };
}

function autoSaveLeg(winnerIdx, isCompleted, callback) {
  const payload = buildSavePayload(isCompleted, winnerIdx);
  fetch('save_leg.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      matchId = data.match_id;
      // Store DB player IDs
      if (data.player_ids) {
        players.forEach(p => {
          const dbId = data.player_ids[p.playerNumber];
          if (dbId) p.dbPlayerId = dbId;
        });
      }
    }
    if (callback) callback();
  })
  .catch(() => { if (callback) callback(); });
}

function saveCurrentLeg() {
  const payload = buildSavePayload(false, -1);
  fetch('save_leg.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      matchId = data.match_id;
      showToast('Leg progress saved.');
    } else {
      showToast('Save failed: ' + (data.message || 'unknown error'));
    }
  })
  .catch(() => showToast('Save failed (network error).'));
}

// ================================================================
// SAVE MATCH
// ================================================================
function autoSaveMatch(winnerPlayer) {
  if (!matchId) return;
  const winnerDbId = winnerPlayer.dbPlayerId;
  const payload = {
    match_id: matchId,
    total_legs: players.reduce((s, p) => s + p.legsWon, 0),
    legs_won: { p1: players[0].legsWon, p2: players[1].legsWon, p3: players[2].legsWon, p4: players[3].legsWon },
    winner_player_id: winnerDbId,
    winner_name: winnerPlayer.name,
  };
  fetch('save_match.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  }).catch(() => {});
}

// ================================================================
// REPORT (inline)
// ================================================================
function showReport() {
  const inner = document.getElementById('report-inner');
  inner.innerHTML = buildReportHTML();
  document.getElementById('report-modal').classList.add('show');
}

function closeReport() {
  document.getElementById('report-modal').classList.remove('show');
}

function buildReportHTML() {
  const totalLegs = players.reduce((s, p) => s + p.legsWon, 0);
  const winner = [...players].sort((a,b) => b.legsWon - a.legsWon)[0];
  const date = new Date().toLocaleString();

  // Compute avg throws
  // For inline report we use current session data — if matchId exists, link to PHP report
  let html = `<h1>🎯 ${gameType} Darts Match Report</h1>`;

  if (matchId) {
    html += `<div class="report-export-btns">
      <button onclick="window.open('report_export.php?match_id=${matchId}&format=html&download=1','_blank')">⬇ Save as HTML</button>
      <button onclick="window.open('report_export.php?match_id=${matchId}&format=excel','_blank')">⬇ Save as Excel</button>
      <button onclick="window.open('report_export.php?match_id=${matchId}&format=print','_blank')">🖨 Print / PDF</button>
    </div>`;
  }

  html += `<h2>Match Overview</h2>
  <table><tr><th>Game Type</th><th>Legs to Win</th><th>Mode</th><th>Date</th><th>Winner</th></tr>
  <tr><td>${gameType}</td><td>${legsToWin}</td><td>${mode}</td><td>${date}</td><td style="color:var(--green);font-weight:bold">${winner.name}</td></tr></table>`;

  html += `<h2>Player Roster</h2>
  <table><tr><th>Player #</th><th>Name</th><th>Team</th></tr>`;
  players.forEach((p,i) => {
    html += `<tr><td>${i+1}</td><td>${escH(p.name)}</td><td>${escH(p.team)}</td></tr>`;
  });
  html += `</table>`;

  html += `<h2>Final Standings</h2>
  <table><tr><th>Rank</th><th>Player</th><th>Team</th><th>Legs Won</th></tr>`;
  const sorted = [...players].sort((a,b) => b.legsWon - a.legsWon);
  sorted.forEach((p,i) => {
    html += `<tr><td>${i+1}</td><td style="${i===0?'color:var(--green);font-weight:bold':''}">${escH(p.name)}</td><td>${escH(p.team)}</td><td>${p.legsWon}</td></tr>`;
  });
  html += `</table>`;

  return html;
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ================================================================
// INIT
// ================================================================
(function init() {
  // Dark mode from localStorage
  const dark = localStorage.getItem('darkMode');
  const isDark = dark === null ? true : dark === '1';
  document.getElementById('dark-mode-toggle').checked = isDark;
  document.body.classList.toggle('light-mode', !isDark);

  renderCards();
  updateArrowBtns();
})();
</script>
</body>
</html>