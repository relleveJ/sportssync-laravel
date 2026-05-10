<?php
// ============================================================
// volleyball_report.php — Volleyball Match Report
// Usage: volleyball_report.php?match_id=N
// ============================================================

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$user    = requireRole('viewer');
$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;

if ($matchId <= 0) {
    http_response_code(400);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#FFD700;font-family:monospace;padding:40px">Invalid or missing match_id.</body></html>';
    exit;
}

// Ensure PDO is available (when run under a wrapper controller $pdo should be provided)
if (!isset($pdo) || !$pdo) {
  http_response_code(500);
  echo '<!DOCTYPE html><html><body style="background:#111;color:#FFD700;font-family:monospace;padding:40px">Database unavailable. Please contact your administrator.</body></html>';
  exit;
}

// Fetch match
$stmt = $pdo->prepare('SELECT * FROM volleyball_matches WHERE match_id = ? LIMIT 1');
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body style="background:#111;color:#FFD700;font-family:monospace;padding:40px">Match ID ' . $matchId . ' not found.</body></html>';
    exit;
}

// Fetch players
$stmtP = $pdo->prepare('SELECT * FROM volleyball_players WHERE match_id = ? ORDER BY team, id ASC');
$stmtP->execute([$matchId]);
$allPlayers = $stmtP->fetchAll();

$playersA = array_filter($allPlayers, fn($p) => $p['team'] === 'A');
$playersB = array_filter($allPlayers, fn($p) => $p['team'] === 'B');

