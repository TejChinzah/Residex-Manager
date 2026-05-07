<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_room') {
        $num  = sanitize($_POST['room_number']);
        $type = sanitize($_POST['room_type']);
        $floor = intval($_POST['floor']);
        $cap  = $type === 'double' ? 2 : 3;

        $check = $db->query("SELECT id FROM rooms WHERE room_number='$num'");
        if ($check->num_rows > 0) {
            $error = 'Room number already exists.';
        } else {
            $db->query("INSERT INTO rooms (room_number, room_type, floor, capacity) VALUES ('$num','$type',$floor,$cap)");
            $success = 'Room added successfully.';
        }
    } elseif ($action === 'update_status') {
        $rid = intval($_POST['room_id']);
        $status = sanitize($_POST['status']);
        $db->query("UPDATE rooms SET status='$status' WHERE id=$rid");
        $success = 'Room status updated.';
    } elseif ($action === 'delete_room') {
        $rid = intval($_POST['room_id']);
        $occ = $db->query("SELECT occupied FROM rooms WHERE id=$rid")->fetch_assoc()['occupied'];
        if ($occ > 0) {
            $error = 'Cannot delete occupied room.';
        } else {
            $db->query("DELETE FROM rooms WHERE id=$rid");
            $success = 'Room deleted.';
        }
    }
}

$rooms = $db->query("SELECT r.*, (SELECT COUNT(*) FROM complaints c JOIN users u ON c.user_id=u.id WHERE u.room_id=r.id AND c.status='pending') as pending_complaints FROM rooms r ORDER BY floor, room_number");

$floor_filter = intval($_GET['floor'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rooms - Residex Admin</title>
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
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <a href="residents.php" class="nav-item"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item active"><span class="icon">🏠</span> Rooms</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
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
        <h2>Room Management</h2>
        <p>Manage hostel rooms and occupancy</p>
      </div>
      <button class="btn btn-primary" onclick="document.getElementById('addRoomModal').classList.add('open')">+ Add Room</button>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

      <div class="card fade-up">
        <div class="card-header">
          <h3>🏠 All Rooms</h3>
          <div style="display:flex; gap:10px; align-items:center; font-size:0.8rem;">
            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;background:rgba(0,212,170,0.5);border-radius:2px;display:inline-block;"></span>Available</span>
            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;background:rgba(255,209,102,0.5);border-radius:2px;display:inline-block;"></span>Partial</span>
            <span style="display:flex;align-items:center;gap:5px;"><span style="width:10px;height:10px;background:rgba(255,107,107,0.5);border-radius:2px;display:inline-block;"></span>Full</span>
          </div>
        </div>

        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Room No.</th>
                <th>Floor</th>
                <th>Type</th>
                <th>Capacity</th>
                <th>Occupied</th>
                <th>Occupancy</th>
                <th>Complaints</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($r = $rooms->fetch_assoc()):
                $occ_pct = $r['capacity'] > 0 ? round(($r['occupied']/$r['capacity'])*100) : 0;
                if ($r['status'] === 'maintenance') $rowcls = 'badge-info';
                elseif ($r['occupied'] >= $r['capacity']) $rowcls = 'badge-danger';
                elseif ($r['occupied'] > 0) $rowcls = 'badge-warning';
                else $rowcls = 'badge-success';
              ?>
              <tr>
                <td><strong style="font-family:'Syne',sans-serif; font-size:1rem;"><?= $r['room_number'] ?></strong></td>
                <td style="color:var(--text-2);">Floor <?= $r['floor'] ?></td>
                <td>
                  <span class="badge <?= $r['room_type']==='double' ? 'badge-info' : 'badge-muted' ?>">
                    <?= ucfirst($r['room_type']) ?>
                  </span>
                </td>
                <td style="text-align:center;"><?= $r['capacity'] ?></td>
                <td style="text-align:center; font-weight:600;"><?= $r['occupied'] ?></td>
                <td style="min-width:120px;">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <div class="progress-bar" style="flex:1; margin:0;">
                      <div class="progress-fill" style="width:<?= $occ_pct ?>%; background:<?= $occ_pct>=100 ? 'var(--accent3)' : ($occ_pct>0 ? 'var(--accent4)' : 'var(--accent2)') ?>;"></div>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-3);"><?= $occ_pct ?>%</span>
                  </div>
                </td>
                <td style="text-align:center;">
                  <?php if ($r['pending_complaints'] > 0): ?>
                    <span class="badge badge-warning"><?= $r['pending_complaints'] ?> pending</span>
                  <?php else: ?>
                    <span style="color:var(--text-3); font-size:0.8rem;">None</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $rowcls ?>"><?= ucfirst($r['status']) ?></span></td>
                <td>
                  <form method="POST" style="display:flex; gap:6px; align-items:center;">
                    <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="form-select" style="padding:4px 8px; font-size:0.78rem; width:auto;">
                      <?php foreach (['available','full','maintenance'] as $s): ?>
                        <option value="<?= $s ?>" <?= $r['status']===$s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Save</button>
                  </form>
                </td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Room Modal -->
<div class="modal-overlay" id="addRoomModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Add New Room</h3>
      <button class="modal-close" onclick="document.getElementById('addRoomModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_room">
      <div class="form-grid-3">
        <div class="form-group">
          <label class="form-label">Room Number *</label>
          <input type="text" name="room_number" class="form-input" placeholder="e.g. 501" required>
        </div>
        <div class="form-group">
          <label class="form-label">Room Type *</label>
          <select name="room_type" class="form-select" required>
            <option value="double">Double (2 beds)</option>
            <option value="triple">Triple (3 beds)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Floor *</label>
          <input type="number" name="floor" class="form-input" min="1" max="10" value="1" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">🏠 Add Room</button>
    </form>
  </div>
</div>
</body>
</html>
