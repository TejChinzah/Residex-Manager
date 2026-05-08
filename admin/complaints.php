<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = intval($_POST['complaint_id']);
    $status = sanitize($_POST['status']);
    $notes = sanitize($_POST['admin_notes'] ?? '');
    $resolved = ($status === 'resolved') ? ", resolved_at=NOW()" : "";
    $db->query("UPDATE complaints SET status='$status', admin_notes='$notes'$resolved WHERE id=$cid");
    $success = 'Complaint updated successfully.';
}

$filter = $_GET['filter'] ?? 'all';
$where = "1=1";
if ($filter !== 'all') $where .= " AND c.status='$filter'";

$complaints = $db->query("SELECT c.*, u.full_name, u.student_id, u.phone, r.room_number, r.floor FROM complaints c JOIN users u ON c.user_id=u.id JOIN rooms r ON c.room_id=r.id WHERE $where ORDER BY FIELD(c.status,'pending','in_progress','resolved','rejected'), c.priority DESC, c.created_at DESC");

$edit_id = intval($_GET['edit'] ?? 0);
$edit_complaint = null;
if ($edit_id) {
    $edit_complaint = $db->query("SELECT c.*, u.full_name, u.student_id, r.room_number FROM complaints c JOIN users u ON c.user_id=u.id JOIN rooms r ON c.room_id=r.id WHERE c.id=$edit_id")->fetch_assoc();
}

$complaint_items_icons = [
    'Fan'=>'🌀','Light'=>'💡','Switch'=>'🔌','Door'=>'🚪','Window'=>'🪟',
    'Tap/Faucet'=>'🚿','Toilet'=>'🚽','Bed/Cot'=>'🛏️','AC/Cooler'=>'❄️',
    'Internet/WiFi'=>'📶','Ceiling'=>'🏛️','Cupboard'=>'🗄️'
];

function statusBadge($s) {
    $map = ['pending'=>'badge-warning','in_progress'=>'badge-info','resolved'=>'badge-success','rejected'=>'badge-danger'];
    $labels = ['pending'=>'⏳ Pending','in_progress'=>'🔧 In Progress','resolved'=>'✅ Resolved','rejected'=>'❌ Rejected'];
    return "<span class='badge {$map[$s]}'>{$labels[$s]}</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Complaints - Residex Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🛡️</div>
        <div class="brand-text"><div class="name">Residex</div><div class="tag">Admin Panel</div></div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Analytics</div>
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <div class="nav-section-label">Management</div>
      <a href="residents.php" class="nav-item"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item active"><span class="icon">🔧</span> Complaints</a>
      <a href="announcements.php" class="nav-item"><span class="icon">📢</span> Announcements</a>
    </nav>
    <div class="sidebar-user">
      <div class="user-avatar" style="background:linear-gradient(135deg,var(--accent4),var(--accent3));">Cz</div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($_SESSION['admin_name']) ?></div>
        <div class="urole" style="color:var(--accent4);">Administrator</div>
      </div>
      <a href="logout.php" class="logout-btn">⏻</a>
    </div>
  </aside>

  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title">
        <h2>Complaint Management</h2>
        <p>Track and resolve maintenance issues</p>
      </div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

      <!-- Filter Tabs -->
      <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
        <?php foreach (['all'=>'All','pending'=>'⏳ Pending','in_progress'=>'🔧 In Progress','resolved'=>'✅ Resolved','rejected'=>'❌ Rejected'] as $k=>$v): ?>
          <a href="?filter=<?= $k ?>" class="btn <?= $filter===$k ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $v ?></a>
        <?php endforeach; ?>
      </div>

      <div class="card fade-up">
        <div class="card-header">
          <h3>🔧 Complaints (<?= $complaints->num_rows ?>)</h3>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Resident</th>
                <th>Room</th>
                <th>Items Reported</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Filed On</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($c = $complaints->fetch_assoc()):
                $items = json_decode($c['complaint_items'], true);
                $pc = ['low'=>'badge-success','medium'=>'badge-warning','high'=>'badge-danger'];
              ?>
              <tr>
                <td style="color:var(--text-3); font-size:0.8rem;">#<?= $c['id'] ?></td>
                <td>
                  <div style="font-weight:600; font-size:0.875rem;"><?= htmlspecialchars($c['full_name']) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-3);"><?= $c['student_id'] ?></div>
                </td>
                <td><span class="badge badge-muted">Room <?= $c['room_number'] ?><br><span style="font-size:0.65rem;">Floor <?= $c['floor'] ?></span></span></td>
                <td>
                  <div style="display:flex; flex-wrap:wrap; gap:4px;">
                    <?php foreach ($items as $it): ?>
                      <span style="background:var(--bg-glass); border:1px solid var(--border); border-radius:99px; padding:2px 8px; font-size:0.72rem;">
                        <?= $complaint_items_icons[$it] ?? '🔧' ?> <?= htmlspecialchars($it) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <?php if ($c['description']): ?>
                    <div style="font-size:0.72rem; color:var(--text-3); margin-top:4px;"><?= htmlspecialchars(substr($c['description'],0,60)) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $pc[$c['priority']] ?>"><?= ucfirst($c['priority']) ?></span></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td style="font-size:0.75rem; color:var(--text-3); white-space:nowrap;">
                  <?= date('M j, Y', strtotime($c['created_at'])) ?><br>
                  <?= date('g:i A', strtotime($c['created_at'])) ?>
                </td>
                <td><a href="?edit=<?= $c['id'] ?>&filter=<?= $filter ?>" class="btn btn-primary btn-sm">Manage</a></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Manage Complaint Modal -->
