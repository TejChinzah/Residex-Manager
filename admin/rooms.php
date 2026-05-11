<?php
require_once '../includes/config.php';
requireLogin('admin');

$db      = getDB();
$success = '';
$error   = '';

// ---- POST Handler ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_room') {
        $num   = sanitize($_POST['room_number']);
        $type  = sanitize($_POST['room_type']);
        $floor = intval($_POST['floor']);
        $cap   = $type === 'double' ? 2 : 3;
        $check = $db->query("SELECT id FROM rooms WHERE room_number='$num'");
        if ($check->num_rows > 0) {
            $error = 'Room number already exists.';
        } else {
            $db->query("INSERT INTO rooms (room_number, room_type, floor, capacity) VALUES ('$num','$type',$floor,$cap)");
            header('Location: rooms.php?msg=added'); exit();
        }
    }

    if ($action === 'update_status') {
        $rid    = intval($_POST['room_id']);
        $status = sanitize($_POST['status']);
        $db->query("UPDATE rooms SET status='$status' WHERE id=$rid");
        header('Location: rooms.php?msg=updated'); exit();
    }

    if ($action === 'delete_room') {
        $rid = intval($_POST['room_id']);
        $occ = $db->query("SELECT occupied FROM rooms WHERE id=$rid")->fetch_assoc()['occupied'];
        if ($occ > 0) {
            $error = 'Cannot delete an occupied room. Remove residents first.';
        } else {
            $db->query("DELETE FROM rooms WHERE id=$rid");
            header('Location: rooms.php?msg=deleted'); exit();
        }
    }
}

// Messages
$msg = sanitize($_GET['msg'] ?? '');
if ($msg === 'added')   $success = 'Room added successfully.';
if ($msg === 'updated') $success = 'Room status updated.';
if ($msg === 'deleted') $success = 'Room deleted.';

// View single room
$view_room_id = intval($_GET['view_room'] ?? 0);
$view_room    = null;
$room_members = [];
$room_complaints = [];

