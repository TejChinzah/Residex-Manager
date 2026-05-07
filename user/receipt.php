<?php
require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDB();
$receipt_number = sanitize($_GET['receipt'] ?? '');
$error = '';
$txn   = null;
$demand = null;

if (!$receipt_number) {
    $error = 'No receipt number provided.';
} else {
    $stmt = $db->prepare("SELECT pt.*, pd.payment_type, pd.payment_label, pd.amount as demanded_amount,
        pd.month, pd.year, pd.due_date, pd.description, pd.qr_token,
        u.full_name, u.student_id, u.email, u.phone, u.gender, u.joined_date,
        r.room_number, r.room_type, r.floor,
        a.full_name as admin_name
        FROM payment_transactions pt
        JOIN payment_demands pd ON pt.demand_id = pd.id
        JOIN users u ON pt.user_id = u.id
        LEFT JOIN rooms r ON u.room_id = r.id
        JOIN admins a ON pd.admin_id = a.id
        WHERE pt.receipt_number = ?");
    $stmt->bind_param('s', $receipt_number);
    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();

    if (!$txn) {
        $error = 'Receipt not found. Please check the receipt number.';
    } else {
        // Security: if logged in as user, only show their own receipt
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $txn['user_id'] && !isset($_SESSION['admin_id'])) {
            $error = 'You are not authorized to view this receipt.';
            $txn = null;
        }
    }
}

