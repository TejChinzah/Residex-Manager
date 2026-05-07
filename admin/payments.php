<?php
require_once '../includes/config.php';
requireLogin('admin');

$db = getDB();
$admin_id = $_SESSION['admin_id'];
$success = '';
$error = '';

// ---- Handle Create Payment Demand ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create_demand') {
        $user_id      = intval($_POST['user_id']);
        $payment_type = sanitize($_POST['payment_type']);
        $payment_label = sanitize($_POST['payment_label']);
        $amount       = floatval($_POST['amount']);
        $due_date     = sanitize($_POST['due_date']);
        $month        = sanitize($_POST['month']);
        $year         = intval($_POST['year']);
        $description  = sanitize($_POST['description']);

        if (!$user_id || !$payment_type || !$amount || !$due_date || !$month || !$year) {
            $error = 'Please fill all required fields.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } else {
            // Generate secure QR token and hash
            $qr_token    = bin2hex(random_bytes(24));
            $secure_hash = hash('sha256', $qr_token . $user_id . $amount . $admin_id . time());

            $stmt = $db->prepare("INSERT INTO payment_demands
                (user_id, admin_id, payment_type, payment_label, amount, due_date, month, year, description, qr_token, secure_hash)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iissdssssss',
                $user_id, $admin_id, $payment_type, $payment_label,
                $amount, $due_date, $month, $year, $description, $qr_token, $secure_hash);

            if ($stmt->execute()) {
                $success = 'Payment demand created successfully!';
            } else {
                $error = 'Failed to create demand: ' . $db->error;
            }
        }
    }

    if ($_POST['action'] === 'cancel_demand') {
        $did = intval($_POST['demand_id']);
        $db->query("UPDATE payment_demands SET status='cancelled' WHERE id=$did AND admin_id=$admin_id");
        $success = 'Payment demand cancelled.';
    }

    if ($_POST['action'] === 'mark_overdue') {
        $db->query("UPDATE payment_demands SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()");
        $success = 'Overdue payments updated.';
    }
}

