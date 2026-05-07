<?php
require_once '../includes/config.php';
requireLogin('user');

$db = getDB();
$user_id = $_SESSION['user_id'];

$user = $db->query("SELECT u.*, r.room_number, r.room_type, r.floor, r.capacity, r.occupied FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.id = $user_id")->fetch_assoc();

// Recent complaints
$complaints = $db->query("SELECT * FROM complaints WHERE user_id=$user_id ORDER BY created_at DESC LIMIT 5");
$total_complaints = $db->query("SELECT COUNT(*) as cnt FROM complaints WHERE user_id=$user_id")->fetch_assoc()['cnt'];
$pending_complaints = $db->query("SELECT COUNT(*) as cnt FROM complaints WHERE user_id=$user_id AND status='pending'")->fetch_assoc()['cnt'];
$resolved_complaints = $db->query("SELECT COUNT(*) as cnt FROM complaints WHERE user_id=$user_id AND status='resolved'")->fetch_assoc()['cnt'];

// Announcements
$announcements = $db->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5");

function statusBadge($s) {
    $map = ['pending'=>'badge-warning','in_progress'=>'badge-info','resolved'=>'badge-success','rejected'=>'badge-danger'];
    $labels = ['pending'=>'⏳ Pending','in_progress'=>'🔧 In Progress','resolved'=>'✅ Resolved','rejected'=>'❌ Rejected'];
    return "<span class='badge {$map[$s]}'>{$labels[$s]}</span>";
}

