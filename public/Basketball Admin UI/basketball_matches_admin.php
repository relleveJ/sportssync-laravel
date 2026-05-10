<?php
// auth.php and db.php live in the parent directory (sportssync-laravel/public/)
$_base = __DIR__;
if (!file_exists($_base . '/auth.php') && file_exists($_base . '/../auth.php')) {
    $_base = realpath(__DIR__ . '/..');
}
require_once $_base . '/auth.php';
require_once $_base . '/db.php';
$user = requireRole('admin');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? min(300, max(10, intval($_GET['per_page']))) : 50;
$offset = ($page - 1) * $perPage;
$export = isset($_GET['export']) ? trim($_GET['export']) : '';

$sqlBase = "SELECT match_id, team_a_name, team_b_name, team_a_score, team_b_score, team_a_quarter, match_result, committee, saved_at AS created_at FROM `matches`";
$where = [];
$bindVals = [];
if ($q !== '') { $where[] = "(match_id = ? OR team_a_name LIKE ? OR team_b_name LIKE ?)"; $bindVals[] = intval($q); $like = '%'.$q.'%'; $bindVals[] = $like; $bindVals[] = $like; }
if ($statusFilter !== '') { $where[] = "match_result = ?"; $bindVals[] = $statusFilter; }
if (!empty($where)) $sqlBase .= ' WHERE ' . implode(' AND ', $where);

$matches = [];
$totalMatches = 0;
$totalPages = 1;
try {
  if ($export === 'csv') {
    $stmt = $pdo->prepare($sqlBase . ' ORDER BY saved_at DESC');
    if (!empty($bindVals)) $stmt->execute($bindVals); else $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="basketball_matches.csv"');
    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
      fputcsv($out, array_keys($rows[0]));
      foreach ($rows as $r) fputcsv($out, $r);
    } else {
      fputcsv($out, ['match_id','team_a_name','team_b_name','team_a_score','team_b_score','team_a_quarter','match_result','committee','created_at']);
    }
    fclose($out);
    exit;
  }

  $countSql = 'SELECT COUNT(*) FROM `matches`';
  if (!empty($where)) $countSql .= ' WHERE ' . implode(' AND ', $where);
  $countStmt = $pdo->prepare($countSql);
  if (!empty($bindVals)) $countStmt->execute($bindVals); else $countStmt->execute();
  $totalMatches = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalMatches / $perPage));

  $sqlPage = $sqlBase . ' ORDER BY saved_at DESC LIMIT ' . (int)$offset . ',' . (int)$perPage;
  $stmt = $pdo->prepare($sqlPage);
  if (!empty($bindVals)) $stmt->execute($bindVals); else $stmt->execute();
  $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $matches = []; $totalMatches = 0; $totalPages = 1; }
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Basketball Matches — Admin</title>
<style>body{font-family:Arial,sans-serif;padding:18px;background:#f4f4f4} .container{max-width:1100px;margin:0 auto;background:#fff;padding:16px;border-radius:6px} .toolbar{display:flex;gap:8px;margin-bottom:12px} .btn{padding:8px 12px;border-radius:4px;border:0;background:#003366;color:#fff;cursor:pointer} .btn.danger{background:#a00} .table{width:100%;border-collapse:collapse} .table th, .table td{border:1px solid #e6e6e6;padding:8px;text-align:left} .table th{background:#f6f6f6} .current-id{margin-left:12px;font-weight:700}</style>
</head>
<body>
<div class="container">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
    <button onclick="window.history.back()" title="Go back">
⬅ Back</button>
    <a href="/" style="padding:8px 16px;background:#003366;color:#fff;border-radius:4px;text-decoration:none;font-size:14px;font-weight:700;">&#8592; Back to Dashboard</a>
    <h2>Basketball Matches — Admin</h2>
  </div>
  <div class="toolbar">
    <form id="searchForm" method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="text" name="q" placeholder="Search by match id or team name" value="<?= htmlspecialchars($q) ?>" style="padding:8px;width:320px">
      <select name="status" style="padding:8px">
        <option value="">All Results</option>
        <option value="TEAM A WINS" <?= $statusFilter==='TEAM A WINS' ? 'selected' : '' ?>>Team A Wins</option>
        <option value="TEAM B WINS" <?= $statusFilter==='TEAM B WINS' ? 'selected' : '' ?>>Team B Wins</option>
        <option value="DRAW" <?= $statusFilter==='DRAW' ? 'selected' : '' ?>>Draw</option>
      </select>
      <select name="per_page" id="perPageSelect" style="padding:8px">
        <option value="10" <?= $perPage==10 ? 'selected' : '' ?>>10</option>
        <option value="25" <?= $perPage==25 ? 'selected' : '' ?>>25</option>
        <option value="50" <?= $perPage==50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $perPage==100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= $perPage==200 ? 'selected' : '' ?>>200</option>
      </select>
      <button class="btn" type="submit">Filter</button>
    </form>
    <div style="margin-left:auto;display:flex;align-items:center">
      <div>Current Admin Match ID:</div>
      <div id="currentMatchId" class="current-id">(none)</div>
    </div>
  </div>

  <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
    <button id="resetSelected" class="btn">Reset Selected</button>
    <button id="deleteSelected" class="btn danger">Delete Selected</button>
    <button id="exportCsvBtn" class="btn" title="Export CSV">Export CSV</button>
    <button id="refreshBtn" class="btn" style="background:#666;margin-left:auto">Refresh</button>
  </div>

  <form id="matchesForm">
  <table class="table">
    <thead>
      <tr>
        <th style="width:36px"><input id="chkAll" type="checkbox"></th>
        <th>Match ID</th>
        <th>Teams</th>
        <th>Score</th>
        <th>Quarter</th>
        <th>Result</th>
        <th>Committee</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
<?php if (empty($matches)): ?>
      <tr><td colspan="9" style="text-align:center;color:#666;padding:12px">No matches found.</td></tr>
<?php else: foreach ($matches as $m): ?>
      <tr>
        <td><input class="chk" type="checkbox" name="match_ids[]" value="<?= (int)$m['match_id'] ?>"></td>
        <td><?= (int)$m['match_id'] ?></td>
        <td><?= htmlspecialchars($m['team_a_name']) ?> vs <?= htmlspecialchars($m['team_b_name']) ?></td>
        <td><?= (int)$m['team_a_score'] ?> - <?= (int)$m['team_b_score'] ?></td>
        <td><?= (int)$m['team_a_quarter'] ?></td>
        <td><?= htmlspecialchars($m['match_result']) ?></td>
        <td><?= htmlspecialchars($m['committee'] ?? '') ?></td>
        <td><?= htmlspecialchars($m['created_at']) ?></td>
        <td>
          <a href="report.php?match_id=<?= (int)$m['match_id'] ?>" target="_blank">View Report</a>
          &nbsp;|&nbsp;
          <button type="button" class="btn small" onclick="bbResetMatch(<?= (int)$m['match_id'] ?>")>Reset</button>
        </td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
  </form>
  <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
    <div>
      Showing <?= ($totalMatches>0 ? ($offset + 1) : 0) ?> - <?= min($offset + $perPage, $totalMatches) ?> of <?= $totalMatches ?>
    </div>
    <div>
      <?php
        $qs = $_GET;
        for ($i = 1; $i <= $totalPages; $i++) {
          $qs['page'] = $i;
          $url = '?' . http_build_query($qs);
          if ($i === $page) echo '<strong style="margin:0 6px">' . $i . '</strong>';
          else echo '<a class="btn" href="' . htmlspecialchars($url) . '" style="margin:0 4px">' . $i . '</a>';
        }
      ?>
    </div>
  </div>
