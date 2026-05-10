<?php
require_once '../includes/config.php';
requireLogin('admin');

$db       = getDB();
$admin_id = $_SESSION['admin_id'];
$success  = '';
$error    = '';

// ---- Success messages via PRG ----
$msg = sanitize($_GET['msg'] ?? '');
if ($msg === 'created')        $success = 'Payment demand created successfully!';
if ($msg === 'bulk_created')   $success = 'Bulk payment demands created successfully!';
if ($msg === 'cancelled')      $success = 'Payment demand cancelled.';
if ($msg === 'overdue')        $success = 'Overdue payments marked successfully.';

// ---- Helper: get users by filter ----
function getUsersByFilter($db, $filter_type, $filter_value) {
    $base = "SELECT u.id FROM users u LEFT JOIN rooms r ON u.room_id = r.id WHERE u.status='active'";
    switch ($filter_type) {
        case 'all':             return $db->query($base);
        case 'veg':             return $db->query($base . " AND u.diet_type='veg'");
        case 'vegan':           return $db->query($base . " AND u.diet_type='vegan'");
        case 'non_veg':         return $db->query($base . " AND u.diet_type='non_veg'");
        case 'non_veg_chicken': return $db->query($base . " AND u.diet_type='non_veg' AND (FIND_IN_SET('chicken',u.non_veg_preference) OR FIND_IN_SET('all',u.non_veg_preference))");
        case 'non_veg_mutton':  return $db->query($base . " AND u.diet_type='non_veg' AND (FIND_IN_SET('mutton',u.non_veg_preference) OR FIND_IN_SET('all',u.non_veg_preference))");
        case 'non_veg_fish':    return $db->query($base . " AND u.diet_type='non_veg' AND (FIND_IN_SET('fish',u.non_veg_preference) OR FIND_IN_SET('all',u.non_veg_preference))");
        case 'non_veg_egg':     return $db->query($base . " AND u.diet_type='non_veg' AND (FIND_IN_SET('egg',u.non_veg_preference) OR FIND_IN_SET('all',u.non_veg_preference))");
        case 'floor':
            $fv = intval($filter_value);
            return $db->query($base . " AND r.floor=$fv");
        case 'room_type_double': return $db->query($base . " AND r.room_type='double'");
        case 'room_type_triple': return $db->query($base . " AND r.room_type='triple'");
        case 'custom_ids':
            $ids = implode(',', array_map('intval', explode(',', $filter_value)));
            if (!$ids) return false;
            return $db->query($base . " AND u.id IN ($ids)");
        default: return false;
    }
}

