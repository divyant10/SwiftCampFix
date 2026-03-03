<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}
include '../backend/config/db.php';

$username = $_SESSION['username'];
$role     = $_SESSION['role'] ?? 'USER';

// helper escape
function h($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Normalize display status: if not admin_statuses -> pending
function normalize_status_for_display($status) {
    $s = strtolower(trim((string)$status));
    $admin = ['assigned','in_progress','action_taken','resolved'];
    if ($s === '' || !in_array($s, $admin, true)) {
        return 'pending';
    }
    return $s;
}

// get user id safely
$user_id = null;
$stmtUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param("s", $username);
    $stmtUser->execute();
    $res = $stmtUser->get_result();
    $userRow = $res ? $res->fetch_assoc() : null;
    $stmtUser->close();
    if (!$userRow) {
        session_unset();
        session_destroy();
        header("Location: login.html");
        exit();
    }
    $user_id = (int)$userRow['id'];
} else {
    error_log("Prepare failed (fetch user): " . $conn->error);
    session_unset();
    session_destroy();
    header("Location: login.html");
    exit();
}

// --- Filters / Search / Pagination ---
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

// ADMIN statuses that indicate admin action
$admin_statuses = ['assigned','in_progress','action_taken','resolved'];

// counts for stats and chips (safe)
// Compute pending as anything NOT in admin_statuses (this covers NULL/''/'new'/etc)
$countsSql = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN LOWER(status) IN ('assigned','in_progress','action_taken','resolved') THEN 0 ELSE 1 END) AS pending,
    SUM(LOWER(status) = 'in_progress') AS in_progress,
    SUM(LOWER(status) = 'assigned') AS assigned,
    SUM(LOWER(status) = 'action_taken') AS action_taken,
    SUM(LOWER(status) = 'resolved') AS resolved
  FROM complaints WHERE user_id = ?";
$cstmt = $conn->prepare($countsSql);
$total = $pending = $inProgress = $resolved = 0;
$assigned = $action_taken = 0;
if ($cstmt) {
    $cstmt->bind_param("i", $user_id);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    $counts = $cres ? $cres->fetch_assoc() : null;
    if ($counts) {
        $total = (int)($counts['total'] ?? 0);
        $pending = (int)($counts['pending'] ?? 0);
        $inProgress = (int)($counts['in_progress'] ?? 0);
        $assigned = (int)($counts['assigned'] ?? 0);
        $action_taken = (int)($counts['action_taken'] ?? 0);
        $resolved = (int)($counts['resolved'] ?? 0);
    }
    $cstmt->close();
} else {
    error_log("Prepare failed (counts): " . $conn->error);
}

// status counts used by chips (we'll populate using the computed values above)
$statusCounts = [
  'all' => $total,
  'pending' => $pending,
  'assigned' => $assigned,
  'in_progress' => $inProgress,
  'action_taken' => $action_taken,
  'resolved' => $resolved
];

// Build WHERE clause and params
$where = " WHERE user_id = ? ";
$params = [$user_id];
$types = "i";