// If match row has no MVP stored, compute it here so every game shows an MVP
if (empty($match['mvp'])) {
  $mvpName = '';
  $mvpRubric = '';
  $mvpScore = -INF;
  foreach (array_merge($playersA, $playersB) as $p) {
    $pts   = isset($p['pts']) ? (int)$p['pts'] : 0;
    $spike = isset($p['spike']) ? (int)$p['spike'] : 0;
    $ace   = isset($p['ace']) ? (int)$p['ace'] : 0;
    $exSet = isset($p['ex_set']) ? (int)$p['ex_set'] : (isset($p['exSet']) ? (int)$p['exSet'] : 0);
    $exDig = isset($p['ex_dig']) ? (int)$p['ex_dig'] : (isset($p['exDig']) ? (int)$p['exDig'] : 0);
    $blk   = isset($p['blk']) ? (int)$p['blk'] : 0;
    $score = ($pts * 2) + ($spike * 1.2) + ($ace * 1.5) + ($exSet * 1) + ($exDig * 1) + ($blk * 1.3);
    if ($score > $mvpScore) {
      $mvpScore = $score;
      $no = isset($p['jersey_no']) && $p['jersey_no'] !== '' ? ('#' . $p['jersey_no'] . ' ') : '';
      $name = isset($p['player_name']) ? $p['player_name'] : '';
      $teamLetter = isset($p['team']) ? $p['team'] : '';
      $mvpName = trim($no . $name) . ($teamLetter ? ' (' . $teamLetter . ')' : '');
      $mvpRubric = sprintf('pts:%d spike:%d ace:%d ex_set:%d ex_dig:%d blk:%d score:%.2f', $pts, $spike, $ace, $exSet, $exDig, $blk, $score);
    }
  }
  if ($mvpName !== '') {
    $match['mvp'] = $mvpName;
    $match['mvp_rubric'] = $mvpRubric;
  }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$exportedAt = date('F j, Y  •  g:i A', strtotime($match['created_at']));
$teamAName  = h($match['team_a_name']);
$teamBName  = h($match['team_b_name']);
$committee  = h($match['committee'] ?? '');
$result     = h($match['match_result']);
$currentSet = (int)$match['current_set'];

// Totals
function sumCol(array $players, string $col): int {
    return array_sum(array_column($players, $col));
}

$jsonMatch = json_encode([
    'match_id'    => $matchId,
    'saved_at'    => $match['created_at'],
    'committee'   => $match['committee'] ?? '',
    'team_a_name' => $match['team_a_name'],
    'team_b_name' => $match['team_b_name'],
    'team_a_score'=> $match['team_a_score'],
    'team_b_score'=> $match['team_b_score'],
    'current_set' => $currentSet,
  'match_result'=> $match['match_result'],
  'mvp'         => $match['mvp'] ?? '',
  'mvp_rubric'  => $match['mvp_rubric'] ?? '',
    'players_a'   => array_values($playersA),
    'players_b'   => array_values($playersB),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Volleyball Report #<?= $matchId ?> — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Barlow+Condensed:wght@400;500;600&display=swap">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Barlow Condensed',Arial,sans-serif;background:#f4f4f4;color:#111;min-height:100vh;padding-bottom:60px}
.export-bar{background:#062a78;border-bottom:3px solid #FFD700;padding:10px 24px;display:flex;align-items:center;gap:10px;justify-content:flex-end;position:sticky;top:0;z-index:50}
.bar-title{font-family:'Oswald',sans-serif;font-size:13px;letter-spacing:1.5px;color:#FFD700;text-transform:uppercase;margin-right:auto;font-weight:700}
.btn-export{border:none;cursor:pointer;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;letter-spacing:1px;padding:8px 18px;border-radius:4px;text-transform:uppercase;transition:filter 0.15s,transform 0.1s}
.btn-export:hover{filter:brightness(1.15)}.btn-export:active{transform:scale(0.96)}
.btn-excel{background:#1e6c35;color:#fff}.btn-print{background:#FFD700;color:#111}
.container{max-width:960px;margin:0 auto;background:#fff;padding:24px 22px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.08);margin-top:24px;margin-bottom:24px}
.report-header-title{margin:0;color:#062a78;font-family:'Oswald',sans-serif;font-size:30px;letter-spacing:2px;font-weight:700}
.report-meta{margin-top:8px;color:#333;font-size:14px}
.report-meta strong{color:#111}
.report-divider{border:none;border-top:2px solid #FFD700;margin:14px 0}
.section-title{font-family:'Oswald',sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;color:#062a78;text-transform:uppercase;margin-bottom:10px;padding-bottom:5px;border-bottom:1px solid #e0e0e0}
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.info-card{background:#f8f8f8;border:1px solid #e0e0e0;border-radius:6px;padding:12px 14px}
.info-card .ic-label{font-family:'Oswald',sans-serif;font-size:10px;letter-spacing:2px;color:#888;text-transform:uppercase;margin-bottom:4px}
.info-card .ic-value{font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:#111}
.ic-value.winner-val{color:#006400}
.score-banner{background:linear-gradient(90deg,#FFD700,#FFD166);border-radius:8px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;padding:18px 24px;margin-bottom:22px;gap:12px}
.sb-team{display:flex;flex-direction:column;align-items:center;gap:4px}
.sb-team .sb-name{font-family:'Oswald',sans-serif;font-size:18px;font-weight:800;color:#062a78;text-transform:uppercase;letter-spacing:1px}
.sb-team .sb-score{font-family:'Oswald',sans-serif;font-size:68px;font-weight:900;color:#111;line-height:1;letter-spacing:-2px}
.sb-team .sb-sub{font-size:12px;color:#555;letter-spacing:0.5px}
.sb-winner-badge{display:inline-block;margin-top:6px;background:#e6f7e6;color:#006400;padding:4px 12px;border-radius:4px;font-weight:800;font-size:13px;letter-spacing:1px}
.sb-vs{font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:rgba(0,0,0,0.25);text-align:center}
.result-row{margin-bottom:14px;font-size:14px}
.result-badge{display:inline-block;background:#e6f7e6;color:#006400;padding:6px 12px;border-radius:4px;font-weight:800;margin-left:8px;letter-spacing:0.5px}
.team-block{background:#FFD700;padding:14px 16px;border-radius:6px;margin-bottom:14px}
.team-block .tb-name{font-family:'Oswald',sans-serif;font-weight:800;font-size:18px;margin-bottom:8px;color:#062a78;text-transform:uppercase;letter-spacing:1px}
table.team-table{width:100%;border-collapse:collapse}
table.team-table thead th{background:#333;color:#fff;padding:10px;font-family:'Oswald',sans-serif;font-size:11px;letter-spacing:1px;text-transform:uppercase;text-align:center}
table.team-table thead th:first-child,table.team-table thead th:nth-child(2){text-align:left}
table.team-table tbody tr:nth-child(even){background:#f6f6f6}
table.team-table tbody tr:nth-child(odd){background:#fff}
table.team-table tfoot td{background:#222;color:#FFD700;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;padding:8px 10px;text-align:center}
table.team-table tfoot td:first-child,table.team-table tfoot td:nth-child(2){text-align:left}
table.team-table tbody td{padding:9px 10px;border:1px solid #e0e0e0;font-size:13px;text-align:center}
table.team-table tbody td:first-child,table.team-table tbody td:nth-child(2){text-align:left}
.report-footer{margin-top:20px;text-align:right;font-size:13px;color:#666;border-top:1px solid #e0e0e0;padding-top:12px}
@media print{body{background:#fff;padding:0}.export-bar{display:none !important}.container{box-shadow:none;border-radius:0;padding:10px;margin:0;max-width:100%}.score-banner{print-color-adjust:exact;-webkit-print-color-adjust:exact}.team-block{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>

<div class="export-bar">
  <span class="bar-title">&#127944; Volleyball Report — Match #<?= $matchId ?></span>
  <button class="btn-export btn-excel" onclick="exportExcel()">&#11015; Export Excel</button>
  <button class="btn-export btn-print" onclick="window.print()">&#128438; Print PDF</button>
  <button class="btn-export" style="background:#FFD700;color:#062a78;border:0;font-family:'Oswald',sans-serif;font-weight:700;letter-spacing:1px;cursor:pointer;margin-left:6px" onclick="newMatch()">➕ New Match</button>
   <button class="btn-export" onclick="window.open('volleyball_matches_admin.php','_blank')">📚 Match History</button>
</div>

<div class="container">

  <h1 class="report-header-title">SPORTSSYNC — VOLLEYBALL RESULT</h1>
  <div class="report-meta">Date: <?= $exportedAt ?></div>
  <?php if ($committee): ?>
  <div class="report-meta"><strong>Committee / Official:</strong> <?= $committee ?></div>
  <?php endif; ?>
  <hr class="report-divider">

  <div class="section-title">Match Information</div>
  <div class="info-grid">
    <div class="info-card"><div class="ic-label">Match ID</div><div class="ic-value"><?= $matchId ?></div></div>
    <div class="info-card"><div class="ic-label">Current Set</div><div class="ic-value"><?= $currentSet ?></div></div>
    <div class="info-card"><div class="ic-label">Status</div><div class="ic-value <?= $match['match_result'] !== 'ONGOING' ? 'winner-val' : '' ?>"><?= h(ucfirst(strtolower($match['match_result']))) ?></div></div>
  </div>

  <div class="score-banner">
    <div class="sb-team">
      <div class="sb-name"><?= $teamAName ?></div>
      <div class="sb-score"><?= (int)$match['team_a_score'] ?></div>
      <div class="sb-sub">Total Points</div>
      <div class="sb-sub">Timeout: <?= (int)$match['team_a_timeout'] ?></div>
      <?php if ($match['team_a_score'] > $match['team_b_score']): ?>
        <div class="sb-winner-badge">&#127942; WINNER</div>
      <?php endif; ?>
    </div>
    <div class="sb-vs">VS</div>
    <div class="sb-team">
      <div class="sb-name"><?= $teamBName ?></div>
      <div class="sb-score"><?= (int)$match['team_b_score'] ?></div>
      <div class="sb-sub">Total Points</div>
      <div class="sb-sub">Timeout: <?= (int)$match['team_b_timeout'] ?></div>
      <?php if ($match['team_b_score'] > $match['team_a_score']): ?>
        <div class="sb-winner-badge">&#127942; WINNER</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($match['mvp']) || !empty($match['mvp_rubric'])): ?>
  <div class="result-row">
    <strong>MVP:</strong>
    <span style="margin-left:8px;font-weight:800;color:#062a78"><?= h($match['mvp'] ?? '') ?: '&mdash;' ?></span>
    <?php if (!empty($match['mvp_rubric'])): ?>
      <div style="margin-top:8px;color:#333"> <strong>Rubric:</strong> <?= h($match['mvp_rubric']) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="result-row">
    <strong>Overall Result:</strong>
    <span class="result-badge"><?= $result ?></span>
  </div>

  <?php foreach ([['A', $teamAName, $playersA], ['B', $teamBName, $playersB]] as [$team, $tName, $players]): ?>
  <div class="team-block">
    <div class="tb-name"><?= $tName ?></div>
    <table class="team-table">
      <thead><tr>
        <th>#</th><th>Name</th><th>PTS</th><th>SPIKE</th><th>ACE</th><th>EX SET</th><th>EX DIG</th><th>BLK</th>
      </tr></thead>
      <tbody>
        <?php foreach ($players as $pl): ?>
        <tr>
          <td><?= h($pl['jersey_no'] ?: '—') ?></td>
          <td><?= h($pl['player_name'] ?: '—') ?></td>
          <td><?= (int)$pl['pts'] ?></td>
          <td><?= (int)$pl['spike'] ?></td>
          <td><?= (int)$pl['ace'] ?></td>
          <td><?= (int)$pl['ex_set'] ?></td>
          <td><?= (int)$pl['ex_dig'] ?></td>
          <td><?= (int)($pl['blk'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($players)): ?>
        <tr><td colspan="7" style="text-align:center;color:#999;padding:16px">No players recorded.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($players)): ?>
      <tfoot><tr>
        <td colspan="2">TOTALS</td>
        <td><?= sumCol(iterator_to_array($players), 'pts') ?></td>
        <td><?= sumCol(iterator_to_array($players), 'spike') ?></td>
        <td><?= sumCol(iterator_to_array($players), 'ace') ?></td>
        <td><?= sumCol(iterator_to_array($players), 'ex_set') ?></td>
        <td><?= sumCol(iterator_to_array($players), 'ex_dig') ?></td>
        <td><?= sumCol(iterator_to_array($players), 'blk') ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="report-footer">
    Generated by SportSync &nbsp;·&nbsp; <?= date('F j, Y  g:i A') ?>
  </div>

</div><!-- /container -->

<script src="https://unpkg.com/xlsx-style@0.8.13/dist/xlsx.full.min.js"></script>
<script>
const MATCH_DATA = <?= $jsonMatch ?>;

window.exportExcel = function () {
  let wb;
  if (XLSX && XLSX.utils && typeof XLSX.utils.book_new === 'function') wb = XLSX.utils.book_new();
  else wb = { SheetNames: [], Sheets: {} };

  function cs(bold, bg, color) {
    function norm(c, fb) { if (!c) return fb||'FFFFFFFF'; let s=String(c).replace('#','').toUpperCase(); if(s.length===6)s='FF'+s; if(s.length===3){s=s.split('').map(ch=>ch+ch).join('');s='FF'+s;} return s; }
    const b={style:'thin',color:{rgb:'000000'}};
    return { font:{bold:!!bold,color:{rgb:norm(color||'111111')},name:'Calibri',sz:11}, fill:{fgColor:{rgb:norm(bg||'FFFFFF')},patternType:'solid'}, alignment:{horizontal:'center',vertical:'center'}, border:{top:b,bottom:b,left:b,right:b} };
  }

  const rows = [];
  rows.push(['SPORTSSYNC — VOLLEYBALL MATCH REPORT']);
  rows.push([]);
  rows.push(['Field','Value']);
  rows.push(['Match ID', MATCH_DATA.match_id]);
  rows.push(['Date / Time', MATCH_DATA.saved_at]);
  rows.push(['Committee / Official', MATCH_DATA.committee || '—']);
  rows.push(['MVP', MATCH_DATA.mvp || '—']);
  rows.push(['MVP Rubric', MATCH_DATA.mvp_rubric || '—']);
  rows.push(['Current Set', MATCH_DATA.current_set]);
  rows.push(['Status', MATCH_DATA.match_result]);
  rows.push(['Team A', MATCH_DATA.team_a_name]);
  rows.push(['Team A Score', MATCH_DATA.team_a_score]);
  rows.push(['Team B', MATCH_DATA.team_b_name]);
  rows.push(['Team B Score', MATCH_DATA.team_b_score]);
  rows.push(['Overall Result', MATCH_DATA.match_result]);
  rows.push([]);

  rows.push(['Players']);
  rows.push(['Team','#','Name','PTS','SPIKE','ACE','EX SET','EX DIG','BLK']);
  (MATCH_DATA.players_a || []).forEach(p => rows.push([MATCH_DATA.team_a_name, p.jersey_no||'', p.player_name||'—', p.pts||0, p.spike||0, p.ace||0, p.ex_set||0, p.ex_dig||0, p.blk||0]));
  (MATCH_DATA.players_b || []).forEach(p => rows.push([MATCH_DATA.team_b_name, p.jersey_no||'', p.player_name||'—', p.pts||0, p.spike||0, p.ace||0, p.ex_set||0, p.ex_dig||0, p.blk||0]));
  rows.push([]);
  rows.push(['Game Result', MATCH_DATA.match_result]);

  let ws;
  if (XLSX && XLSX.utils && typeof XLSX.utils.aoa_to_sheet === 'function') {
    ws = XLSX.utils.aoa_to_sheet(rows);
  } else {
    ws = {}; const R=rows.length;
    for(let r=0;r<R;++r){const row=rows[r]||[];for(let c=0;c<row.length;++c){const v=row[c];if(v==null)continue;const ref=(XLSX&&XLSX.utils&&typeof XLSX.utils.encode_cell==='function')?XLSX.utils.encode_cell({r,c}):(function(r,c){let col='';let cc=c;while(cc>=0){col=String.fromCharCode(65+(cc%26))+col;cc=Math.floor(cc/26)-1;}return col+(r+1);})(r,c);ws[ref]={v,t:typeof v==='number'?'n':'s'};}}
    ws['!ref']=(XLSX&&XLSX.utils&&typeof XLSX.utils.encode_range==='function')?XLSX.utils.encode_range({s:{r:0,c:0},e:{r:R-1,c:7}}):'A1:H'+R;
  }
  ws['!cols'] = [{wch:20},{wch:8},{wch:28},{wch:8},{wch:8},{wch:8},{wch:9},{wch:9}];

  try {
    const range = XLSX.utils.decode_range(ws['!ref']);
    for(let R=range.s.r;R<=range.e.r;++R){for(let C=range.s.c;C<=range.e.c;++C){const ref=XLSX.utils.encode_cell({r:R,c:C});const cell=ws[ref];if(!cell)continue;
      if(R===0){cell.s=cs(true,'FFE600','062A78');continue;}
      if(R===2&&(cell.v==='Field'||cell.v==='Value')){cell.s=cs(true,'062A78','FFFFFF');continue;}
      if(String(cell.v||'').toLowerCase()==='players'){cell.s=cs(true,'062A78','FFFFFF');continue;}
      if(cell.v==='Team'||cell.v==='#'){for(let cc=range.s.c;cc<=range.e.c;++cc){const pr=XLSX.utils.encode_cell({r:R,c:cc});if(ws[pr])ws[pr].s=cs(true,'062A78','FFFFFF');}break;}
      const bg=['EAF3FF','D0E8FF','FFFFFF','EAF3FF','D0E8FF','EAF3FF','D0E8FF','EAF3FF'][C-range.s.c]||'FFFFFF';
      cell.s=cs(false,bg,'111111');
    }}
  } catch(_){}

  if(XLSX&&XLSX.utils&&typeof XLSX.utils.book_append_sheet==='function')XLSX.utils.book_append_sheet(wb,ws,'Match Report');
  else{wb.SheetNames.push('Match Report');wb.Sheets['Match Report']=ws;}

  const filename='volleyball_report_'+MATCH_DATA.match_id+'.xlsx';
  if(typeof XLSX.write==='function'){try{const wbout=XLSX.write(wb,{bookType:'xlsx',type:'binary'});function s2ab(s){const buf=new ArrayBuffer(s.length);const view=new Uint8Array(buf);for(let i=0;i<s.length;++i)view[i]=s.charCodeAt(i)&0xFF;return buf;}const blob=new Blob([s2ab(wbout)],{type:'application/octet-stream'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);return;}catch(_){}}
  if(typeof XLSX.writeFile==='function'){try{XLSX.writeFile(wb,filename);return;}catch(_){}}
  alert('Excel export not supported in this browser build.');
};
</script>
<script>
(function(){
  try {
    if (sessionStorage.getItem('disableBackAfterSave_volleyball') === '1') {
      try { sessionStorage.removeItem('disableBackAfterSave_volleyball'); } catch(e){}
      try { localStorage.removeItem('volleyballMatchState'); } catch(e){}
      try { localStorage.removeItem('volleyballAdminState'); } catch(e){}
      try { sessionStorage.removeItem('volleyball_match_id'); } catch(e){}
      history.pushState(null, null, location.href);
      window.addEventListener('popstate', function(){ history.pushState(null, null, location.href); });
    }
  } catch(e){}
})();
function newMatch(){
  try { localStorage.removeItem('volleyballMatchState'); } catch(e){}
  try { localStorage.removeItem('volleyballAdminState'); } catch(e){}
  try { sessionStorage.removeItem('volleyball_match_id'); } catch(e){}
  window.location.href = 'volleyball_admin.php';
}
</script>
</body>
</html>