<?php if ($edit_complaint): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Complaint #<?= $edit_complaint['id'] ?></h3>
      <button class="modal-close" onclick="location.href='complaints.php?filter=<?= $filter ?>'">✕</button>
    </div>

    <div style="background:var(--bg-glass); border-radius:10px; padding:14px; margin-bottom:20px; font-size:0.85rem; display:grid; gap:8px;">
      <div class="flex justify-between"><span style="color:var(--text-2)">Resident</span><strong><?= htmlspecialchars($edit_complaint['full_name']) ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Room</span><strong>Room <?= $edit_complaint['room_number'] ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Student ID</span><strong><?= $edit_complaint['student_id'] ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Filed</span><strong><?= date('M j, Y g:i A', strtotime($edit_complaint['created_at'])) ?></strong></div>
    </div>

    <div style="margin-bottom:16px;">
      <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-3); margin-bottom:8px;">Reported Items</div>
      <div class="complaint-items-display">
        <?php foreach (json_decode($edit_complaint['complaint_items'], true) as $it): ?>
          <span class="complaint-item-chip"><?= $complaint_items_icons[$it] ?? '🔧' ?> <?= htmlspecialchars($it) ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($edit_complaint['description']): ?>
    <div style="background:var(--bg-glass); border-radius:8px; padding:12px; margin-bottom:16px; font-size:0.85rem; color:var(--text-2);">
      <strong style="color:var(--text-1); font-size:0.75rem; text-transform:uppercase; letter-spacing:0.06em;">Description</strong><br>
      <span style="margin-top:6px; display:block;"><?= htmlspecialchars($edit_complaint['description']) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="complaint_id" value="<?= $edit_complaint['id'] ?>">

      <div class="form-group">
        <label class="form-label">Update Status</label>
        <select name="status" class="form-select">
          <?php foreach (['pending'=>'⏳ Pending','in_progress'=>'🔧 In Progress','resolved'=>'✅ Resolved','rejected'=>'❌ Rejected'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $edit_complaint['status']===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Admin Notes (visible to resident)</label>
        <textarea name="admin_notes" class="form-textarea" placeholder="Enter notes, actions taken, timeline..."><?= htmlspecialchars($edit_complaint['admin_notes'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn btn-primary">💾 Update Complaint</button>
    </form>
  </div>
</div>
<?php endif; ?>
</body>
</html>
