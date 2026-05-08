<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();
$success = '';
$error = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $uid = intval($_POST['user_id']);
    $action = $_POST['action'];

    if ($action === 'approve') {
        $db->query("UPDATE users SET status='active' WHERE id=$uid");
        $success = 'Resident approved successfully.';
    } elseif ($action === 'deactivate') {
        $db->query("UPDATE users SET status='inactive' WHERE id=$uid");
        $success = 'Resident deactivated.';
    } elseif ($action === 'delete') {
        $user = $db->query("SELECT room_id FROM users WHERE id=$uid")->fetch_assoc();
        if ($user && $user['room_id']) {
            $db->query("UPDATE rooms SET occupied = GREATEST(0, occupied-1), status = CASE WHEN occupied-1 < capacity AND status='full' THEN 'available' ELSE status END WHERE id={$user['room_id']}");
        }
        $db->query("DELETE FROM users WHERE id=$uid");
        $success = 'Resident removed.';
    } elseif ($action === 'update_room') {
        $new_room = intval($_POST['new_room_id']);
        $old_room = intval($_POST['old_room_id']);
        $bed = intval($_POST['bed_number']);

        if ($old_room) {
            $db->query("UPDATE rooms SET occupied = GREATEST(0, occupied-1), status = CASE WHEN occupied-1 < capacity AND status='full' THEN 'available' ELSE status END WHERE id=$old_room");
        }
        if ($new_room) {
            $db->query("UPDATE rooms SET occupied = occupied+1, status = CASE WHEN occupied+1 >= capacity THEN 'full' ELSE 'available' END WHERE id=$new_room");
        }
        $db->query("UPDATE users SET room_id=$new_room, bed_number=$bed WHERE id=$uid");
        $success = 'Room assignment updated.';
    }
}

$filter = $_GET['filter'] ?? 'all';
$search = sanitize($_GET['search'] ?? '');

$where = "1=1";
if ($filter === 'pending') $where .= " AND u.status='pending'";
if ($filter === 'active') $where .= " AND u.status='active'";
if ($filter === 'inactive') $where .= " AND u.status='inactive'";
if ($search) $where .= " AND (u.full_name LIKE '%$search%' OR u.student_id LIKE '%$search%' OR u.email LIKE '%$search%')";

$users = $db->query("SELECT u.*, r.room_number, r.room_type FROM users u LEFT JOIN rooms r ON u.room_id=r.id WHERE $where ORDER BY u.created_at DESC");

$edit_user = null;
$edit_id = intval($_GET['edit'] ?? 0);
if ($edit_id) {
    $edit_user = $db->query("SELECT * FROM users WHERE id=$edit_id")->fetch_assoc();
}

$rooms = $db->query("SELECT * FROM rooms ORDER BY room_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Residents - Residex Admin</title>
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
      <a href="residents.php" class="nav-item active"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
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
        <h2>Resident Management</h2>
        <p>View and manage all hostel residents</p>
      </div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

      <!-- Filters -->
      <div class="card fade-up" style="margin-bottom: 20px;">
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
          <form method="GET" style="display:flex; gap:10px; flex:1; flex-wrap:wrap;">
            <input type="text" name="search" class="form-input" placeholder="Search name, ID, email..." value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
            <input type="hidden" name="filter" value="<?= $filter ?>">
            <button type="submit" class="btn btn-outline btn-sm">🔍 Search</button>
          </form>
          <div style="display:flex; gap:8px;">
            <?php foreach (['all'=>'All','pending'=>'⏳ Pending','active'=>'✅ Active','inactive'=>'❌ Inactive'] as $k=>$v): ?>
              <a href="?filter=<?= $k ?>" class="btn <?= $filter===$k ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $v ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Residents Table -->
      <div class="card fade-up">
        <div class="card-header">
          <h3>👥 Residents (<?= $users->num_rows ?>)</h3>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Student ID</th>
                <th>Contact</th>
                <th>Room</th>
                <th>Gender</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($u = $users->fetch_assoc()):
                $sc = ['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger'];
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-3);"><?= $u['email'] ?></div>
                </td>
                <td style="font-family:'Syne',sans-serif; font-weight:600;"><?= $u['student_id'] ?></td>
                <td style="color:var(--text-2); font-size:0.82rem;"><?= $u['phone'] ?></td>
                <td><?= $u['room_number'] ? "<span class='badge badge-muted'>Room {$u['room_number']} ({$u['room_type']})</span>" : '<span style="color:var(--text-3)">—</span>' ?></td>
                <td style="color:var(--text-2);"><?= ucfirst($u['gender']) ?></td>
                <td><span class="badge <?= $sc[$u['status']] ?>"><?= ucfirst($u['status']) ?></span></td>
                <td style="font-size:0.75rem; color:var(--text-3);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                <td>
                  <div style="display:flex; gap:6px;">
                    <a href="?edit=<?= $u['id'] ?>&filter=<?= $filter ?>" class="btn btn-outline btn-sm">Edit</a>
                    <?php if ($u['status'] === 'pending'): ?>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success btn-sm">✅ Approve</button>
                      </form>
                    <?php elseif ($u['status'] === 'active'): ?>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--accent3);">Deactivate</button>
                      </form>
                    <?php else: ?>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-outline btn-sm">Reactivate</button>
                      </form>
                    <?php endif; ?>
                  </div>
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

<!-- Edit Modal -->
<?php if ($edit_user): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Resident: <?= htmlspecialchars($edit_user['full_name']) ?></h3>
      <button class="modal-close" onclick="document.getElementById('editModal').classList.remove('open')">✕</button>
    </div>

    <div style="display:grid; gap:10px; font-size:0.85rem; margin-bottom:20px; background:var(--bg-glass); padding:14px; border-radius:10px;">
      <div class="flex justify-between"><span style="color:var(--text-2)">Email</span><strong><?= $edit_user['email'] ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Phone</span><strong><?= $edit_user['phone'] ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Student ID</span><strong><?= $edit_user['student_id'] ?></strong></div>
      <div class="flex justify-between"><span style="color:var(--text-2)">Gender</span><strong><?= ucfirst($edit_user['gender']) ?></strong></div>
    </div>

    <form method="POST">
      <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
      <input type="hidden" name="old_room_id" value="<?= $edit_user['room_id'] ?? 0 ?>">
      <input type="hidden" name="action" value="update_room">

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Assign Room</label>
          <select name="new_room_id" class="form-select">
            <option value="0">No Room</option>
            <?php $rooms->data_seek(0); while ($r = $rooms->fetch_assoc()): ?>
              <option value="<?= $r['id'] ?>" <?= $edit_user['room_id'] == $r['id'] ? 'selected' : '' ?>>
                Room <?= $r['room_number'] ?> (<?= ucfirst($r['room_type']) ?>) - <?= $r['occupied'] ?>/<?= $r['capacity'] ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Bed Number</label>
          <select name="bed_number" class="form-select">
            <?php for ($b = 1; $b <= 3; $b++): ?>
              <option value="<?= $b ?>" <?= $edit_user['bed_number'] == $b ? 'selected' : '' ?>>#<?= $b ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">💾 Update Room</button>
    </form>

    <div class="divider"></div>

    <div style="display:flex; gap:10px;">
      <?php if ($edit_user['status'] === 'pending'): ?>
        <form method="POST">
          <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
          <input type="hidden" name="action" value="approve">
          <button class="btn btn-success">✅ Approve</button>
        </form>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Remove this resident? This cannot be undone.');">
        <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-danger">🗑️ Remove</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
</body>
</html>
