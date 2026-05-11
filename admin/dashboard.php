<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();

// Analytics data
$total_capacity = 100; // Max hostel capacity
$total_members  = $db->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
$active_members = $db->query("SELECT COUNT(*) as c FROM users WHERE status='active'")->fetch_assoc()['c'];
$pending_members = $db->query("SELECT COUNT(*) as c FROM users WHERE status='pending'")->fetch_assoc()['c'];

$total_rooms   = $db->query("SELECT COUNT(*) as c FROM rooms")->fetch_assoc()['c'];
$full_rooms    = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='full'")->fetch_assoc()['c'];
$avail_rooms   = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='available'")->fetch_assoc()['c'];
$maint_rooms   = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='maintenance'")->fetch_assoc()['c'];

$total_complaints   = $db->query("SELECT COUNT(*) as c FROM complaints")->fetch_assoc()['c'];
$pending_complaints = $db->query("SELECT COUNT(*) as c FROM complaints WHERE status='pending'")->fetch_assoc()['c'];
$inprog_complaints  = $db->query("SELECT COUNT(*) as c FROM complaints WHERE status='in_progress'")->fetch_assoc()['c'];
$resolved_complaints = $db->query("SELECT COUNT(*) as c FROM complaints WHERE status='resolved'")->fetch_assoc()['c'];

// Complaint items frequency
$items_freq = [];
$all_items = $db->query("SELECT complaint_items FROM complaints");
while ($r = $all_items->fetch_assoc()) {
    $items = json_decode($r['complaint_items'], true);
    foreach ($items as $item) {
        $items_freq[$item] = ($items_freq[$item] ?? 0) + 1;
    }
}
arsort($items_freq);

// Recent complaints
$recent_complaints = $db->query("SELECT c.*, u.full_name, u.student_id, r.room_number FROM complaints c JOIN users u ON c.user_id=u.id JOIN rooms r ON c.room_id=r.id ORDER BY c.created_at DESC LIMIT 8");

// Recent users
$recent_users = $db->query("SELECT u.*, r.room_number, r.room_type FROM users u LEFT JOIN rooms r ON u.room_id=r.id ORDER BY u.created_at DESC LIMIT 6");

// Rooms data for occupancy grid
$rooms_data = $db->query("SELECT * FROM rooms ORDER BY floor, room_number");

// Monthly complaint trend (last 6 months)
$monthly_data = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M', strtotime("-$i months"));
    $cnt = $db->query("SELECT COUNT(*) as c FROM complaints WHERE DATE_FORMAT(created_at,'%Y-%m')='$month'")->fetch_assoc()['c'];
    $monthly_data[] = ['label' => $label, 'count' => (int)$cnt];
}

$max_monthly = max(array_column($monthly_data, 'count') ?: [1]);
$occupancy_pct = $total_capacity > 0 ? round(($active_members / $total_capacity) * 100) : 0;

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
  <title>Admin Dashboard - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .metric-ring { position: relative; display: inline-flex; align-items: center; justify-content: center; }
    .metric-ring svg { transform: rotate(-90deg); }
    .ring-label { position: absolute; text-align: center; }
    .ring-label .val { font-family:'Syne',sans-serif; font-size:1.4rem; font-weight:800; display:block; }
    .ring-label .sub { font-size:0.65rem; color:var(--text-2); }

    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
    .grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:24px; }
    .grid-auto { display:grid; grid-template-columns: 2fr 1fr; gap:24px; }

    .top-item { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--border); }
    .top-item:last-child { border-bottom:none; }
    .top-bar-bg { background:var(--bg-glass); border-radius:99px; height:6px; flex:1; margin:0 12px; overflow:hidden; }
    .top-bar-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--accent),var(--accent2)); }
  </style>