// ---- POST Handler ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // SINGLE DEMAND
    if ($_POST['action'] === 'create_demand') {
        $user_id       = intval($_POST['user_id']);
        $payment_type  = sanitize($_POST['payment_type']);
        $payment_label = sanitize($_POST['payment_label']);
        $amount        = floatval($_POST['amount']);
        $due_date      = sanitize($_POST['due_date']);
        $month         = sanitize($_POST['month']);
        $year          = intval($_POST['year']);
        $description   = sanitize($_POST['description']);

        if (!$user_id || !$payment_type || !$amount || !$due_date || !$month || !$year) {
            $error = 'Please fill all required fields.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } else {
            $qr_token    = bin2hex(random_bytes(24));
            $secure_hash = hash('sha256', $qr_token . $user_id . $amount . $admin_id . time());
            $stmt = $db->prepare("INSERT INTO payment_demands
                (user_id, admin_id, payment_type, payment_label, amount, due_date, month, year, description, qr_token, secure_hash)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iissdssssss',
                $user_id, $admin_id, $payment_type, $payment_label,
                $amount, $due_date, $month, $year, $description, $qr_token, $secure_hash);
            if ($stmt->execute()) {
                header('Location: payments.php?msg=created');
                exit();
            } else {
                $error = 'Failed to create demand: ' . $db->error;
            }
        }
    }

    // BULK DEMAND
    if ($_POST['action'] === 'bulk_demand') {
        $filter_type   = sanitize($_POST['filter_type']);
        $filter_value  = sanitize($_POST['filter_value'] ?? '');
        $payment_type  = sanitize($_POST['payment_type']);
        $payment_label = sanitize($_POST['payment_label']);
        $amount        = floatval($_POST['amount']);
        $due_date      = sanitize($_POST['due_date']);
        $month         = sanitize($_POST['month']);
        $year          = intval($_POST['year']);
        $description   = sanitize($_POST['description']);

        if (!$filter_type || !$payment_type || !$amount || !$due_date || !$month || !$year) {
            $error = 'Please fill all required fields.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0.';
        } else {
            $users_result = getUsersByFilter($db, $filter_type, $filter_value);
            if (!$users_result || $users_result->num_rows === 0) {
                $error = 'No active residents found for the selected group.';
            } else {
                $batch_ref   = 'BATCH-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                $total       = $users_result->num_rows;
                $filter_desc = $filter_type . ($filter_value ? ':' . $filter_value : '');
                $bstmt = $db->prepare("INSERT INTO demand_batches
                    (batch_ref, admin_id, payment_type, payment_label, amount, due_date, month, year, description, total_demands, filter_used)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $bstmt->bind_param('siisdssssis',
                    $batch_ref, $admin_id, $payment_type, $payment_label,
                    $amount, $due_date, $month, $year, $description, $total, $filter_desc);
                $bstmt->execute();
                $batch_id = $db->insert_id;

                $created = 0;
                $skipped = 0;
                while ($u = $users_result->fetch_assoc()) {
                    $uid      = $u['id'];
                    $existing = $db->query("SELECT id FROM payment_demands
                        WHERE user_id=$uid AND payment_type='$payment_type'
                        AND month='$month' AND year=$year AND status != 'cancelled'");
                    if ($existing && $existing->num_rows > 0) { $skipped++; continue; }

                    $qr_token    = bin2hex(random_bytes(24));
                    $secure_hash = hash('sha256', $qr_token . $uid . $amount . $admin_id . time());
                    $istmt = $db->prepare("INSERT INTO payment_demands
                        (batch_id, user_id, admin_id, payment_type, payment_label, amount, due_date, month, year, description, qr_token, secure_hash)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $istmt->bind_param('iiissdssssss',
                        $batch_id, $uid, $admin_id, $payment_type, $payment_label,
                        $amount, $due_date, $month, $year, $description, $qr_token, $secure_hash);
                    if ($istmt->execute()) $created++;
                }
                $db->query("UPDATE demand_batches SET total_demands=$created WHERE id=$batch_id");
                header("Location: payments.php?msg=bulk_created&created=$created&skipped=$skipped");
                exit();
            }
        }
    }

    // CANCEL DEMAND
    if ($_POST['action'] === 'cancel_demand') {
        $did = intval($_POST['demand_id']);
        $db->query("UPDATE payment_demands SET status='cancelled' WHERE id=$did AND admin_id=$admin_id");
        header('Location: payments.php?msg=cancelled');
        exit();
    }

    // CANCEL BATCH
    if ($_POST['action'] === 'cancel_batch') {
        $bid = intval($_POST['batch_id']);
        $db->query("UPDATE payment_demands SET status='cancelled' WHERE batch_id=$bid AND status IN ('unpaid','overdue')");
        header('Location: payments.php?msg=cancelled');
        exit();
    }

    // MARK OVERDUE
    if ($_POST['action'] === 'mark_overdue') {
        $db->query("UPDATE payment_demands SET status='overdue' WHERE status='unpaid' AND due_date < CURDATE()");
        header('Location: payments.php?msg=overdue');
        exit();
    }
}

// Bulk creation result message
$created_count = intval($_GET['created'] ?? 0);
$skipped_count = intval($_GET['skipped'] ?? 0);
if ($msg === 'bulk_created' && $created_count > 0) {
    $success = "✅ Bulk demands created for $created_count residents." .
        ($skipped_count > 0 ? " ($skipped_count skipped — already had demand this month.)" : '');
}

// Fetch users
$users = $db->query("SELECT u.id, u.full_name, u.student_id, u.email, u.diet_type,
    u.non_veg_preference, r.room_number
    FROM users u LEFT JOIN rooms r ON u.room_id = r.id
    WHERE u.status='active' ORDER BY u.full_name");

// Filter & fetch demands
$filter_status = sanitize($_GET['status']  ?? 'all');
$filter_user   = intval($_GET['user_id']   ?? 0);
$filter_batch  = intval($_GET['batch_id']  ?? 0);
$view_mode     = sanitize($_GET['view']    ?? 'demands');

$where = "1=1";
if ($filter_status !== 'all') $where .= " AND pd.status='$filter_status'";
if ($filter_user)             $where .= " AND pd.user_id=$filter_user";
if ($filter_batch)            $where .= " AND pd.batch_id=$filter_batch";

$demands = $db->query("SELECT pd.*, u.full_name, u.student_id, r.room_number,
    pt.transaction_ref, pt.paid_at, pt.receipt_number
    FROM payment_demands pd
    JOIN users u ON pd.user_id = u.id
    LEFT JOIN rooms r ON u.room_id = r.id
    LEFT JOIN payment_transactions pt ON pd.id = pt.demand_id
    WHERE $where ORDER BY pd.created_at DESC");

// Fetch batches
$batches = $db->query("SELECT db2.*, a.full_name as admin_name,
    SUM(CASE WHEN pd.status='paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN pd.status IN ('unpaid','overdue') THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN pd.status='paid' THEN pd.amount ELSE 0 END) as collected
    FROM demand_batches db2
    JOIN admins a ON db2.admin_id = a.id
    LEFT JOIN payment_demands pd ON db2.id = pd.batch_id
    GROUP BY db2.id ORDER BY db2.created_at DESC");

// Stats
$total_demanded  = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status != 'cancelled'")->fetch_assoc()['t'];
$total_collected = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status='paid'")->fetch_assoc()['t'];
$total_pending   = $db->query("SELECT COALESCE(SUM(amount),0) as t FROM payment_demands WHERE status IN ('unpaid','overdue')")->fetch_assoc()['t'];
$total_overdue   = $db->query("SELECT COUNT(*) as c FROM payment_demands WHERE status='overdue' OR (status='unpaid' AND due_date < CURDATE())")->fetch_assoc()['c'];

$type_labels = [
    'room_rent'        => '🏠 Room Rent',
    'mess_fee'         => '🍽️ Mess Fee',
    'maintenance_fee'  => '🔧 Maintenance Fee',
    'security_deposit' => '🔒 Security Deposit',
    'fine'             => '⚠️ Fine',
    'other'            => '📋 Other',
];

$filter_labels = [
    'all'              => '👥 All Residents',
    'veg'              => '🥦 Veg Only',
    'vegan'            => '🌱 Vegan Only',
    'non_veg'          => '🍗 All Non-Veg',
    'non_veg_chicken'  => '🍗 Non-Veg: Chicken',
    'non_veg_mutton'   => '🍖 Non-Veg: Mutton',
    'non_veg_fish'     => '🐟 Non-Veg: Fish',
    'non_veg_egg'      => '🥚 Non-Veg: Egg',
    'floor'            => '🏢 By Floor',
    'room_type_double' => '🛏️ Double Rooms',
    'room_type_triple' => '🛏️ Triple Rooms',
    'custom_ids'       => '✏️ Custom Selection',
];

function statusBadgePay($s) {
    $map   = ['unpaid'=>'badge-warning','paid'=>'badge-success','overdue'=>'badge-danger','cancelled'=>'badge-muted'];
    $ico   = ['unpaid'=>'⏳','paid'=>'✅','overdue'=>'🔴','cancelled'=>'❌'];
    $cls   = $map[$s]  ?? 'badge-muted';
    $label = ($ico[$s] ?? '') . ' ' . ucfirst($s);
    return "<span class='badge $cls'>$label</span>";
}

// Group preview counts
$group_counts = [];
$gc_queries = [
    'all'             => "SELECT COUNT(*) as c FROM users WHERE status='active'",
    'veg'             => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='veg'",
    'vegan'           => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='vegan'",
    'non_veg'         => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='non_veg'",
    'non_veg_chicken' => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='non_veg' AND (FIND_IN_SET('chicken',non_veg_preference) OR FIND_IN_SET('all',non_veg_preference))",
    'non_veg_mutton'  => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='non_veg' AND (FIND_IN_SET('mutton',non_veg_preference) OR FIND_IN_SET('all',non_veg_preference))",
    'non_veg_fish'    => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='non_veg' AND (FIND_IN_SET('fish',non_veg_preference) OR FIND_IN_SET('all',non_veg_preference))",
    'non_veg_egg'     => "SELECT COUNT(*) as c FROM users WHERE status='active' AND diet_type='non_veg' AND (FIND_IN_SET('egg',non_veg_preference) OR FIND_IN_SET('all',non_veg_preference))",
];
foreach ($gc_queries as $key => $q) {
    $r = $db->query($q);
    $group_counts[$key] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}
$floors = $db->query("SELECT DISTINCT r.floor FROM rooms r JOIN users u ON u.room_id=r.id WHERE u.status='active' ORDER BY r.floor");
$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments - Residex Admin</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <style>
    .rupee { font-size:1rem;color:var(--text-2);margin-right:2px; }
    .pay-type-badge { display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:8px;font-size:0.75rem;font-weight:600;background:rgba(108,99,255,0.1);color:var(--accent);border:1px solid rgba(108,99,255,0.2); }
    .qr-mini { width:36px;height:36px;background:white;border-radius:6px;padding:3px;cursor:pointer;transition:transform 0.2s; }
    .qr-mini:hover { transform:scale(1.1); }
    .filter-bar { display:flex;gap:10px;align-items:center;flex-wrap:wrap; }
    .stat-highlight { font-size:0.72rem;color:var(--text-3);margin-top:4px; }
    .view-tabs { display:flex;gap:8px;margin-bottom:20px; }
    .group-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px; }
    .group-card { border:2px solid var(--border);border-radius:12px;padding:14px 12px;cursor:pointer;transition:all 0.2s;text-align:center;background:var(--bg-glass); }
    .group-card:hover { border-color:var(--accent);background:rgba(108,99,255,0.08); }
    .group-card.selected { border-color:var(--accent);background:rgba(108,99,255,0.15);box-shadow:0 0 0 3px rgba(108,99,255,0.15); }
    .group-card .g-icon { font-size:24px;margin-bottom:6px; }
    .group-card .g-name { font-size:0.78rem;font-weight:700;margin-bottom:3px; }
    .group-card .g-count { font-size:0.7rem;color:var(--text-3); }
    .group-card.selected .g-count { color:var(--accent); }
    .batch-bar { display:flex;align-items:center;gap:8px; }
    .batch-prog-bg { flex:1;height:6px;background:var(--bg-glass);border-radius:99px;overflow:hidden; }
    .batch-prog-fill { height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent2),#00ffcc); }
    .preview-box { background:rgba(0,212,170,0.08);border:1px solid rgba(0,212,170,0.25);border-radius:10px;padding:12px 16px;font-size:0.82rem;color:var(--accent2);display:flex;align-items:center;gap:10px;margin-top:8px; }
    .preview-box.warn { background:rgba(255,209,102,0.08);border-color:rgba(255,209,102,0.25);color:var(--accent4); }
    .user-check-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;max-height:220px;overflow-y:auto;padding:4px; }
    .user-check-item { display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:all 0.15s; }
    .user-check-item:hover { border-color:var(--accent);background:rgba(108,99,255,0.06); }
    .user-check-item input[type=checkbox] { accent-color:var(--accent);width:15px;height:15px;flex-shrink:0; }
    .user-check-item.checked { border-color:var(--accent);background:rgba(108,99,255,0.1); }
  </style>
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
      <a href="residents.php" class="nav-item"><span class="icon">👥</span> Residents</a>
      <a href="rooms.php" class="nav-item"><span class="icon">🏠</span> Rooms</a>
      <a href="complaints.php" class="nav-item"><span class="icon">🔧</span> Complaints</a>
      <a href="payments.php" class="nav-item active"><span class="icon">💳</span> Payments</a>
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
        <p>Single demands · Bulk by group · Track &amp; receipt</p>
      </div>
      <div class="topbar-actions">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="mark_overdue">
          <button class="btn btn-outline btn-sm">🔴 Mark Overdue</button>
        </form>
        <button class="btn btn-outline" onclick="document.getElementById('singleModal').classList.add('open')">
          👤 Single Demand
        </button>
        <button class="btn btn-primary" onclick="document.getElementById('bulkModal').classList.add('open')">
          👥 Bulk Demand
        </button>
      </div>
    </div>

    <div class="page-body">

      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Stats -->
      <div class="stats-grid" style="margin-bottom:28px;">
        <div class="stat-card teal fade-up">
          <div class="stat-icon">💰</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_collected,0) ?></div>
          <div class="stat-label">Total Collected</div>
          <div class="stat-highlight">All time payments received</div>
        </div>
        <div class="stat-card yellow fade-up fade-up-1">
          <div class="stat-icon">⏳</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_pending,0) ?></div>
          <div class="stat-label">Pending Amount</div>
          <div class="stat-highlight">Unpaid + overdue combined</div>
        </div>
        <div class="stat-card red fade-up fade-up-2">
          <div class="stat-icon">🔴</div>
          <div class="stat-value"><?= $total_overdue ?></div>
          <div class="stat-label">Overdue Demands</div>
          <div class="stat-highlight">Past due date</div>
        </div>
        <div class="stat-card purple fade-up fade-up-3">
          <div class="stat-icon">📋</div>
          <div class="stat-value"><span class="rupee">₹</span><?= number_format($total_demanded,0) ?></div>
          <div class="stat-label">Total Demanded</div>
          <div class="stat-highlight">Gross amount raised</div>
        </div>
      </div>

      <!-- View Tabs -->
      <div class="view-tabs">
        <a href="payments.php?view=demands" class="btn <?= $view_mode==='demands'?'btn-primary':'btn-outline' ?>">📋 Individual Demands</a>
        <a href="payments.php?view=batches" class="btn <?= $view_mode==='batches'?'btn-primary':'btn-outline' ?>">👥 Bulk Batches</a>
      </div>

      <?php if ($view_mode === 'batches'): ?>
      <!-- BATCHES VIEW -->
      <div class="card fade-up">
        <div class="card-header">
          <h3>👥 Bulk Payment Batches</h3>
          <span style="font-size:0.8rem;color:var(--text-2);"><?= $batches->num_rows ?> batches</span>
        </div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Batch Ref</th><th>Type</th><th>Amount</th><th>Month</th>
                <th>Group</th><th>Progress</th><th>Collected</th><th>Date</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($batches->num_rows === 0): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-3);">
                  No bulk batches yet. Click <strong>👥 Bulk Demand</strong> to create one.
                </td></tr>
              <?php else: while ($b = $batches->fetch_assoc()):
                $paid    = (int)$b['paid_count'];
                $total_d = (int)$b['total_demands'];
                $pct     = $total_d > 0 ? round(($paid/$total_d)*100) : 0;
                $fp      = explode(':', $b['filter_used'] ?? '');
                $fdisp   = $filter_labels[$fp[0] ?? ''] ?? $b['filter_used'];
              ?>
              <tr>
                <td><span style="font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:var(--accent);"><?= $b['batch_ref'] ?></span></td>
                <td><span class="pay-type-badge"><?= $type_labels[$b['payment_type']] ?? $b['payment_type'] ?></span></td>
                <td style="font-family:'Syne',sans-serif;font-weight:800;color:var(--accent2);">₹<?= number_format($b['amount'],2) ?></td>
                <td style="color:var(--text-2);font-size:0.82rem;"><?= $b['month'] ?> <?= $b['year'] ?></td>
                <td><span class="badge badge-info" style="font-size:0.7rem;"><?= htmlspecialchars($fdisp) ?></span></td>
                <td style="min-width:140px;">
                  <div class="batch-bar">
                    <div class="batch-prog-bg"><div class="batch-prog-fill" style="width:<?= $pct ?>%"></div></div>
                    <span style="font-size:0.75rem;color:var(--text-2);white-space:nowrap;"><?= $paid ?>/<?= $total_d ?></span>
                  </div>
                  <div style="font-size:0.68rem;color:var(--text-3);margin-top:3px;"><?= $pct ?>% paid</div>
                </td>
                <td style="color:var(--accent2);font-weight:700;">₹<?= number_format($b['collected'],2) ?></td>
                <td style="color:var(--text-3);font-size:0.75rem;"><?= date('d M Y', strtotime($b['created_at'])) ?></td>
                <td>
                  <div style="display:flex;gap:6px;">
                    <a href="payments.php?batch_id=<?= $b['id'] ?>&view=demands" class="btn btn-outline btn-sm">View</a>
                    <?php if ($b['pending_count'] > 0): ?>
                    <form method="POST" onsubmit="return confirm('Cancel all unpaid demands in this batch?')">
                      <input type="hidden" name="action" value="cancel_batch">
                      <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                      <button class="btn btn-outline btn-sm" style="color:var(--accent3);">❌</button>
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

      <?php else: ?>
      <!-- DEMANDS VIEW -->
      <div class="card fade-up" style="margin-bottom:20px;padding:16px 24px;">
        <div class="filter-bar">
          <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="view" value="demands">
            <select name="status" class="form-select" style="width:auto;">
              <option value="all"       <?= $filter_status==='all'       ?'selected':'' ?>>All Status</option>
              <option value="unpaid"    <?= $filter_status==='unpaid'    ?'selected':'' ?>>⏳ Unpaid</option>
              <option value="paid"      <?= $filter_status==='paid'      ?'selected':'' ?>>✅ Paid</option>
              <option value="overdue"   <?= $filter_status==='overdue'   ?'selected':'' ?>>🔴 Overdue</option>
              <option value="cancelled" <?= $filter_status==='cancelled' ?'selected':'' ?>>❌ Cancelled</option>
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
          <div style="margin-left:auto;font-size:0.8rem;color:var(--text-2);"><?= $demands->num_rows ?> records</div>
        </div>
        <?php if ($filter_batch): ?>
          <div class="preview-box" style="margin-top:12px;">
            📦 Showing batch demands only. <a href="payments.php" style="color:var(--accent);margin-left:8px;">Clear</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="card fade-up">
        <div class="card-header"><h3>📋 Payment Demands</h3></div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>#</th><th>Resident</th><th>Room</th><th>Type</th><th>Amount</th>
                <th>Month</th><th>Due Date</th><th>Status</th><th>QR</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($demands->num_rows === 0): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-3);">No demands found.</td></tr>
              <?php else: while ($d = $demands->fetch_assoc()): ?>
              <tr>
                <td style="color:var(--text-3);font-size:0.8rem;">
                  #<?= $d['id'] ?>
                  <?php if ($d['batch_id']): ?>
                    <div style="font-size:0.62rem;color:var(--accent);">Bulk</div>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($d['full_name']) ?></div>
                  <div style="font-size:0.72rem;color:var(--text-3);"><?= $d['student_id'] ?></div>
                </td>
                <td><span class="badge badge-muted">Room <?= $d['room_number']??'N/A' ?></span></td>
                <td>
                  <span class="pay-type-badge"><?= $type_labels[$d['payment_type']]??$d['payment_type'] ?></span>
                  <div style="font-size:0.7rem;color:var(--text-3);margin-top:3px;"><?= htmlspecialchars($d['payment_label']) ?></div>
                </td>
                <td style="font-family:'Syne',sans-serif;font-weight:800;color:var(--accent2);">₹<?= number_format($d['amount'],2) ?></td>
                <td style="color:var(--text-2);font-size:0.82rem;"><?= $d['month'] ?> <?= $d['year'] ?></td>
                <td style="color:var(--text-2);font-size:0.82rem;">
                  <?= date('d M Y', strtotime($d['due_date'])) ?>
                  <?php if ($d['status']==='unpaid' && strtotime($d['due_date'])<time()): ?>
                    <div style="color:var(--accent3);font-size:0.7rem;">⚠️ Overdue</div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= statusBadgePay($d['status']) ?>
                  <?php if ($d['paid_at']): ?>
                    <div style="font-size:0.68rem;color:var(--text-3);margin-top:2px;"><?= date('d M Y g:i A',strtotime($d['paid_at'])) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($d['status']!=='cancelled' && $d['status']!=='paid'): ?>
                    <img class="qr-mini"
                      src="https://api.qrserver.com/v1/create-qr-code/?size=36x36&data=<?= urlencode(SITE_URL.'/user/pay.php?token='.$d['qr_token']) ?>"
                      onclick="showQRModal('<?= $d['qr_token'] ?>','<?= htmlspecialchars($d['full_name'],ENT_QUOTES) ?>','<?= number_format($d['amount'],2) ?>','<?= addslashes($type_labels[$d['payment_type']]??'') ?>')"
                      title="View QR" alt="QR">
                  <?php elseif ($d['status']==='paid'): ?>
                    <span style="font-size:0.75rem;color:var(--accent2);">✅</span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <?php if ($d['status']==='paid' && $d['receipt_number']): ?>
                      <a href="receipt.php?receipt=<?= urlencode($d['receipt_number']) ?>" target="_blank" class="btn btn-success btn-sm">🖨️</a>
                    <?php endif; ?>
                    <?php if ($d['status']==='unpaid' || $d['status']==='overdue'): ?>
                      <form method="POST" onsubmit="return confirm('Cancel this demand?')">
                        <input type="hidden" name="action" value="cancel_demand">
                        <input type="hidden" name="demand_id" value="<?= $d['id'] ?>">
                        <button class="btn btn-outline btn-sm" style="color:var(--accent3);">❌</button>
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
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- SINGLE MODAL -->
<div class="modal-overlay" id="singleModal">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3>👤 Single Payment Demand</h3>
      <button class="modal-close" onclick="document.getElementById('singleModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_demand">
      <div class="form-group">
        <label class="form-label">Select Resident *</label>
        <select name="user_id" class="form-select" required>
          <option value="">— Select Resident —</option>
          <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> — <?= $u['student_id'] ?> (Room <?= $u['room_number']??'N/A' ?>)</option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Payment Type *</label>
          <select name="payment_type" class="form-select" required onchange="updateSingleLabel(this.value)">
            <option value="">— Select —</option>
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
          <input type="text" name="payment_label" id="singleLabel" class="form-input" placeholder="e.g. Room Rent - May 2025" required>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Amount (₹) *</label>
          <input type="number" name="amount" class="form-input" placeholder="e.g. 5000" min="1" step="0.01" required>
        </div>
        <div class="form-group">
          <label class="form-label">Due Date *</label>
          <input type="date" name="due_date" class="form-input" required min="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">For Month *</label>
          <select name="month" class="form-select" required>
            <?php $cm=date('F'); foreach($months as $m): ?>
              <option value="<?= $m ?>" <?= $m===$cm?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Year *</label>
          <select name="year" class="form-select" required>
            <?php for($y=date('Y');$y<=date('Y')+1;$y++): ?>
              <option value="<?= $y ?>" <?= $y==date('Y')?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-textarea" rows="2" placeholder="Optional notes..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;">🚀 Create Demand</button>
    </form>
  </div>
