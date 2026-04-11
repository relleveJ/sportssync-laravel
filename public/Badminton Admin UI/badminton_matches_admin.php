<?php
require_once 'db_config.php';
// Simple admin list for badminton matches
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$params = [];
$sql = "SELECT id, match_type, best_of, team_a_name, team_b_name, status, winner_name, created_at FROM badminton_matches";
$where = [];
if ($q !== '') {
  $where[] = "(id = ? OR team_a_name LIKE ? OR team_b_name LIKE ?)";
}
if ($statusFilter !== '') {
    $where[] = "status = ?";
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC LIMIT 200';

// prepare and bind dynamically
$stmt = $mysqli->prepare($sql);
if ($stmt) {
    $bindTypes = '';
    $bindVals = [];
    if ($q !== '') {
        // If q is numeric, use id exact match and name like
        $bindTypes .= 'iss';
        $bindVals[] = intval($q);
        $like = '%' . $q . '%';
        $bindVals[] = $like; $bindVals[] = $like;
    }
    if ($statusFilter !== '') { $bindTypes .= 's'; $bindVals[] = $statusFilter; }
    if ($bindTypes) {
        $stmt->bind_param($bindTypes, ...$bindVals);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // fallback: run raw query (no filters)
    $res = $mysqli->query('SELECT id, match_type, best_of, team_a_name, team_b_name, status, winner_name, created_at FROM badminton_matches ORDER BY created_at DESC LIMIT 200');
    $matches = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Badminton Matches — Admin</title>
<style>
body{font-family:Arial,sans-serif;padding:18px;background:#f4f4f4}
.container{max-width:1100px;margin:0 auto;background:#fff;padding:16px;border-radius:6px}
.toolbar{display:flex;gap:8px;margin-bottom:12px}
.btn{padding:8px 12px;border-radius:4px;border:0;background:#003366;color:#fff;cursor:pointer}
.btn.danger{background:#a00}
.table{width:100%;border-collapse:collapse}
.table th, .table td{border:1px solid #e6e6e6;padding:8px;text-align:left}
.table th{background:#f6f6f6}
.search{margin-right:auto}
.current-id{margin-left:12px;font-weight:700}
</style>
</head>
<body>
<div class="container">
  <h2>Badminton Matches — Admin</h2>
  <div class="toolbar">
    <form id="searchForm" method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="text" name="q" placeholder="Search by match id or team name" value="<?= htmlspecialchars($q) ?>" style="padding:8px;width:320px">
      <select name="status" style="padding:8px">
        <option value="">All Statuses</option>
        <option value="ongoing" <?= $statusFilter==='ongoing' ? 'selected' : '' ?>>Ongoing</option>
        <option value="completed" <?= $statusFilter==='completed' ? 'selected' : '' ?>>Completed</option>
        <option value="reset" <?= $statusFilter==='reset' ? 'selected' : '' ?>>Reset</option>
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
    <button id="refreshBtn" class="btn" style="background:#666;margin-left:auto">Refresh</button>
  </div>

  <form id="matchesForm">
  <table class="table">
    <thead>
      <tr>
        <th style="width:36px"><input id="chkAll" type="checkbox"></th>
        <th>Match ID</th>
        <th>Teams</th>
        <th>Type</th>
        <th>Best Of</th>
        <th>Status</th>
        <th>Winner</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
<?php if (empty($matches)): ?>
      <tr><td colspan="9" style="text-align:center;color:#666;padding:12px">No matches found.</td></tr>
<?php else: foreach ($matches as $m): ?>
      <tr>
        <td><input class="chk" type="checkbox" name="match_ids[]" value="<?= (int)$m['id'] ?>"></td>
        <td><?= (int)$m['id'] ?></td>
        <td><?= htmlspecialchars($m['team_a_name']) ?> vs <?= htmlspecialchars($m['team_b_name']) ?></td>
        <td><?= htmlspecialchars($m['match_type']) ?></td>
        <td><?= (int)$m['best_of'] ?></td>
        <td><?= htmlspecialchars($m['status']) ?></td>
        <td><?= htmlspecialchars($m['winner_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($m['created_at']) ?></td>
        <td>
          <a href="badminton_report.php?match_id=<?= (int)$m['id'] ?>" target="_blank">View Report</a>
          &nbsp;|&nbsp;
          <button type="button" class="btn small" onclick="resetMatch(<?= (int)$m['id'] ?>)">Reset</button>
        </td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
  </form>
</div>

<script>
// show current match id from sessionStorage
(function(){
  try{ const el=document.getElementById('currentMatchId'); const id = sessionStorage.getItem('badminton_match_id'); if (id) el.textContent = id; else el.textContent = '(none)'; }catch(e){}
})();

// checkbox helpers
document.getElementById('chkAll').addEventListener('change', function(){ document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked); });

document.getElementById('refreshBtn').addEventListener('click', function(){ location.reload(); });

function resetMatch(id) {
  if (!confirm('Reset match ' + id + '? This will clear saved sets.')) return;
  fetch('reset_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: id }) })
    .then(r => r.json()).then(j => {
      if (j && j.success) { alert('Match reset'); location.reload(); } else { alert('Reset failed: ' + (j && j.message ? j.message : 'Unknown')); }
    }).catch(e=>{console.error(e); alert('Reset request failed');});
}

document.getElementById('deleteSelected').addEventListener('click', function(){
  const ids = Array.from(document.querySelectorAll('.chk:checked')).map(i=>parseInt(i.value,10));
  if (!ids.length) { alert('Select at least one match to delete.'); return; }
  if (!confirm('Delete selected match(es)? This will remove match records permanently.')) return;
  fetch('delete_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_ids: ids }) })
    .then(r=>r.json()).then(j=>{ if (j && j.success) { alert('Deleted'); location.reload(); } else { alert('Delete failed: ' + (j && j.message ? j.message : 'Unknown')); } }).catch(e=>{console.error(e); alert('Delete request failed');});
});

document.getElementById('resetSelected').addEventListener('click', function(){
  const ids = Array.from(document.querySelectorAll('.chk:checked')).map(i=>parseInt(i.value,10));
  if (!ids.length) { alert('Select at least one match to reset.'); return; }
  if (!confirm('Reset selected match(es)?')) return;
  // reset sequentially
  Promise.all(ids.map(id => fetch('reset_match.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ match_id: id }) }).then(r=>r.json()).catch(()=>({success:false})) ))
    .then(results=>{ alert('Reset completed'); location.reload(); }).catch(e=>{console.error(e); alert('Reset request failed'); });
});
</script>
</body>
</html>