</head>
<body>
<div class="app-wrapper">

  <!-- Admin Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🛡️</div>
        <div class="brand-text">
          <div class="name">Residex</div>
          <div class="tag">Admin Panel</div>
        </div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Analytics</div>
      <a href="dashboard.php" class="nav-item active"><span class="icon">📊</span> Dashboard</a>

      <div class="nav-section-label">Management</div>
      <a href="residents.php" class="nav-item">
        <span class="icon">👥</span> Residents
        <?php if ($pending_members > 0): ?><span class="nav-badge"><?= $pending_members ?></span><?php endif; ?>
      </a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item">
        <span class="icon">🔧</span> Complaints
        <?php if ($pending_complaints > 0): ?><span class="nav-badge"><?= $pending_complaints ?></span><?php endif; ?>
      </a>
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
        <h2>Analytics Dashboard</h2>
        <p><?= date('l, F j, Y') ?> · Residex Manager</p>
      </div>
      <div class="topbar-actions">
        <span class="badge badge-success">🟢 System Online</span>
        <a href="residents.php?filter=pending" class="btn btn-outline btn-sm">
          <?php if ($pending_members > 0): ?>⚠️ <?= $pending_members ?> Pending Approvals<?php else: ?>✅ All Approved<?php endif; ?>
        </a>
      </div>
    </div>

    <div class="page-body">

      <!-- KPI Stats -->
      <div class="stats-grid">
        <div class="stat-card purple fade-up">
          <div class="stat-icon">👥</div>
          <div class="stat-value"><?= $active_members ?><span style="font-size:1rem;color:var(--text-3);">/<?= $total_capacity ?></span></div>
          <div class="stat-label">Active Residents</div>
          <div class="progress-bar"><div class="progress-fill fill-purple" style="width:<?= $occupancy_pct ?>%"></div></div>
          <div class="stat-trend"><span style="color:var(--text-3);"><?= $occupancy_pct ?>% occupancy</span></div>
        </div>

        <div class="stat-card teal fade-up fade-up-1">
          <div class="stat-icon">🏠</div>
          <div class="stat-value"><?= $avail_rooms ?><span style="font-size:1rem;color:var(--text-3);">/<?= $total_rooms ?></span></div>
          <div class="stat-label">Available Rooms</div>
          <div class="progress-bar"><div class="progress-fill fill-teal" style="width:<?= $total_rooms > 0 ? round(($avail_rooms/$total_rooms)*100) : 0 ?>%"></div></div>
          <div class="stat-trend"><span style="color:var(--text-3);"><?= $full_rooms ?> full · <?= $maint_rooms ?> maintenance</span></div>
        </div>

        <div class="stat-card yellow fade-up fade-up-2">
          <div class="stat-icon">🔧</div>
          <div class="stat-value"><?= $pending_complaints ?></div>
          <div class="stat-label">Pending Complaints</div>
          <div class="progress-bar"><div class="progress-fill fill-yellow" style="width:<?= $total_complaints > 0 ? round(($pending_complaints/$total_complaints)*100) : 0 ?>%"></div></div>
          <div class="stat-trend"><span style="color:var(--text-3);"><?= $inprog_complaints ?> in progress</span></div>
        </div>

        <div class="stat-card red fade-up fade-up-3">
          <div class="stat-icon">⏳</div>
          <div class="stat-value"><?= $pending_members ?></div>
          <div class="stat-label">Pending Approvals</div>
          <div class="progress-bar"><div class="progress-fill fill-red" style="width:<?= $total_members > 0 ? round(($pending_members/$total_members)*100) : 0 ?>%"></div></div>
          <div class="stat-trend"><span style="color:var(--text-3);"><?= $total_members ?> total registered</span></div>
        </div>
      </div>

      <!-- Row 2: Charts -->
      <div class="grid-auto" style="margin-bottom: 24px;">

        <!-- Monthly Complaint Trend -->
        <div class="card fade-up">
          <div class="card-header">
            <h3>📈 Complaint Trends (6 Months)</h3>
          </div>
          <div class="bar-chart" style="height:140px;">
            <?php foreach ($monthly_data as $m): ?>
              <div class="bar-col">
                <div class="bar" data-val="<?= $m['count'] ?>"
                  style="height:<?= $max_monthly > 0 ? max(4, round(($m['count']/$max_monthly)*120)) : 4 ?>px;"></div>
                <div class="bar-label"><?= $m['label'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex; gap:20px; margin-top:20px; font-size:0.8rem;">
            <div><span style="color:var(--text-2)">Total</span> <strong><?= $total_complaints ?></strong></div>
            <div><span style="color:var(--text-2)">Resolved</span> <strong style="color:var(--accent2)"><?= $resolved_complaints ?></strong></div>
            <div><span style="color:var(--text-2)">Pending</span> <strong style="color:var(--accent4)"><?= $pending_complaints ?></strong></div>
          </div>
        </div>

        <!-- Occupancy Donut + Complaint Status -->
        <div style="display:flex; flex-direction:column; gap: 24px;">

          <!-- Occupancy Ring -->
          <div class="card fade-up fade-up-1">
            <div class="card-header"><h3>🏠 Occupancy</h3></div>
            <div style="display:flex; align-items:center; gap:20px;">
              <div class="metric-ring">
                <?php
                  $r = 40; $circ = 2 * M_PI * $r;
                  $fill = $circ * ($occupancy_pct / 100);
                  $gap  = $circ - $fill;
                ?>
                <svg width="110" height="110" viewBox="0 0 110 110">
                  <circle cx="55" cy="55" r="<?=$r?>" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10"/>
                  <circle cx="55" cy="55" r="<?=$r?>" fill="none" stroke="url(#occ)" stroke-width="10"
                    stroke-dasharray="<?= round($fill,1) ?> <?= round($gap,1) ?>" stroke-linecap="round"/>
                  <defs>
                    <linearGradient id="occ" x1="0%" y1="0%" x2="100%" y2="0%">
                      <stop offset="0%" stop-color="#6c63ff"/>
                      <stop offset="100%" stop-color="#00d4aa"/>
                    </linearGradient>
                  </defs>
                </svg>
                <div class="ring-label">
                  <span class="val"><?= $occupancy_pct ?>%</span>
                  <span class="sub">Occupied</span>
                </div>
              </div>
              <div style="flex:1; font-size:0.8rem; display:grid; gap:8px;">
                <div class="flex justify-between"><span style="color:var(--text-2)">Active</span><strong style="color:var(--accent2)"><?= $active_members ?></strong></div>
                <div class="flex justify-between"><span style="color:var(--text-2)">Vacant</span><strong><?= $total_capacity - $active_members ?></strong></div>
                <div class="flex justify-between"><span style="color:var(--text-2)">Pending</span><strong style="color:var(--accent4)"><?= $pending_members ?></strong></div>
              </div>
            </div>
          </div>

          <!-- Complaint Status -->
          <div class="card fade-up fade-up-2">
            <div class="card-header"><h3>📋 Complaint Status</h3></div>
            <div style="display:grid; gap:10px; font-size:0.82rem;">
              <?php
              $cstats = [
                ['Pending', $pending_complaints, '#ffd166', $total_complaints],
                ['In Progress', $inprog_complaints, '#6c63ff', $total_complaints],
                ['Resolved', $resolved_complaints, '#00d4aa', $total_complaints],
              ];
              foreach ($cstats as [$label, $cnt, $color, $total]):
                $pct = $total > 0 ? round(($cnt/$total)*100) : 0;
              ?>
              <div>
                <div class="flex justify-between" style="margin-bottom:4px;">
                  <span style="color:var(--text-2)"><?= $label ?></span>
                  <strong><?= $cnt ?></strong>
                </div>
                <div class="progress-bar">
                  <div class="progress-fill" style="width:<?=$pct?>%; background:<?=$color?>;"></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Row 3: Top Complaint Items + Room Grid -->
      <div class="grid-2" style="margin-bottom: 24px;">

        <!-- Top Complained Items -->
        <div class="card fade-up">
          <div class="card-header"><h3>🔧 Most Reported Issues</h3></div>
          <?php if (empty($items_freq)): ?>
            <p style="color:var(--text-3); text-align:center; padding:24px;">No complaints yet.</p>
          <?php else:
            $max_freq = max($items_freq);
            $count = 0;
            foreach ($items_freq as $item => $freq):
              if ($count++ >= 8) break;
              $pct = $max_freq > 0 ? round(($freq/$max_freq)*100) : 0;
          ?>
          <div class="top-item">
            <span style="font-size:0.85rem; min-width:110px;"><?= htmlspecialchars($item) ?></span>
            <div class="top-bar-bg"><div class="top-bar-fill" style="width:<?=$pct?>%"></div></div>
            <strong style="font-size:0.85rem; min-width:24px; text-align:right;"><?= $freq ?></strong>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Room Occupancy Grid -->
        <div class="card fade-up fade-up-1">
          <div class="card-header"><h3>🏠 Room Status</h3></div>
          <div style="display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
            <span style="display:flex;align-items:center;gap:6px;font-size:0.72rem;color:var(--text-2)"><span style="width:10px;height:10px;background:rgba(0,212,170,0.4);border-radius:2px;display:inline-block;"></span>Available</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:0.72rem;color:var(--text-2)"><span style="width:10px;height:10px;background:rgba(255,209,102,0.4);border-radius:2px;display:inline-block;"></span>Partial</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:0.72rem;color:var(--text-2)"><span style="width:10px;height:10px;background:rgba(255,107,107,0.4);border-radius:2px;display:inline-block;"></span>Full</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:0.72rem;color:var(--text-2)"><span style="width:10px;height:10px;background:rgba(108,99,255,0.4);border-radius:2px;display:inline-block;"></span>Maint.</span>
          </div>
          <div class="room-grid" style="max-height:260px; overflow-y:auto;">
            <?php
            $rooms_data->data_seek(0);
            while ($room = $rooms_data->fetch_assoc()):
              if ($room['status'] === 'maintenance') $cls = 'maintenance';
              elseif ($room['occupied'] >= $room['capacity']) $cls = 'full';
              elseif ($room['occupied'] > 0) $cls = 'partial';
              else $cls = 'empty';
            ?>
            <div class="room-cell <?= $cls ?>" title="Room <?= $room['room_number'] ?>: <?= $room['occupied'] ?>/<?= $room['capacity'] ?>">
              <div class="rnum"><?= $room['room_number'] ?></div>
              <div style="font-size:0.62rem; color:var(--text-3);"><?= $room['occupied'] ?>/<?= $room['capacity'] ?></div>
              <div style="font-size:0.58rem; color:var(--text-3);"><?= $room['room_type'][0] ?>rm</div>
            </div>
            <?php endwhile; ?>
          </div>
        </div>
      </div>

      <!-- Recent Complaints Table -->
      <div class="card fade-up" style="margin-bottom: 24px;">
        <div class="card-header">
          <h3>🔧 Recent Complaints</h3>
          <a href="complaints.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Resident</th>
                <th>Room</th>
                <th>Items</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($c = $recent_complaints->fetch_assoc()):
                $items = json_decode($c['complaint_items'], true);
                $pc = ['low'=>'badge-success','medium'=>'badge-warning','high'=>'badge-danger'];
              ?>
              <tr>
                <td style="color:var(--text-3); font-size:0.8rem;">#<?= $c['id'] ?></td>
                <td>
                  <div style="font-weight:600; font-size:0.875rem;"><?= htmlspecialchars($c['full_name']) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-3);"><?= $c['student_id'] ?></div>
                </td>
                <td><span class="badge badge-muted">Room <?= $c['room_number'] ?></span></td>
                <td style="font-size:0.82rem; color:var(--text-2);"><?= implode(', ', array_slice($items, 0, 2)) ?><?= count($items) > 2 ? ' +'.( count($items)-2) : '' ?></td>
                <td><span class="badge <?= $pc[$c['priority']] ?>"><?= ucfirst($c['priority']) ?></span></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td style="font-size:0.75rem; color:var(--text-3);"><?= date('M j', strtotime($c['created_at'])) ?></td>
                <td><a href="complaints.php?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Manage</a></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent Residents -->
      <div class="card fade-up">
        <div class="card-header">
          <h3>👥 Recently Registered</h3>
          <a href="residents.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Student ID</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Room</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($u = $recent_users->fetch_assoc()):
                $sc = ['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger'];
              ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></td>
                <td style="color:var(--text-2);"><?= $u['student_id'] ?></td>
                <td style="color:var(--text-2); font-size:0.82rem;"><?= $u['email'] ?></td>
                <td style="color:var(--text-2);"><?= $u['phone'] ?></td>
                <td><?= $u['room_number'] ? "<span class='badge badge-muted'>Room {$u['room_number']}</span>" : '—' ?></td>
                <td><span class="badge <?= $sc[$u['status']] ?>"><?= ucfirst($u['status']) ?></span></td>
                <td><a href="residents.php?edit=<?= $u['id'] ?>" class="btn btn-outline btn-sm">Manage</a></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
<footer class="dev-footer no-sidebar">
  <div class="dev-footer-inner">
    <span>&copy; <?php echo date("Y"); ?> Residex Manager</span>
    <span class="dot">&#9679;</span>
    <span>Designed &amp; Developed with</span>
    <span class="heart">&#9829;</span>
    <span>by <span class="dev-name">Shit Happens Inc.</span></span>
  </div>
</footer>
</body>
</html>