if ($statusFilter !== '') {
    // If user asked to filter for 'pending', use the same definition: status NOT IN admin_statuses OR NULL/empty
    if (strtolower($statusFilter) === 'pending') {
        $where .= " AND (LOWER(status) NOT IN ('assigned','in_progress','action_taken','resolved') OR status IS NULL OR status = '') ";
        // no extra bind param needed
    } else {
        // for other statuses, match by lowercase value
        $where .= " AND LOWER(status) = ? ";
        $params[] = strtolower($statusFilter);
        $types .= "s";
    }
}
if ($search !== '') {
    $where .= " AND (title LIKE ? OR description LIKE ?) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

// total rows for pagination
$sqlCount = "SELECT COUNT(*) AS cnt FROM complaints {$where}";
$stmtCount = $conn->prepare($sqlCount);
$totalRows = 0;
if ($stmtCount) {
    // bind dynamically; ensure number of types matches params
    $bindParams = [];
    if ($types !== '') {
        $bindParams[] = &$types;
        for ($i = 0; $i < count($params); $i++) {
            $bindParams[] = &$params[$i];
        }
        call_user_func_array([$stmtCount, 'bind_param'], $bindParams);
    }
    $stmtCount->execute();
    $countRes = $stmtCount->get_result();
    $totalRows = (int)($countRes ? $countRes->fetch_assoc()['cnt'] : 0);
    $stmtCount->close();
}
$totalPages = max(1, ceil(max(0,$totalRows) / $perPage));

// fetch complaints for the grid
$sql = "SELECT id, title, status, category, created_at, attachment FROM complaints {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params_with_limit = $params;
$types_with_limit = $types . "ii";
$params_with_limit[] = $perPage;
$params_with_limit[] = $offset;

$complaints = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    // bind dynamically for fetch query
    $bindParams = [];
    $bindParams[] = &$types_with_limit;
    for ($i = 0; $i < count($params_with_limit); $i++) {
        $bindParams[] = &$params_with_limit[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    $complaints = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
} else {
    error_log("Prepare failed (fetch complaints): " . $conn->error);
}

// recent 5 complaints for Recent Activity block
$recentComplaints = [];
$recentSql = "SELECT id, title, category, status, created_at FROM complaints WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
$rstmt = $conn->prepare($recentSql);
if ($rstmt) {
    $rstmt->bind_param("i", $user_id);
    $rstmt->execute();
    $recentComplaints = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();
}

// helper to build url while preserving params
function build_query($overrides = []) {
    $q = array_merge($_GET, $overrides);
    return http_build_query($q);
}
function status_label($s) { return ucfirst(str_replace('_',' ', $s)); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>All Complaints — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/student-all-complaints.css">

  <style>
    /* background */
    body { background: url("assets/images/dash-bg.png") no-repeat center center fixed; background-size: cover; min-height:100vh; margin:0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .dash-main { background: rgba(255,255,255,0.95); border-radius:10px; padding:18px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
    .header-row { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    .stat { display:inline-block; width:22%; padding:12px; background: #fff; border-radius:8px; text-align:center; border:1px solid rgba(0,0,0,0.06); box-shadow:0 2px 8px rgba(0,0,0,0.03); }

    /* ---- STATUS BOXES (chips) ---- */
    .status-boxes { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .status-box {
      display:inline-flex;
      min-width:120px;
      padding:12px 14px;
      border-radius:8px;
      justify-content:space-between;
      align-items:center;
      background:#fff;
      box-shadow: 0 2px 6px rgba(0,0,0,0.03);
      border:3px solid #0f9d58; /* default green */
      font-weight:800;
      color:#111;
    }
    .status-box .label { margin-right:10px; font-weight:700; }
    .status-box .count { background:transparent; font-weight:900; }

    /* red & green variants */
    .status-box.red { border-color: #ff3b3b; }
    .status-box.green { border-color: #0f9d58; }

    /* small responsive */
    @media (max-width:800px) {
      .status-box { min-width: 110px; padding:10px; }
    }

    .search input[type="search"]{ padding:8px 10px; width:220px; border-radius:6px; border:1px solid #ccc; }
    .complaint-grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:14px; margin-top:12px; }
    .complaint-card{ display:block; text-decoration:none; color:inherit; background:#fff; padding:12px; border-radius:8px; border:1px solid rgba(0,0,0,0.06); box-shadow:0 2px 6px rgba(0,0,0,0.03); }
    .card-title { font-weight:700; margin-bottom:8px; }
    .card-meta { display:flex; justify-content:space-between; align-items:center; gap:8px; font-size:0.9rem; color:#666; }
    .status-pill { padding:6px 8px; border-radius:999px; background:#f3f4f6; font-weight:700; font-size:0.85rem; color:#111; }
    .pager a { margin:0 8px; text-decoration:none; color:#0d6efd; }
    .empty { padding:18px; color:#666; background: rgba(255,255,255,0.7); border-radius:8px; text-align:center; }
  </style>
</head>
<body class="role-<?php echo strtolower(h($role)); ?>">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar"><img src="assets/images/user-icon.png" alt="" style="width:64px;height:64px;border-radius:8px;"></div>

            <!-- username clickable -->
            <div class="side-name">
              <a href="studentdashboard.php" style="color:inherit;text-decoration:none;"><?php echo h($username); ?></a>
            </div>

            <!-- role shown as STUDENT -->
            <div class="side-role"><?php echo 'STUDENT'; ?></div>
          </div>
          <nav class="side-nav">
            <a href="register.php" class="side-link">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link active">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <main class="dash-main" style="padding:20px;">
          <div class="header-row">
            <h1 class="all-title" style="margin:0;">Your Complaints <span style="color:#666; font-weight:700; font-size:16px; margin-left:8px;">(<?php echo $totalRows; ?>)</span></h1>
            <div><a href="register.php" class="cta" style="text-decoration:none;padding:10px 12px;background:#0d6efd;color:#fff;border-radius:8px;font-weight:700;">Register New Complaint</a></div>
          </div>

          <!-- status boxes row (Option A: single horizontal row) -->
          <div class="status-boxes" role="list" aria-label="Status summary">
            <!-- All (green) -->
            <a href="?<?php echo build_query(['status'=>'']); ?>" class="status-box green" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">All</span>
              <span class="count"><?php echo $statusCounts['all']; ?></span>
            </a>

            <!-- Pending (red) -->
            <a href="?<?php echo build_query(['status'=>'pending']); ?>" class="status-box red" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">Pending</span>
              <span class="count"><?php echo $statusCounts['pending']; ?></span>
            </a>

            <!-- Assigned (red) -->
            <a href="?<?php echo build_query(['status'=>'assigned']); ?>" class="status-box red" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">Assigned</span>
              <span class="count"><?php echo $statusCounts['assigned']; ?></span>
            </a>

            <!-- In Progress (green) -->
            <a href="?<?php echo build_query(['status'=>'in_progress']); ?>" class="status-box green" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">In Progress</span>
              <span class="count"><?php echo $statusCounts['in_progress']; ?></span>
            </a>

            <!-- Action Taken (green) -->
            <a href="?<?php echo build_query(['status'=>'action_taken']); ?>" class="status-box green" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">Action Taken</span>
              <span class="count"><?php echo $statusCounts['action_taken']; ?></span>
            </a>

            <!-- Resolved (green) -->
            <a href="?<?php echo build_query(['status'=>'resolved']); ?>" class="status-box green" role="listitem" style="text-decoration:none;color:inherit;">
              <span class="label">Resolved</span>
              <span class="count"><?php echo $statusCounts['resolved']; ?></span>
            </a>
          </div>

          <section class="stats-row" style="display:flex; gap:10px; margin:12px 0 18px 0;">
            <article class="stat" aria-label="Total"><div class="stat-title">Total</div><div class="stat-num"><?php echo $total; ?></div></article>
            <article class="stat" aria-label="Pending"><div class="stat-title">Pending</div><div class="stat-num"><?php echo $pending; ?></div></article>
            <article class="stat" aria-label="In Progress"><div class="stat-title">In Progress</div><div class="stat-num"><?php echo $inProgress; ?></div></article>
            <article class="stat" aria-label="Resolved"><div class="stat-title">Resolved</div><div class="stat-num"><?php echo $resolved; ?></div></article>
          </section>

          <!-- toolbar: search + chips (hidden original chips replaced by status boxes above) -->
          <div style="margin-top:6px; margin-bottom:12px;">
            <form method="get" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
              <div class="search" style="min-width:220px;">
                <input name="q" type="search" value="<?php echo h($search); ?>" placeholder="Search title or description..." aria-label="Search complaints">
                <button type="submit" class="search-btn" title="Search">🔍</button>
              </div>
            </form>
          </div>

          <!-- Grid of complaint cards -->
          <div class="list-wrap">
            <?php if (empty($complaints)): ?>
              <div class="empty">No complaints found.</div>
            <?php else: ?>
              <div class="complaint-grid" role="list" aria-label="Complaints grid">
                <?php foreach ($complaints as $c):
                  // normalize status for display: anything not admin_statuses -> 'pending'
                  $displayStatus = normalize_status_for_display($c['status'] ?? '');
                  $statusC = strtolower($displayStatus);
                  $detailUrl = "student-complaint-details.php?id=" . (int)$c['id'];
                ?>
                  <a class="complaint-card" role="listitem" href="<?php echo h($detailUrl); ?>">
                    <div class="card-category"><?php echo h($c['category'] ?? 'General'); ?></div>
                    <div class="card-title"><?php echo h($c['title']); ?></div>
                    <div class="card-meta">
                      <div class="status-pill <?php echo h($statusC); ?>"><?php echo h(status_label($displayStatus)); ?></div>
                      <div class="date"><?php echo h(date('d M, Y', strtotime($c['created_at']))); ?></div>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Pagination -->
          <div class="pager" role="navigation" aria-label="Pagination" style="margin-top:18px;">
            <?php if ($page > 1): ?>
              <a href="?<?php echo build_query(array_merge($_GET, ['page' => $page-1])); ?>">Prev</a>
            <?php endif; ?>
            <span style="font-weight:800;">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
            <?php if ($page < $totalPages): ?>
              <a href="?<?php echo build_query(array_merge($_GET, ['page' => $page+1])); ?>">Next</a>
            <?php endif; ?>
          </div>

          <!-- Recent Activity (right below) -->
          <section class="recent-wrap" style="margin-top:26px;">
            <h3>Recent Activity &amp; Updates</h3>
            <div class="recent-list" style="margin-top:8px;">
              <?php if (empty($recentComplaints)): ?>
                <div class="empty">No recent activity yet.</div>
              <?php else: ?>
                <?php foreach ($recentComplaints as $r): 
                  $displayRecentStatus = normalize_status_for_display($r['status'] ?? '');
                ?>
                  <div class="recent-item" style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #efefef;">
                    <div>
                      <a href="student-complaint-details.php?id=<?php echo (int)$r['id']; ?>" style="text-decoration:none;font-weight:700;color:#111;"><?php echo h($r['title']); ?></a>
                      <div style="color:#666;font-size:0.9rem;"><?php echo h($r['category'] ?? 'General'); ?></div>
                    </div>
                    <div style="text-align:right;">
                      <div class="status-pill" style="display:inline-block;"><?php echo h(status_label($displayRecentStatus)); ?></div>
                      <div style="color:#666;font-size:0.85rem;margin-top:6px;"><?php echo h(date('M d, Y', strtotime($r['created_at']))); ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="view-all-row" style="margin-top:12px;">
              <a href="student-all-complaints.php">View All Complaints &amp; Updates →</a>
            </div>
          </section>

        </main>
      </div>
    </div>
  </div>
</body>
</html>