if ($view_room_id) {
    $view_room = $db->query("SELECT * FROM rooms WHERE id=$view_room_id")->fetch_assoc();
    if ($view_room) {
        // Residents in this room
        $rm_res = $db->query("SELECT u.*, u.address, u.emergency_contact
            FROM users u
            WHERE u.room_id = $view_room_id
            ORDER BY u.bed_number");
        while ($r = $rm_res->fetch_assoc()) $room_members[] = $r;

        // Recent complaints from this room
        $rc = $db->query("SELECT c.*, u.full_name, u.student_id
            FROM complaints c
            JOIN users u ON c.user_id = u.id
            WHERE c.room_id = $view_room_id
            ORDER BY c.created_at DESC LIMIT 8");
        while ($r = $rc->fetch_assoc()) $room_complaints[] = $r;
    }
}

// All rooms with pending complaint count
$floor_filter = intval($_GET['floor'] ?? 0);
$type_filter  = sanitize($_GET['type'] ?? 'all');
$rwhere = "1=1";
if ($floor_filter) $rwhere .= " AND r.floor=$floor_filter";
if ($type_filter !== 'all') $rwhere .= " AND r.room_type='$type_filter'";

$rooms = $db->query("SELECT r.*,
    (SELECT COUNT(*) FROM complaints c JOIN users u ON c.user_id=u.id
     WHERE u.room_id=r.id AND c.status='pending') as pending_complaints,
    (SELECT COUNT(*) FROM users u2 WHERE u2.room_id=r.id AND u2.status='active') as active_residents
    FROM rooms r WHERE $rwhere ORDER BY r.floor, r.room_number");

// Stats
$total_rooms  = $db->query("SELECT COUNT(*) as c FROM rooms")->fetch_assoc()['c'];
$full_rooms   = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='full'")->fetch_assoc()['c'];
$avail_rooms  = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='available'")->fetch_assoc()['c'];
$maint_rooms  = $db->query("SELECT COUNT(*) as c FROM rooms WHERE status='maintenance'")->fetch_assoc()['c'];
$floors_list  = $db->query("SELECT DISTINCT floor FROM rooms ORDER BY floor");

$complaint_items_icons = [
    'Fan'=>'🌀','Light'=>'💡','Switch'=>'🔌','Door'=>'🚪','Window'=>'🪟',
    'Tap/Faucet'=>'🚿','Toilet'=>'🚽','Bed/Cot'=>'🛏️','AC/Cooler'=>'❄️',
    'Internet/WiFi'=>'📶','Ceiling'=>'🏛️','Cupboard'=>'🗄️'
];

function cStatusBadge($s) {
    $m = ['pending'=>'badge-warning','in_progress'=>'badge-info','resolved'=>'badge-success','rejected'=>'badge-danger'];
    $i = ['pending'=>'⏳','in_progress'=>'🔧','resolved'=>'✅','rejected'=>'❌'];
    return "<span class='badge ".($m[$s]??'badge-muted')."'>".($i[$s]??'')." ".ucfirst(str_replace('_',' ',$s))."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rooms - Residex Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    /* Room cards grid */
    .rooms-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; }

    .room-card {
      background:var(--bg-card); border:1px solid var(--border);
      border-radius:14px; padding:16px; cursor:pointer;
      transition:all 0.2s; position:relative; overflow:hidden;
    }
    .room-card:hover { transform:translateY(-2px); box-shadow:0 6px 24px rgba(0,0,0,0.3); border-color:var(--accent); }
    .room-card.full    { border-color:rgba(255,107,107,0.4); background:rgba(255,107,107,0.04); }
    .room-card.partial { border-color:rgba(255,209,102,0.4); background:rgba(255,209,102,0.04); }
    .room-card.empty   { border-color:rgba(0,212,170,0.4);   background:rgba(0,212,170,0.04); }
    .room-card.maintenance { border-color:rgba(108,99,255,0.4); background:rgba(108,99,255,0.04); }

    .room-num { font-family:'Syne',sans-serif; font-weight:900; font-size:1.4rem; letter-spacing:-0.03em; margin-bottom:6px; }
    .room-meta { font-size:0.72rem; color:var(--text-3); margin-bottom:10px; }
    .room-occ-bar { background:rgba(255,255,255,0.08); border-radius:99px; height:5px; overflow:hidden; margin-bottom:8px; }
    .room-occ-fill { height:100%; border-radius:99px; }
    .fill-green  { background:linear-gradient(90deg,#00d4aa,#00ffcc); }
    .fill-yellow { background:linear-gradient(90deg,#ffd166,#ffec8b); }
    .fill-red    { background:linear-gradient(90deg,#ff6b6b,#ff9999); }
    .fill-purple { background:linear-gradient(90deg,#6c63ff,#9f97ff); }
    .room-footer { display:flex; justify-content:space-between; align-items:center; font-size:0.72rem; }
    .complaint-dot { background:var(--accent3); color:white; border-radius:99px; padding:1px 7px; font-size:0.65rem; font-weight:700; }

    /* Room detail panel */
    .room-detail { background:var(--bg-card); border:1px solid var(--border); border-radius:16px; overflow:hidden; margin-bottom:24px; }
    .room-detail-header { background:linear-gradient(135deg,#1a1535,#0c1f2a); padding:24px 28px; position:relative; overflow:hidden; }
    .room-detail-header::before { content:''; position:absolute; top:-60px; right:-60px; width:200px; height:200px; background:rgba(108,99,255,0.15); border-radius:50%; }
    .room-detail-header::after  { content:''; position:absolute; bottom:-40px; left:-40px; width:140px; height:140px; background:rgba(0,212,170,0.1); border-radius:50%; }

    .resident-profile {
      background:var(--bg-glass); border:1px solid var(--border);
      border-radius:12px; padding:16px; margin-bottom:10px;
    }
    .rp-header { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
    .rp-avatar { width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--accent2)); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1rem; flex-shrink:0; }
    .rp-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:0.8rem; }
    .rp-field { }
    .rp-label { font-size:0.65rem; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-3); margin-bottom:2px; }
    .rp-value { font-weight:600; color:var(--text-1); }
    .rp-address { grid-column:1/-1; }
    .bed-badge { background:rgba(108,99,255,0.15); color:var(--accent); border:1px solid rgba(108,99,255,0.3); padding:3px 10px; border-radius:99px; font-size:0.7rem; font-weight:700; }

    .filter-pill { display:flex; gap:8px; flex-wrap:wrap; }
    .section-hdr { font-size:0.68rem; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-3); font-weight:700; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--border); }
  </style>
</head>
<body>
<div class="app-wrapper">

  <!-- Sidebar -->
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
      <a href="rooms.php" class="nav-item active"><span class="icon">🏠</span> Rooms</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="announcements.php" class="nav-item"><span class="icon">📢</span> Announcements</a>
    </nav>
    <div class="sidebar-user">
      <div class="user-avatar" style="background:linear-gradient(135deg,var(--accent4),var(--accent3));">A</div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($_SESSION['admin_name']) ?></div>
        <div class="urole" style="color:var(--accent4);">Administrator</div>
      </div>
      <a href="logout.php" class="logout-btn">⏻</a>
    </div>
  </aside>

  <!-- Main -->
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-title">
        <h2>🏠 Room Management</h2>
        <p>View rooms, residents, occupancy and complaints</p>
      </div>
      <div class="topbar-actions">
        <a href="rooms.php" class="btn btn-outline btn-sm">All Rooms</a>
        <button class="btn btn-primary" onclick="document.getElementById('addRoomModal').classList.add('open')">
          + Add Room
        </button>
      </div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

      <!-- Stats -->
      <div class="stats-grid" style="margin-bottom:24px;">
        <div class="stat-card purple fade-up">
          <div class="stat-icon">🏠</div>
          <div class="stat-value"><?= $total_rooms ?></div>
          <div class="stat-label">Total Rooms</div>
        </div>
        <div class="stat-card teal fade-up fade-up-1">
          <div class="stat-icon">✅</div>
          <div class="stat-value"><?= $avail_rooms ?></div>
          <div class="stat-label">Available</div>
        </div>
        <div class="stat-card red fade-up fade-up-2">
          <div class="stat-icon">🔴</div>
          <div class="stat-value"><?= $full_rooms ?></div>
          <div class="stat-label">Full</div>
        </div>
        <div class="stat-card yellow fade-up fade-up-3">
          <div class="stat-icon">🔧</div>
          <div class="stat-value"><?= $maint_rooms ?></div>
          <div class="stat-label">Maintenance</div>
        </div>
      </div>

      <!-- ===== ROOM DETAIL VIEW ===== -->
      <?php if ($view_room): ?>
      <div class="room-detail fade-up">

        <!-- Detail Header -->
        <div class="room-detail-header">
          <div style="position:relative;z-index:1;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
              <div>
                <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.12em;color:rgba(255,255,255,0.5);margin-bottom:6px;">Room Details</div>
                <div style="font-family:'Syne',sans-serif;font-weight:900;font-size:2.5rem;letter-spacing:-0.04em;line-height:1;">
                  Room <?= $view_room['room_number'] ?>
                </div>
                <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;align-items:center;">
                  <span class="badge badge-info">Floor <?= $view_room['floor'] ?></span>
                  <span class="badge badge-muted"><?= ucfirst($view_room['room_type']) ?> Room</span>
                  <?php
                  $vstatus = $view_room['status'];
                  $vs_map  = ['available'=>'badge-success','full'=>'badge-danger','maintenance'=>'badge-info'];
                  ?>
                  <span class="badge <?= $vs_map[$vstatus]??'badge-muted' ?>"><?= ucfirst($vstatus) ?></span>
                </div>
              </div>
              <div style="text-align:right;">
                <div style="font-size:0.7rem;color:rgba(255,255,255,0.5);margin-bottom:4px;">Occupancy</div>
                <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:2rem;">
                  <?= $view_room['occupied'] ?><span style="font-size:1rem;color:rgba(255,255,255,0.4);">/<?= $view_room['capacity'] ?></span>
                </div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.5);margin-top:2px;">beds occupied</div>
              </div>
            </div>

            <!-- Occupancy bar -->
            <?php $op = $view_room['capacity']>0 ? round(($view_room['occupied']/$view_room['capacity'])*100) : 0; ?>
            <div style="margin-top:16px;">
              <div style="background:rgba(255,255,255,0.1);border-radius:99px;height:8px;overflow:hidden;">
                <div style="height:100%;border-radius:99px;width:<?= $op ?>%;background:<?= $op>=100?'#ff6b6b':($op>0?'#00d4aa':'rgba(255,255,255,0.3)') ?>;transition:width 1s ease;"></div>
              </div>
              <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);margin-top:6px;"><?= $op ?>% occupied · <?= $view_room['capacity']-$view_room['occupied'] ?> bed<?= $view_room['capacity']-$view_room['occupied']!=1?'s':'' ?> available</div>
            </div>
          </div>
        </div>

        <div style="padding:24px 28px;">
          <div style="display:grid;grid-template-columns:1fr 320px;gap:24px;">

            <!-- Left: Residents -->
            <div>
              <div class="section-hdr">🛏️ Residents in this Room (<?= count($room_members) ?>)</div>

              <?php if (empty($room_members)): ?>
                <div style="text-align:center;padding:40px;color:var(--text-3);background:var(--bg-glass);border:1px solid var(--border);border-radius:12px;">
                  <div style="font-size:36px;margin-bottom:10px;">🏠</div>
                  This room is currently empty.
                </div>
              <?php else: foreach ($room_members as $m):
                $sc=['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger'];
              ?>
              <div class="resident-profile">
                <div class="rp-header">
                  <div class="rp-avatar"><?= strtoupper(substr($m['full_name'],0,1)) ?></div>
                  <div style="flex:1;">
                    <div style="font-weight:700;font-size:0.95rem;"><?= htmlspecialchars($m['full_name']) ?></div>
                    <div style="font-size:0.72rem;color:var(--text-2);margin-top:2px;"><?= $m['student_id'] ?></div>
                  </div>
                  <span class="bed-badge">Bed #<?= $m['bed_number'] ?? '—' ?></span>
                  <span class="badge <?= $sc[$m['status']] ?>"><?= ucfirst($m['status']) ?></span>
                  <a href="residents.php?edit=<?= $m['id'] ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
                </div>
                <div class="rp-grid">
                  <div class="rp-field">
                    <div class="rp-label">Email</div>
                    <div class="rp-value" style="font-size:0.78rem;"><?= htmlspecialchars($m['email']) ?></div>
                  </div>
                  <div class="rp-field">
                    <div class="rp-label">Phone</div>
                    <div class="rp-value"><?= htmlspecialchars($m['phone']) ?></div>
                  </div>
                  <div class="rp-field">
                    <div class="rp-label">Gender</div>
                    <div class="rp-value"><?= ucfirst($m['gender']) ?></div>
                  </div>
                  <div class="rp-field">
                    <div class="rp-label">Emergency Contact</div>
                    <div class="rp-value"><?= $m['emergency_contact'] ? htmlspecialchars($m['emergency_contact']) : '—' ?></div>
                  </div>
                  <?php if ($m['address']): ?>
                  <div class="rp-field rp-address">
                    <div class="rp-label">Home Address</div>
                    <div class="rp-value" style="font-weight:400;color:var(--text-2);line-height:1.5;">
                      📍 <?= nl2br(htmlspecialchars($m['address'])) ?>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; endif; ?>
            </div>

            <!-- Right: Room Controls + Complaints -->
            <div>
              <!-- Status Update -->
              <div style="margin-bottom:20px;">
                <div class="section-hdr">⚙️ Room Settings</div>
                <form method="POST">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="room_id" value="<?= $view_room['id'] ?>">
                  <div class="form-group">
                    <label class="form-label">Room Status</label>
                    <select name="status" class="form-select">
                      <?php foreach (['available'=>'✅ Available','full'=>'🔴 Full','maintenance'=>'🔧 Maintenance'] as $s=>$l): ?>
                        <option value="<?= $s ?>" <?= $view_room['status']===$s?'selected':'' ?>><?= $l ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;">Update Status</button>
                </form>

                <?php if ($view_room['occupied'] == 0): ?>
                <form method="POST" style="margin-top:8px;" onsubmit="return confirm('Delete this room permanently?')">
                  <input type="hidden" name="action" value="delete_room">
                  <input type="hidden" name="room_id" value="<?= $view_room['id'] ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="width:100%;justify-content:center;color:var(--accent3);">🗑️ Delete Room</button>
                </form>
                <?php endif; ?>
              </div>

              <!-- Recent Complaints -->
              <div>
                <div class="section-hdr">🔧 Complaints from this Room (<?= count($room_complaints) ?>)</div>
                <?php if (empty($room_complaints)): ?>
                  <div style="text-align:center;padding:20px;color:var(--text-3);font-size:0.82rem;background:var(--bg-glass);border:1px solid var(--border);border-radius:10px;">
                    🎉 No complaints from this room
                  </div>
                <?php else: foreach ($room_complaints as $c):
                  $items = json_decode($c['complaint_items'], true);
                  if (!is_array($items)) $items = [];
                ?>
                <div style="background:var(--bg-glass);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:8px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-size:0.8rem;font-weight:600;"><?= htmlspecialchars($c['full_name']) ?></span>
                    <?= cStatusBadge($c['status']) ?>
                  </div>
                  <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:5px;">
                    <?php foreach ($items as $it): ?>
                      <span style="background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:99px;padding:2px 8px;font-size:0.68rem;">
                        <?= $complaint_items_icons[$it]??'🔧' ?> <?= htmlspecialchars($it) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <div style="font-size:0.68rem;color:var(--text-3);"><?= date('d M Y', strtotime($c['created_at'])) ?></div>
                </div>
                <?php endforeach; endif; ?>
              </div>
            </div>

          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- ===== ROOM FILTERS ===== -->
      <div class="card fade-up" style="margin-bottom:20px;padding:14px 20px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="filter-pill">
            <a href="rooms.php" class="btn <?= !$floor_filter && $type_filter==='all'?'btn-primary':'btn-outline' ?> btn-sm">All</a>
            <?php if ($floors_list): while ($fl=$floors_list->fetch_assoc()): ?>
              <a href="rooms.php?floor=<?= $fl['floor'] ?>" class="btn <?= $floor_filter==$fl['floor']?'btn-primary':'btn-outline' ?> btn-sm">
                Floor <?= $fl['floor'] ?>
              </a>
            <?php endwhile; endif; ?>
            <a href="rooms.php?type=double" class="btn <?= $type_filter==='double'?'btn-primary':'btn-outline' ?> btn-sm">🛏️ Double</a>
            <a href="rooms.php?type=triple" class="btn <?= $type_filter==='triple'?'btn-primary':'btn-outline' ?> btn-sm">🛏️ Triple</a>
          </div>
          <div style="margin-left:auto;display:flex;gap:12px;font-size:0.75rem;color:var(--text-3);">
            <span>🟢 Available</span>
            <span>🟡 Partial</span>
            <span>🔴 Full</span>
            <span>🟣 Maintenance</span>
          </div>
        </div>
      </div>

      <!-- ===== ROOMS GRID ===== -->
      <div class="rooms-grid fade-up">
        <?php if ($rooms): while ($r = $rooms->fetch_assoc()):
          if ($r['status']==='maintenance') $cls='maintenance';
          elseif ($r['occupied']>=$r['capacity']) $cls='full';
          elseif ($r['occupied']>0) $cls='partial';
          else $cls='empty';

          $op   = $r['capacity']>0 ? round(($r['occupied']/$r['capacity'])*100) : 0;
          $fcls = $cls==='full'?'fill-red':($cls==='partial'?'fill-yellow':($cls==='maintenance'?'fill-purple':'fill-green'));
        ?>
        <a href="rooms.php?view_room=<?= $r['id'] ?>" style="text-decoration:none;">
          <div class="room-card <?= $cls ?>">
            <div class="room-num">Room <?= $r['room_number'] ?></div>
            <div class="room-meta">
              Floor <?= $r['floor'] ?> · <?= ucfirst($r['room_type']) ?>
            </div>
            <div class="room-occ-bar">
              <div class="room-occ-fill <?= $fcls ?>" style="width:<?= $op ?>%"></div>
            </div>
            <div class="room-footer">
              <span style="color:var(--text-2);">
                <?= $r['occupied'] ?>/<?= $r['capacity'] ?> beds
              </span>
              <div style="display:flex;gap:5px;align-items:center;">
                <?php if ($r['pending_complaints']>0): ?>
                  <span class="complaint-dot">🔧 <?= $r['pending_complaints'] ?></span>
                <?php endif; ?>
                <?php
                $st_colors=['available'=>'var(--accent2)','full'=>'var(--accent3)','maintenance'=>'var(--accent)'];
                $st_dots=['available'=>'●','full'=>'●','maintenance'=>'●'];
                ?>
                <span style="color:<?= $st_colors[$r['status']]??'var(--text-3)' ?>;font-size:0.65rem;font-weight:700;">
                  <?= ucfirst($r['status']) ?>
                </span>
              </div>
            </div>
          </div>
        </a>
        <?php endwhile; else: ?>
          <div style="grid-column:1/-1;text-align:center;padding:60px;color:var(--text-3);">
            No rooms found. Add one using the button above.
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<!-- ===== ADD ROOM MODAL ===== -->
<div class="modal-overlay" id="addRoomModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header">
      <h3>🏠 Add New Room</h3>
      <button class="modal-close" onclick="document.getElementById('addRoomModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_room">
      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Room No. *</label>
          <input type="text" name="room_number" class="form-input" placeholder="e.g. 501" required>
        </div>
        <div class="form-group">
          <label class="form-label">Type *</label>
          <select name="room_type" class="form-select" required>
            <option value="double">Double (2)</option>
            <option value="triple">Triple (3)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Floor *</label>
          <input type="number" name="floor" class="form-input" min="1" max="20" value="1" required>
        </div>
      </div>
      <div style="background:rgba(108,99,255,0.08);border:1px solid rgba(108,99,255,0.2);border-radius:10px;padding:12px;margin-bottom:16px;font-size:0.82rem;color:var(--text-2);">
        <strong style="color:var(--accent);">ℹ️</strong>
        Double room = 2 beds. Triple room = 3 beds. Capacity is set automatically.
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;">
        🏠 Add Room
      </button>
    </form>
  </div>
</div>

<!-- Footer -->
<footer class="dev-footer no-sidebar">
  <div class="dev-footer-inner">
    <span>&copy; <?php echo date("Y"); ?> Residex Manager</span>
    <span class="dot">&#9679;</span>
    <span>Designed &amp; Developed with</span>
    <span class="heart">&#9829;</span>
    <span>by <span class="dev-name">Tej Chinzah</span></span>
  </div>
</footer>
</body>
</html>
