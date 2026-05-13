<?php
require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDB();
$receipt_number = sanitize($_GET['receipt'] ?? '');
$error = '';
$txn   = null;

if (!$receipt_number) {
    $error = 'No receipt number provided.';
} else {
    $stmt = $db->prepare("SELECT pt.*, pd.payment_type, pd.payment_label,
        pd.amount as demanded_amount, pd.month, pd.year, pd.due_date,
        pd.description, pd.qr_token,
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
        $error = 'Receipt not found.';
    } elseif (isset($_SESSION['user_id']) && !isset($_SESSION['admin_id']) && $_SESSION['user_id'] != $txn['user_id']) {
        $error = 'You are not authorized to view this receipt.';
        $txn = null;
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
    /* ---- Screen wrapper ---- */
    body { background:var(--bg-deep); min-height:100vh; display:flex; flex-direction:column; align-items:center; padding:30px 16px 80px; }

    .screen-actions {
      display:flex; gap:10px; margin-bottom:20px;
      width:100%; max-width:794px;
    }

    /* ---- A4 Receipt ---- */
    .receipt-a4 {
      width: 794px;           /* A4 at 96dpi = 794px */
      min-height: 1123px;     /* A4 height */
      background: white;
      color: #111;
      font-family: 'DM Sans', 'Segoe UI', Arial, sans-serif;
      font-size: 13px;
      box-shadow: 0 20px 80px rgba(0,0,0,0.5);
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
    }

    /* Top colour band */
    .rcpt-band {
      height: 8px;
      background: linear-gradient(90deg, #6c63ff, #00d4aa);
      flex-shrink: 0;
    }

    /* Header */
    .rcpt-head {
      background: linear-gradient(135deg, #1a1035 0%, #0c1f2a 100%);
      color: white;
      padding: 28px 40px 24px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-shrink: 0;
      position: relative;
    }
    .rcpt-head::after {
      content: '';
      position: absolute; bottom: -1px; left: 0; right: 0; height: 1px;
      background: linear-gradient(90deg, #6c63ff33, #00d4aa33);
    }

    .rcpt-brand { display:flex; align-items:center; gap:14px; }
    .rcpt-brand-icon {
      width:48px; height:48px; border-radius:12px;
      background:linear-gradient(135deg,#6c63ff,#00d4aa);
      display:flex; align-items:center; justify-content:center;
      font-size:22px; flex-shrink:0;
    }
    .rcpt-brand-name { font-size:1.3rem; font-weight:900; letter-spacing:-0.02em; line-height:1; }
    .rcpt-brand-sub  { font-size:0.65rem; color:rgba(255,255,255,0.5); text-transform:uppercase; letter-spacing:0.1em; margin-top:3px; }

    .rcpt-head-right { text-align:right; }
    .rcpt-doc-label  { font-size:0.62rem; text-transform:uppercase; letter-spacing:0.15em; color:rgba(255,255,255,0.4); margin-bottom:4px; }
    .rcpt-doc-title  { font-size:1.1rem; font-weight:700; color:white; letter-spacing:-0.01em; }
    .rcpt-doc-no     { font-size:0.78rem; color:#00d4aa; margin-top:4px; font-family:'Courier New',monospace; }
    .rcpt-paid-stamp {
      display:inline-block; background:rgba(0,212,170,0.2);
      border:1.5px solid #00d4aa; color:#00d4aa;
      padding:4px 14px; border-radius:99px;
      font-size:0.68rem; font-weight:700; text-transform:uppercase;
      letter-spacing:0.1em; margin-top:6px;
    }

    /* Body */
    .rcpt-body {
      padding: 28px 40px 20px;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    /* Amount hero */
    .rcpt-amount-hero {
      background: linear-gradient(135deg, #f0fdf8, #eef2ff);
      border: 1.5px solid #d1fae5;
      border-radius: 14px;
      padding: 20px 28px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 22px;
    }
    .rcpt-amt-left .lbl { font-size:0.65rem; text-transform:uppercase; letter-spacing:0.1em; color:#6b7280; margin-bottom:4px; }
    .rcpt-amt-value { font-size:2.6rem; font-weight:900; color:#059669; letter-spacing:-0.04em; line-height:1; }
    .rcpt-amt-type  { display:inline-block; background:#ede9fe; color:#7c3aed; padding:4px 14px; border-radius:99px; font-size:0.72rem; font-weight:700; margin-top:8px; }
    .rcpt-verify    { display:flex; align-items:center; gap:8px; background:#dcfce7; border:1.5px solid #16a34a; color:#15803d; padding:8px 18px; border-radius:10px; font-size:0.78rem; font-weight:700; }

    /* Section */
    .rcpt-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-bottom: 18px;
    }
    .rcpt-section { margin-bottom: 18px; }
    .rcpt-sec-title {
      font-size: 0.6rem; text-transform:uppercase; letter-spacing:0.12em;
      color: #9ca3af; font-weight: 700;
      border-bottom: 1px solid #e5e7eb;
      padding-bottom: 6px; margin-bottom: 10px;
    }

    .rcpt-fields { display:grid; grid-template-columns:1fr 1fr; gap:8px 16px; }
    .rcpt-field .k { font-size:0.65rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:2px; }
    .rcpt-field .v { font-size:0.85rem; font-weight:600; color:#111; }

    /* Transaction box */
    .rcpt-txn-box {
      background: #f9fafb; border:1px solid #e5e7eb;
      border-radius:10px; overflow:hidden;
    }
    .rcpt-txn-row {
      display:flex; justify-content:space-between; align-items:center;
      padding: 8px 16px; border-bottom:1px solid #e5e7eb;
      font-size: 0.82rem;
    }
    .rcpt-txn-row:last-child { border-bottom:none; }
    .rcpt-txn-row .tk { color:#6b7280; }
    .rcpt-txn-row .tv { font-weight:700; color:#111; font-family:'Courier New',monospace; font-size:0.78rem; }
    .rcpt-txn-row .tv.green { color:#059669; font-size:0.85rem; }

    /* Divider dashed */
    .rcpt-dash { border:none; border-top:2px dashed #e5e7eb; margin:16px 0; }

    /* Footer */
    .rcpt-foot {
      border-top: 2px dashed #e5e7eb;
      padding: 16px 40px 20px;
      background: #fafafa;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-shrink: 0;
      gap: 20px;
    }
    .rcpt-foot-note { font-size:0.68rem; color:#9ca3af; line-height:1.6; flex:1; }
    .rcpt-foot-brand { font-size:0.6rem; color:#d1d5db; text-transform:uppercase; letter-spacing:0.12em; text-align:right; }
    .rcpt-foot-brand strong { display:block; font-size:0.72rem; color:#6b7280; margin-bottom:2px; }

    /* Bottom band */
    .rcpt-bottom-band {
      height: 6px;
      background: linear-gradient(90deg, #00d4aa, #6c63ff);
      flex-shrink: 0;
    }

    /* ---- PRINT ---- */
    @media print {
      @page {
        size: A4 portrait;
        margin: 0;
      }
      html, body {
        margin: 0; padding: 0;
        background: white !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .screen-actions { display:none !important; }
      .dev-footer { display:none !important; }
      body { padding: 0 !important; }
      .receipt-a4 {
        width: 100% !important;
        min-height: 100vh !important;
        box-shadow: none !important;
        page-break-after: avoid;
      }
      .rcpt-head, .rcpt-band, .rcpt-bottom-band,
      .rcpt-amount-hero, .rcpt-verify, .rcpt-txn-box, .rcpt-foot {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
    }
  </style>
</head>
<body>

<?php if ($error): ?>
  <div class="screen-actions" style="justify-content:center;">
    <div class="alert alert-error" style="width:100%;max-width:794px;">⚠️ <?= htmlspecialchars($error) ?></div>
  </div>
  <a href="../user/dashboard.php" class="btn btn-outline">← Back</a>

<?php elseif ($txn): ?>

  <!-- Screen Buttons -->
  <div class="screen-actions">
    <a href="<?= isset($_SESSION['admin_id']) ? '../admin/payments.php' : 'dashboard.php' ?>"
       class="btn btn-outline btn-sm">← Back</a>
    <button onclick="window.print()" class="btn btn-primary">🖨️ Print Receipt (A4)</button>
    <button onclick="window.print()" class="btn btn-outline btn-sm">⬇️ Save as PDF</button>
  </div>

  <!-- A4 Receipt -->
  <div class="receipt-a4" id="receiptDoc">

    <!-- Top Band -->
    <div class="rcpt-band"></div>

    <!-- Header -->
    <div class="rcpt-head">
      <div class="rcpt-brand">
        <div class="rcpt-brand-icon">🏠</div>
        <div>
          <div class="rcpt-brand-name">Residex Manager</div>
          <div class="rcpt-brand-sub">Hostel Management System</div>
        </div>
      </div>
      <div class="rcpt-head-right">
        <div class="rcpt-doc-label">Official Document</div>
        <div class="rcpt-doc-title">Payment Receipt</div>
        <div class="rcpt-doc-no"><?= htmlspecialchars($txn['receipt_number']) ?></div>
        <div><span class="rcpt-paid-stamp">✓ Paid &amp; Verified</span></div>
      </div>
    </div>

    <!-- Body -->
    <div class="rcpt-body">

      <!-- Amount Hero -->
      <div class="rcpt-amount-hero">
        <div class="rcpt-amt-left">
          <div class="lbl">Amount Paid</div>
          <div class="rcpt-amt-value">₹<?= number_format($txn['amount'], 2) ?></div>
          <div>
            <span class="rcpt-amt-type">
              <?= $type_labels[$txn['payment_type']] ?? $txn['payment_type'] ?>
            </span>
          </div>
        </div>
        <div class="rcpt-verify">
          <span style="font-size:18px;">✅</span>
          <div>
            <div>Payment Confirmed</div>
            <div style="font-size:0.65rem;font-weight:400;color:#166534;"><?= date('d M Y, g:i A', strtotime($txn['paid_at'])) ?></div>
          </div>
        </div>
      </div>

      <!-- Resident + Payment Info side by side -->
      <div class="rcpt-row">

        <!-- Resident Info -->
        <div class="rcpt-section">
          <div class="rcpt-sec-title">Resident Information</div>
          <div class="rcpt-fields">
            <div class="rcpt-field">
              <div class="k">Full Name</div>
              <div class="v"><?= htmlspecialchars($txn['full_name']) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Student / Member ID</div>
              <div class="v"><?= htmlspecialchars($txn['student_id']) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Room Number</div>
              <div class="v">Room <?= $txn['room_number'] ?? 'N/A' ?> (<?= ucfirst($txn['room_type'] ?? '') ?>)</div>
            </div>
            <div class="rcpt-field">
              <div class="k">Floor</div>
              <div class="v">Floor <?= $txn['floor'] ?? 'N/A' ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Email</div>
              <div class="v" style="font-size:0.78rem;"><?= htmlspecialchars($txn['email']) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Phone</div>
              <div class="v"><?= htmlspecialchars($txn['phone']) ?></div>
            </div>
          </div>
        </div>

        <!-- Payment Details -->
        <div class="rcpt-section">
          <div class="rcpt-sec-title">Payment Details</div>
          <div class="rcpt-fields">
            <div class="rcpt-field">
              <div class="k">Payment For</div>
              <div class="v"><?= htmlspecialchars($txn['payment_label']) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Payment Type</div>
              <div class="v"><?= $type_labels[$txn['payment_type']] ?? $txn['payment_type'] ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Billing Month</div>
              <div class="v"><?= $txn['month'] ?> <?= $txn['year'] ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Due Date</div>
              <div class="v"><?= date('d M Y', strtotime($txn['due_date'])) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Amount Demanded</div>
              <div class="v">₹<?= number_format($txn['demanded_amount'], 2) ?></div>
            </div>
            <div class="rcpt-field">
              <div class="k">Amount Paid</div>
              <div class="v" style="color:#059669;">₹<?= number_format($txn['amount'], 2) ?></div>
            </div>
            <?php if ($txn['description']): ?>
            <div class="rcpt-field" style="grid-column:1/-1;">
              <div class="k">Admin Note</div>
              <div class="v" style="font-weight:400;color:#6b7280;"><?= htmlspecialchars($txn['description']) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <hr class="rcpt-dash">

      <!-- Transaction Record -->
      <div class="rcpt-section">
        <div class="rcpt-sec-title">Transaction Record</div>
        <div class="rcpt-txn-box">
          <div class="rcpt-txn-row">
            <span class="tk">Receipt Number</span>
            <span class="tv"><?= htmlspecialchars($txn['receipt_number']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="tk">Transaction Reference</span>
            <span class="tv"><?= htmlspecialchars($txn['transaction_ref']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="tk">Payment Method</span>
            <span class="tv"><?= $txn['payment_method'] === 'qr_scan' ? 'QR Code Scan' : 'Online' ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="tk">Payment Date &amp; Time</span>
            <span class="tv"><?= date('d M Y, g:i:s A', strtotime($txn['paid_at'])) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="tk">Processed By (Admin)</span>
            <span class="tv"><?= htmlspecialchars($txn['admin_name']) ?></span>
          </div>
          <div class="rcpt-txn-row">
            <span class="tk">Payment Status</span>
            <span class="tv green">✅ CONFIRMED &amp; PAID</span>
          </div>
        </div>
      </div>

    </div><!-- /rcpt-body -->

    <!-- Footer -->
    <div class="rcpt-foot">
      <div class="rcpt-foot-note">
        This is a computer-generated receipt and is valid without a physical signature.<br>
        Please retain this receipt for your records. For queries contact hostel administration.<br>
        Generated on <?= date('d M Y \a\t g:i A') ?>
      </div>
      <div class="rcpt-foot-brand">
        <strong>Residex Manager</strong>
        Hostel Management System<br>
        <?= htmlspecialchars($txn['receipt_number']) ?>
      </div>
    </div>

    <!-- Bottom Band -->
    <div class="rcpt-bottom-band"></div>

  </div><!-- /receipt-a4 -->

<?php endif; ?>

<!-- Footer (screen only, hidden on print) -->
<footer class="dev-footer no-sidebar" style="margin-top:20px;">
  <div class="dev-footer-inner">
    <span>&copy; <?php echo date("Y"); ?> Residex Manager</span>
    <span class="dot">&#9679;</span>
    <span>Designed &amp; Developed with</span>
    <span class="heart">&#9829;</span>
    <span>by <span class="dev-name">Shit Happen Inc.</span></span>
  </div>
</footer>
</body>
</html>