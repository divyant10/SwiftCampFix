<?php
// admin-feedback.php
session_start();
include '../backend/config/db.php';

// allow only admin
if (!isset($_SESSION['username']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

$adminName = $_SESSION['username'];

// helpers
function h($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// get complaint id (optional)
$complaint_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$feedback = null;
$feedback_by = null;
$feedback_time = null;
$ratingValue = 0;
$complaint_title = null;
$errors = [];

// if id provided -> fetch feedback for that complaint
if ($complaint_id > 0) {
    // fetch complaint title (for header) and feedback joined with user
    $sql = "
      SELECT c.title AS complaint_title,
             f.rating, f.comment, f.created_at,
             u.username AS feedback_user
      FROM complaints c
      LEFT JOIN feedback f ON f.complaint_id = c.id
      LEFT JOIN users u ON f.user_id = u.id
      WHERE c.id = ?
      LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "DB error: failed to prepare statement.";
    } else {
        $stmt->bind_param("i", $complaint_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $complaint_title = $row['complaint_title'] ?? null;
            $ratingValue = isset($row['rating']) ? (int)$row['rating'] : 0;
            $feedback = $row['comment'] ?? '';
            $feedback_by = $row['feedback_user'] ?? null;
            $feedback_time = $row['created_at'] ?? null;
        } else {
            $errors[] = "Complaint not found.";
        }
    }
} else {
    // no id — fetch recent feedback list (latest 20) to allow admin to pick
    $sql = "
      SELECT f.complaint_id, f.rating, f.comment, f.created_at,
             u.username AS feedback_user, c.title AS complaint_title
      FROM feedback f
      LEFT JOIN users u ON f.user_id = u.id
      LEFT JOIN complaints c ON f.complaint_id = c.id
      ORDER BY f.created_at DESC
      LIMIT 20
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errors[] = "DB error: failed to prepare statement.";
    } else {
        $stmt->execute();
        $recentFeedbacks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Feedback — Swift CampFix</title>

  <!-- your project's CSS (adjust paths if needed) -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <link rel="stylesheet" href="assets/css/admindashboard.css">

  <style>
    /* small page-specific styles */
    .details-card{ max-width:980px; margin:20px auto; padding:18px; background:#fff; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.04); }
    .details-head{ display:flex; gap:12px; align-items:center; margin-bottom:14px; }
    .back-arrow{ display:inline-grid; place-items:center; width:36px; height:36px; border-radius:50%; border:2px solid #000; text-decoration:none; font-weight:900; color:#000; }
    .lbl{ display:block; font-weight:900; font-size:18px; margin:8px 0; }
    .feedback-text{ border:3px solid #000; border-radius:12px; background:#fff; padding:14px 16px; font-weight:700; box-sizing:border-box; min-height:88px; white-space:pre-wrap; }
    .no-feedback { font-weight:800; color:#333; font-style:italic; }
    .stars { display:flex; gap:8px; margin-top:8px; }
    .star svg{ width:36px; height:36px; }
    .star.filled svg{ fill:#ffd02b; stroke:#000; }
    .recent-list { display:flex; flex-direction:column; gap:10px; margin-top:8px; }
    .recent-item { border:2px solid #eee; padding:10px; border-radius:10px; display:flex; justify-content:space-between; gap:10px; align-items:center; background:#fff; }
    .recent-left { flex:1; min-width:0; }
    .recent-title { font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .recent-meta { color:#666; font-weight:700; font-size:13px; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#0b63ff; color:#fff; text-decoration:none; font-weight:900; }
    .muted { color:#666; font-weight:700; }
    .note { margin-top:12px; color:#666; font-weight:700; }
  </style>
</head>
<body class="role-admin">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar"><img src="assets/images/user-icon.png" alt="Avatar"></div>
            <div class="side-name"><?php echo h($adminName); ?></div>
            <div class="side-role">ADMIN</div>
          </div>
          <nav class="side-nav">
            <a href="admin-all-complaints.php" class="side-link">CHECK COMPLAINTS</a>
            <a href="admin-feedback.php" class="side-link active">VIEW FEEDBACK</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <main class="dash-main">
          <div class="details-card">
            <header class="details-head">
              <a class="back-arrow" href="admin-all-complaints.php" aria-label="Back">←</a>
              <div>
                <h1 style="margin:0; font-size:28px;">Feedback</h1>
                <div class="muted" style="font-size:13px;">View user feedback for complaints</div>
              </div>
            </header>

            <?php if (!empty($errors)): ?>
              <?php foreach ($errors as $e): ?>
                <div style="margin-bottom:12px; padding:10px; background:#ffecec; border:1px solid #f1c0c0; color:#a11; border-radius:8px;"><?php echo h($e); ?></div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($complaint_id > 0): ?>
              <!-- Single complaint feedback view -->
              <div>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                  <div>
                    <div style="font-weight:900; font-size:18px;"><?php echo $complaint_title ? h($complaint_title) : 'Complaint #' . h($complaint_id); ?></div>
                    <div class="muted">Complaint ID: <?php echo h($complaint_id); ?></div>
                  </div>
                  <div style="text-align:right;">
                    <a class="btn" href="admin-complaint-details.php?id=<?php echo (int)$complaint_id; ?>">Open Complaint</a>
                  </div>
                </div>

                <label class="lbl">Feedback:</label>
                <?php if (trim((string)$feedback) === ''): ?>
                  <div class="feedback-text no-feedback" aria-live="polite">No feedback given yet.</div>
                <?php else: ?>
                  <div class="feedback-text" aria-live="polite"><?php echo nl2br(h($feedback)); ?></div>
                <?php endif; ?>

                <label class="lbl" style="margin-top:12px;">Rating:</label>
                <div class="stars" role="img" aria-label="Rating: <?php echo (int)$ratingValue; ?> out of 5">
                  <?php for ($i=1;$i<=5;$i++): $cls = $i <= $ratingValue ? 'star filled' : 'star'; ?>
                    <span class="<?php echo $cls; ?>" aria-hidden="true">
                      <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                        <path d="M12 .587l3.668 7.431L23.6 9.75l-5.8 5.66L19.468 24 12 19.897 4.532 24l1.667-8.59L.4 9.75l7.932-1.732z"/>
                      </svg>
                    </span>
                  <?php endfor; ?>
                </div>

                <?php if ($feedback_by || $feedback_time): ?>
                  <div class="note">Submitted by <?php echo $feedback_by ? h($feedback_by) : 'Unknown'; ?><?php if ($feedback_time) echo ' on ' . h(date('d M, Y H:i', strtotime($feedback_time))); ?>.</div>
                <?php endif; ?>
              </div>

            <?php else: ?>
              <!-- Recent feedback list (no id provided) -->
              <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <div style="font-weight:900;">Recent feedback (latest 20)</div>
                <div><a class="btn" href="admin-all-complaints.php">All complaints</a></div>
              </div>

              <div class="recent-list" style="margin-top:12px;">
                <?php if (empty($recentFeedbacks)): ?>
                  <div class="muted">No feedback entries yet.</div>
                <?php else: ?>
                  <?php foreach ($recentFeedbacks as $rf): 
                    $cid = (int)($rf['complaint_id'] ?? 0);
                    $ctitle = $rf['complaint_title'] ?? ('Complaint #' . $cid);
                    $cuser = $rf['feedback_user'] ?? 'Unknown';
                    $crtime = $rf['created_at'] ?? null;
                    $crating = isset($rf['rating']) ? (int)$rf['rating'] : 0;
                  ?>
                    <div class="recent-item">
                      <div class="recent-left">
                        <div class="recent-title"><?php echo h($ctitle); ?></div>
                        <div class="recent-meta">By: <?php echo h($cuser); ?> — <?php echo $crtime ? h(date('d M, Y H:i', strtotime($crtime))) : '—'; ?></div>
                      </div>

                      <div style="display:flex; gap:8px; align-items:center;">
                        <div class="muted" style="min-width:60px; text-align:center;"><?php echo (int)$crating; ?>/5</div>
                        <?php if ($cid): ?>
                          <a class="btn" href="admin-feedback.php?id=<?php echo $cid; ?>">Open</a>
                        <?php else: ?>
                          <span class="muted">No complaint</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          </div>
        </main>

      </div>
    </div>
  </div>
</body>
</html>