$initials = strtoupper(substr($user['full_name'],0,1)) . (strpos($user['full_name'],' ') !== false ? strtoupper(substr(strrchr($user['full_name'],' '),1,1)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Dashboard - Residex Manager</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🏠</div>
        <div class="brand-text">
          <div class="name">Residex Manager</div>
          <div class="tag">Resident Portal</div>
        </div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <a href="dashboard.php" class="nav-item active">
        <span class="icon">📊</span> Dashboard
      </a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item">
        <span class="icon">🔧</span> Complaints
        <?php if ($pending_complaints > 0): ?><span class="nav-badge"><?= $pending_complaints ?></span><?php endif; ?>
      </a>
      <a href="profile.php" class="nav-item">
        <span class="icon">👤</span> My Profile
      </a>
      <div class="nav-section-label">Info</div>
      <a href="announcements.php" class="nav-item">
        <span class="icon">📢</span> Announcements
      </a>
    </nav>

    <div class="sidebar-user">
      <div class="user-avatar"><?= $initials ?></div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="urole">Room <?= $user['room_number'] ?? 'N/A' ?></div>
      </div>
      <a href="logout.php" class="logout-btn" title="Logout">⏻</a>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title">
        <h2>Welcome back, <?= explode(' ', $user['full_name'])[0] ?>! 👋</h2>
        <p><?= date('l, F j, Y') ?></p>
      </div>
      <div class="topbar-actions">
        <?php if ($user['status'] === 'pending'): ?>
          <span class="badge badge-warning">⏳ Approval Pending</span>
        <?php else: ?>
          <span class="badge badge-success">✅ Active Resident</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-body">

      <?php if ($user['status'] === 'pending'): ?>
        <div class="alert alert-warning fade-up">
          ⏳ <strong>Account Pending:</strong> Your account is awaiting admin approval. Some features may be limited.
        </div>
      <?php endif; ?>

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card purple fade-up">
          <div class="stat-icon">🏠</div>
          <div class="stat-value"><?= $user['room_number'] ?? '—' ?></div>
          <div class="stat-label">Room Number</div>
          <div class="stat-trend">
            <span>Floor <?= $user['floor'] ?? '—' ?> • Bed <?= $user['bed_number'] ?? '—' ?></span>
          </div>
        </div>

        <div class="stat-card teal fade-up fade-up-1">
          <div class="stat-icon">🛏️</div>
          <div class="stat-value"><?= ucfirst($user['room_type'] ?? '—') ?></div>
          <div class="stat-label">Room Type</div>
          <div class="stat-trend">
            <span><?= $user['occupied'] ?? 0 ?>/<?= $user['capacity'] ?? 0 ?> Occupied</span>
          </div>
        </div>

        <div class="stat-card yellow fade-up fade-up-2">
          <div class="stat-icon">🔧</div>
          <div class="stat-value"><?= $total_complaints ?></div>
          <div class="stat-label">Total Complaints</div>
          <div class="stat-trend"><span><?= $pending_complaints ?> pending</span></div>
        </div>

        <div class="stat-card red fade-up fade-up-3">
          <div class="stat-icon">✅</div>
          <div class="stat-value"><?= $resolved_complaints ?></div>
          <div class="stat-label">Resolved</div>
          <div class="stat-trend trend-up"><span>↑ All time</span></div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns: 1fr 340px; gap: 24px;">

        <!-- Recent Complaints -->
        <div class="card fade-up">
          <div class="card-header">
            <h3>Recent Complaints</h3>
            <a href="complaints.php" class="btn btn-outline btn-sm">View All</a>
          </div>

          <?php if ($complaints->num_rows === 0): ?>
            <div style="text-align:center; padding: 40px 0; color: var(--text-3);">
              <div style="font-size:40px; margin-bottom:12px;">🎉</div>
              <p>No complaints filed yet</p>
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Items</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($c = $complaints->fetch_assoc()):
                    $items = json_decode($c['complaint_items'], true);
                  ?>
                  <tr>
                    <td style="color: var(--text-3); font-size:0.8rem;">#<?= $c['id'] ?></td>
                    <td><?= implode(', ', array_slice($items, 0, 2)) ?><?= count($items) > 2 ? ' +'.( count($items)-2).' more' : '' ?></td>
                    <td>
                      <?php $pc = ['low'=>'badge-success','medium'=>'badge-warning','high'=>'badge-danger']; ?>
                      <span class="badge <?= $pc[$c['priority']] ?>"><?= ucfirst($c['priority']) ?></span>
                    </td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td style="color:var(--text-3); font-size:0.8rem;"><?= date('M j', strtotime($c['created_at'])) ?></td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <div style="margin-top: 20px;">
            <a href="complaints.php#new" class="btn btn-primary">+ File New Complaint</a>
          </div>
        </div>

        <!-- Announcements -->
        <div style="display:flex; flex-direction:column; gap: 24px;">
          <div class="card fade-up fade-up-2">
            <div class="card-header">
              <h3>📢 Announcements</h3>
            </div>
            <?php while ($ann = $announcements->fetch_assoc()): ?>
              <div class="announcement-item <?= $ann['type'] ?>">
                <div class="ann-title"><?= htmlspecialchars($ann['title']) ?></div>
                <div class="ann-content"><?= htmlspecialchars(substr($ann['content'], 0, 80)) ?>...</div>
                <div class="ann-date"><?= date('M j, Y', strtotime($ann['created_at'])) ?></div>
              </div>
            <?php endwhile; ?>
          </div>

          <!-- Room Info Card -->
          <div class="card fade-up fade-up-3">
            <div class="card-header">
              <h3>🏠 Room Info</h3>
            </div>
            <div style="display:grid; gap:12px; font-size:0.875rem;">
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Room No.</span>
                <strong><?= $user['room_number'] ?? 'N/A' ?></strong>
              </div>
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Type</span>
                <strong><?= ucfirst($user['room_type'] ?? 'N/A') ?></strong>
              </div>
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Floor</span>
                <strong><?= $user['floor'] ?? 'N/A' ?></strong>
              </div>
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Bed Number</span>
                <strong>#<?= $user['bed_number'] ?? 'N/A' ?></strong>
              </div>
              <div class="divider"></div>
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Student ID</span>
                <strong><?= $user['student_id'] ?></strong>
              </div>
              <div class="flex justify-between items-center">
                <span style="color:var(--text-2)">Phone</span>
                <strong><?= $user['phone'] ?></strong>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
</body>
</html>
