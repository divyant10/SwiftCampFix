<?php
session_start();
include '../backend/config/db.php';

// Allow only admin
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$adminName = $_SESSION['username'];

// ADMIN statuses considered as "admin acted"
$admin_statuses = ['assigned','in_progress','action_taken','resolved'];

// read filters from GET
$filter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all'; // all|pending|resolved
$range  = isset($_GET['range']) ? strtolower(trim($_GET['range'])) : ''; // this_month|last_3_months|last_6_months|this_year

// build WHERE clauses
$whereParts = [];

// status filter
if ($filter === 'pending') {
    // pending = status not in admin_statuses OR NULL/empty
    $whereParts[] = "(COALESCE(LOWER(c.status),'') NOT IN ('assigned','in_progress','action_taken','resolved') OR c.status IS NULL OR c.status = '')";
} elseif ($filter === 'resolved') {
    $whereParts[] = "LOWER(c.status) = 'resolved'";
} else {
    // all -> no status restriction
}

// date range filter  ➜ NOTE: now using c.created_at (no ambiguity)
if ($range === 'this_month') {
    $whereParts[] = "c.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
} elseif ($range === 'last_3_months') {
    $whereParts[] = "c.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($range === 'last_6_months') {
    $whereParts[] = "c.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
} elseif ($range === 'this_year') {
    $whereParts[] = "YEAR(c.created_at) = YEAR(CURDATE())";
}

// build final where
$where = '';
if (!empty($whereParts)) {
    $where = "WHERE " . implode(' AND ', $whereParts);
}

// fetch complaints according to filters (server-side)
// IMPORTANT: alias complaints as c, and use c.created_at so 'created_at' is never ambiguous
$sql = "SELECT c.id, c.title, c.status, c.created_at 
        FROM complaints c 
        {$where}
        ORDER BY c.created_at DESC";

$res = $conn->query($sql);
$complaints = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// compute counts (All / Pending / Resolved) — we compute counts globally (ignoring range)
$countSql = "
  SELECT
    COUNT(*) AS cnt_total,
    SUM(
      CASE 
        WHEN COALESCE(LOWER(status),'') NOT IN ('assigned','in_progress','action_taken','resolved')
             OR status IS NULL OR status = '' 
        THEN 1 ELSE 0 
      END
    ) AS cnt_pending,
    SUM(CASE WHEN LOWER(status) = 'resolved' THEN 1 ELSE 0 END) AS cnt_resolved
  FROM complaints
";
$cres = $conn->query($countSql);
$counts = $cres ? $cres->fetch_assoc() : [];
$totalAll = (int)($counts['cnt_total'] ?? 0);
$totalPending = (int)($counts['cnt_pending'] ?? 0);
$totalResolved = (int)($counts['cnt_resolved'] ?? 0);

