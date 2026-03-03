<?php
session_start();
if (!isset($_SESSION['username'])) {
  header("Location: login.html");
  exit();
}

include '../backend/config/db.php';

// escape helper
function h($s) {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'USER';

// get logged-in user id (cached)
$stmtU = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmtU->bind_param("s", $username);
$stmtU->execute();
$resU = $stmtU->get_result();
$userRow = $resU->fetch_assoc();
$stmtU->close();

if (!$userRow) {
  // session user no longer exists
  session_unset();
  session_destroy();
  header("Location: login.html");
  exit();
}
$logged_user_id = (int)$userRow['id'];

// get complaint id from query
$complaint_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($complaint_id <= 0) {
  $_SESSION['msg_err'] = "Invalid complaint id.";
  header("Location: student-all-complaints.php");
  exit();
}

// fetch complaint record (and owner's username)
$stmt = $conn->prepare("
  SELECT c.*, u.username AS owner_username 
  FROM complaints c 
  JOIN users u ON c.user_id = u.id
  WHERE c.id = ? LIMIT 1
");
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$compl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$compl) {
  $_SESSION['msg_err'] = "Complaint not found.";
  header("Location: student-all-complaints.php");
  exit();
}

// permission: owner or admin
if ($compl['user_id'] != $logged_user_id && strtolower($role) !== 'admin') {
  $_SESSION['msg_err'] = "You don't have permission to view this complaint.";
  header("Location: student-all-complaints.php");
  exit();
}

// check existing feedback by this user for this complaint (if any)
$fstmt = $conn->prepare("SELECT id, rating, comment, created_at FROM feedback WHERE complaint_id = ? AND user_id = ? LIMIT 1");
$fstmt->bind_param("ii", $complaint_id, $logged_user_id);
$fstmt->execute();
$existingFeedback = $fstmt->get_result()->fetch_assoc();
$fstmt->close();

// fetch tracking history if table exists (optional)
$tracking = [];
$tsql = "SELECT id, status, note, created_at, created_by FROM complaint_tracking WHERE complaint_id = ? ORDER BY created_at ASC";
$tstmt = $conn->prepare($tsql);
if ($tstmt) {
  $tstmt->bind_param("i", $complaint_id);
  $tstmt->execute();
  $tracking = $tstmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $tstmt->close();
}

// attachments handling: assume 'attachment' column holds a path or comma-separated paths
$attachments = [];
if (!empty($compl['attachment'])) {
  if (strpos($compl['attachment'], ',') !== false) {
    $parts = array_map('trim', explode(',', $compl['attachment']));
  } else {
    $parts = [trim($compl['attachment'])];
  }
  foreach ($parts as $p) {
    if ($p === '') continue;
    $attachments[] = $p;
  }
}

// helper for timeline mapping
function step_class(int $n, int $current): string {
  if ($n < $current) return 'step done';
  if ($n === $current) return 'step current';
  return 'step todo';
}

// map status to numeric step (adjust to your application's statuses)
$statusMap = [
  'registered' => 1,
  'assigned' => 2,
  'in_progress' => 3,
  'action_taken' => 4,
  'resolved' => 5
];
$currentStep = $statusMap[strtolower($compl['status'] ?? '')] ?? 1;

// prepare session messages
$msg_success = $_SESSION['msg_success'] ?? null;
$msg_err = $_SESSION['msg_err'] ?? null;
unset($_SESSION['msg_success'], $_SESSION['msg_err']);

// compute display role: admin => ADMIN, else STUDENT
$displayRole = (strtolower($role) === 'admin') ? 'ADMIN' : 'STUDENT';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Complaint Details — Swift CampFix</title>

  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/complaint-details-student.css">

  <style>
    /* background */
    body {
      background: url("assets/images/dash-bg.png") no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }

    /* small local adjustments (keeps visual parity with your mock) */
    .details-card { max-width:980px; margin:18px auto; padding:20px; background:#fff; border-radius:12px; }
    .meta-grid p { margin:6px 0; }
    .lbl { font-weight:800; margin-top:14px; display:block; }
    .readout { background:#fafafa; border:1px solid #eee; padding:10px; border-radius:8px; margin-top:8px; }
    .attachments-row { display:flex; gap:16px; align-items:flex-start; margin-top:18px; }
    .attachments-left { display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }
    .attachment-thumb { width:160px; height:110px; border-radius:10px; overflow:hidden; border:2px solid #000; display:block; background:#fff; }
    .attachment-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
    .feedback-pill { display:inline-block; background:#1976ff; color:#fff; font-weight:900; padding:10px 16px; border-radius:12px; text-decoration:none; box-shadow:0 8px 0 rgba(0,0,0,0.12); align-self:flex-start; }
    .timeline { list-style:none; padding:0; margin:12px 0; display:flex; gap:8px; flex-wrap:wrap; }
    .timeline .step { padding:8px 10px; border-radius:8px; background:#f5f5f5; font-weight:800; }
    .timeline .step.done { background:#e6f4ea; color:#065f46; border:2px solid #cfead6; }
    .timeline .step.current { background:#fff9e6; border:2px solid #ffdd57; }
    .msg { padding:10px 12px; border-radius:8px; margin-bottom:12px; }
    .msg.ok { background:#ecfdf5; color:#064e3b; border:1px solid #dff6e8; }
    .msg.err { background:#ffecec; color:#a11; border:1px solid #f1c0c0; }
    .tracking-list { margin-top:12px; }
    .tracking-item { padding:10px; border:1px solid #eee; border-radius:8px; margin-bottom:8px; background:#fff; }
  </style>
</head>
<body class="role-<?php echo h(strtolower($role)); ?>">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- Sidebar -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar"><img src="assets/images/user-icon.png" alt="Avatar"></div>
            <div class="side-name">
              <a href="studentdashboard.php" style="color:inherit;text-decoration:none;"><?php echo h($username); ?></a>
            </div>
            <div class="side-role"><?php echo h($displayRole); ?></div>
          </div>

          <nav class="side-nav">
            <a href="register.php" class="side-link">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- Main -->
        <main class="dash-main details-card" role="main">
          <header class="details-head" style="display:flex; align-items:center; gap:12px; justify-content:space-between;">
            <div>
              <a class="back-arrow" href="student-all-complaints.php" aria-label="Back">← Back</a>
              <h1 style="margin:8px 0 0 0;">COMPLAINT DETAILS</h1>
            </div>
            <div style="text-align:right;">
              <?php if ($msg_success): ?>
                <div class="msg ok"><?php echo h($msg_success); ?></div>
              <?php endif; ?>
              <?php if ($msg_err): ?>
                <div class="msg err"><?php echo h($msg_err); ?></div>
              <?php endif; ?>
            </div>
          </header>

          <section class="meta-grid" aria-label="Complaint meta">
            <p><strong>Complaint ID:</strong> <?php echo 'CMP-' . (int)$compl['id']; ?></p>
            <p><strong>Submitted By:</strong> <?php echo h($compl['owner_username']); ?></p>
            <p><strong>Submitted On:</strong> <?php echo h(date('d/m/Y H:i', strtotime($compl['created_at']))); ?></p>
            <p><strong>Status:</strong> <?php echo h(ucfirst(str_replace('_',' ', $compl['status']))); ?></p>
          </section>

          <label class="lbl">Title</label>
          <div class="readout"><?php echo h($compl['title']); ?></div>

          <h3 class="lbl">Description</h3>
          <div class="readout"><?php echo nl2br(h($compl['description'] ?? 'No description provided.')); ?></div>

          <h3 class="lbl">Complaint Progress</h3>
          <ol class="timeline" aria-hidden="false">
            <li class="<?php echo step_class(1, $currentStep); ?>">Registered</li>
            <li class="<?php echo step_class(2, $currentStep); ?>">Assigned</li>
            <li class="<?php echo step_class(3, $currentStep); ?>">In Progress</li>
            <li class="<?php echo step_class(4, $currentStep); ?>">Action Taken</li>
            <li class="<?php echo step_class(5, $currentStep); ?>">Resolved</li>
          </ol>

          <h3 class="lbl">Attachments</h3>
          <div class="attachments-row">
            <div class="attachments-left">
              <?php if (empty($attachments)): ?>
                <div class="readout">No attachment uploaded.</div>
              <?php else: ?>
                <?php foreach ($attachments as $idx => $a): 
                  // Always map stored path to /uploads/complaints/<filename>
                  $clean = str_replace('\\', '/', trim($a));
                  $filename = basename($clean); // only file name
                  // student-complaint-details.php is in /SwiftCampFix/frontend/
                  // so ../uploads/complaints/<file> => /SwiftCampFix/uploads/complaints/<file>
                  $webPath = '../uploads/complaints/' . $filename;
                ?>
                  <div>
                    <a class="attachment-thumb" href="<?php echo h($webPath); ?>" target="_blank" rel="noopener noreferrer" title="Open attachment">
                      <img src="<?php echo h($webPath); ?>" alt="attachment <?php echo $idx+1; ?>">
                    </a>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div style="margin-left:auto;">
              <?php
                $isResolved = strtolower($compl['status'] ?? '') === 'resolved';
              ?>
              <?php if ($isResolved): ?>
                <?php if ($existingFeedback): ?>
                  <div style="font-weight:800; color:#2e7d32">
                    Feedback submitted on <?php echo h(date('d M, Y', strtotime($existingFeedback['created_at']))); ?>
                  </div>
                <?php else: ?>
                  <a class="feedback-pill" href="student-feedback.php?complaint_id=<?php echo (int)$complaint_id; ?>">Give Feedback</a>
                <?php endif; ?>
              <?php else: ?>
                <div style="color:#777; font-weight:700;">Feedback available after resolution</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($tracking)): ?>
            <h3 class="lbl" style="margin-top:20px;">Tracking History</h3>
            <div class="tracking-list">
              <?php foreach ($tracking as $t): ?>
                <div class="tracking-item">
                  <div style="font-weight:800;">
                    <?php echo h(ucfirst(str_replace('_',' ', $t['status']))); ?>
                    — <span style="font-weight:600;color:#666;">
                      <?php echo h(date('d M, Y H:i', strtotime($t['created_at']))); ?>
                    </span>
                  </div>
                  <?php if (!empty($t['note'])): ?>
                    <div style="margin-top:8px;"><?php echo nl2br(h($t['note'])); ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </main>
      </div>
    </div>
  </div>
</body>
</html>