</div>

<!-- BULK MODAL -->
<div class="modal-overlay" id="bulkModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <h3>👥 Bulk Payment Demand</h3>
      <button class="modal-close" onclick="document.getElementById('bulkModal').classList.remove('open')">✕</button>
    </div>
    <form method="POST" id="bulkForm">
      <input type="hidden" name="action" value="bulk_demand">
      <input type="hidden" name="filter_type" id="bulkFilterType" value="">
      <input type="hidden" name="filter_value" id="bulkFilterValue" value="">

      <div class="form-group">
        <label class="form-label">Step 1 — Select Resident Group *</label>
        <div class="group-grid">
          <div class="group-card" onclick="selectGroup('all','',event)">
            <div class="g-icon">👥</div><div class="g-name">All Residents</div>
            <div class="g-count"><?= $group_counts['all'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('veg','',event)">
            <div class="g-icon">🥦</div><div class="g-name">Veg Only</div>
            <div class="g-count"><?= $group_counts['veg'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('vegan','',event)">
            <div class="g-icon">🌱</div><div class="g-name">Vegan Only</div>
            <div class="g-count"><?= $group_counts['vegan'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('non_veg','',event)">
            <div class="g-icon">🍗</div><div class="g-name">All Non-Veg</div>
            <div class="g-count"><?= $group_counts['non_veg'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('non_veg_chicken','',event)">
            <div class="g-icon">🍗</div><div class="g-name">Chicken</div>
            <div class="g-count"><?= $group_counts['non_veg_chicken'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('non_veg_mutton','',event)">
            <div class="g-icon">🍖</div><div class="g-name">Mutton</div>
            <div class="g-count"><?= $group_counts['non_veg_mutton'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('non_veg_fish','',event)">
            <div class="g-icon">🐟</div><div class="g-name">Fish</div>
            <div class="g-count"><?= $group_counts['non_veg_fish'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('non_veg_egg','',event)">
            <div class="g-icon">🥚</div><div class="g-name">Egg</div>
            <div class="g-count"><?= $group_counts['non_veg_egg'] ?> residents</div>
          </div>
          <div class="group-card" onclick="selectGroup('room_type_double','',event)">
            <div class="g-icon">🛏️</div><div class="g-name">Double Rooms</div>
            <div class="g-count">2-bed rooms</div>
          </div>
          <div class="group-card" onclick="selectGroup('room_type_triple','',event)">
            <div class="g-icon">🛏️</div><div class="g-name">Triple Rooms</div>
            <div class="g-count">3-bed rooms</div>
          </div>
          <?php if ($floors && $floors->num_rows > 0): $floors->data_seek(0); while ($fl = $floors->fetch_assoc()): ?>
          <div class="group-card" onclick="selectGroup('floor','<?= $fl['floor'] ?>',event)">
            <div class="g-icon">🏢</div><div class="g-name">Floor <?= $fl['floor'] ?></div>
            <div class="g-count">All rooms</div>
          </div>
          <?php endwhile; endif; ?>
          <div class="group-card" onclick="selectGroup('custom_ids','',event)">
            <div class="g-icon">✏️</div><div class="g-name">Custom Pick</div>
            <div class="g-count">Select manually</div>
          </div>
        </div>
        <div id="groupPreview" style="display:none;" class="preview-box">
          <span>👥</span><span id="groupPreviewText"></span>
        </div>
        <div id="groupNoneWarn" style="display:none;" class="preview-box warn">
          ⚠️ No residents found. Update diet preferences in Residents page first.
        </div>
      </div>

      <!-- Custom picker -->
      <div id="customUserSection" style="display:none;" class="form-group">
        <label class="form-label">Select Residents Manually</label>
        <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
          <button type="button" class="btn btn-outline btn-sm" onclick="selectAllUsers(true)">Select All</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="selectAllUsers(false)">Clear All</button>
          <input type="text" id="userSearch" class="form-input" style="max-width:180px;padding:6px 10px;" placeholder="Search..." oninput="filterUsers(this.value)">
        </div>
        <div class="user-check-grid" id="userCheckGrid">
          <?php $users->data_seek(0); while ($u = $users->fetch_assoc()): ?>
            <label class="user-check-item" id="uci_<?= $u['id'] ?>">
              <input type="checkbox" class="user-custom-cb" value="<?= $u['id'] ?>" onchange="updateCustomIds()">
              <div>
                <div style="font-size:0.82rem;font-weight:600;"><?= htmlspecialchars($u['full_name']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-3);"><?= $u['student_id'] ?> · Room <?= $u['room_number']??'N/A' ?>
                  <?php if ($u['diet_type']!=='any'): ?> · <?= $u['diet_type']==='veg'?'🥦':($u['diet_type']==='non_veg'?'🍗':'🌱') ?><?php endif; ?>
                </div>
              </div>
            </label>
          <?php endwhile; ?>
        </div>
        <div id="customCountPreview" style="margin-top:8px;font-size:0.8rem;color:var(--text-2);"></div>
      </div>

      <div class="divider"></div>
      <label class="form-label" style="display:block;margin-bottom:12px;">Step 2 — Payment Details</label>

      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Payment Type *</label>
          <select name="payment_type" class="form-select" required onchange="updateBulkLabel(this.value)">
            <option value="">— Select —</option>
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
          <input type="text" name="payment_label" id="bulkLabel" class="form-input" placeholder="e.g. Mess Fee - May 2025" required>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Amount (₹) *</label>
          <input type="number" name="amount" class="form-input" placeholder="Same for all" min="1" step="0.01" required>
        </div>
        <div class="form-group">
          <label class="form-label">Due Date *</label>
          <input type="date" name="due_date" class="form-input" required min="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">For Month *</label>
          <select name="month" class="form-select" required>
            <?php $cm=date('F'); foreach($months as $m): ?>
              <option value="<?= $m ?>" <?= $m===$cm?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Year *</label>
          <select name="year" class="form-select" required>
            <?php for($y=date('Y');$y<=date('Y')+1;$y++): ?>
              <option value="<?= $y ?>" <?= $y==date('Y')?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-textarea" rows="2" placeholder="Optional note for all residents..."></textarea>
      </div>
      <div style="background:rgba(108,99,255,0.08);border:1px solid rgba(108,99,255,0.2);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:0.82rem;color:var(--text-2);">
        <strong style="color:var(--accent);">ℹ️</strong> One QR per resident is generated.
        Residents who already have a demand for the same type &amp; month are automatically skipped.
      </div>
      <button type="button" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;" onclick="submitBulk()">
        🚀 Send Bulk Demands
      </button>
    </form>
  </div>
</div>

<!-- QR MODAL -->
<div class="modal-overlay" id="qrModal">
  <div class="modal" style="max-width:400px;text-align:center;">
    <div class="modal-header">
      <h3>📱 QR Payment Code</h3>
      <button class="modal-close" onclick="document.getElementById('qrModal').classList.remove('open')">✕</button>
    </div>
    <div id="qrResidentName" style="font-weight:700;font-size:1.1rem;margin-bottom:4px;"></div>
    <div id="qrTypeName" style="color:var(--text-2);font-size:0.85rem;margin-bottom:12px;"></div>
    <div id="qrAmount" style="font-family:'Syne',sans-serif;font-weight:800;font-size:2rem;color:var(--accent2);margin-bottom:20px;"></div>
    <div style="background:white;border-radius:16px;padding:16px;display:inline-block;margin-bottom:16px;">
      <img id="qrCodeImg" src="" width="200" height="200" alt="QR">
    </div>
    <div id="qrLink" style="background:var(--bg-glass);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:0.72rem;color:var(--text-3);word-break:break-all;margin-bottom:16px;"></div>
    <button class="btn btn-outline btn-sm" onclick="copyPayLink()">📋 Copy Link</button>
  </div>
</div>

<script>
const groupCounts = <?= json_encode($group_counts) ?>;
let selectedFilterType = '';
let selectedFilterValue = '';

function selectGroup(type, value, e) {
  selectedFilterType  = type;
  selectedFilterValue = value;
  document.getElementById('bulkFilterType').value  = type;
  document.getElementById('bulkFilterValue').value = value;
  document.querySelectorAll('.group-card').forEach(c => c.classList.remove('selected'));
  e.currentTarget.classList.add('selected');
  document.getElementById('customUserSection').style.display = (type === 'custom_ids') ? 'block' : 'none';
  if (type === 'custom_ids') {
    document.getElementById('groupPreview').style.display = 'none';
    document.getElementById('groupNoneWarn').style.display = 'none';
    updateCustomIds(); return;
  }
  const count   = groupCounts[type] !== undefined ? groupCounts[type] : '?';
  const preview = document.getElementById('groupPreview');
  const warn    = document.getElementById('groupNoneWarn');
  if (count === 0) {
    preview.style.display = 'none'; warn.style.display = 'flex';
  } else {
    warn.style.display = 'none'; preview.style.display = 'flex';
    document.getElementById('groupPreviewText').textContent =
      count + ' resident' + (count !== 1 ? 's' : '') + ' will receive this demand.';
  }
}

function updateCustomIds() {
  const checked = Array.from(document.querySelectorAll('.user-custom-cb:checked')).map(c => c.value);
  document.getElementById('bulkFilterValue').value = checked.join(',');
  document.getElementById('bulkFilterType').value  = 'custom_ids';
  const preview = document.getElementById('groupPreview');
  const countEl = document.getElementById('customCountPreview');
  if (checked.length > 0) {
    preview.style.display = 'flex';
    document.getElementById('groupPreviewText').textContent = checked.length + ' resident' + (checked.length!==1?'s':'') + ' selected.';
    countEl.textContent = checked.length + ' selected';
  } else {
    preview.style.display = 'none'; countEl.textContent = 'None selected';
  }
  document.querySelectorAll('.user-check-item').forEach(el => {
    const cb = el.querySelector('input[type=checkbox]');
    el.classList.toggle('checked', cb && cb.checked);
  });
}

function selectAllUsers(state) {
  document.querySelectorAll('.user-custom-cb').forEach(cb => cb.checked = state);
  updateCustomIds();
}

function filterUsers(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.user-check-item').forEach(el => {
    el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function submitBulk() {
  if (!selectedFilterType) { alert('Please select a resident group first.'); return; }
  if (selectedFilterType === 'custom_ids' && !document.getElementById('bulkFilterValue').value) {
    alert('Please select at least one resident.'); return;
  }
  document.getElementById('bulkForm').submit();
}

function updateSingleLabel(type) {
  const labels = { 'room_rent':'Room Rent - <?= date("F Y") ?>','mess_fee':'Mess Fee - <?= date("F Y") ?>',
    'maintenance_fee':'Maintenance Fee - <?= date("F Y") ?>','security_deposit':'Security Deposit',
    'fine':'Fine - <?= date("F Y") ?>','other':'' };
  document.getElementById('singleLabel').value = labels[type] || '';
}

function updateBulkLabel(type) {
  const labels = { 'room_rent':'Room Rent - <?= date("F Y") ?>','mess_fee':'Mess Fee - <?= date("F Y") ?>',
    'maintenance_fee':'Maintenance Fee - <?= date("F Y") ?>','security_deposit':'Security Deposit',
    'fine':'Fine - <?= date("F Y") ?>','other':'' };
  document.getElementById('bulkLabel').value = labels[type] || '';
}

let currentPayLink = '';
function showQRModal(token, name, amount, type) {
  const baseUrl = '<?= SITE_URL ?>/user/pay.php?token=' + token;
  currentPayLink = baseUrl;
  document.getElementById('qrResidentName').textContent = name;
  document.getElementById('qrTypeName').textContent     = type;
  document.getElementById('qrAmount').textContent       = '₹' + amount;
  document.getElementById('qrCodeImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(baseUrl);
  document.getElementById('qrLink').textContent = baseUrl;
  document.getElementById('qrModal').classList.add('open');
}

function copyPayLink() {
  navigator.clipboard.writeText(currentPayLink).then(() => alert('Payment link copied!'));
}
</script>

<!-- Footer -->
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