<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title   = sanitize($_POST['title']);
        $content = sanitize($_POST['content']);
        $type    = sanitize($_POST['type']);
        $admin_id = $_SESSION['admin_id'];
        $db->query("INSERT INTO announcements (title, content, type, admin_id) VALUES ('$title','$content','$type',$admin_id)");
        $success = 'Announcement posted.';
    } elseif ($action === 'delete') {
        $aid = intval($_POST['ann_id']);
        $db->query("DELETE FROM announcements WHERE id=$aid");
        $success = 'Announcement deleted.';
    }
}

$anns = $db->query("SELECT * FROM announcements ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Announcements - Residex Admin</title>
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
      <a href="payments.php" class="nav-item"><span class="icon">💳</span> Payments</a>
      <a href="residents.php" class="nav-item"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="announcements.php" class="nav-item active"><span class="icon">📢</span> Announcements</a>
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
      <div class="topbar-title"><h2>Announcements</h2><p>Post notices for all residents</p></div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

      <div style="display:grid; grid-template-columns: 1fr 380px; gap: 24px;">
        <!-- Existing Announcements -->
        <div class="card fade-up">
          <div class="card-header"><h3>📢 Posted Announcements</h3></div>
          <?php if ($anns->num_rows === 0): ?>
            <p style="color:var(--text-3); text-align:center; padding:32px;">No announcements yet.</p>
          <?php else: ?>
          <?php while ($a = $anns->fetch_assoc()): ?>
            <div class="announcement-item <?= $a['type'] ?>" style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
              <div style="flex:1;">
                <div class="ann-title"><?= htmlspecialchars($a['title']) ?></div>
                <div class="ann-content"><?= htmlspecialchars($a['content']) ?></div>
                <div class="ann-date"><?= date('M j, Y g:i A', strtotime($a['created_at'])) ?></div>
              </div>
              <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ann_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--accent3);" onclick="return confirm('Delete this announcement?')">🗑️</button>
              </form>
            </div>
          <?php endwhile; ?>
          <?php endif; ?>
        </div>

        <!-- New Announcement Form -->
        <div class="card fade-up fade-up-1" style="align-self:start;">
          <div class="card-header"><h3>✏️ New Announcement</h3></div>
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
              <label class="form-label">Title *</label>
              <input type="text" name="title" class="form-input" placeholder="Announcement title..." required>
            </div>
            <div class="form-group">
              <label class="form-label">Content *</label>
              <textarea name="content" class="form-textarea" placeholder="Write the announcement content..." required></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <option value="info">ℹ️ Info</option>
                <option value="warning">⚠️ Warning</option>
                <option value="urgent">🚨 Urgent</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">📢 Post Announcement</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
