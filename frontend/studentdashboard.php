<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}
include '../backend/config/db.php';

$username = $_SESSION['username'];
$role     = $_SESSION['role'] ?? 'USER';

// helper for escaping
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// display name (trim just to be safe)
$displayName = trim($username);

// fetch user id
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    header("Location: login.html");
    exit();
}
$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();
$userRow = $res->fetch_assoc();
$stmt->close();

if (!$userRow) {
    session_unset();
    session_destroy();
    header("Location: login.html");
    exit();
}

$user_id = (int)$userRow['id'];

// admin statuses
$admin_statuses = ['assigned','in_progress','action_taken','resolved'];

// counts
$sqlCounts = "
    SELECT 
      COUNT(*) AS total,
      SUM(CASE WHEN LOWER(status) IN ('assigned','in_progress','action_taken','resolved') THEN 0 ELSE 1 END) AS pending,
      SUM(LOWER(status) = 'in_progress') AS inprogress,
      SUM(LOWER(status) = 'resolved') AS resolved
    FROM complaints
    WHERE user_id = ?
";
$stmt2 = $conn->prepare($sqlCounts);
if (!$stmt2) {
    error_log("Prepare failed (counts): " . $conn->error);
    $total = $pending = $inProgress = $resolved = 0;
} else {
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $statsRes = $stmt2->get_result();
    $stats = $statsRes ? $statsRes->fetch_assoc() : null;
    $stmt2->close();

    $total      = (int)($stats['total'] ?? 0);
    $pending    = (int)($stats['pending'] ?? 0);
    $inProgress = (int)($stats['inprogress'] ?? 0);
    $resolved   = (int)($stats['resolved'] ?? 0);
}

// recent complaints
$recentComplaints = [];
$stmt3 = $conn->prepare("
    SELECT id, title, category, status, created_at
    FROM complaints
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 3
");
if ($stmt3) {
    $stmt3->bind_param("i", $user_id);
    $stmt3->execute();
    $recentRes = $stmt3->get_result();
    if ($recentRes) {
        $recentComplaints = $recentRes->fetch_all(MYSQLI_ASSOC);
    }
    $stmt3->close();
}

// normalize status
function normalize_status_for_display($status) {
    $s = strtolower(trim((string)$status));
    $admin = ['assigned','in_progress','action_taken','resolved'];
    if ($s === '' || !in_array($s, $admin, true)) {
        return 'pending';
    }
    return $s;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    body {
        background: url("assets/images/dash-bg.png") no-repeat center center fixed;
        background-size: cover;
    }

    .row { padding:10px 0; border-bottom:1px solid #efefef; display:flex; gap:10px; align-items:center; }
    .row-link { display:flex; gap:10px; align-items:center; width:100%; text-decoration:none; color:inherit; }
    .row-title { flex:1; font-weight:600; }
    .row-title a { color:inherit; text-decoration:none; display:block; width:100%; padding:6px 0; }
    .row-status { padding:6px 10px; border-radius:8px; font-size:0.95rem; font-weight:800; }
    .row-status.pending { background:#fff0f0; color:#b91c1c; border:1px solid #ffdede; }
    .row-status.in_progress { background:#fff7ed; color:#92400e; border:1px solid #ffedd5; }
    .row-status.resolved { background:#ecfdf5; color:#065f46; border:1px solid #d9f2e6; }
    .row-status.assigned { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
    .row-status.action_taken { background:#fff7ed; color:#92400e; border:1px solid #ffedd5; }
    .row-date { white-space:nowrap; color:#666; font-size:0.9rem; }
    .empty-note { padding:16px; color:#666; text-align:center; }
    .view-all { margin-top:12px; }
    .view-all a { text-decoration:none; color:#0d6efd; font-weight:600; }
    .stat { display:inline-block; width:22%; padding:14px; background:#fff; border-radius:10px; text-align:center; margin-right:1%; box-shadow: 0 1px 4px rgba(0,0,0,0.03); border:2px solid #000; }
    .stat .stat-title { font-weight:800; color:#222; }
    .stat-num { font-size:1.8rem; font-weight:700; margin-top:8px; color:#111; }
    @media (max-width:800px){
      .stat { width:48%; margin-bottom:8px; }
      .stats-row { display:flex; flex-wrap:wrap; gap:8px; }
    }
  </style>
</head>
<body class="role-<?php echo strtolower(h($role)); ?>">

  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- LEFT SIDEBAR -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar">
              <img src="assets/images/user-icon.png" alt="User Avatar">
            </div>
            <div class="side-name">
              <a href="studentdashboard.php" style="color:inherit;text-decoration:none;"><?php echo h($displayName); ?></a>
            </div>
            <div class="side-role">STUDENT</div>
          </div>

          <nav class="side-nav" aria-label="Main navigation">
            <a href="register.php" class="side-link">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- MAIN DASHBOARD -->
        <main class="dash-main">
          <header class="main-head">
            <!-- yahan comma hata diya -->
            <h1>Welcome back <span class="hl"><?php echo h($displayName); ?></span>!</h1>
            <a href="register.php" class="cta">REGISTER A COMPLAINT</a>
          </header>

          <!-- STATS -->
          <section class="stats-row" style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
            <article class="stat" aria-label="Total complaints">
              <div class="stat-title">Total Complaints</div>
              <div class="stat-num"><?php echo $total; ?></div>
            </article>
            <article class="stat" aria-label="Pending complaints">
              <div class="stat-title">Pending</div>
              <div class="stat-num"><?php echo $pending; ?></div>
            </article>
            <article class="stat" aria-label="In progress complaints">
              <div class="stat-title">In Progress</div>
              <div class="stat-num"><?php echo $inProgress; ?></div>
            </article>
            <article class="stat" aria-label="Resolved complaints">
              <div class="stat-title">Resolved</div>
              <div class="stat-num"><?php echo $resolved; ?></div>
            </article>
          </section>

          <!-- RECENT ACTIVITY -->
          <section class="activity" id="recent-activity">
            <h3>Recent Activity &amp; Updates</h3>

            <?php if (!empty($recentComplaints)): ?>
              <?php foreach ($recentComplaints as $r): ?>
                <?php
                  $rawStatus = $r['status'] ?? '';
                  $displayStatus = normalize_status_for_display($rawStatus);
                  $statusClass = h(str_replace(' ', '_', strtolower($displayStatus)));
                  $statusLabel = h(ucfirst(str_replace('_', ' ', $displayStatus)));
                  $createdAt = date('M d, Y', strtotime($r['created_at']));
                  $detailUrl = "student-complaint-details.php?id=" . (int)$r['id'];
                ?>
                <div class="row" role="listitem">
                  <a class="row-link" href="<?php echo h($detailUrl); ?>">
                    <div class="row-title">
                      <span><?php echo h($r['title']); ?></span>
                      <div style="color:#666; font-size:0.9rem; margin-top:4px;"><?php echo h($r['category'] ?? ''); ?></div>
                    </div>

                    <div class="row-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></div>
                    <div class="row-date"><?php echo h($createdAt); ?></div>
                  </a>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-note">No recent activity yet.</div>
            <?php endif; ?>

            <div class="view-all">
              <a href="student-all-complaints.php">View All Complaints &amp; Updates →</a>
            </div>
          </section>
        </main>
      </div>
    </div>
  </div>
</body>
</html>
