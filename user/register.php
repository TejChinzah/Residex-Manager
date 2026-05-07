<?php
require_once '../includes/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = sanitize($_POST['full_name'] ?? '');
    $email            = sanitize($_POST['email'] ?? '');
    $phone            = sanitize($_POST['phone'] ?? '');
    $student_id       = strtoupper(sanitize($_POST['student_id'] ?? ''));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gender           = sanitize($_POST['gender'] ?? '');
    $room_id          = intval($_POST['room_id'] ?? 0);
    $address          = sanitize($_POST['address'] ?? '');
    $emergency_contact = sanitize($_POST['emergency_contact'] ?? '');

    if (!$full_name || !$email || !$phone || !$student_id || !$password || !$gender || !$room_id) {
        $error = 'Please fill in all required fields.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!str_ends_with($email, "@gmail.com")) {
        $error = 'Only gmail accounts allowed.';
    } elseif (!preg_match("/^MZUC?[0-9]{8,}$/", $student_id)) {
        $error = 'Invalid Student ID format. Must be MZU or MZUC followed by at least 8 digits. Ex: MZU22000069, MZUC240000372';
    } else {
        $db = getDB();

        // Check email or student ID exists
        $check = $db->query("SELECT id FROM users WHERE email='$email' OR student_id='$student_id'");
        if ($check->num_rows > 0) {
            $error = 'Email or Student ID already registered.';
        } else {
            // Check room availability
            $room = $db->query("SELECT * FROM rooms WHERE id=$room_id AND status != 'maintenance'")->fetch_assoc();
            if (!$room) {
                $error = 'Selected room is unavailable.';
            } elseif ($room['occupied'] >= $room['capacity']) {
                $error = 'Selected room is already full.';
            } else {
                $bed = $room['occupied'] + 1;
                $hashed = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, student_id, password, room_id, bed_number, gender, address, emergency_contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssssisss', $full_name, $email, $phone, $student_id, $hashed, $room_id, $bed, $gender, $address, $emergency_contact);

                if ($stmt->execute()) {
                    $db->query("UPDATE rooms SET occupied = occupied + 1, status = CASE WHEN occupied+1 >= capacity THEN 'full' ELSE 'available' END WHERE id=$room_id");
                    $success = 'Registration successful! Your account is pending approval. Please login.';
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

// Fetch available rooms
$db = getDB();
$rooms = $db->query("SELECT * FROM rooms WHERE status != 'full' ORDER BY room_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register - Residex Manager</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .auth-page { align-items: flex-start; padding: 40px 20px; }
    .auth-card { max-width: 600px; }
    .bg-circle-1 { width: 500px; height: 500px; top: -200px; right: -200px; background: var(--accent); }
    .bg-circle-2 { width: 400px; height: 400px; bottom: -100px; left: -150px; background: var(--accent2); }
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
      <p>Create your hostel account</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠️ <?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>

    <form method="POST" id="registerForm">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-input" placeholder="Tej Chinzah" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Student / Member ID *</label>
          <input type="text" name="student_id" class="form-input" placeholder="MZU22000069 or MZUC240000372" value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Email Address *</label>
          <input type="email" name="email" class="form-input" placeholder="name@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone Number *</label>
          <input type="tel" name="phone" class="form-input" placeholder="+91 xxxxxxxxxx" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Gender *</label>
          <select name="gender" class="form-select" required>
            <option value="">Select Gender</option>
            <option value="male" <?= ($_POST['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($_POST['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Room Preference *</label>
          <select name="room_id" class="form-select" required>
            <option value="">Select Room</option>
            <?php while ($r = $rooms->fetch_assoc()): ?>
              <option value="<?= $r['id'] ?>" <?= ($_POST['room_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                Room <?= $r['room_number'] ?> (Floor <?= $r['floor'] ?>) -
                <?= ucfirst($r['room_type']) ?> |
                <?= $r['occupied'] ?>/<?= $r['capacity'] ?> occupied
              </option>
            <?php endwhile; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Home Address</label>
        <textarea name="address" class="form-textarea" placeholder="Your permanent address..." rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Emergency Contact</label>
        <input type="tel" name="emergency_contact" class="form-input" placeholder="Parent / Guardian phone" value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '') ?>">
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Password *</label>
          <input type="password" name="password" class="form-input" placeholder="Min 6 characters" required>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password *</label>
          <input type="password" name="confirm_password" class="form-input" placeholder="Repeat password" required>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-full" style="justify-content:center; padding: 14px;">
        🚀 Create Account
      </button>
    </form>

    <div class="auth-link">
      Already have an account? <a href="login.php">Sign in</a>
    </div>
  </div>
</div>

<script>
document.getElementById('registerForm').addEventListener('submit', function(e) {
    const email = document.querySelector('input[name="email"]').value.trim();
    const studentId = document.querySelector('input[name="student_id"]').value.trim().toUpperCase();
    const password = document.querySelector('input[name="password"]').value;
    const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
    
    let errorMsg = '';

    if (!email.endsWith('@gmail.com')) {
        errorMsg = 'Only Gmail accounts are allowed.';
    }
    else if (!/^MZUC?[0-9]{8,}$/.test(studentId)) {
        errorMsg = 'Invalid Student ID. Must be MZU or MZUC followed by at least 8 digits. Ex: MZU22000069';
    }
    else if (password !== confirmPassword) {
        errorMsg = 'Passwords do not match.';
    }
    else if (password.length < 6) {
        errorMsg = 'Password must be at least 6 characters.';
    }

    if (errorMsg) {
        e.preventDefault();
        showError(errorMsg);
    }
});

function showError(msg) {
    let alertBox = document.querySelector('.alert-error');
    if (!alertBox) {
        alertBox = document.createElement('div');
        alertBox.className = 'alert alert-error';
        document.querySelector('.auth-logo').insertAdjacentElement('afterend', alertBox);
    }
    alertBox.innerHTML = '⚠️ ' + msg;
    alertBox.scrollIntoView({behavior: 'smooth'});
}

// Validate on blur
document.querySelector('input[name="email"]').addEventListener('blur', function() {
    if (this.value && !this.value.endsWith('@gmail.com')) {
        showError('Only Gmail accounts are allowed.');
    }
});

document.querySelector('input[name="student_id"]').addEventListener('blur', function() {
    const val = this.value.toUpperCase();
    if (val && !/^MZUC?[0-9]{8,}$/.test(val)) {
        showError('Invalid Student ID format. Use MZU or MZUC followed by at least 8 digits. Ex: MZU22000069');
    }
});
</script>

</body>
</html>