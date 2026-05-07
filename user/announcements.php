<?php
require_once '../includes/config.php';
requireLogin('user');

$db = getDB();
$user_id = $_SESSION['user_id'];
$user = $db->query("SELECT u.*, r.room_number FROM users u LEFT JOIN rooms r ON u.room_id=r.id WHERE u.id=$user_id")->fetch_assoc();
$anns = $db->query("SELECT * FROM announcements ORDER BY created_at DESC");
$initials = strtoupper(substr($user['full_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Announcements - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-wrapper">
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="brand">
        <div class="brand-icon">🏠</div>
        <div class="brand-text"><div class="name">Residex</div><div class="tag">Resident Portal</div></div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="profile.php" class="nav-item"><span class="icon">👤</span> My Profile</a>
      <a href="announcements.php" class="nav-item active"><span class="icon">📢</span> Announcements</a>
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
      <div class="topbar-title"><h2>Announcements</h2><p>Notices from hostel management</p></div>
    </div>
    <div class="page-body">
      <div class="card fade-up" style="max-width:700px;">
        <?php if ($anns->num_rows === 0): ?>
          <p style="color:var(--text-3); text-align:center; padding:40px;">No announcements yet.</p>
        <?php else: ?>
          <?php while ($a = $anns->fetch_assoc()): ?>
            <div class="announcement-item <?= $a['type'] ?>" style="margin-bottom:16px;">
              <div class="ann-title" style="font-size:1rem;"><?= htmlspecialchars($a['title']) ?></div>
              <div class="ann-content" style="margin-top:6px; font-size:0.875rem; line-height:1.6;"><?= htmlspecialchars($a['content']) ?></div>
              <div class="ann-date"><?= date('F j, Y \a\t g:i A', strtotime($a['created_at'])) ?></div>
            </div>
          <?php endwhile; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