// Fetch all users for dropdown
$users = $db->query("SELECT u.id, u.full_name, u.student_id, u.email, r.room_number
    FROM users u LEFT JOIN rooms r ON u.room_id = r.id
    WHERE u.status='active' ORDER BY u.full_name");

// Fetch all demands with user info
$filter_status = sanitize($_GET['status'] ?? 'all');
$filter_user   = intval($_GET['user_id'] ?? 0);
$where = "1=1";
if ($filter_status !== 'all') $where .= " AND pd.status='$filter_status'";
if ($filter_user) $where .= " AND pd.user_id=$filter_user";

$demands = $db->query("SELECT pd.*, u.full_name, u.student_id, u.email, r.room_number,
    pt.transaction_ref, pt.paid_at, pt.receipt_number
    FROM payment_demands pd
    JOIN users u ON pd.user_id = u.id
    LEFT JOIN rooms r ON u.room_id = r.id
    LEFT JOIN payment_transactions pt ON pd.id = pt.demand_id
    WHERE $where
    ORDER BY pd.created_at DESC");

// Stats
$total_demanded  = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status != 'cancelled'")->fetch_assoc()['t'];
$total_collected = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status='paid'")->fetch_assoc()['t'];
$total_pending   = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status='unpaid'")->fetch_assoc()['t'];
$total_overdue   = $db->query("SELECT COUNT(*) as c FROM payment_demands WHERE status='overdue'")->fetch_assoc()['c'];

$type_labels = [
    'room_rent'          => '🏠 Room Rent',
    'mess_fee'           => '🍽️ Mess Fee',
    'maintenance_fee'    => '🔧 Maintenance Fee',
    'security_deposit'   => '🔒 Security Deposit',
    'fine'               => '⚠️ Fine',
    'other'              => '📋 Other',
];

function statusBadgePay($s) {
    $map = ['unpaid'=>'badge-warning','paid'=>'badge-success','overdue'=>'badge-danger','cancelled'=>'badge-muted'];
    $ico = ['unpaid'=>'⏳','paid'=>'✅','overdue'=>'🔴','cancelled'=>'❌'];
    $cls = $map[$s] ?? 'badge-muted';
    $label = ($ico[$s] ?? '') . ' ' . ucfirst($s);
    return "<span class='badge $cls'>$label</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments - Residex Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .amount-big { font-family:'Syne',sans-serif; font-weight:800; font-size:1.6rem; letter-spacing:-0.03em; }
    .rupee { font-size:1rem; color:var(--text-2); margin-right:2px; }
    .pay-type-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:8px; font-size:0.75rem; font-weight:600; background:rgba(108,99,255,0.1); color:var(--accent); border:1px solid rgba(108,99,255,0.2); }
    .qr-mini { width:36px; height:36px; background:white; border-radius:6px; padding:3px; cursor:pointer; transition:transform 0.2s; }
    .qr-mini:hover { transform:scale(1.1); }
    .filter-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .stat-highlight { font-size:0.72rem; color:var(--text-3); margin-top:4px; }
  </style>
</head>
<body>
<div class="app-wrapper">
  <!-- Sidebar -->
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
      <a href="payments.php" class="nav-item active"><span class="icon">💳</span> Payments</a>
      <a href="residents.php" class="nav-item"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
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
        <h2>💳 Payment Management</h2>
        <p>Create demands, track payments, issue receipts</p>
      </div>
      <div class="topbar-actions">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="mark_overdue">
          <button class="btn btn-outline btn-sm">🔴 Mark Overdue</button>
        </form>
        <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')">
          + New Payment Demand
        </button>
      </div>
    </div>

    <div class="page-body">
      <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

      <!-- Stats -->
      <div class="stats-grid" style="margin-bottom:28px;">
        <div class="stat-card teal fade-up">
          <div class="stat-icon">💰</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_collected, 0) ?></div>
          <div class="stat-label">Total Collected</div>
          <div class="stat-highlight">All time payments received</div>
        </div>
        <div class="stat-card yellow fade-up fade-up-1">
          <div class="stat-icon">⏳</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_pending, 0) ?></div>
          <div class="stat-label">Pending Amount</div>
          <div class="stat-highlight">Yet to be paid</div>
        </div>
        <div class="stat-card red fade-up fade-up-2">
          <div class="stat-icon">🔴</div>
          <div class="stat-value"><?= $total_overdue ?></div>
          <div class="stat-label">Overdue Demands</div>
          <div class="stat-highlight">Past due date</div>
        </div>
        <div class="stat-card purple fade-up fade-up-3">
          <div class="stat-icon">📋</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_demanded, 0) ?></div>
          <div class="stat-label">Total Demanded</div>
          <div class="stat-highlight">Gross amount raised</div>
        </div>
      </div>

      <!-- Filter Bar -->
      <div class="card fade-up" style="margin-bottom:20px; padding:16px 24px;">
        <div class="filter-bar">
          <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <select name="status" class="form-select" style="width:auto;">
              <option value="all" <?= $filter_status==='all'?'selected':'' ?>>All Status</option>
              <option value="unpaid" <?= $filter_status==='unpaid'?'selected':'' ?>>⏳ Unpaid</option>
              <option value="paid" <?= $filter_status==='paid'?'selected':'' ?>>✅ Paid</option>
              <option value="overdue" <?= $filter_status==='overdue'?'selected':'' ?>>🔴 Overdue</option>
              <option value="cancelled" <?= $filter_status==='cancelled'?'selected':'' ?>>❌ Cancelled</option>
            </select>
            <select name="user_id" class="form-select" style="width:auto;">
              <option value="0">All Residents</option>
              <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
                <option value="<?= $u['id'] ?>" <?= $filter_user==$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars($u['full_name']) ?> (<?= $u['student_id'] ?>)
                </option>
              <?php endwhile; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
            <a href="payments.php" class="btn btn-outline btn-sm">Reset</a>
          </form>
          <div style="margin-left:auto; font-size:0.8rem; color:var(--text-2);">
            <?= $demands->num_rows ?> records found
          </div>
        </div>
      </div>

      <!-- Demands Table -->
      <div class="card fade-up">
        <div class="card-header">
          <h3>📋 Payment Demands</h3>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Resident</th>
                <th>Room</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Month</th>
                <th>Due Date</th>
                <th>Status</th>
                <th>QR</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($demands->num_rows === 0): ?>
                <tr><td colspan="10" style="text-align:center; padding:40px; color:var(--text-3);">
                  No payment demands found. Create one using the button above.
                </td></tr>
              <?php else: while ($d = $demands->fetch_assoc()): ?>
              <tr>
                <td style="color:var(--text-3); font-size:0.8rem;">#<?= $d['id'] ?></td>
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($d['full_name']) ?></div>
                  <div style="font-size:0.72rem; color:var(--text-3);"><?= $d['student_id'] ?></div>
                </td>
                <td><span class="badge badge-muted">Room <?= $d['room_number'] ?? 'N/A' ?></span></td>
                <td>
                  <span class="pay-type-badge"><?= $type_labels[$d['payment_type']] ?? $d['payment_type'] ?></span>
                  <div style="font-size:0.72rem; color:var(--text-3); margin-top:3px;"><?= htmlspecialchars($d['payment_label']) ?></div>
                </td>
                <td>
                  <div style="font-family:'Syne',sans-serif; font-weight:800; font-size:1.1rem; color:var(--accent2);">
                    ₹<?= number_format($d['amount'], 2) ?>
                  </div>
                </td>
                <td style="color:var(--text-2); font-size:0.82rem;"><?= $d['month'] ?> <?= $d['year'] ?></td>
                <td style="color:var(--text-2); font-size:0.82rem;">
                  <?= date('d M Y', strtotime($d['due_date'])) ?>
                  <?php if ($d['status'] === 'unpaid' && strtotime($d['due_date']) < time()): ?>
                    <div style="color:var(--accent3); font-size:0.7rem;">⚠️ Overdue</div>
                  <?php endif; ?>
                </td>
                <td><?= statusBadgePay($d['status']) ?>
                  <?php if ($d['paid_at']): ?>
                    <div style="font-size:0.68rem; color:var(--text-3); margin-top:3px;">
                      <?= date('d M Y g:i A', strtotime($d['paid_at'])) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($d['status'] !== 'cancelled' && $d['status'] !== 'paid'): ?>
                    <img class="qr-mini"
                      src="https://api.qrserver.com/v1/create-qr-code/?size=36x36&data=<?= urlencode(SITE_URL . '/user/pay.php?token=' . $d['qr_token']) ?>"
                      onclick="showQRModal('<?= $d['qr_token'] ?>', '<?= htmlspecialchars($d['full_name'], ENT_QUOTES) ?>', '<?= number_format($d['amount'],2) ?>', '<?= $type_labels[$d['payment_type']] ?>')"
                      title="View QR Code" alt="QR">
                  <?php elseif ($d['status'] === 'paid'): ?>
                    <span style="font-size:0.75rem; color:var(--accent2);">✅ Paid</span>
                  <?php else: ?>
                    <span style="font-size:0.75rem; color:var(--text-3);">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <?php if ($d['status'] === 'paid' && $d['receipt_number']): ?>
                      <a href="receipt.php?receipt=<?= urlencode($d['receipt_number']) ?>"
                         target="_blank" class="btn btn-success btn-sm">🖨️ Receipt</a>
                    <?php endif; ?>
                    <?php if ($d['status'] === 'unpaid' || $d['status'] === 'overdue'): ?>
                      <form method="POST" onsubmit="return confirm('Cancel this demand?')">
                        <input type="hidden" name="action" value="cancel_demand">
                        <input type="hidden" name="demand_id" value="<?= $d['id'] ?>">
                        <button class="btn btn-outline btn-sm" style="color:var(--accent3);">❌ Cancel</button>
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
    </div>
  </div>
