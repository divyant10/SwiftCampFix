<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

include '../backend/config/db.php';

// escape helper
function h($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$username = $_SESSION['username'];
$role     = $_SESSION['role'] ?? 'USER';

// fetch logged-in user's id
$stmtU = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmtU->bind_param("s", $username);
$stmtU->execute();
$resU = $stmtU->get_result();
$userRow = $resU->fetch_assoc();
$stmtU->close();

if (!$userRow) {
    session_unset(); session_destroy();
    header("Location: login.html");
    exit();
}
$logged_user_id = (int)$userRow['id'];

// read complaint_id (GET for form view, POST for submit)
$complaint_id = isset($_REQUEST['complaint_id']) ? (int)$_REQUEST['complaint_id'] : 0;
if ($complaint_id <= 0) {
    $_SESSION['msg_err'] = "Invalid complaint selected.";
    header("Location: student-all-complaints.php");
    exit();
}

// fetch complaint & owner
$stmtC = $conn->prepare("SELECT c.*, u.username AS owner_username FROM complaints c JOIN users u ON c.user_id = u.id WHERE c.id = ? LIMIT 1");
$stmtC->bind_param("i", $complaint_id);
$stmtC->execute();
$compl = $stmtC->get_result()->fetch_assoc();
$stmtC->close();

if (!$compl) {
    $_SESSION['msg_err'] = "Complaint not found.";
    header("Location: student-all-complaints.php");
    exit();
}

// permission: owner or admin
if ($compl['user_id'] != $logged_user_id && strtolower($role) !== 'admin') {
    $_SESSION['msg_err'] = "You don't have permission to give feedback for this complaint.";
    header("Location: student-all-complaints.php");
    exit();
}

// allow feedback only when complaint is resolved
$isResolved = (strtolower($compl['status']) === 'resolved');

// check existing feedback by this user for this complaint
$fstmt = $conn->prepare("SELECT id, rating, comment, created_at FROM feedback WHERE complaint_id = ? AND user_id = ? LIMIT 1");
$fstmt->bind_param("ii", $complaint_id, $logged_user_id);
$fstmt->execute();
$existingFeedback = $fstmt->get_result()->fetch_assoc();
$fstmt->close();

// POST handling: save feedback (only if none exists)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($existingFeedback)) {
    // extra safety: ensure complaint still resolved at submit
    if (!$isResolved) {
        $_SESSION['msg_err'] = "Feedback is only allowed after the complaint has been resolved.";
        header("Location: student-complaint-details.php?id=" . $complaint_id);
        exit();
    }

    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    if ($rating < 1 || $rating > 5) {
        $error = "Please provide a rating between 1 and 5.";
    } elseif (mb_strlen($comment) < 5) {
        $error = "Please enter a comment (at least 5 characters).";
    } else {
        $ins = $conn->prepare("INSERT INTO feedback (complaint_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->bind_param("iiis", $complaint_id, $logged_user_id, $rating, $comment);
        $ok = $ins->execute();
        $ins->close();

        if ($ok) {
            $_SESSION['msg_success'] = "Thank you — your feedback has been submitted.";
        } else {
            $_SESSION['msg_err'] = "Failed to save feedback. Please try again.";
        }

        header("Location: student-complaint-details.php?id=" . $complaint_id);
        exit();
    }
}

// messages from redirects
$msg_success = $_SESSION['msg_success'] ?? null;
$msg_err = $_SESSION['msg_err'] ?? null;
unset($_SESSION['msg_success'], $_SESSION['msg_err']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Feedback — Complaint #<?php echo h($complaint_id); ?> — Swift CampFix</title>

  <!-- global styles (keep consistent with other pages) -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">

  <style>
    /* feedback card inside main area */
    .details-card { max-width:880px; margin:18px auto; padding:18px; background:#fff; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,0.04); }
    .feedback-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .back-arrow { text-decoration:none; font-weight:900; color:#0b63ff; }
    .compl-title { font-weight:900; color:#081226; margin-bottom:6px; }
    .compl-meta { color:#666; margin-bottom:12px; font-weight:700; }
    label { display:block; font-weight:800; margin-bottom:6px; }
    select, textarea { width:100%; padding:10px; border:2px solid #ddd; border-radius:10px; font-size:14px; box-sizing:border-box; }
    select:focus, textarea:focus { outline:none; border-color:#0b63ff; box-shadow:0 6px 24px rgba(11,99,255,0.06); }
    textarea { min-height:120px; resize:vertical; }
    .btn { display:inline-block; padding:10px 14px; background:#0b63ff; color:#fff; font-weight:900; border-radius:8px; border:none; cursor:pointer; text-decoration:none; }
    .btn:disabled { opacity:0.6; cursor:not-allowed; }
    .msg { padding:10px 12px; border-radius:8px; margin-bottom:12px; }
    .msg.err { background:#ffecec; color:#a11; border:1px solid #f1c0c0; }
    .msg.ok { background:#ecfdf5; color:#064e3b; border:1px solid #dff6e8; }
    .disabled-note { color:#666; font-weight:700; margin-top:6px; }
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
            <div class="side-name"><?php echo h($username); ?></div>
            <div class="side-role"><?php echo h(ucfirst($role)); ?></div>
          </div>

          <nav class="side-nav">
            <a href="register.php" class="side-link">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- Main -->
        <main class="dash-main">
          <div class="details-card" role="main">

            <div class="feedback-head">
              <div>
                <a class="back-arrow" href="student-complaint-details.php?id=<?php echo h($complaint_id); ?>">← Back</a>
                <h1 style="margin:8px 0 0 0; font-size:20px;">Provide Feedback</h1>
              </div>

              <div style="text-align:right;">
                <?php if ($msg_success): ?>
                  <div class="msg ok"><?php echo h($msg_success); ?></div>
                <?php endif; ?>
                <?php if ($msg_err): ?>
                  <div class="msg err"><?php echo h($msg_err); ?></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="compl-title">Feedback for: <?php echo h($compl['title']); ?></div>
            <div class="compl-meta">Complaint ID: <?php echo 'CMP-' . (int)$compl['id']; ?> • Submitted on <?php echo h(date('d M, Y, H:i', strtotime($compl['created_at']))); ?> • Status: <?php echo h(ucfirst(str_replace('_',' ', $compl['status']))); ?></div>

            <?php if (!$isResolved): ?>
              <div class="msg err">Feedback is only available after the complaint has been resolved.</div>
              <div><a class="btn" href="student-complaint-details.php?id=<?php echo h($complaint_id); ?>">Back to Complaint</a></div>
            <?php elseif (!empty($existingFeedback)): ?>
              <div class="msg ok">You've already submitted feedback on <?php echo h(date('d M, Y', strtotime($existingFeedback['created_at']))); ?>.</div>

              <div style="margin-bottom:12px;">
                <strong>Rating:</strong> <?php echo (int)$existingFeedback['rating']; ?> / 5
              </div>

              <div style="margin-bottom:12px;">
                <strong>Comment:</strong>
                <div style="margin-top:8px; padding:12px; border-radius:8px; border:1px solid #eee; background:#fafafa;"><?php echo nl2br(h($existingFeedback['comment'])); ?></div>
              </div>

              <div class="disabled-note">If you want to edit your feedback, please contact the admin.</div>
            <?php else: ?>
              <?php if (isset($error)): ?>
                <div class="msg err"><?php echo h($error); ?></div>
              <?php endif; ?>

              <form method="post" action="student-feedback.php" novalidate>
                <input type="hidden" name="complaint_id" value="<?php echo (int)$complaint_id; ?>">
                <div style="margin-bottom:12px;">
                  <label for="rating">Rating (1 - 5)</label>
                  <select name="rating" id="rating" required>
                    <option value="">Select rating</option>
                    <option value="5">5 — Excellent</option>
                    <option value="4">4 — Good</option>
                    <option value="3">3 — Okay</option>
                    <option value="2">2 — Poor</option>
                    <option value="1">1 — Very poor</option>
                  </select>
                </div>

                <div style="margin-bottom:12px;">
                  <label for="comment">Comments / Suggestion</label>
                  <textarea name="comment" id="comment" placeholder="Write about your experience or suggestion..." required></textarea>
                </div>

                <div style="display:flex; gap:12px; align-items:center;">
                  <button type="submit" class="btn">Submit Feedback</button>
                  <a class="back-arrow" href="student-complaint-details.php?id=<?php echo h($complaint_id); ?>">Cancel</a>
                </div>
              </form>
            <?php endif; ?>

          </div>
        </main>
      </div>
    </div>
  </div>
</body>
</html>
