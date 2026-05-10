<?php
require_once '../includes/config.php';
requireLogin('user');

$db = getDB();
$user_id = $_SESSION['user_id'];
$user = $db->query("SELECT u.*, r.room_number, r.room_type, r.floor FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.id=$user_id")->fetch_assoc();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $emergency_contact = sanitize($_POST['emergency_contact'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $current_password = $_POST['current_password'] ?? '';

    if ($new_password) {
        if (!password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 6) {
            $error = 'New password must be at least 6 characters.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $db->query("UPDATE users SET phone='$phone', address='$address', emergency_contact='$emergency_contact', password='$hashed' WHERE id=$user_id");
            $success = 'Profile and password updated successfully.';
        }
    } else {
        $db->query("UPDATE users SET phone='$phone', address='$address', emergency_contact='$emergency_contact' WHERE id=$user_id");
        $success = 'Profile updated successfully.';
    }
    $user = $db->query("SELECT u.*, r.room_number, r.room_type, r.floor FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.id=$user_id")->fetch_assoc();
}

$initials = strtoupper(substr($user['full_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🏠</div>
        <div class="brand-text"><div class="name">Residex Manager</div><div class="tag">Resident Portal</div></div>
      </div>
    </div>
    <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="profile.php" class="nav-item active"><span class="icon">👤</span> My Profile</a>
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
        <h2>My Profile</h2>
        <p>Manage your personal information</p>
      </div>
    </div>

    <div class="page-body">
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

      <div style="display:grid; grid-template-columns: 280px 1fr; gap: 24px;">
        <!-- Profile Card -->
        <div>
          <div class="card fade-up" style="text-align:center;">
            <div style="width:80px; height:80px; background:linear-gradient(135deg,var(--accent),var(--accent2)); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:800; margin:0 auto 16px; font-family:'Syne',sans-serif;">
              <?= strtoupper(substr($user['full_name'],0,1)) ?>
            </div>
            <h3 style="font-size:1.1rem;"><?= htmlspecialchars($user['full_name']) ?></h3>
            <p style="color:var(--text-2); font-size:0.8rem; margin-top:4px;"><?= $user['student_id'] ?></p>

            <div class="divider"></div>

            <div style="display:grid; gap:10px; text-align:left; font-size:0.85rem;">
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Status</span>
                <?php $sc=['active'=>'badge-success','pending'=>'badge-warning','inactive'=>'badge-danger']; ?>
                <span class="badge <?= $sc[$user['status']] ?>"><?= ucfirst($user['status']) ?></span>
              </div>
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Room</span>
                <strong><?= $user['room_number'] ?? '—' ?></strong>
              </div>
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Type</span>
                <strong><?= ucfirst($user['room_type'] ?? '—') ?></strong>
              </div>
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Floor</span>
                <strong><?= $user['floor'] ?? '—' ?></strong>
              </div>
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Bed</span>
                <strong>#<?= $user['bed_number'] ?? '—' ?></strong>
              </div>
              <div class="flex justify-between">
                <span style="color:var(--text-2);">Joined</span>
                <strong><?= date('M Y', strtotime($user['joined_date'])) ?></strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Edit Form -->
        <div class="card fade-up fade-up-1">
          <div class="card-header">
            <h3>Edit Information</h3>
          </div>

          <form method="POST">
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" class="form-input" value="<?= htmlspecialchars($user['full_name']) ?>" disabled style="opacity:0.5;">
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" disabled style="opacity:0.5;">
              </div>
            </div>

            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone']) ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Emergency Contact</label>
                <input type="tel" name="emergency_contact" class="form-input" value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Home Address</label>
              <textarea name="address" class="form-textarea" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
            </div>

            <div class="divider"></div>
            <h4 style="font-size:0.9rem; margin-bottom:16px; color:var(--text-2);">Change Password (optional)</h4>

            <div class="form-grid-3">
              <div class="form-group">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-input" placeholder="Current password">
              </div>
              <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-input" placeholder="New password">
              </div>
              <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px;">Save Changes</button>
              </div>
            </div>
          </form>
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
    <span>by <span class="dev-name">Tej Chinzah</span></span>
  </div>
</footer>
</body>
</html>