$type_labels = [
    'room_rent'        => 'Room Rent',
    'mess_fee'         => 'Mess Fee',
    'maintenance_fee'  => 'Maintenance Fee',
    'security_deposit' => 'Security Deposit',
    'fine'             => 'Fine',
    'other'            => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt <?= htmlspecialchars($receipt_number) ?> - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    /* Screen styles */
    body { background:var(--bg-deep); min-height:100vh; display:flex; flex-direction:column; align-items:center; padding:30px 20px; }

    .screen-actions {
      display:flex; gap:12px; margin-bottom:24px;
      width:100%; max-width:680px;
    }

    .receipt-wrapper {
      width:100%; max-width:680px;
      background:white;
      border-radius:16px;
      overflow:hidden;
      box-shadow:0 20px 80px rgba(0,0,0,0.5);
      color:#111;
    }

    /* ---- Receipt Design ---- */
    .rcpt-header {
      background:linear-gradient(135deg, #1a1035, #0c1a24);
      color:white;
      padding:32px 40px 28px;
      position:relative;
      overflow:hidden;
    }

    .rcpt-header::before {
      content:'';
      position:absolute; top:-60px; right:-60px;
      width:200px; height:200px;
      background:rgba(108,99,255,0.2);
      border-radius:50%;
    }
    .rcpt-header::after {
      content:'';
      position:absolute; bottom:-40px; left:-40px;
      width:150px; height:150px;
      background:rgba(0,212,170,0.15);
      border-radius:50%;
    }

    .rcpt-top-row {
      display:flex; justify-content:space-between; align-items:flex-start;
      position:relative; z-index:1;
    }

    .rcpt-brand { display:flex; align-items:center; gap:14px; }
    .rcpt-brand-icon {
      width:48px; height:48px;
      background:linear-gradient(135deg, #6c63ff, #00d4aa);
      border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      font-size:22px;
    }
    .rcpt-brand-name { font-size:1.4rem; font-weight:900; letter-spacing:-0.03em; }
    .rcpt-brand-sub  { font-size:0.72rem; color:rgba(255,255,255,0.6); text-transform:uppercase; letter-spacing:0.1em; }

    .rcpt-badge {
      background:rgba(0,212,170,0.2);
      border:1px solid rgba(0,212,170,0.4);
      color:#00d4aa;
      padding:6px 14px; border-radius:99px;
      font-size:0.78rem; font-weight:700;
      text-transform:uppercase; letter-spacing:0.06em;
    }

    .rcpt-title-row {
      margin-top:24px;
      position:relative; z-index:1;
    }
    .rcpt-title { font-size:1.1rem; font-weight:300; color:rgba(255,255,255,0.7); text-transform:uppercase; letter-spacing:0.15em; }
    .rcpt-receipt-no { font-size:1.8rem; font-weight:900; letter-spacing:-0.02em; margin-top:4px; }

    /* Body */
    .rcpt-body { padding:32px 40px; }

    .rcpt-amount-section {
      background:linear-gradient(135deg, #f0fdf8, #eef2ff);
      border:2px solid #d1fae5;
      border-radius:14px;
      padding:24px 28px;
      text-align:center;
      margin-bottom:28px;
    }
    .rcpt-amount-label { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.12em; color:#6b7280; margin-bottom:6px; }
    .rcpt-amount-value { font-size:3rem; font-weight:900; color:#059669; letter-spacing:-0.04em; line-height:1; }
    .rcpt-payment-type { display:inline-block; background:#ede9fe; color:#7c3aed; padding:5px 14px; border-radius:99px; font-size:0.78rem; font-weight:700; margin-top:10px; }

    .rcpt-section { margin-bottom:24px; }
    .rcpt-section-title {
      font-size:0.65rem; text-transform:uppercase; letter-spacing:0.12em;
      color:#9ca3af; font-weight:700; margin-bottom:12px;
      padding-bottom:8px; border-bottom:1px solid #e5e7eb;
    }

    .rcpt-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    .rcpt-field { }
    .rcpt-field-label { font-size:0.72rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:3px; }
    .rcpt-field-value { font-size:0.92rem; font-weight:600; color:#111; }

    .rcpt-divider {
      border:none;
      border-top:2px dashed #e5e7eb;
      margin:24px 0;
    }

    .rcpt-txn-box {
      background:#f9fafb;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:16px 20px;
    }

    .rcpt-txn-row {
      display:flex; justify-content:space-between;
      padding:7px 0;
      font-size:0.85rem;
      border-bottom:1px solid #e5e7eb;
    }
    .rcpt-txn-row:last-child { border-bottom:none; }
    .rcpt-txn-key { color:#6b7280; }
    .rcpt-txn-val { font-weight:700; color:#111; font-family:'Courier New', monospace; }

    .rcpt-footer {
      background:#f9fafb;
      border-top:2px dashed #e5e7eb;
      padding:24px 40px;
      text-align:center;
    }
    .rcpt-footer-note { font-size:0.78rem; color:#9ca3af; line-height:1.6; }
    .rcpt-footer-brand { font-size:0.72rem; color:#d1d5db; margin-top:8px; font-weight:600; text-transform:uppercase; letter-spacing:0.1em; }

    .valid-stamp {
      display:inline-flex; align-items:center; gap:6px;
      background:#dcfce7; border:2px solid #16a34a;
      color:#15803d; padding:8px 18px; border-radius:99px;
      font-size:0.82rem; font-weight:700;
      margin-top:12px;
    }

    /* Print styles */
    @media print {
      body { background:white; padding:0; }
      .screen-actions { display:none !important; }
      .receipt-wrapper { box-shadow:none; border-radius:0; max-width:100%; }
      .rcpt-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .rcpt-amount-section { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
  </style>
</head>
<body>

<?php if ($error): ?>
  <div class="screen-actions" style="justify-content:center;">
    <div class="alert alert-error" style="width:100%;">⚠️ <?= htmlspecialchars($error) ?></div>
  </div>
  <a href="../user/dashboard.php" class="btn btn-outline">← Back to Dashboard</a>

<?php elseif ($txn): ?>

  <!-- Screen Buttons -->
  <div class="screen-actions">
    <a href="<?= isset($_SESSION['admin_id']) ? '../admin/payments.php' : 'dashboard.php' ?>"
       class="btn btn-outline btn-sm">← Back</a>
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print Receipt</button>
    <button onclick="downloadReceipt()" class="btn btn-outline btn-sm">⬇️ Save as PDF</button>
  </div>

  <!-- Receipt -->
  <div class="receipt-wrapper" id="receiptDoc">

    <!-- Header -->
    <div class="rcpt-header">
      <div class="rcpt-top-row">
        <div class="rcpt-brand">
          <div class="rcpt-brand-icon">🏠</div>
          <div>
            <div class="rcpt-brand-name">Residex Manager</div>
            <div class="rcpt-brand-sub">Hostel Management System</div>
          </div>
        </div>
        <div class="rcpt-badge">✅ PAID</div>
      </div>
      <div class="rcpt-title-row">
        <div class="rcpt-title">Payment Receipt</div>
        <div class="rcpt-receipt-no"><?= htmlspecialchars($txn['receipt_number']) ?></div>
      </div>
    </div>

    <!-- Body -->
    <div class="rcpt-body">

      <!-- Amount Block -->
      <div class="rcpt-amount-section">
        <div class="rcpt-amount-label">Amount Paid</div>
        <div class="rcpt-amount-value">₹<?= number_format($txn['amount'], 2) ?></div>
        <div>
          <span class="rcpt-payment-type">
            <?= $type_labels[$txn['payment_type']] ?? $txn['payment_type'] ?>
          </span>
        </div>
        <div class="valid-stamp">✅ Payment Verified &amp; Confirmed</div>
      </div>

      <!-- Resident Info -->
      <div class="rcpt-section">
        <div class="rcpt-section-title">Resident Information</div>
        <div class="rcpt-grid">
          <div class="rcpt-field">
            <div class="rcpt-field-label">Full Name</div>
            <div class="rcpt-field-value"><?= htmlspecialchars($txn['full_name']) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Student / Member ID</div>
            <div class="rcpt-field-value"><?= htmlspecialchars($txn['student_id']) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Room Number</div>
            <div class="rcpt-field-value">Room <?= $txn['room_number'] ?? 'N/A' ?> (<?= ucfirst($txn['room_type'] ?? '') ?>)</div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Floor</div>
            <div class="rcpt-field-value">Floor <?= $txn['floor'] ?? 'N/A' ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Email</div>
            <div class="rcpt-field-value" style="font-size:0.82rem;"><?= htmlspecialchars($txn['email']) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Phone</div>
            <div class="rcpt-field-value"><?= htmlspecialchars($txn['phone']) ?></div>
          </div>
        </div>
      </div>

      <hr class="rcpt-divider">

      <!-- Payment Info -->
      <div class="rcpt-section">
        <div class="rcpt-section-title">Payment Details</div>
        <div class="rcpt-grid">
          <div class="rcpt-field">
            <div class="rcpt-field-label">Payment For</div>
            <div class="rcpt-field-value"><?= htmlspecialchars($txn['payment_label']) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Payment Type</div>
            <div class="rcpt-field-value"><?= $type_labels[$txn['payment_type']] ?? $txn['payment_type'] ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Billing Month</div>
            <div class="rcpt-field-value"><?= $txn['month'] ?> <?= $txn['year'] ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Due Date</div>
            <div class="rcpt-field-value"><?= date('d M Y', strtotime($txn['due_date'])) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Amount Demanded</div>
            <div class="rcpt-field-value">₹<?= number_format($txn['demanded_amount'], 2) ?></div>
          </div>
          <div class="rcpt-field">
            <div class="rcpt-field-label">Amount Paid</div>
            <div class="rcpt-field-value" style="color:#059669;">₹<?= number_format($txn['amount'], 2) ?></div>
          </div>
          <?php if ($txn['description']): ?>
          <div class="rcpt-field" style="grid-column:1/-1;">
            <div class="rcpt-field-label">Admin Note</div>
            <div class="rcpt-field-value" style="font-weight:400; color:#6b7280;"><?= htmlspecialchars($txn['description']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <hr class="rcpt-divider">

      <!-- Transaction Info -->
      <div class="rcpt-section">
        <div class="rcpt-section-title">Transaction Record</div>
        <div class="rcpt-txn-box">
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Receipt Number</span>
            <span class="rcpt-txn-val"><?= htmlspecialchars($txn['receipt_number']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Transaction Reference</span>
            <span class="rcpt-txn-val"><?= htmlspecialchars($txn['transaction_ref']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Payment Method</span>
            <span class="rcpt-txn-val"><?= $txn['payment_method'] === 'qr_scan' ? 'QR Code Scan' : 'Online' ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Payment Date &amp; Time</span>
            <span class="rcpt-txn-val"><?= date('d M Y, g:i:s A', strtotime($txn['paid_at'])) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Processed By</span>
            <span class="rcpt-txn-val"><?= htmlspecialchars($txn['admin_name']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="rcpt-txn-key">Status</span>
            <span class="rcpt-txn-val" style="color:#059669;">✅ CONFIRMED</span>
          </div>
        </div>
      </div>

    </div><!-- /rcpt-body -->

    <!-- Footer -->
    <div class="rcpt-footer">
      <div class="rcpt-footer-note">
        This is a computer-generated receipt and is valid without a physical signature.<br>
        For queries, contact hostel administration. Keep this receipt for your records.
      </div>
      <div class="rcpt-footer-brand">Residex Manager · Hostel Management System</div>
      <div style="font-size:0.68rem; color:#d1d5db; margin-top:4px;">
        Generated on <?= date('d M Y, g:i A') ?> · Receipt: <?= htmlspecialchars($txn['receipt_number']) ?>
      </div>
    </div>

  </div><!-- /receipt-wrapper -->

<?php endif; ?>

<script>
function downloadReceipt() {
  window.print();
}
</script>
</body>
</html>
