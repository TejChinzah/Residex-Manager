<?php
require_once '../includes/config.php';

if (isLoggedIn('admin')) redirect(SITE_URL . '/admin/dashboard.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter credentials.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['full_name'];
            redirect(SITE_URL . '/admin/dashboard.php');
        } else {
            $error = 'Invalid admin credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .bg-circle-1 { width:600px; height:600px; top:-200px; left:-200px; background: var(--accent2); }
    .bg-circle-2 { width:400px; height:400px; bottom:-100px; right:-100px; background: var(--accent); }
    .admin-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(255,209,102,0.15); border:1px solid rgba(255,209,102,0.3); color:var(--accent4); padding:6px 14px; border-radius:99px; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:20px; }
  </style>
</head>
<body>
<div class="auth-page">
  <div class="auth-bg">
    <div class="auth-bg-circle bg-circle-1"></div>
    <div class="auth-bg-circle bg-circle-2"></div>
  </div>

  <div class="auth-card fade-up">
    <div class="auth-logo">
      <div class="icon">🛡️</div>
      <h1>Admin Portal</h1>
      <p>Residex Manager Control Center</p>
    </div>

    <div style="text-align:center;">
      <span class="admin-badge">🔐 Secured Admin Access</span>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">Admin Email</label>
        <input type="email" name="email" class="form-input" placeholder="admin@residex.com" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="*******" required>
      </div>
      <button type="submit" class="btn btn-primary w-full" style="justify-content:center; padding:14px;">
        🔓 Access Dashboard
      </button>
    </form>

    <div class="auth-link">
      <a href="../user/login.php">← Back to Resident Login</a>
    </div>

    <div style="margin-top:20px; padding:14px; background:rgba(255,255,255,0.04); border-radius:10px; font-size:0.78rem; color:var(--text-3); text-align:center;">
      Designed & Developed By Tej Chinzah
    </div>
  </div>
</div>
</body>
</html>
