<?php
require_once '../includes/config.php';
requireLogin('user');

$db = getDB();
$user_id = $_SESSION['user_id'];
$user = $db->query("SELECT u.*, r.room_number, r.room_type FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.id=$user_id")->fetch_assoc();

// Fetch all demands for this user
$demands = $db->query("SELECT pd.*,
    pt.transaction_ref, pt.receipt_number, pt.paid_at
    FROM payment_demands pd
    LEFT JOIN payment_transactions pt ON pd.id = pt.demand_id
    WHERE pd.user_id = $user_id
    ORDER BY pd.created_at DESC");

// Stats
$total_paid    = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE user_id=$user_id AND status='paid'")->fetch_assoc()['t'];
$total_pending = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE user_id=$user_id AND status IN ('unpaid','overdue')")->fetch_assoc()['t'];
$count_pending = $db->query("SELECT COUNT(*) as c FROM payment_demands WHERE user_id=$user_id AND status IN ('unpaid','overdue')")->fetch_assoc()['c'];
$count_paid    = $db->query("SELECT COUNT(*) as c FROM payment_demands WHERE user_id=$user_id AND status='paid'")->fetch_assoc()['c'];

$initials = strtoupper(substr($user['full_name'], 0, 1));

$type_labels = [
    'room_rent'        => '🏠 Room Rent',
    'mess_fee'         => '🍽️ Mess Fee',
    'maintenance_fee'  => '🔧 Maintenance Fee',
    'security_deposit' => '🔒 Security Deposit',
    'fine'             => '⚠️ Fine',
    'other'            => '📋 Other',
];

function statusBadgePay($s) {
    $map = ['unpaid'=>'badge-warning','paid'=>'badge-success','overdue'=>'badge-danger','cancelled'=>'badge-muted'];
    $ico = ['unpaid'=>'⏳','paid'=>'✅','overdue'=>'🔴','cancelled'=>'❌'];
    $cls = $map[$s] ?? 'badge-muted';
    $lbl = ($ico[$s] ?? '') . ' ' . ucfirst($s);
    return "<span class='badge $cls'>$lbl</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Payments - Residex</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .pay-card-item {
      background:var(--bg-card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:20px 24px;
      display:grid;
      grid-template-columns:auto 1fr auto auto;
      gap:16px;
      align-items:center;
      transition:transform 0.2s, box-shadow 0.2s;
      margin-bottom:12px;
    }
    .pay-card-item:hover { transform:translateY(-1px); box-shadow:0 4px 20px rgba(0,0,0,0.3); }

    .pay-type-icon {
      width:48px; height:48px;
      border-radius:12px;
      display:flex; align-items:center; justify-content:center;
      font-size:22px;
      flex-shrink:0;
    }
    .icon-rent     { background:rgba(108,99,255,0.15); }
    .icon-mess     { background:rgba(0,212,170,0.15); }
    .icon-maint    { background:rgba(255,209,102,0.15); }
    .icon-security { background:rgba(255,107,107,0.15); }
    .icon-fine     { background:rgba(255,107,107,0.2); }
    .icon-other    { background:rgba(255,255,255,0.06); }

    .pay-amount { font-family:'Syne',sans-serif; font-weight:800; font-size:1.4rem; letter-spacing:-0.02em; }
    .pay-amount.paid { color:var(--accent2); }
    .pay-amount.unpaid { color:var(--accent4); }
    .pay-amount.overdue { color:var(--accent3); }

    .qr-pay-btn {
      display:inline-flex; align-items:center; gap:8px;
      background:linear-gradient(135deg, var(--accent), #9f97ff);
      color:white; border:none; border-radius:10px;
      padding:10px 20px; font-size:0.875rem; font-weight:700;
      cursor:pointer; text-decoration:none;
      transition:all 0.2s;
    }
    .qr-pay-btn:hover { transform:translateY(-1px); box-shadow:0 4px 20px rgba(108,99,255,0.4); }
  </style>
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
      <div class="nav-section-label">Main</div>
      <a href="dashboard.php" class="nav-item"><span class="icon">📊</span> Dashboard</a>
      <a href="payments.php" class="nav-item active">
        <span class="icon">💳</span> Payments
        <?php if ($count_pending > 0): ?><span class="nav-badge"><?= $count_pending ?></span><?php endif; ?>
      </a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="profile.php" class="nav-item"><span class="icon">👤</span> My Profile</a>
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
        <h2>💳 My Payments</h2>
        <p>View and pay your hostel dues</p>
      </div>
    </div>

    <div class="page-body">

      <!-- Stats -->
      <div class="stats-grid" style="margin-bottom:28px;">
        <div class="stat-card teal fade-up">
          <div class="stat-icon">✅</div>
          <div class="stat-value" style="font-size:1.6rem;">₹<?= number_format($total_paid, 0) ?></div>
          <div class="stat-label">Total Paid</div>
          <div class="stat-trend trend-up"><span>↑ <?= $count_paid ?> payments</span></div>
        </div>
        <div class="stat-card yellow fade-up fade-up-1">
          <div class="stat-icon">⏳</div>
          <div class="stat-value" style="font-size:1.6rem;">₹<?= number_format($total_pending, 0) ?></div>
          <div class="stat-label">Amount Due</div>
          <div class="stat-trend"><span><?= $count_pending ?> pending demand<?= $count_pending != 1 ? 's' : '' ?></span></div>
        </div>
        <div class="stat-card purple fade-up fade-up-2">
          <div class="stat-icon">🏠</div>
          <div class="stat-value"><?= $user['room_number'] ?? '—' ?></div>
          <div class="stat-label">Room Number</div>
          <div class="stat-trend"><span><?= ucfirst($user['room_type'] ?? '—') ?> Room</span></div>
        </div>
        <div class="stat-card red fade-up fade-up-3">
          <div class="stat-icon">📋</div>
          <div class="stat-value"><?= $demands->num_rows ?></div>
          <div class="stat-label">Total Demands</div>
          <div class="stat-trend"><span>All time</span></div>
        </div>
      </div>

      <?php if ($count_pending > 0): ?>
      <!-- Pending Banner -->
      <div style="background:linear-gradient(135deg, rgba(255,209,102,0.1), rgba(255,107,107,0.08)); border:1px solid rgba(255,209,102,0.3); border-radius:var(--radius); padding:18px 24px; margin-bottom:24px; display:flex; align-items:center; gap:16px;" class="fade-up">
        <div style="font-size:28px;">⚠️</div>
        <div>
          <div style="font-weight:700; font-size:1rem;">You have <?= $count_pending ?> pending payment<?= $count_pending != 1 ? 's' : '' ?></div>
          <div style="color:var(--text-2); font-size:0.82rem; margin-top:3px;">Total due: ₹<?= number_format($total_pending, 2) ?>. Click "Pay Now" on any demand to pay via QR.</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Payment Demands List -->
      <div class="fade-up">
        <h3 style="font-size:1rem; font-weight:700; margin-bottom:16px; color:var(--text-2);">ALL PAYMENT DEMANDS</h3>

        <?php if ($demands->num_rows === 0): ?>
          <div class="card" style="text-align:center; padding:60px 40px;">
            <div style="font-size:48px; margin-bottom:12px;">💳</div>
            <h3 style="margin-bottom:8px;">No Payments Yet</h3>
            <p style="color:var(--text-2);">When admin raises a payment demand, it will appear here.</p>
          </div>
        <?php else:
          $demands->data_seek(0);
          while ($d = $demands->fetch_assoc()):
            $iconMap = [
              'room_rent'=>'🏠', 'mess_fee'=>'🍽️', 'maintenance_fee'=>'🔧',
              'security_deposit'=>'🔒', 'fine'=>'⚠️', 'other'=>'📋'
            ];
            $iconClass = [
              'room_rent'=>'icon-rent', 'mess_fee'=>'icon-mess', 'maintenance_fee'=>'icon-maint',
              'security_deposit'=>'icon-security', 'fine'=>'icon-fine', 'other'=>'icon-other'
            ];
            $icon  = $iconMap[$d['payment_type']] ?? '📋';
            $icls  = $iconClass[$d['payment_type']] ?? 'icon-other';
            $amtClass = $d['status'] === 'paid' ? 'paid' : ($d['status'] === 'overdue' ? 'overdue' : 'unpaid');
        ?>
        <div class="pay-card-item">
          <!-- Icon -->
          <div class="pay-type-icon <?= $icls ?>"><?= $icon ?></div>

          <!-- Info -->
          <div>
            <div style="font-weight:700; font-size:0.95rem; margin-bottom:3px;">
              <?= htmlspecialchars($d['payment_label']) ?>
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <span style="font-size:0.78rem; color:var(--text-2);"><?= $type_labels[$d['payment_type']] ?? $d['payment_type'] ?></span>
              <span style="color:var(--text-3); font-size:0.7rem;">·</span>
              <span style="font-size:0.78rem; color:var(--text-2);"><?= $d['month'] ?> <?= $d['year'] ?></span>
              <span style="color:var(--text-3); font-size:0.7rem;">·</span>
              <span style="font-size:0.78rem; color:<?= strtotime($d['due_date']) < time() && $d['status'] !== 'paid' ? 'var(--accent3)' : 'var(--text-2)' ?>;">
                Due: <?= date('d M Y', strtotime($d['due_date'])) ?>
              </span>
            </div>
            <?php if ($d['description']): ?>
              <div style="font-size:0.75rem; color:var(--text-3); margin-top:4px;"><?= htmlspecialchars(substr($d['description'],0,60)) ?></div>
            <?php endif; ?>
            <?php if ($d['paid_at']): ?>
              <div style="font-size:0.72rem; color:var(--accent2); margin-top:4px;">
                ✅ Paid on <?= date('d M Y, g:i A', strtotime($d['paid_at'])) ?>
                <?php if ($d['receipt_number']): ?>
                  · <a href="receipt.php?receipt=<?= urlencode($d['receipt_number']) ?>" target="_blank" style="color:var(--accent);">View Receipt</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Amount -->
          <div style="text-align:right;">
            <div class="pay-amount <?= $amtClass ?>">₹<?= number_format($d['amount'], 2) ?></div>
            <div style="margin-top:6px;"><?= statusBadgePay($d['status']) ?></div>
          </div>

          <!-- Action -->
          <div style="min-width:120px; text-align:right;">
            <?php if ($d['status'] === 'unpaid' || $d['status'] === 'overdue'): ?>
              <a href="pay.php?token=<?= urlencode($d['qr_token']) ?>" class="qr-pay-btn">
                ⚡ Pay Now
              </a>
            <?php elseif ($d['status'] === 'paid' && $d['receipt_number']): ?>
              <a href="receipt.php?receipt=<?= urlencode($d['receipt_number']) ?>"
                 target="_blank" class="btn btn-success btn-sm">
                🖨️ Receipt
              </a>
            <?php elseif ($d['status'] === 'cancelled'): ?>
              <span style="color:var(--text-3); font-size:0.8rem;">Cancelled</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; endif; ?>
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
