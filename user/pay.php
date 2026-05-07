<?php
require_once '../includes/config.php';

// This page works both logged-in and via QR token link
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDB();
$token   = sanitize($_GET['token'] ?? '');
$error   = '';
$success = '';
$demand  = null;
$already_paid = false;

// ---- Validate Token ----
if (!$token) {
    $error = 'Invalid payment link. No token provided.';
} else {
    $stmt = $db->prepare("SELECT pd.*, u.full_name, u.email, u.student_id, u.phone,
        r.room_number, r.room_type, r.floor
        FROM payment_demands pd
        JOIN users u ON pd.user_id = u.id
        LEFT JOIN rooms r ON u.room_id = r.id
        WHERE pd.qr_token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $demand = $stmt->get_result()->fetch_assoc();

    if (!$demand) {
        $error = 'Invalid or expired payment link.';
    } elseif ($demand['status'] === 'cancelled') {
        $error = 'This payment demand has been cancelled by the admin.';
    } elseif ($demand['status'] === 'paid') {
        $already_paid = true;
        // Fetch receipt
        $txn = $db->query("SELECT * FROM payment_transactions WHERE demand_id={$demand['id']}")->fetch_assoc();
    }
}

// ---- Process Payment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_pay']) && $demand) {
    // Re-verify token from POST as well
    $post_token = sanitize($_POST['pay_token'] ?? '');
    $post_hash  = sanitize($_POST['pay_hash'] ?? '');

    // Verify secure hash matches
    $expected_hash = hash('sha256', $demand['qr_token'] . $demand['user_id'] . $demand['amount'] . $demand['admin_id'] . $demand['created_at']);

    if ($post_token !== $token) {
        $error = 'Security check failed: token mismatch.';
    } elseif ($post_hash !== $expected_hash) {
        $error = 'Security check failed: invalid signature.';
    } elseif ($demand['status'] !== 'unpaid' && $demand['status'] !== 'overdue') {
        $error = 'This payment cannot be processed (already paid or cancelled).';
    } else {
        // Generate unique transaction ref and receipt number
        $transaction_ref = 'TXN' . strtoupper(bin2hex(random_bytes(8)));
        $receipt_number  = 'RCP-' . date('Y') . '-' . str_pad($demand['id'], 5, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $ip              = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua              = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $user_id         = $demand['user_id'];
        $demand_id       = $demand['id'];
        $amount          = $demand['amount'];

        // Insert transaction
        $stmt2 = $db->prepare("INSERT INTO payment_transactions
            (demand_id, user_id, amount, payment_method, transaction_ref, ip_address, user_agent, receipt_number)
            VALUES (?,?,?,'qr_scan',?,?,?,?)");
        $stmt2->bind_param('iidssss', $demand_id, $user_id, $amount, $transaction_ref, $ip, $ua, $receipt_number);

        if ($stmt2->execute()) {
            // Update demand status
            $db->query("UPDATE payment_demands SET status='paid', updated_at=NOW() WHERE id=$demand_id");
            $demand['status'] = 'paid';
            $already_paid = true;
            $txn = [
                'transaction_ref' => $transaction_ref,
                'receipt_number'  => $receipt_number,
                'paid_at'         => date('Y-m-d H:i:s'),
                'amount'          => $amount,
            ];
            $success = 'Payment successful!';
        } else {
            $error = 'Payment processing failed. Please try again.';
        }
    }
}

// Build secure hash for form
$form_hash = '';
if ($demand && !$already_paid) {
    $form_hash = hash('sha256', $demand['qr_token'] . $demand['user_id'] . $demand['amount'] . $demand['admin_id'] . $demand['created_at']);
}

$type_labels = [
    'room_rent'        => '🏠 Room Rent',
    'mess_fee'         => '🍽️ Mess Fee',
    'maintenance_fee'  => '🔧 Maintenance Fee',
    'security_deposit' => '🔒 Security Deposit',
    'fine'             => '⚠️ Fine',
    'other'            => '📋 Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pay Now - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px; }

    .pay-wrapper {
      width:100%; max-width:500px;
      position:relative; z-index:1;
    }

    .pay-card {
      background:var(--bg-card);
      border:1px solid var(--border);
      border-radius:24px;
      overflow:hidden;
      box-shadow:0 20px 80px rgba(0,0,0,0.5);
    }

    .pay-header {
      background:linear-gradient(135deg, #1a1535, #0d1f1a);
      padding:32px 32px 28px;
      text-align:center;
      border-bottom:1px solid var(--border);
      position:relative;
    }

    .pay-header::before {
      content:'';
      position:absolute; inset:0;
      background:radial-gradient(circle at 50% 0%, rgba(108,99,255,0.15), transparent 60%);
    }

    .pay-logo {
      width:52px; height:52px;
      background:linear-gradient(135deg, var(--accent), var(--accent2));
      border-radius:14px;
      display:flex; align-items:center; justify-content:center;
      font-size:22px;
      margin:0 auto 16px;
      position:relative; z-index:1;
    }

    .pay-title {
      font-family:'Syne',sans-serif;
      font-size:1.4rem; font-weight:800;
      letter-spacing:-0.03em;
      position:relative; z-index:1;
    }

    .pay-subtitle {
      font-size:0.82rem; color:var(--text-2);
      margin-top:4px;
      position:relative; z-index:1;
    }

    .pay-body { padding:28px 32px; }

    .amount-display {
      text-align:center;
      padding:24px;
      background:linear-gradient(135deg, rgba(0,212,170,0.08), rgba(108,99,255,0.08));
      border:1px solid rgba(0,212,170,0.2);
      border-radius:16px;
      margin-bottom:24px;
    }

    .amount-label { font-size:0.78rem; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-2); margin-bottom:8px; }

    .amount-value {
      font-family:'Syne',sans-serif;
      font-size:3rem; font-weight:800;
      letter-spacing:-0.04em;
      background:linear-gradient(135deg, var(--accent2), #00ffcc);
      -webkit-background-clip:text;
      -webkit-text-fill-color:transparent;
      background-clip:text;
    }

    .pay-type-pill {
      display:inline-flex; align-items:center; gap:6px;
      background:rgba(108,99,255,0.12);
      border:1px solid rgba(108,99,255,0.25);
      color:var(--accent);
      padding:6px 16px; border-radius:99px;
      font-size:0.82rem; font-weight:600;
      margin-top:10px;
    }

    .info-row {
      display:flex; align-items:center; justify-content:space-between;
      padding:11px 0;
      border-bottom:1px solid rgba(255,255,255,0.04);
      font-size:0.875rem;
    }
    .info-row:last-child { border-bottom:none; }
    .info-key { color:var(--text-2); }
    .info-val { font-weight:600; text-align:right; }

    .pay-btn {
      width:100%;
      background:linear-gradient(135deg, var(--accent2), #00a882);
      color:#0a0c12;
      border:none;
      border-radius:14px;
      padding:18px;
      font-family:'Syne',sans-serif;
      font-size:1.1rem; font-weight:800;
      cursor:pointer;
      transition:all 0.2s;
      letter-spacing:-0.01em;
      margin-top:20px;
      display:flex; align-items:center; justify-content:center; gap:10px;
    }
    .pay-btn:hover { transform:translateY(-2px); box-shadow:0 8px 30px rgba(0,212,170,0.35); }
    .pay-btn:active { transform:translateY(0); }

    .security-note {
      display:flex; align-items:center; gap:8px;
      margin-top:16px; padding:12px 16px;
      background:rgba(255,255,255,0.03);
      border-radius:10px;
      font-size:0.75rem; color:var(--text-3);
    }

    /* Success State */
    .success-card {
      text-align:center;
      padding:40px 32px;
    }

    .success-icon {
      width:80px; height:80px;
      background:linear-gradient(135deg, var(--accent2), #00ffcc);
      border-radius:50%;
      display:flex; align-items:center; justify-content:center;
      font-size:36px;
      margin:0 auto 20px;
      animation:popIn 0.5s cubic-bezier(0.175,0.885,0.32,1.275);
    }

    @keyframes popIn {
      from { transform:scale(0); opacity:0; }
      to   { transform:scale(1); opacity:1; }
    }

    .receipt-box {
      background:var(--bg-glass);
      border:1px solid var(--border);
      border-radius:14px;
      padding:20px;
      margin:20px 0;
      text-align:left;
    }

    .receipt-row {
      display:flex; justify-content:space-between;
      padding:8px 0;
      font-size:0.85rem;
      border-bottom:1px solid rgba(255,255,255,0.04);
    }
    .receipt-row:last-child { border-bottom:none; }
    .receipt-row .rk { color:var(--text-2); }
    .receipt-row .rv { font-weight:600; }

    .bg-blob-1 { position:fixed; width:500px; height:500px; top:-200px; right:-200px; background:var(--accent); border-radius:50%; filter:blur(100px); opacity:0.06; z-index:0; }
    .bg-blob-2 { position:fixed; width:400px; height:400px; bottom:-150px; left:-150px; background:var(--accent2); border-radius:50%; filter:blur(100px); opacity:0.06; z-index:0; }
  </style>
</head>
<body>
<div class="bg-blob-1"></div>
<div class="bg-blob-2"></div>

<div class="pay-wrapper">

  <?php if ($error && !$demand): ?>
    <!-- Error: Invalid link -->
    <div class="pay-card">
      <div class="pay-header">
        <div class="pay-logo">🏠</div>
        <div class="pay-title">Residex Manager</div>
      </div>
      <div class="pay-body" style="text-align:center; padding:40px 32px;">
        <div style="font-size:48px; margin-bottom:16px;">❌</div>
        <h3 style="color:var(--accent3); margin-bottom:8px;">Invalid Payment Link</h3>
        <p style="color:var(--text-2); font-size:0.875rem;"><?= htmlspecialchars($error) ?></p>
        <a href="../user/login.php" class="btn btn-outline" style="margin-top:20px;">← Back to Login</a>
      </div>
    </div>

  <?php elseif ($already_paid && isset($txn)): ?>
    <!-- Already Paid / Success -->
    <div class="pay-card">
      <div class="pay-header">
        <div class="pay-logo">🏠</div>
        <div class="pay-title">Residex Manager</div>
        <div class="pay-subtitle">Secure Payment Portal</div>
      </div>
      <div class="success-card">
        <div class="success-icon">✅</div>
        <h2 style="font-size:1.5rem; margin-bottom:8px; letter-spacing:-0.03em;">Payment Successful!</h2>
        <p style="color:var(--text-2); font-size:0.875rem; margin-bottom:4px;">
          <?= htmlspecialchars($demand['payment_label']) ?>
        </p>
        <div style="font-family:'Syne',sans-serif; font-size:2.2rem; font-weight:800; color:var(--accent2); margin:12px 0;">
          ₹<?= number_format($demand['amount'], 2) ?>
        </div>

        <div class="receipt-box">
          <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.1em; color:var(--text-3); margin-bottom:12px;">Payment Receipt</div>
          <div class="receipt-row"><span class="rk">Receipt No.</span><span class="rv" style="color:var(--accent);"><?= htmlspecialchars($txn['receipt_number']) ?></span></div>
          <div class="receipt-row"><span class="rk">Transaction Ref</span><span class="rv" style="font-size:0.8rem;"><?= htmlspecialchars($txn['transaction_ref']) ?></span></div>
          <div class="receipt-row"><span class="rk">Resident</span><span class="rv"><?= htmlspecialchars($demand['full_name']) ?></span></div>
          <div class="receipt-row"><span class="rk">Student ID</span><span class="rv"><?= $demand['student_id'] ?></span></div>
          <div class="receipt-row"><span class="rk">Room</span><span class="rv">Room <?= $demand['room_number'] ?? 'N/A' ?></span></div>
          <div class="receipt-row"><span class="rk">Payment Type</span><span class="rv"><?= $type_labels[$demand['payment_type']] ?? $demand['payment_type'] ?></span></div>
          <div class="receipt-row"><span class="rk">For Month</span><span class="rv"><?= $demand['month'] ?> <?= $demand['year'] ?></span></div>
          <div class="receipt-row"><span class="rk">Amount Paid</span><span class="rv" style="color:var(--accent2);">₹<?= number_format($txn['amount'], 2) ?></span></div>
          <div class="receipt-row"><span class="rk">Paid On</span><span class="rv"><?= date('d M Y, g:i A', strtotime($txn['paid_at'])) ?></span></div>
        </div>

        <a href="receipt.php?receipt=<?= urlencode($txn['receipt_number']) ?>"
           target="_blank" class="btn btn-primary" style="width:100%; justify-content:center; padding:14px; margin-bottom:10px;">
          🖨️ Print / Download Receipt
        </a>
        <a href="dashboard.php" class="btn btn-outline" style="width:100%; justify-content:center;">
          ← Back to Dashboard
        </a>
      </div>
    </div>

  <?php elseif ($demand): ?>
    <!-- Payment Form -->
    <div class="pay-card">
      <div class="pay-header">
        <div class="pay-logo">🏠</div>
        <div class="pay-title">Residex Manager</div>
        <div class="pay-subtitle">Secure Payment Portal</div>
      </div>

      <div class="pay-body">
        <?php if ($error): ?>
          <div class="alert alert-error" style="margin-bottom:16px;">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Amount Display -->
        <div class="amount-display">
          <div class="amount-label">Amount Due</div>
          <div class="amount-value">₹<?= number_format($demand['amount'], 2) ?></div>
          <div>
            <span class="pay-type-pill"><?= $type_labels[$demand['payment_type']] ?? $demand['payment_type'] ?></span>
          </div>
        </div>

        <!-- Demand Info -->
        <div style="margin-bottom:24px;">
          <div class="info-row">
            <span class="info-key">Resident Name</span>
            <span class="info-val"><?= htmlspecialchars($demand['full_name']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Student ID</span>
            <span class="info-val"><?= htmlspecialchars($demand['student_id']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Room</span>
            <span class="info-val">Room <?= $demand['room_number'] ?? 'N/A' ?> (<?= ucfirst($demand['room_type'] ?? '') ?>)</span>
          </div>
          <div class="info-row">
            <span class="info-key">Payment For</span>
            <span class="info-val"><?= htmlspecialchars($demand['payment_label']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Month</span>
            <span class="info-val"><?= $demand['month'] ?> <?= $demand['year'] ?></span>
          </div>
          <div class="info-row">
            <span class="info-key">Due Date</span>
            <span class="info-val" style="color:<?= strtotime($demand['due_date']) < time() ? 'var(--accent3)' : 'var(--text-1)' ?>">
              <?= date('d M Y', strtotime($demand['due_date'])) ?>
              <?= strtotime($demand['due_date']) < time() ? ' ⚠️ Overdue' : '' ?>
            </span>
          </div>
          <?php if ($demand['description']): ?>
          <div class="info-row">
            <span class="info-key">Note</span>
            <span class="info-val" style="font-weight:400; color:var(--text-2); font-size:0.82rem; text-align:right; max-width:60%;">
              <?= htmlspecialchars($demand['description']) ?>
            </span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Pay Button Form -->
        <form method="POST" id="payForm">
          <input type="hidden" name="confirm_pay" value="1">
          <input type="hidden" name="pay_token" value="<?= htmlspecialchars($token) ?>">
          <input type="hidden" name="pay_hash" value="<?= htmlspecialchars($form_hash) ?>">

          <button type="button" class="pay-btn" onclick="confirmPayment()">
            <span>⚡</span> Pay ₹<?= number_format($demand['amount'], 2) ?> Now
          </button>
        </form>

        <div class="security-note">
          🔒 This is a secure, one-time payment link. The amount is fixed by your hostel admin and cannot be modified. Your payment will be confirmed instantly.
        </div>
      </div>
    </div>

  <?php endif; ?>

  <!-- Footer -->
  <div style="text-align:center; margin-top:20px; font-size:0.72rem; color:var(--text-3);">
    Secured by Residex Manager &nbsp;·&nbsp; 256-bit SHA Encryption &nbsp;·&nbsp;
    <a href="../user/login.php" style="color:var(--text-3);">Resident Portal</a>
  </div>

</div>

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal" style="max-width:380px; text-align:center;">
    <div style="font-size:48px; margin-bottom:12px;">💳</div>
    <h3 style="margin-bottom:8px;">Confirm Payment</h3>
    <p style="color:var(--text-2); font-size:0.875rem; margin-bottom:20px;">
      You are about to pay <strong style="color:var(--accent2);">₹<?= number_format($demand['amount'] ?? 0, 2) ?></strong>
      for <strong><?= htmlspecialchars($demand['payment_label'] ?? '') ?></strong>.
      This action cannot be undone.
    </p>
    <div style="display:flex; gap:12px;">
      <button class="btn btn-outline" style="flex:1;" onclick="document.getElementById('confirmModal').classList.remove('open')">
        Cancel
      </button>
      <button class="btn btn-success" style="flex:1;" onclick="document.getElementById('payForm').submit()">
        ✅ Confirm Pay
      </button>
    </div>
  </div>
</div>

<script>
function confirmPayment() {
  document.getElementById('confirmModal').classList.add('open');
}
</script>
</body>
</html>
