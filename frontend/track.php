<?php
session_start();
include '../backend/config/db.php';

// Redirect if not logged in
if (!isset($_SESSION['username'])) {
  header("Location: login.html");
  exit();
}

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

// Fetch user_id
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$user_id = (int)($user['id'] ?? 0);

// Fetch all complaints for this user (latest first)
$complaints = [];
if ($user_id) {
  $sql = "SELECT id, title, category, status, created_at, attachment
          FROM complaints 
          WHERE user_id = ?
          ORDER BY created_at DESC";
  $stmt2 = $conn->prepare($sql);
  if ($stmt2) {
      $stmt2->bind_param("i", $user_id);
      $stmt2->execute();
      $complaints = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt2->close();
  } else {
      error_log("Prepare failed (track fetch): " . $conn->error);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Track Complaints — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/track.css">

  <style>
    /* background image added (dash-bg.png) - layout/CSS preserved */
    body {
      background: url("assets/images/dash-bg.png") no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }

    /* Ensure the clickable card anchor looks identical to the original section.track-card */
    .track-card-link {
      display: block;
      color: inherit;
      text-decoration: none;
    }
    .track-card-link:focus { outline: 3px solid rgba(11,99,255,0.18); outline-offset:3px; }

    /* Reuse design language from student-all-complaints */
    .dash-main { background: rgba(255,255,255,0.95); border-radius:10px; padding:18px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
    .track-head { margin-bottom:12px; }
    .track-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:14px; }
    .track-card { background:#fff; padding:14px; border-radius:8px; border:1px solid rgba(0,0,0,0.06); box-shadow:0 2px 6px rgba(0,0,0,0.03); }
    .track-card .cat { color:#666; font-size:0.9rem; margin-bottom:8px; }
    .track-card .title { font-weight:800; font-size:1.05rem; margin-bottom:10px; }
    .track-card .meta { display:flex; justify-content:space-between; align-items:center; gap:8px; color:#666; font-size:0.9rem; }
    .status-pill { padding:6px 8px; border-radius:999px; background:#f3f4f6; font-weight:700; font-size:0.85rem; color:#111; }
    .empty-note { padding:18px; color:#666; text-align:center; }
  </style>
</head>

<body class="role-<?php echo strtolower(h($role)); ?>">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- Sidebar -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar">
              <img src="assets/images/user-icon.png" alt="User Avatar">
            </div>

            <!-- username clickable -->
            <div class="side-name">
              <a href="studentdashboard.php" style="color:inherit;text-decoration:none;"><?php echo h($username); ?></a>
            </div>

            <!-- role shown as STUDENT -->
            <div class="side-role">STUDENT</div>
          </div>

          <nav class="side-nav">
            <a href="register.php" class="side-link">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link active">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- Main content -->
        <main class="dash-main track-main" role="main" aria-labelledby="track-heading">
          <header class="track-head" id="track-heading">
            <h1 style="margin:0;">TRACK COMPLAINTS</h1>
            <div style="color:#666; margin-top:6px;">Click any complaint to view full details and timeline.</div>
          </header>

          <?php if (empty($complaints)): ?>
            <p class="empty-note">You haven't registered any complaints yet.</p>
          <?php else: ?>
            <div class="track-grid" role="list" aria-label="Your complaints">
              <?php foreach ($complaints as $c):
                $raw = $c['status'] ?? '';
                $displayStatus = normalize_status_for_display($raw);
                $detailUrl = 'student-complaint-details.php?id=' . (int)$c['id'];
              ?>
                <a class="track-card-link" href="<?php echo h($detailUrl); ?>" aria-label="Open complaint <?php echo h($c['title']); ?>">
                  <article class="track-card" role="article">
                    <div class="cat"><?php echo h($c['category'] ?? 'General'); ?></div>
                    <div class="title"><?php echo h($c['title']); ?></div>
                    <div class="meta">
                      <div><span style="font-weight:700;"><?php echo h(date('d M, Y', strtotime($c['created_at']))); ?></span></div>
                      <div><span class="status-pill <?php echo h(strtolower($displayStatus)); ?>"><?php echo h(ucfirst(str_replace('_',' ', $displayStatus))); ?></span></div>
                    </div>
                  </article>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </main>
      </div>
    </div>
  </div>
</body>
</html>