</div>

<!-- ===================== CREATE DEMAND MODAL ===================== -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">
      <h3>💳 Create Payment Demand</h3>
      <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_demand">

      <div class="form-group">
        <label class="form-label">Select Resident *</label>
        <select name="user_id" class="form-select" required>
          <option value="">— Select Resident —</option>
          <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
            <option value="<?= $u['id'] ?>">
              <?= htmlspecialchars($u['full_name']) ?> — <?= $u['student_id'] ?> (Room <?= $u['room_number'] ?? 'N/A' ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Payment Type *</label>
          <select name="payment_type" class="form-select" required onchange="updateLabel(this.value)">
            <option value="">— Select Type —</option>
            <option value="room_rent">🏠 Room Rent</option>
            <option value="mess_fee">🍽️ Mess Fee</option>
            <option value="maintenance_fee">🔧 Maintenance Fee</option>
            <option value="security_deposit">🔒 Security Deposit</option>
            <option value="fine">⚠️ Fine</option>
            <option value="other">📋 Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Payment Label *</label>
          <input type="text" name="payment_label" id="paymentLabel" class="form-input"
            placeholder="e.g. Room Rent - May 2025" required>
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Amount (₹) *</label>
          <input type="number" name="amount" class="form-input" placeholder="e.g. 5000"
            min="1" step="0.01" required>
        </div>
        <div class="form-group">
          <label class="form-label">Due Date *</label>
          <input type="date" name="due_date" class="form-input" required
            min="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">For Month *</label>
          <select name="month" class="form-select" required>
            <?php
            $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            $curMonth = date('F');
            foreach ($months as $m):
            ?>
              <option value="<?= $m ?>" <?= $m===$curMonth?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Year *</label>
          <select name="year" class="form-select" required>
            <?php for ($y = date('Y'); $y <= date('Y')+1; $y++): ?>
              <option value="<?= $y ?>" <?= $y==date('Y')?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description / Notes</label>
        <textarea name="description" class="form-textarea" rows="2"
          placeholder="Any additional notes for the resident..."></textarea>
      </div>

      <div style="background:rgba(108,99,255,0.08); border:1px solid rgba(108,99,255,0.2); border-radius:10px; padding:14px; margin-bottom:16px; font-size:0.82rem; color:var(--text-2);">
        <strong style="color:var(--accent);">ℹ️ How it works:</strong><br>
        A secure QR code will be generated. The resident can scan or click it to pay.
        The exact amount you set will be charged — residents cannot modify it.
        Payment is marked instantly on scan.
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:14px;">
        🚀 Create Demand & Generate QR
      </button>
    </form>
  </div>
</div>

<!-- ===================== QR VIEW MODAL ===================== -->
<div class="modal-overlay" id="qrModal">
  <div class="modal" style="max-width:400px; text-align:center;">
    <div class="modal-header">
      <h3>📱 QR Payment Code</h3>
      <button class="modal-close" onclick="document.getElementById('qrModal').classList.remove('open')">✕</button>
    </div>
    <div id="qrModalBody">
      <div id="qrResidentName" style="font-weight:700; font-size:1.1rem; margin-bottom:4px;"></div>
      <div id="qrTypeName" style="color:var(--text-2); font-size:0.85rem; margin-bottom:12px;"></div>
      <div id="qrAmount" style="font-family:'Syne',sans-serif; font-weight:800; font-size:2rem; color:var(--accent2); margin-bottom:20px;"></div>
      <div style="background:white; border-radius:16px; padding:16px; display:inline-block; margin-bottom:16px;">
        <img id="qrCodeImg" src="" width="200" height="200" alt="QR Code">
      </div>
      <p style="font-size:0.8rem; color:var(--text-2); margin-bottom:16px;">
        Share this QR code with the resident. They can scan it to complete payment instantly.
      </p>
      <div id="qrLink" style="background:var(--bg-glass); border:1px solid var(--border); border-radius:8px; padding:10px; font-size:0.72rem; color:var(--text-3); word-break:break-all; margin-bottom:16px;"></div>
      <button class="btn btn-outline btn-sm" onclick="copyPayLink()">📋 Copy Payment Link</button>
    </div>
  </div>
</div>

<script>
function updateLabel(type) {
  const labels = {
    'room_rent': 'Room Rent - <?= date("F Y") ?>',
    'mess_fee': 'Mess Fee - <?= date("F Y") ?>',
    'maintenance_fee': 'Maintenance Fee - <?= date("F Y") ?>',
    'security_deposit': 'Security Deposit',
    'fine': 'Fine - <?= date("F Y") ?>',
    'other': ''
  };
  document.getElementById('paymentLabel').value = labels[type] || '';
}

let currentPayLink = '';

function showQRModal(token, name, amount, type) {
  const baseUrl = '<?= SITE_URL ?>/user/pay.php?token=' + token;
  currentPayLink = baseUrl;
  const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(baseUrl);

  document.getElementById('qrResidentName').textContent = name;
  document.getElementById('qrTypeName').textContent = type;
  document.getElementById('qrAmount').textContent = '₹' + amount;
  document.getElementById('qrCodeImg').src = qrUrl;
  document.getElementById('qrLink').textContent = baseUrl;
  document.getElementById('qrModal').classList.add('open');
}

function copyPayLink() {
  navigator.clipboard.writeText(currentPayLink).then(() => {
    alert('Payment link copied to clipboard!');
  });
}
</script>
</body>
</html>