</div>

<script>
(function(){ try{ const el=document.getElementById('currentMatchId'); const id = sessionStorage.getItem('basketball_match_id'); if (id) el.textContent = id; else el.textContent = '(none)'; }catch(e){} })();
document.getElementById('chkAll').addEventListener('change', function(){ document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked); });
document.getElementById('refreshBtn').addEventListener('click', function(){ location.reload(); });
function bbResetMatch(id) { if (!confirm('Reset match ' + id + '? This will clear saved data.')) return; fetch('delete_match.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: id }) }).then(r => r.json()).then(j => { if (j && j.success) { alert('Match reset'); location.reload(); } else { alert('Reset failed: ' + (j && j.error ? j.error : 'Unknown')); } }).catch(e=>{console.error(e); alert('Reset request failed');}); }
document.getElementById('deleteSelected').addEventListener('click', function(){ const ids = Array.from(document.querySelectorAll('.chk:checked')).map(i=>parseInt(i.value,10)); if (!ids.length) { alert('Select at least one match to delete.'); return; } if (!confirm('Delete selected match(es)? This will remove match records permanently.')) return; fetch('delete_match.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_ids: ids }) }).then(r=>r.json()).then(j=>{ if (j && j.success) { alert('Deleted'); location.reload(); } else { alert('Delete failed: ' + (j && j.message ? j.message : 'Unknown')); } }).catch(e=>{console.error(e); alert('Delete request failed');}); });
document.getElementById('resetSelected').addEventListener('click', function(){ const ids = Array.from(document.querySelectorAll('.chk:checked')).map(i=>parseInt(i.value,10)); if (!ids.length) { alert('Select at least one match to reset.'); return; } if (!confirm('Reset selected match(es)?')) return; Promise.all(ids.map(id => fetch('delete_match.php', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: id }) }).then(r=>r.json()).catch(()=>({success:false})) )).then(results=>{ alert('Reset completed'); location.reload(); }).catch(e=>{console.error(e); alert('Reset request failed'); }); });
try {
  const expBtn = document.getElementById('exportCsvBtn');
  if (expBtn) expBtn.addEventListener('click', function(){ const params = new URLSearchParams(location.search); params.set('export','csv'); location.href = '?' + params.toString(); });
  const perSel = document.getElementById('perPageSelect');
  if (perSel) perSel.addEventListener('change', function(){ const params = new URLSearchParams(location.search); params.set('per_page', this.value); params.set('page', 1); location.href = '?' + params.toString(); });
} catch (e) {}
</script>
</body>
</html>