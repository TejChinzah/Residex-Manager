<?php
require_once '../includes/config.php';
requireLogin('admin');

$db      = getDB();
$success = '';
$error   = '';

// ---- POST Handler ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $uid    = intval($_POST['user_id']);
    $action = $_POST['action'];

    if ($action === 'approve') {
        $db->query("UPDATE users SET status='active' WHERE id=$uid");
        header('Location: residents.php?msg=approved'); exit();
    }
    if ($action === 'deactivate') {
        $db->query("UPDATE users SET status='inactive' WHERE id=$uid");
        header('Location: residents.php?msg=deactivated'); exit();
    }
    if ($action === 'reactivate') {
        $db->query("UPDATE users SET status='active' WHERE id=$uid");
        header('Location: residents.php?msg=reactivated'); exit();
    }
    if ($action === 'delete') {
        $u2 = $db->query("SELECT room_id FROM users WHERE id=$uid")->fetch_assoc();
        if ($u2 && $u2['room_id']) {
            $db->query("UPDATE rooms SET occupied=GREATEST(0,occupied-1),
                status=CASE WHEN occupied-1<capacity AND status='full' THEN 'available' ELSE status END
                WHERE id={$u2['room_id']}");
        }
        $db->query("DELETE FROM users WHERE id=$uid");
        header('Location: residents.php?msg=deleted'); exit();
    }
    if ($action === 'update_all') {
        $full_name         = sanitize($_POST['full_name']);
        $email             = sanitize($_POST['email']);
        $phone             = sanitize($_POST['phone']);
        $student_id        = sanitize($_POST['student_id']);
        $gender            = sanitize($_POST['gender']);
        $address           = sanitize($_POST['address']);
        $emergency_contact = sanitize($_POST['emergency_contact']);
        $status            = sanitize($_POST['status']);
        $new_room          = intval($_POST['new_room_id']);
        $old_room          = intval($_POST['old_room_id']);
        $bed               = intval($_POST['bed_number']);
        $new_password      = $_POST['new_password'] ?? '';

        // Diet fields (only if columns exist)
        $has_diet = $db->query("SHOW COLUMNS FROM users LIKE 'diet_type'")->num_rows > 0;
        $diet_set = '';
        if ($has_diet) {
            $diet_type = sanitize($_POST['diet_type'] ?? 'any');
            $nvp_arr   = isset($_POST['non_veg_preference']) ? array_map('sanitize', $_POST['non_veg_preference']) : [];
            $nvp       = implode(',', $nvp_arr);
            $diet_set  = "diet_type='$diet_type', non_veg_preference='$nvp',";
        }

        // Room change
        if ($old_room && $old_room != $new_room) {
            $db->query("UPDATE rooms SET occupied=GREATEST(0,occupied-1),
                status=CASE WHEN occupied-1<capacity AND status='full' THEN 'available' ELSE status END
                WHERE id=$old_room");
        }
        if ($new_room && $new_room != $old_room) {
            $db->query("UPDATE rooms SET occupied=occupied+1,
                status=CASE WHEN occupied+1>=capacity THEN 'full' ELSE 'available' END
                WHERE id=$new_room");
        }
        $room_sql = $new_room ? "room_id=$new_room, bed_number=$bed," : "room_id=NULL, bed_number=NULL,";

        if ($new_password) {
            $hp = password_hash($new_password, PASSWORD_BCRYPT);
            $db->query("UPDATE users SET full_name='$full_name', email='$email', phone='$phone',
                student_id='$student_id', gender='$gender', address='$address',
                emergency_contact='$emergency_contact', $diet_set $room_sql
                status='$status', password='$hp' WHERE id=$uid");
        } else {
            $db->query("UPDATE users SET full_name='$full_name', email='$email', phone='$phone',
                student_id='$student_id', gender='$gender', address='$address',
                emergency_contact='$emergency_contact', $diet_set $room_sql
                status='$status' WHERE id=$uid");
        }
        header("Location: residents.php?msg=updated&edit=$uid&filter=" . sanitize($_POST['filter'] ?? 'all'));
        exit();
    }
}

// Messages
$msg = sanitize($_GET['msg'] ?? '');
if ($msg === 'approved')    $success = 'Resident approved successfully.';
if ($msg === 'updated')     $success = 'Resident updated successfully.';
if ($msg === 'deactivated') $success = 'Resident deactivated.';
if ($msg === 'reactivated') $success = 'Resident reactivated.';
if ($msg === 'deleted')     $success = 'Resident removed.';

// Filters
$filter = sanitize($_GET['filter'] ?? 'all');
$search = sanitize($_GET['search'] ?? '');
$where  = "1=1";
if ($filter === 'pending')  $where .= " AND u.status='pending'";
if ($filter === 'active')   $where .= " AND u.status='active'";
if ($filter === 'inactive') $where .= " AND u.status='inactive'";
if ($search) $where .= " AND (u.full_name LIKE '%$search%' OR u.student_id LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%')";

$users = $db->query("SELECT u.*, r.room_number, r.room_type, r.floor
    FROM users u LEFT JOIN rooms r ON u.room_id=r.id
    WHERE $where ORDER BY u.created_at DESC");

$rooms = $db->query("SELECT * FROM rooms ORDER BY floor, room_number");

// Edit user
$edit_id   = intval($_GET['edit'] ?? 0);
$edit_user = null;
$roommates = [];
$pay_paid  = 0;
$pay_due   = 0;
$comp_cnt  = 0;
$has_diet  = $db->query("SHOW COLUMNS FROM users LIKE 'diet_type'")->num_rows > 0;

if ($edit_id) {
    $edit_user = $db->query("SELECT u.*, r.room_number, r.room_type, r.floor, r.capacity, r.occupied
        FROM users u LEFT JOIN rooms r ON u.room_id=r.id
        WHERE u.id=$edit_id")->fetch_assoc();

    if ($edit_user && $edit_user['room_id']) {
        $rm_res = $db->query("SELECT id, full_name, student_id, bed_number, gender, phone, email
            FROM users WHERE room_id={$edit_user['room_id']} AND id!=$edit_id AND status!='inactive'");
        while ($r = $rm_res->fetch_assoc()) $roommates[] = $r;
    }
    $pay_paid = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE user_id=$edit_id AND status='paid'")->fetch_assoc()['t'];
    $pay_due  = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE user_id=$edit_id AND status IN ('unpaid','overdue')")->fetch_assoc()['t'];
    $comp_cnt = $db->query("SELECT COUNT(*) as c FROM complaints WHERE user_id=$edit_id")->fetch_assoc()['c'];
}

$total_active   = $db->query("SELECT COUNT(*) as c FROM users WHERE status='active'")->fetch_assoc()['c'];
$total_pending  = $db->query("SELECT COUNT(*) as c FROM users WHERE status='pending'")->fetch_assoc()['c'];
$total_inactive = $db->query("SELECT COUNT(*) as c FROM users WHERE status='inactive'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Residents - Residex Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .res-avatar { width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.9rem;flex-shrink:0; }
    .big-avatar { width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.6rem;flex-shrink:0;font-family:'Syne',sans-serif; }
    .tab-bar { display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px; }
    .tab-btn { padding:10px 18px;font-size:0.85rem;font-weight:600;background:none;border:none;border-bottom:2px solid transparent;color:var(--text-2);cursor:pointer;transition:all 0.2s;margin-bottom:-1px; }
    .tab-btn.active { color:var(--accent);border-bottom-color:var(--accent); }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }
    .info-card { background:var(--bg-glass);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px; }
    .info-row { display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04);font-size:0.85rem; }
    .info-row:last-child { border-bottom:none; }
    .info-key { color:var(--text-2); }
    .info-val { font-weight:600;text-align:right;max-width:60%;word-break:break-word; }
    .stat-mini { display:flex;align-items:center;gap:10px;padding:12px;background:var(--bg-glass);border:1px solid var(--border);border-radius:10px; }
    .stat-mini .v { font-family:'Syne',sans-serif;font-weight:800;font-size:1.1rem; }
    .rm-card { display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-glass);border:1px solid var(--border);border-radius:10px;margin-bottom:8px; }
    .rm-avatar { width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--accent2),#00ffcc);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;flex-shrink:0; }
    .diet-opts { display:flex;gap:8px;flex-wrap:wrap; }
    .diet-opt input { display:none; }
    .diet-opt label { padding:8px 16px;border-radius:99px;border:1px solid var(--border);cursor:pointer;font-size:0.82rem;font-weight:600;transition:all 0.2s; }
    .diet-opt input:checked + label { background:rgba(0,212,170,0.12);border-color:var(--accent2);color:var(--accent2); }
    .nveg-opts { display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-top:10px; }
    .nveg-opt input { display:none; }
    .nveg-opt label { display:flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:0.82rem;transition:all 0.2s; }
    .nveg-opt input:checked + label { background:rgba(255,107,107,0.1);border-color:var(--accent3); }
    .room-badge-link { text-decoration:none; }
    .room-badge-link .badge:hover { background:rgba(108,99,255,0.2); }
  </style>
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
      <div class="user-avatar" style="background:linear-gradient(135deg,var(--accent4),var(--accent3));">A</div>
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
        <h2>👥 Resident Management</h2>
        <p>View, edit and manage all hostel residents</p>
      </div>
      <div class="topbar-actions">
        <span class="badge badge-success">✅ <?= $total_active ?> Active</span>
        <?php if ($total_pending > 0): ?>
          <span class="badge badge-warning">⏳ <?= $total_pending ?> Pending</span>
        <?php endif; ?>
        <span class="badge badge-muted">❌ <?= $total_inactive ?> Inactive</span>
      </div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

      <!-- Filters -->
      <div class="card fade-up" style="margin-bottom:20px;padding:16px 24px;">
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <form method="GET" style="display:flex;gap:10px;flex:1;flex-wrap:wrap;align-items:center;">
            <input type="text" name="search" class="form-input"
              placeholder="Search name, ID, email, phone..."
              value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <input type="hidden" name="filter" value="<?= $filter ?>">
            <button type="submit" class="btn btn-outline btn-sm">🔍 Search</button>
            <?php if ($search): ?><a href="residents.php?filter=<?= $filter ?>" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
          </form>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach (['all'=>'👥 All','pending'=>'⏳ Pending','active'=>'✅ Active','inactive'=>'❌ Inactive'] as $k=>$v): ?>
              <a href="?filter=<?= $k ?>" class="btn <?= $filter===$k?'btn-primary':'btn-outline' ?> btn-sm"><?= $v ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="card fade-up">
        <div class="card-header">
          <h3>Residents (<?= $users->num_rows ?>)</h3>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Resident</th>
                <th>Student ID</th>
                <th>Phone</th>
                <th>Room</th>
                <?php if ($has_diet): ?><th>Diet</th><?php endif; ?>
                <th>Gender</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($users->num_rows === 0): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-3);">No residents found.</td></tr>
              <?php else: while ($u = $users->fetch_assoc()):
                $sc = ['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger'];
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <div class="res-avatar"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
                    <div>
                      <div style="font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></div>
                      <div style="font-size:0.72rem;color:var(--text-3);"><?= htmlspecialchars($u['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-family:'Syne',sans-serif;font-weight:700;color:var(--accent);"><?= $u['student_id'] ?></td>
                <td style="color:var(--text-2);font-size:0.85rem;"><?= $u['phone'] ?></td>
                <td>
                  <?php if ($u['room_number']): ?>
                    <a href="rooms.php?view_room=<?= $u['room_id'] ?>" class="room-badge-link">
                      <span class="badge badge-info" title="Click to view room">🏠 Room <?= $u['room_number'] ?></span>
                    </a>
                    <div style="font-size:0.68rem;color:var(--text-3);margin-top:2px;">Fl.<?= $u['floor'] ?> · <?= ucfirst($u['room_type']) ?></div>
                  <?php else: ?>
                    <span style="color:var(--text-3);font-size:0.82rem;">Not assigned</span>
                  <?php endif; ?>
                </td>
                <?php if ($has_diet): ?>
                <td style="font-size:0.82rem;">
                  <?php $di=['veg'=>'🥦 Veg','non_veg'=>'🍗 Non-Veg','vegan'=>'🌱 Vegan','any'=>'🍽️ Any'];
                  echo $di[$u['diet_type']??'any']??'—'; ?>
                </td>
                <?php endif; ?>
                <td style="color:var(--text-2);"><?= ucfirst($u['gender']) ?></td>
                <td><span class="badge <?= $sc[$u['status']] ?>"><?= ucfirst($u['status']) ?></span></td>
                <td style="font-size:0.75rem;color:var(--text-3);"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="?edit=<?= $u['id'] ?>&filter=<?= $filter ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
                    <?php if ($u['status']==='pending'): ?>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-success btn-sm">✅</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-wrapper -->

<!-- ===================== FULL EDIT MODAL ===================== -->
<?php if ($edit_user): ?>
<div class="modal-overlay open" id="editModal"
  style="align-items:flex-start;padding:16px;overflow-y:auto;">
  <div class="modal" style="max-width:680px;width:100%;margin:auto;">

    <!-- Header -->
    <div class="modal-header" style="margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:14px;">
        <div class="big-avatar"><?= strtoupper(substr($edit_user['full_name'],0,1)) ?></div>
        <div>
          <div style="font-size:1.15rem;font-weight:800;letter-spacing:-0.02em;">
            <?= htmlspecialchars($edit_user['full_name']) ?>
          </div>
          <div style="display:flex;gap:6px;margin-top:5px;flex-wrap:wrap;align-items:center;">
            <span style="font-size:0.78rem;color:var(--text-3);"><?= $edit_user['student_id'] ?></span>
            <?php $sc=['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger']; ?>
            <span class="badge <?= $sc[$edit_user['status']] ?>"><?= ucfirst($edit_user['status']) ?></span>
            <?php if ($edit_user['room_number']): ?>
              <span class="badge badge-info">🏠 Room <?= $edit_user['room_number'] ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <button class="modal-close" onclick="location.href='residents.php?filter=<?= $filter ?>'">✕</button>
    </div>

    <!-- Mini stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:20px;">
      <div class="stat-mini">
        <span style="font-size:20px;">💰</span>
        <div>
          <div class="v" style="color:var(--accent2);">₹<?= number_format($pay_paid,0) ?></div>
          <div style="font-size:0.68rem;color:var(--text-3);">Total Paid</div>
        </div>
      </div>
      <div class="stat-mini">
        <span style="font-size:20px;">⏳</span>
        <div>
          <div class="v" style="color:var(--accent4);">₹<?= number_format($pay_due,0) ?></div>
          <div style="font-size:0.68rem;color:var(--text-3);">Amount Due</div>
        </div>
      </div>
      <div class="stat-mini">
        <span style="font-size:20px;">🔧</span>
        <div>
          <div class="v"><?= $comp_cnt ?></div>
          <div style="font-size:0.68rem;color:var(--text-3);">Complaints</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button class="tab-btn active" onclick="switchTab('personal',this)">👤 Personal</button>
      <button class="tab-btn" onclick="switchTab('contact',this)">📞 Contact</button>
      <button class="tab-btn" onclick="switchTab('room',this)">🏠 Room</button>
      <?php if ($has_diet): ?>
      <button class="tab-btn" onclick="switchTab('diet',this)">🍽️ Diet</button>
      <?php endif; ?>
      <button class="tab-btn" onclick="switchTab('security',this)">🔒 Security</button>
    </div>

    <form method="POST">
      <input type="hidden" name="action" value="update_all">
      <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
      <input type="hidden" name="old_room_id" value="<?= $edit_user['room_id'] ?? 0 ?>">
      <input type="hidden" name="filter" value="<?= $filter ?>">

      <!-- ===== TAB: PERSONAL ===== -->
      <div class="tab-pane active" id="tab-personal">
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="full_name" class="form-input"
              value="<?= htmlspecialchars($edit_user['full_name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Student / Member ID *</label>
            <input type="text" name="student_id" class="form-input"
              value="<?= htmlspecialchars($edit_user['student_id']) ?>" required>
          </div>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Gender *</label>
            <select name="gender" class="form-select" required>
              <option value="male"   <?= $edit_user['gender']==='male'  ?'selected':'' ?>>Male</option>
              <option value="female" <?= $edit_user['gender']==='female'?'selected':'' ?>>Female</option>
              <option value="other"  <?= $edit_user['gender']==='other' ?'selected':'' ?>>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Account Status *</label>
            <select name="status" class="form-select" required>
              <option value="active"   <?= $edit_user['status']==='active'  ?'selected':'' ?>>✅ Active</option>
              <option value="pending"  <?= $edit_user['status']==='pending' ?'selected':'' ?>>⏳ Pending</option>
              <option value="inactive" <?= $edit_user['status']==='inactive'?'selected':'' ?>>❌ Inactive</option>
            </select>
          </div>
        </div>

        <!-- Read-only info -->
        <div class="info-card" style="margin-top:8px;">
          <div class="info-row">
            <span class="info-key">Registered On</span>
            <span class="info-val"><?= date('d M Y, g:i A', strtotime($edit_user['created_at'])) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Joined Date</span>
            <span class="info-val"><?= $edit_user['joined_date'] ? date('d M Y', strtotime($edit_user['joined_date'])) : '—' ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Bed Number</span>
            <span class="info-val">Bed #<?= $edit_user['bed_number'] ?? '—' ?></span>
          </div>
        </div>
      </div>

      <!-- ===== TAB: CONTACT ===== -->
      <div class="tab-pane" id="tab-contact">
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Email Address *</label>
            <input type="email" name="email" class="form-input"
              value="<?= htmlspecialchars($edit_user['email']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Phone Number *</label>
            <input type="tel" name="phone" class="form-input"
              value="<?= htmlspecialchars($edit_user['phone']) ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Emergency Contact (Guardian)</label>
          <input type="tel" name="emergency_contact" class="form-input"
            value="<?= htmlspecialchars($edit_user['emergency_contact'] ?? '') ?>"
            placeholder="Parent / guardian phone number">
        </div>
        <div class="form-group">
          <label class="form-label">Home / Permanent Address</label>
          <textarea name="address" class="form-textarea" rows="4"
            placeholder="Full home address..."><?= htmlspecialchars($edit_user['address'] ?? '') ?></textarea>
        </div>

        <!-- Display current address clearly -->
        <?php if ($edit_user['address']): ?>
        <div class="info-card">
          <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);margin-bottom:8px;">Current Address on File</div>
          <div style="font-size:0.88rem;line-height:1.6;color:var(--text-1);">
            📍 <?= nl2br(htmlspecialchars($edit_user['address'])) ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($edit_user['emergency_contact']): ?>
        <div class="info-card" style="margin-top:8px;">
          <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);margin-bottom:4px;">Emergency Contact on File</div>
          <div style="font-size:0.9rem;font-weight:700;">📞 <?= htmlspecialchars($edit_user['emergency_contact']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ===== TAB: ROOM ===== -->
      <div class="tab-pane" id="tab-room">
        <div class="form-grid-2" style="margin-bottom:16px;">
          <div class="form-group">
            <label class="form-label">Assign Room</label>
            <select name="new_room_id" class="form-select">
              <option value="0">— No Room —</option>
              <?php $rooms->data_seek(0); while ($r = $rooms->fetch_assoc()):
                $sel  = ($edit_user['room_id'] == $r['id']);
                $full = ($r['occupied'] >= $r['capacity']) && !$sel;
              ?>
                <option value="<?= $r['id'] ?>" <?= $sel?'selected':'' ?> <?= $full?'disabled':'' ?>>
                  Room <?= $r['room_number'] ?> — Fl.<?= $r['floor'] ?> (<?= ucfirst($r['room_type']) ?>)
                  — <?= $r['occupied'] ?>/<?= $r['capacity'] ?> occupied<?= $full?' [FULL]':'' ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Bed Number</label>
            <select name="bed_number" class="form-select">
              <?php for ($b=1;$b<=3;$b++): ?>
                <option value="<?= $b ?>" <?= $edit_user['bed_number']==$b?'selected':'' ?>>Bed #<?= $b ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <?php if ($edit_user['room_number']): ?>
        <!-- Current Room Details -->
        <div style="background:rgba(108,99,255,0.07);border:1px solid rgba(108,99,255,0.2);border-radius:12px;padding:16px;margin-bottom:16px;">
          <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-3);margin-bottom:12px;">Current Room Details</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:0.85rem;">
            <div>
              <div style="color:var(--text-3);font-size:0.7rem;margin-bottom:3px;">Room Number</div>
              <div style="font-family:'Syne',sans-serif;font-weight:800;font-size:1.3rem;color:var(--accent);">Room <?= $edit_user['room_number'] ?></div>
            </div>
            <div>
              <div style="color:var(--text-3);font-size:0.7rem;margin-bottom:3px;">Room Type</div>
              <div style="font-weight:700;"><?= ucfirst($edit_user['room_type']) ?></div>
            </div>
            <div>
              <div style="color:var(--text-3);font-size:0.7rem;margin-bottom:3px;">Floor</div>
              <div style="font-weight:700;">Floor <?= $edit_user['floor'] ?></div>
            </div>
            <div>
              <div style="color:var(--text-3);font-size:0.7rem;margin-bottom:3px;">Occupancy</div>
              <div style="font-weight:700;"><?= $edit_user['occupied'] ?>/<?= $edit_user['capacity'] ?> beds taken</div>
            </div>
          </div>
          <div style="margin-top:12px;">
            <a href="rooms.php?view_room=<?= $edit_user['room_id'] ?>" target="_blank"
               class="btn btn-outline btn-sm">🔗 View Full Room Details</a>
          </div>
        </div>

        <!-- Roommates -->
        <div>
          <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-3);margin-bottom:10px;">
            🛏️ Roommates (<?= count($roommates) ?>)
          </div>
          <?php if (empty($roommates)): ?>
            <div style="text-align:center;padding:20px;color:var(--text-3);font-size:0.85rem;background:var(--bg-glass);border:1px solid var(--border);border-radius:10px;">
              No roommates — this resident is currently alone in the room.
            </div>
          <?php else: foreach ($roommates as $rm): ?>
            <div class="rm-card">
              <div class="rm-avatar"><?= strtoupper(substr($rm['full_name'],0,1)) ?></div>
              <div style="flex:1;">
                <div style="font-weight:700;font-size:0.88rem;"><?= htmlspecialchars($rm['full_name']) ?></div>
                <div style="font-size:0.72rem;color:var(--text-2);">
                  <?= $rm['student_id'] ?> · Bed #<?= $rm['bed_number'] ?> · <?= ucfirst($rm['gender']) ?>
                </div>
                <div style="font-size:0.7rem;color:var(--text-3);">
                  <?= htmlspecialchars($rm['phone']) ?> · <?= htmlspecialchars($rm['email']) ?>
                </div>
              </div>
              <a href="?edit=<?= $rm['id'] ?>&filter=<?= $filter ?>" class="btn btn-outline btn-sm">View</a>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <?php else: ?>
          <div style="text-align:center;padding:32px;color:var(--text-3);">
            <div style="font-size:40px;margin-bottom:10px;">🏠</div>
            This resident has not been assigned a room yet.<br>
            <span style="font-size:0.82rem;">Select a room from the dropdown above and save.</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- ===== TAB: DIET ===== -->
      <?php if ($has_diet): ?>
      <div class="tab-pane" id="tab-diet">
        <div class="form-group">
          <label class="form-label">Diet Type</label>
          <div class="diet-opts">
            <?php foreach (['veg'=>'🥦 Veg','non_veg'=>'🍗 Non-Veg','vegan'=>'🌱 Vegan','any'=>'🍽️ Any / Not Set'] as $val=>$lbl): ?>
              <div class="diet-opt">
                <input type="radio" name="diet_type" id="dt_<?= $val ?>" value="<?= $val ?>"
                  <?= ($edit_user['diet_type']??'any')===$val?'checked':'' ?>
                  onchange="toggleNveg(this.value)">
                <label for="dt_<?= $val ?>"><?= $lbl ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div id="nveg_section" style="display:<?= ($edit_user['diet_type']??'')==='non_veg'?'block':'none' ?>;">
          <div class="form-group">
            <label class="form-label">Non-Veg Preferences (tick what they eat)</label>
            <div class="nveg-opts">
              <?php
              $cur_nvp = explode(',', $edit_user['non_veg_preference'] ?? '');
              foreach (['chicken'=>'🍗 Chicken','mutton'=>'🍖 Mutton','fish'=>'🐟 Fish','egg'=>'🥚 Egg','all'=>'🍽️ All Types'] as $v=>$l):
              ?>
                <div class="nveg-opt">
                  <input type="checkbox" name="non_veg_preference[]" id="nvp_<?= $v ?>"
                    value="<?= $v ?>" <?= in_array($v,$cur_nvp)?'checked':'' ?>>
                  <label for="nvp_<?= $v ?>"><?= $l ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div style="background:rgba(0,212,170,0.06);border:1px solid rgba(0,212,170,0.2);border-radius:10px;padding:14px;font-size:0.82rem;color:var(--text-2);margin-top:12px;">
          <strong style="color:var(--accent2);">ℹ️ Why this matters:</strong><br>
          Diet preferences allow you to send bulk mess fee demands to specific diet groups —
          for example, charging the chicken mess fee only to residents who eat chicken.
        </div>
      </div>
      <?php endif; ?>

      <!-- ===== TAB: SECURITY ===== -->
      <div class="tab-pane" id="tab-security">
        <div class="alert alert-warning" style="margin-bottom:16px;">
          ⚠️ Leave password field blank to keep existing password unchanged.
          Only fill it if you want to reset this resident's password.
        </div>
        <div class="form-group">
          <label class="form-label">Set New Password (optional)</label>
          <input type="password" name="new_password" class="form-input"
            placeholder="Leave blank to keep current password" autocomplete="new-password">
        </div>
        <div class="info-card">
          <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);margin-bottom:10px;">Account Information</div>
          <div class="info-row">
            <span class="info-key">Login Email</span>
            <span class="info-val"><?= htmlspecialchars($edit_user['email']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Account Created</span>
            <span class="info-val"><?= date('d M Y, g:i A', strtotime($edit_user['created_at'])) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Current Status</span>
            <span class="info-val">
              <span class="badge <?= ['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger'][$edit_user['status']] ?>">
                <?= ucfirst($edit_user['status']) ?>
              </span>
            </span>
          </div>
        </div>
      </div>

      <!-- Save Button -->
      <div style="display:flex;gap:10px;margin-top:24px;padding-top:16px;border-top:1px solid var(--border);">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:14px;">
          💾 Save All Changes
        </button>
        <?php if ($edit_user['status']==='pending'): ?>
          <button type="button" onclick="quickAction('approve')" class="btn btn-success">✅ Approve</button>
        <?php elseif ($edit_user['status']==='active'): ?>
          <button type="button" onclick="quickAction('deactivate')" class="btn btn-outline" style="color:var(--accent3);">Deactivate</button>
        <?php else: ?>
          <button type="button" onclick="quickAction('reactivate')" class="btn btn-outline">Reactivate</button>
        <?php endif; ?>
        <button type="button" onclick="quickAction('delete')" class="btn btn-danger">🗑️</button>
      </div>
    </form>

  </div>
</div>

<!-- Hidden quick-action forms -->
<form method="POST" id="qa_approve"    style="display:none;"><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>"></form>
<form method="POST" id="qa_deactivate" style="display:none;"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>"></form>
<form method="POST" id="qa_reactivate" style="display:none;"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>"></form>
<form method="POST" id="qa_delete"     style="display:none;"><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>"></form>
<?php endif; ?>

<script>
// Tab switching
function switchTab(name, btn) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const pane = document.getElementById('tab-' + name);
  if (pane) pane.classList.add('active');
  btn.classList.add('active');
}

// Diet toggle
function toggleNveg(val) {
  const sec = document.getElementById('nveg_section');
  if (sec) sec.style.display = (val === 'non_veg') ? 'block' : 'none';
}

// Quick actions
function quickAction(action) {
  const msgs = {
    'approve':    'Approve this resident?',
    'deactivate': 'Deactivate this resident?',
    'reactivate': 'Reactivate this resident?',
    'delete':     'Permanently remove this resident? This cannot be undone!'
  };
  if (confirm(msgs[action] || 'Are you sure?')) {
    document.getElementById('qa_' + action).submit();
  }
}
</script>

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
