<?php
require_once '../includes/config.php';

if (isLoggedIn('user')) redirect(SITE_URL . '/user/dashboard.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter email and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'inactive') {
                $error = 'Your account has been deactivated. Contact admin.';
            } else {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_room'] = $user['room_id'];
                if ($user['status'] === 'pending') {
                    $_SESSION['user_pending'] = true;
                }
                redirect(SITE_URL . '/user/dashboard.php');
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - Residex Manager</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .bg-circle-1 { width: 600px; height: 600px; top: -250px; right: -250px; background: var(--accent); }
    .bg-circle-2 { width: 400px; height: 400px; bottom: -150px; left: -150px; background: var(--accent2); }
    .role-switch { display: flex; gap: 10px; justify-content: center; margin-bottom: 24px; }
    .role-btn { padding: 8px 20px; border-radius: 99px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border); color: var(--text-2); background: none; text-decoration: none; transition: all 0.2s; }
    .role-btn.active { background: var(--accent); border-color: var(--accent); color: white; }
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
      <div class="icon">🏠</div>
      <h1>Residex Manager</h1>
      <p>Smart Hostel Management System</p>
    </div>

    <div class="role-switch">
      <span class="role-btn active">👤 Resident</span>
      <a href="../admin/login.php" class="role-btn">🛡️ Admin</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-input" placeholder="your@email.com" required autofocus>
      </div>

      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="******" required>
      </div>

      <button type="submit" class="btn btn-primary w-full" style="justify-content:center; padding:14px; margin-top: 8px;">
        Sign In →
      </button>
    </form>

    <div class="auth-link" style="margin-top: 16px;">
      New here? <a href="register.php">Create an account</a>
    </div>
  </div>
</div>
</body>
</html>
