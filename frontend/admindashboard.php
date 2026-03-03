<?php
session_start();
include '../backend/config/db.php';

//  Allow only admin
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$adminName = $_SESSION['username'];

// admin-statuses considered as "admin acted"
$admin_statuses = ['assigned','in_progress','action_taken','resolved'];

// --- Complaint stats ---
// total, pending (anything NOT in admin_statuses), resolved in last 7 days
$sql = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN LOWER(status) IN ('assigned','in_progress','action_taken','resolved') THEN 0 ELSE 1 END) AS pending,
        SUM(CASE WHEN LOWER(status) = 'resolved' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS resolved7
    FROM complaints
";
$res = $conn->query($sql);
$stats = $res ? $res->fetch_assoc() : [];
$total = (int)($stats['total'] ?? 0);
$pending = (int)($stats['pending'] ?? 0);
$resolved7 = (int)($stats['resolved7'] ?? 0);

// --- Recent complaints (latest 5) but only those that are still 'registered'/pending (no admin update yet)
$recentSql = "
  SELECT id, title, COALESCE(status, '') AS status, created_at
  FROM complaints
  WHERE (LOWER(status) NOT IN ('assigned','in_progress','action_taken','resolved') OR status IS NULL OR status = '')
  ORDER BY created_at DESC
  LIMIT 5
";
$recentRes = $conn->query($recentSql);
$recentRows = $recentRes ? $recentRes->fetch_all(MYSQLI_ASSOC) : [];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Dashboard — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/admindashboard.css">

  <style>
    body {
      background: url("assets/images/dash-bg.png") no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }
    .dash-shell { position: relative; z-index: 2; }

    .row-link {
      display:flex;
      gap:12px;
      align-items:center;
      padding:12px;
      border-radius:8px;
      text-decoration:none;
      color:inherit;
      background: rgba(255,255,255,0.95);
      border:1px solid rgba(0,0,0,0.04);
      margin-bottom:8px;
    }
    .row-link:hover { box-shadow: 0 6px 18px rgba(0,0,0,0.06); transform: translateY(-2px); transition: all .16s ease; }
    .row-title { flex:1; font-weight:700; color:#111; }
    .row-status { padding:6px 10px; border-radius:8px; font-weight:800; white-space:nowrap; }
    .row-status.pending { border:2px solid #ff3b3b; color:#ff3b3b; background: rgba(255,59,59,0.06); }
    .row-status.assigned { border:2px solid #0f9d58; color:#0f9d58; background: rgba(15,157,88,0.06); }
    .row-date { color:#666; font-size:0.9rem; white-space:nowrap; }

    .notif-item {
      background: rgba(255,255,255,0.95);
      border-radius:8px;
      padding:10px;
      margin-bottom:8px;
      display:flex;
      flex-direction:column;
      gap:6px;
      position:relative;
    }
    .notif-item a { color:inherit; text-decoration:none; display:block; }
    .notif-top { font-weight:800; color:#111; }
    .notif-sub { color:#555; font-size:0.95rem; }

    .notif-close { position:absolute; right:8px; top:6px; border:0; background:transparent; font-size:18px; cursor:pointer; color:#999; }
    .notif-close:hover { color:#444; }
    .admin-cta { display:inline-block; padding:10px 14px; border-radius:8px; text-decoration:none; color:#fff; background:#0d6efd; font-weight:800; }
  </style>
</head>

<body class="role-admin">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">

        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar">
              <img src="assets/images/user-icon.png" alt="Avatar">
            </div>
            <div class="side-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="side-role">ADMIN</div>
          </div>

          <nav class="side-nav">
            <a href="admin-all-complaints.php" class="side-link admin-only">CHECK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>

          <section class="notif" style="margin-top:14px;">
            <div class="notif-head" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
              <span class="notif-title" style="font-weight:800;">NOTIFICATIONS</span>
              <span class="notif-arrow" style="color:#666;">→</span>
            </div>

            <div class="notif-list" id="notif-list">
              <?php if (count($recentRows) > 0): ?>
                <?php foreach ($recentRows as $c): 
                  $cid = (int)$c['id'];
                  $title = htmlspecialchars($c['title']);
                  $link = "admin-complaint-details.php?id={$cid}";
                ?>
                  <div class="notif-item" data-id="CMP-<?= $cid ?>">
                    <a href="<?= $link ?>">
                      <div class="notif-top">New Complaint Registered</div>
                      <div class="notif-sub"><?= $title ?></div>
                    </a>
                    <button class="notif-close" aria-label="Dismiss">×</button>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="notif-item">
                  <div class="notif-sub">No new complaints yet.</div>
                </div>
              <?php endif; ?>
            </div>
          </section>
        </aside>

        <main class="dash-main admin-main">
          <header class="main-head">
            <!-- UPDATED HERE → Comma Removed -->
            <h1>Welcome back <span class="hl"><?= htmlspecialchars($adminName) ?></span>!</h1>
          </header>

          <section class="stats-row admin-stats" style="display:flex;gap:12px;margin-bottom:16px;">
            <article class="stat stat-tight" style="flex:1;">
              <div class="stat-title center">Total<br>Complaints</div>
              <div class="stat-num stat-blue"><?= $total ?></div>
            </article>
            <article class="stat stat-tight" style="flex:1;">
              <div class="stat-title center">Pending<br>Complaints</div>
              <div class="stat-num stat-red"><?= $pending ?></div>
            </article>
            <article class="stat stat-tight" style="flex:1;">
              <div class="stat-title center">Resolved<br>Last 7 Days</div>
              <div class="stat-num stat-green"><?= $resolved7 ?></div>
            </article>
          </section>

          <div class="admin-cta-wrap" style="margin-bottom:20px;">
            <a href="admin-all-complaints.php?filter=pending" class="cta admin-only admin-cta">
              REVIEW ALL PENDING COMPLAINTS
            </a>
          </div>

          <section class="activity">
            <h3>Recent Activity & Updates</h3>

            <?php if (count($recentRows) > 0): ?>
              <?php foreach ($recentRows as $r):
                $rid = (int)$r['id'];
                $title = htmlspecialchars($r['title']);
                $rawStatus = trim((string)$r['status']);
                $displayStatus = ($rawStatus === '' ? 'pending' : strtolower($rawStatus));
                $statusLabel = ucfirst(str_replace('_',' ',$displayStatus));
                $link = "admin-complaint-details.php?id={$rid}";
              ?>
                <a class="row-link" href="<?= $link ?>" aria-label="Open complaint <?= $rid ?>">
                  <div class="row-title"><?= $title ?></div>
                  <div class="row-status <?= htmlspecialchars($displayStatus) ?>"><?= htmlspecialchars($statusLabel) ?></div>
                  <div class="row-date"><?= date('d/m/Y', strtotime($r['created_at'])) ?></div>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-note">No recent complaints yet.</div>
            <?php endif; ?>

            <div class="view-all" style="margin-top:12px;">
              <a href="admin-all-complaints.php">View All Updates <span>→</span></a>
            </div>
          </section>
        </main>
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('click', e => {
    const btn = e.target.closest('.notif-close');
    if (!btn) return;
    const it = btn.closest('.notif-item');
    if (it) it.remove();
  });
  </script>

</body>
</html>
