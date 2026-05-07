<?php
require_once '../includes/config.php';
requireLogin('user');

$db = getDB();
$user_id = $_SESSION['user_id'];
$user = $db->query("SELECT u.*, r.room_number, r.room_type FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.id=$user_id")->fetch_assoc();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $items = $_POST['items'] ?? [];
    $description = sanitize($_POST['description'] ?? '');
    $priority = sanitize($_POST['priority'] ?? 'medium');

    if (empty($items)) {
        $error = 'Please select at least one item to complain about.';
    } elseif (!$user['room_id']) {
        $error = 'You are not assigned to a room. Contact admin.';
    } else {
        $items_json = json_encode($items);
        $room_id = $user['room_id'];
        $stmt = $db->prepare("INSERT INTO complaints (user_id, room_id, complaint_items, description, priority) VALUES (?,?,?,?,?)");
        $stmt->bind_param('iisss', $user_id, $room_id, $items_json, $description, $priority);
        if ($stmt->execute()) {
            $success = 'Complaint filed successfully! Admin will review it shortly.';
        } else {
            $error = 'Failed to submit. Please try again.';
        }
    }
}

$complaints = $db->query("SELECT * FROM complaints WHERE user_id=$user_id ORDER BY created_at DESC");

function statusBadge($s) {
    $map = ['pending'=>'badge-warning','in_progress'=>'badge-info','resolved'=>'badge-success','rejected'=>'badge-danger'];
    $labels = ['pending'=>'⏳ Pending','in_progress'=>'🔧 In Progress','resolved'=>'✅ Resolved','rejected'=>'❌ Rejected'];
    return "<span class='badge {$map[$s]}'>{$labels[$s]}</span>";
}

$initials = strtoupper(substr($user['full_name'],0,1));

$complaint_items_list = [
    'Fan'      => '🌀',
    'Light'    => '💡',
    'Switch'   => '🔌',
    'Door'     => '🚪',
    'Window'   => '🪟',
    'Tap/Faucet' => '🚿',
    'Toilet'   => '🚽',
    'Bed/Cot'  => '🛏️',
    'AC/Cooler' => '❄️',
    'Internet/WiFi' => '📶',
    'Ceiling'  => '🏛️',
    'Cupboard' => '🗄️',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Complaints - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🏠</div>
        <div class="brand-text">
          <div class="name">Residex</div>
          <div class="tag">Resident Portal</div>
        </div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item active"><span class="icon">🔧</span> Complaints</a>
      <a href="profile.php" class="nav-item"><span class="icon">👤</span> My Profile</a>
      <div class="nav-section-label">Info</div>
      <a href="announcements.php" class="nav-item"><span class="icon">📢</span> Announcements</a>
    </nav>
    <div class="sidebar-user">
      <div class="user-avatar"><?= $initials ?></div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="urole">Room <?= $user['room_number'] ?? 'N/A' ?></div>
      </div>
      <a href="logout.php" class="logout-btn">⏻</a>
    </div>
  </aside>

  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title">
        <h2>Maintenance Complaints</h2>
        <p>Report issues in your room</p>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= $error ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= $success ?></div>
      <?php endif; ?>

      <!-- New Complaint Form -->
      <div class="card fade-up" id="new">
        <div class="card-header">
          <h3>🔧 File a New Complaint</h3>
          <span style="font-size:0.8rem; color:var(--text-2);">Room <?= $user['room_number'] ?? 'Not Assigned' ?></span>
        </div>

        <?php if (!$user['room_id']): ?>
          <div class="alert alert-warning">You are not assigned to a room. Please contact the admin.</div>
        <?php else: ?>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">What needs attention? (Select all that apply) *</label>
              <div class="checkbox-group">
                <?php foreach ($complaint_items_list as $item => $icon): ?>
                  <div class="checkbox-item">
                    <input type="checkbox" name="items[]" value="<?= $item ?>" id="item_<?= str_replace('/','_',$item) ?>">
                    <label class="checkbox-label" for="item_<?= str_replace('/','_',$item) ?>">
                      <span class="checkbox-icon"><?= $icon ?></span>
                      <?= $item ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="form-group" style="margin-top: 20px;">
              <label class="form-label">Priority Level</label>
              <div class="priority-group">
                <div class="priority-item low">
                  <input type="radio" name="priority" id="p_low" value="low">
                  <label class="priority-label" for="p_low">🟢 Low</label>
                </div>
                <div class="priority-item medium">
                  <input type="radio" name="priority" id="p_med" value="medium" checked>
                  <label class="priority-label" for="p_med">🟡 Medium</label>
                </div>
                <div class="priority-item high">
                  <input type="radio" name="priority" id="p_high" value="high">
                  <label class="priority-label" for="p_high">🔴 High</label>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Additional Description</label>
              <textarea name="description" class="form-textarea" placeholder="Describe the issue in detail (optional)..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary">📤 Submit Complaint</button>
          </form>
        <?php endif; ?>
      </div>

      <!-- Complaint History -->
      <div class="card fade-up" style="margin-top: 24px;">
        <div class="card-header">
          <h3>📋 Complaint History</h3>
          <span style="font-size:0.8rem; color:var(--text-2);"><?= $complaints->num_rows ?> total</span>
        </div>

        <?php if ($complaints->num_rows === 0): ?>
          <div style="text-align:center; padding: 40px 0; color:var(--text-3);">
            <div style="font-size:48px; margin-bottom:12px;">🎉</div>
            <p>No complaints filed yet. Hope everything is working fine!</p>
          </div>
        <?php else: ?>
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Items Reported</th>
                  <th>Description</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Admin Notes</th>
                  <th>Date</th>
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
                    <div class="complaint-items-display">
                      <?php foreach ($items as $it): ?>
                        <span class="complaint-item-chip">
                          <?= $complaint_items_list[$it] ?? '🔧' ?> <?= htmlspecialchars($it) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  </td>
                  <td style="max-width:180px; font-size:0.8rem; color:var(--text-2);"><?= $c['description'] ? htmlspecialchars(substr($c['description'],0,60)).'...' : '—' ?></td>
                  <td><span class="badge <?= $pc[$c['priority']] ?>"><?= ucfirst($c['priority']) ?></span></td>
                  <td><?= statusBadge($c['status']) ?></td>
                  <td style="font-size:0.8rem; color:var(--text-2);"><?= $c['admin_notes'] ? htmlspecialchars(substr($c['admin_notes'],0,50)) : '—' ?></td>
                  <td style="font-size:0.75rem; color:var(--text-3); white-space:nowrap;"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