// helper to safely echo
function h($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// helper to build link preserving other params
function build_link($filterVal, $rangeVal) {
    $qs = [];
    if ($filterVal !== null && $filterVal !== '') $qs['filter'] = $filterVal;
    if ($rangeVal !== null && $rangeVal !== '') $qs['range'] = $rangeVal;
    return '?' . http_build_query($qs);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>All Complaints — Admin — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/admindashboard.css">
  <link rel="stylesheet" href="assets/css/all-complaints.css">

  <style>
    /* Background image */
    body {
      background: url("assets/images/dash-bg.png") no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }
    .dash-shell { position:relative; z-index:2; }

    /* toolbar layout */
    .toolbar { display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap; margin-bottom:12px; }
    .chips { display:flex; gap:12px; align-items:center; }

    /* chip / square box */
    .chip {
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:10px;
      background:#fff;
      border:2px solid #000;
      font-weight:800;
      cursor:pointer;
      user-select:none;
      min-width:120px;
      justify-content:space-between;
      text-decoration:none;
    }
    .chip .label { display:inline-block; }
    .chip .badge { display:inline-block; min-width:30px; text-align:center; padding:6px 8px; border-radius:6px; font-weight:900; }

    /* default (All) */
    .chip[data-filter="all"] { border-color:#000; color:#000; }
    .chip[data-filter="all"].active { background:#111; color:#fff; }
    .chip[data-filter="all"].active .badge { background: rgba(255,255,255,0.12); color:#fff; }

    /* Pending: red border when inactive; red filled background when active */
    .chip[data-filter="pending"] { border-color:#ff3b3b; color:#ff3b3b; }
    .chip[data-filter="pending"] .badge { background: transparent; color: inherit; }
    .chip[data-filter="pending"].active {
      background: #ff3b3b;
      color: #fff;
      border-color: #ff3b3b;
    }
    .chip[data-filter="pending"].active .label,
    .chip[data-filter="pending"].active .badge {
      color: #ffffff !important;
    }
    .chip[data-filter="pending"].active .badge { background: rgba(255,255,255,0.12); }

    /* Resolved: green */
    .chip[data-filter="resolved"] { border-color:#0f9d58; color:#0f9d58; }
    .chip[data-filter="resolved"] .badge { background: transparent; color: inherit; }
    .chip[data-filter="resolved"].active {
      background: #0f9d58;
      color: #fff;
      border-color: #0f9d58;
    }
    .chip[data-filter="resolved"].active .label,
    .chip[data-filter="resolved"].active .badge {
      color: #ffffff !important;
    }
    .chip[data-filter="resolved"].active .badge { background: rgba(255,255,255,0.12); }

    /* report generation dropdown */
    .report-wrap { display:flex; gap:8px; align-items:center; }
    .report-select { padding:8px 10px; border-radius:8px; border:1px solid #ccc; background:#fff; }
    .report-btn { padding:8px 12px; border-radius:8px; background:#0b63ff; color:#fff; font-weight:800; border:0; cursor:pointer; }

    /* complaint list styling */
    .complaint-list { padding:0; margin:0; list-style:none; display:flex; flex-direction:column; gap:10px; }
    .complaint-row {
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:12px;
      border-radius:12px;
      background: rgba(255,255,255,0.98);
      border:2px solid #000;
      gap:12px;
    }
    .complaint-left { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; flex:1; min-width:0; }
    .complaint-title { font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:60%; }
    .complaint-date { color:#666; font-weight:700; white-space:nowrap; margin-left:8px; }

    .complaint-actions { display:flex; gap:8px; align-items:center; }
    .btn-details { padding:8px 12px; border-radius:8px; background:#fff; color:#0b63ff; border:2px solid #0b63ff; font-weight:800; text-decoration:none; }
    .btn-feedback { padding:8px 12px; border-radius:8px; background:#2b78ff; color:#fff; font-weight:800; text-decoration:none; }

    /* status pill for right side */
    .status-pill { padding:6px 10px; border-radius:8px; font-weight:900; border:2px solid #000; }
    .status-pill.pending { border-color:#ff3b3b; color:#ff3b3b; background: rgba(255,59,59,0.06); }
    .status-pill.resolved { border-color:#0f9d58; color:#0f9d58; background: rgba(15,157,88,0.06); }
    .status-pill.assigned { border-color:#0b63ff; color:#0b63ff; background: rgba(11,99,255,0.06); }

    @media (max-width:900px){
      .chip { padding:8px 10px; min-width:90px; font-size:14px; }
      .complaint-title { max-width:45%; }
      .toolbar { flex-direction:column; align-items:flex-start; gap:8px; }
    }
  </style>
</head>
<body class="role-admin">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- LEFT SIDEBAR -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar"><img src="assets/images/user-icon.png" alt="Avatar"></div>
            <div class="side-name"><?= h($adminName) ?></div>
            <div class="side-role">ADMIN</div>
          </div>

          <nav class="side-nav">
            <a href="admin-all-complaints.php" class="side-link admin-only active">CHECK COMPLAINTS</a>
            <a href="admin-feedback.php" class="side-link">VIEW FEEDBACK</a>
            <a href="admin-report.php" class="side-link">REPORT GENERATION</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- MAIN PANEL -->
        <main class="dash-main all-main">
          <h1 class="all-title">ALL COMPLAINTS</h1>

          <div class="toolbar">
            <div style="display:flex; gap:12px; align-items:center;">
              <div class="search" style="display:flex; align-items:center; gap:8px;">
                <input id="searchBox" type="text" placeholder="Search by ID or title..." aria-label="Search">
                <button id="searchBtn" class="search-btn" aria-label="Search">🔍</button>
              </div>

              <div class="chips" role="tablist" aria-label="Filters">
                <a class="chip <?php echo ($filter==='all' ? 'active':''); ?>" href="<?php echo build_link('all', $range); ?>" data-filter="all">
                  <span class="label">All</span>
                  <span class="badge"><?php echo $totalAll; ?></span>
                </a>

                <a class="chip <?php echo ($filter==='pending' ? 'active':''); ?>" href="<?php echo build_link('pending', $range); ?>" data-filter="pending">
                  <span class="label">Pending</span>
                  <span class="badge"><?php echo $totalPending; ?></span>
                </a>

                <a class="chip <?php echo ($filter==='resolved' ? 'active':''); ?>" href="<?php echo build_link('resolved', $range); ?>" data-filter="resolved">
                  <span class="label">Resolved</span>
                  <span class="badge"><?php echo $totalResolved; ?></span>
                </a>
              </div>
            </div>

            <!-- Report generation controls -->
            <div class="report-wrap" role="region" aria-label="Report generation">
              <select id="reportRange" class="report-select" aria-label="Select range">
                <option value="" <?php echo ($range==='' ? 'selected':''); ?>>Report: All time</option>
                <option value="this_month" <?php echo ($range==='this_month' ? 'selected':''); ?>>This month</option>
                <option value="last_3_months" <?php echo ($range==='last_3_months' ? 'selected':''); ?>>Last 3 months</option>
                <option value="last_6_months" <?php echo ($range==='last_6_months' ? 'selected':''); ?>>Last 6 months</option>
                <option value="this_year" <?php echo ($range==='this_year' ? 'selected':''); ?>>This year</option>
              </select>

              <select id="reportBucket" class="report-select" aria-label="Select bucket">
                <option value="all" <?php echo ($filter==='all' ? 'selected':''); ?>>All</option>
                <option value="pending" <?php echo ($filter==='pending' ? 'selected':''); ?>>Pending</option>
                <option value="resolved" <?php echo ($filter==='resolved' ? 'selected':''); ?>>Resolved</option>
              </select>

              <button id="generateReport" class="report-btn">Generate CSV</button>
            </div>
          </div>

          <ul class="complaint-list" id="complaintList">
            <?php if (count($complaints) === 0): ?>
              <li class="complaint-row"><div style="font-weight:800;">No complaints found for this filter/range.</div></li>
            <?php else: ?>
              <?php foreach ($complaints as $c): 
                $cid = (int)$c['id'];
                $stat = strtolower((string)$c['status']);
                // normalize display status: empty -> pending
                $displayStat = ($stat === '' ? 'pending' : $stat);
                $statusLabel = ucfirst(str_replace('_',' ', $displayStat));
              ?>
                <li class="complaint-row" data-id="cmp-<?php echo $cid; ?>" data-status="<?php echo h($displayStat); ?>">
                  <a class="complaint-left" href="admin-complaint-details.php?id=<?php echo $cid; ?>">
                    <div class="complaint-title"><?php echo h($c['title']); ?></div>
                    <div class="complaint-date"><?php echo h(date('d/m/Y', strtotime($c['created_at']))); ?></div>
                  </a>

                  <div class="complaint-actions">
                    <div class="status-pill <?php echo h($displayStat); ?>"><?php echo h($statusLabel); ?></div>
                    <a class="btn-details" href="admin-complaint-details.php?id=<?php echo $cid; ?>">Details</a>
                    <a class="btn-feedback" href="admin-feedback.php?id=<?php echo $cid; ?>">View Feedback</a>
                  </div>
                </li>
              <?php endforeach; ?>
            <?php endif; ?>
          </ul>
        </main>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const searchBox = document.getElementById('searchBox');
    const searchBtn = document.getElementById('searchBtn');
    const list = document.getElementById('complaintList');
    const rows = Array.from(list.querySelectorAll('.complaint-row'));
    const generateBtn = document.getElementById('generateReport');
    const rangeSelect = document.getElementById('reportRange');
    const bucketSelect = document.getElementById('reportBucket');

    // client-side search (only hiding rows on the already server-rendered list)
    function applySearch() {
      const q = (searchBox.value || '').trim().toLowerCase();
      rows.forEach(row => {
        const title = row.querySelector('.complaint-title').textContent.toLowerCase();
        const id = row.dataset.id.toLowerCase();
        const match = !q || title.includes(q) || id.includes(q);
        row.style.display = match ? '' : 'none';
      });
    }
    searchBtn.addEventListener('click', applySearch);
    searchBox.addEventListener('keydown', (e)=> { if (e.key === 'Enter') applySearch(); });

    // Generate CSV -> open export_csv.php in new tab with selected params
    generateBtn.addEventListener('click', () => {
      const r = rangeSelect.value;
      const b = bucketSelect.value;
      const base = location.origin + '/SwiftCampFix/frontend/export_csv.php';
      const url = new URL(base);
      if (b) url.searchParams.set('filter', b);
      if (r) url.searchParams.set('range', r);
      window.open(url.toString(), '_blank');
    });

  })();
  </script>
</body>
</html>
