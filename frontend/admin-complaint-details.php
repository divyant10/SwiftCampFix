<?php
// admin-complaint-details.php (updated: more robust dash-bg.png resolution + attachments + clickable admin name)

session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

include '../backend/config/db.php';

function h($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function prepare_or_error(mysqli $conn, string $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("DB prepare failed: " . $conn->error . " -- SQL: " . $sql);
        http_response_code(500);
        echo "<h2>Database error</h2><p>Failed to prepare statement. Check server logs.</p>";
        exit();
    }
    return $stmt;
}

/* --- Helpers for attachments & path mapping --- */
function fs_path_to_web_url(string $fsPath) {
    $fs = str_replace('\\', '/', $fsPath);
    if (stripos($fs, 'file://') === 0) {
        $fs = preg_replace('#^file://#i', '', $fs);
    }
    $fs = preg_replace('#/+#', '/', $fs);

    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
    if ($docRoot === '') return null;

    if (strpos($fs, $docRoot) === 0) {
        $web = substr($fs, strlen($docRoot));
        if ($web === '') $web = '/';
        if ($web[0] !== '/') $web = '/' . $web;
        return $web;
    }
    return null;
}

function normalize_attachment_url(string $raw) {
    $s = trim($raw);
    if ($s === '') return '';

    if (preg_match('#^https?://#i', $s)) return $s;
    if ($s[0] === '/') {
        return $s;
    }
    if (preg_match('#^[A-Za-z]:[\\\\/]#', $s) || strpos($s, '\\') !== false) {
        $mapped = fs_path_to_web_url($s);
        if ($mapped !== null) return $mapped;
    }

    $basename = basename($s);
    $candidates = [];

    // try detect project folder name
    $projectFolder = null;
    $cwd = str_replace('\\','/', __DIR__);
    $docRoot = str_replace('\\','/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
    if ($docRoot !== '' && strpos($cwd, $docRoot) === 0) {
        $rest = substr($cwd, strlen($docRoot));
        $rest = trim($rest, '/');
        $parts = explode('/', $rest);
        if (!empty($parts[0])) $projectFolder = $parts[0];
    }

    if ($projectFolder) {
        $candidates[] = '/' . $projectFolder . '/uploads/complaints/' . $basename;
        $candidates[] = '/' . $projectFolder . '/uploads/' . $basename;
        $candidates[] = '/' . $projectFolder . '/complaints/' . $basename;
    }

    $candidates[] = '/uploads/complaints/' . $basename;
    $candidates[] = '/uploads/' . $basename;
    if (strpos($s, '/') !== false) {
        if ($projectFolder) $candidates[] = '/' . $projectFolder . '/' . ltrim($s, '/');
        $candidates[] = '/' . ltrim($s, '/');
    }

    foreach ($candidates as $cand) {
        $serverPath = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . str_replace('/', DIRECTORY_SEPARATOR, $cand);
        if (@file_exists($serverPath)) {
            return $cand;
        }
    }

    $tryLocal = __DIR__ . '/' . ltrim($s, '/');
    if (@file_exists($tryLocal)) {
        $mapped = fs_path_to_web_url($tryLocal);
        if ($mapped !== null) return $mapped;
    }

    return $s;
}

/* --- Require admin --- */
$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'USER';
if (strtolower($role) !== 'admin') {
    header("Location: index.php");
    exit();
}

/* --- Fetch admin id --- */
$stmtU = prepare_or_error($conn, "SELECT id FROM users WHERE username = ? LIMIT 1");
$stmtU->bind_param("s", $username);
$stmtU->execute();
$resU = $stmtU->get_result();
$adminRow = $resU->fetch_assoc();
$stmtU->close();
$admin_id = (int)($adminRow['id'] ?? 0);

/* --- Complaint id (GET) --- */
$complaint_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($complaint_id <= 0) {
    $_SESSION['msg_err'] = "Invalid complaint id.";
    header("Location: admin-all-complaints.php");
    exit();
}

/* --- Delete tracking handler --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tracking') {
    $posted_complaint_id = isset($_POST['complaint_id']) ? (int)$_POST['complaint_id'] : 0;
    $track_id = isset($_POST['track_id']) ? (int)$_POST['track_id'] : 0;
    $use_complaint_id = ($posted_complaint_id > 0) ? $posted_complaint_id : $complaint_id;

    if ($track_id > 0 && $use_complaint_id > 0) {
        $dstmt = prepare_or_error($conn, "DELETE FROM complaint_tracking WHERE id = ? AND complaint_id = ? LIMIT 1");
        $dstmt->bind_param("ii", $track_id, $use_complaint_id);
        $dstmt->execute();
        $affected = $conn->affected_rows;
        $dstmt->close();
        if ($affected > 0) {
            $_SESSION['msg_success'] = "Tracking entry deleted.";
        } else {
            $_SESSION['msg_err'] = "Failed to delete tracking entry (not found or already removed).";
        }
    } else {
        $_SESSION['msg_err'] = "Invalid tracking id or complaint id.";
    }
    header("Location: admin-complaint-details.php?id=" . ($use_complaint_id > 0 ? $use_complaint_id : $complaint_id));
    exit();
}

/* --- Update status handler --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $allowedStatuses = ['registered','assigned','in_progress','action_taken','resolved'];
    $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';
    if (!in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['msg_err'] = "Invalid status selected.";
    } else {
        $u = prepare_or_error($conn, "UPDATE complaints SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
        $u->bind_param("si", $newStatus, $complaint_id);
        $ok = $u->execute();
        $u->close();

        $t = prepare_or_error($conn, "INSERT INTO complaint_tracking (complaint_id, status, note, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
        $t->bind_param("issi", $complaint_id, $newStatus, $note, $admin_id);
        $t->execute();
        $t->close();

        if ($ok) $_SESSION['msg_success'] = "Status updated successfully."; else $_SESSION['msg_err'] = "Failed to update status.";
    }
    header("Location: admin-complaint-details.php?id=" . $complaint_id);
    exit();
}

/* --- Fetch complaint --- */
$sqlComplaint = "SELECT c.*, u.username AS owner_username FROM complaints c JOIN users u ON c.user_id = u.id WHERE c.id = ? LIMIT 1";
$stmt = prepare_or_error($conn, $sqlComplaint);
$stmt->bind_param("i", $complaint_id);
$stmt->execute();
$compl = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$compl) {
    $_SESSION['msg_err'] = "Complaint not found.";
    header("Location: admin-all-complaints.php");
    exit();
}

/* --- Attachments --- */
$attachments = [];
if (!empty($compl['attachment'])) {
    $parts = (strpos($compl['attachment'], ',') !== false) ? array_map('trim', explode(',', $compl['attachment'])) : [trim($compl['attachment'])];
    foreach ($parts as $p) {
        if ($p === '') continue;
        $normalized = normalize_attachment_url($p);
        $serverCheck = null;
        if ($normalized && $normalized[0] === '/') {
            $serverCheck = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }
        $exists = $serverCheck ? @file_exists($serverCheck) : false;
        $attachments[] = [
            'raw' => $p,
            'url' => $normalized,
            'exists' => $exists
        ];
    }
}

/* --- Tracking history --- */
$tracking = [];
$tsql = "SELECT ct.id, ct.status, ct.note, ct.created_by, ct.created_at, u.username AS actor_username
         FROM complaint_tracking ct
         LEFT JOIN users u ON ct.created_by = u.id
         WHERE ct.complaint_id = ?
         ORDER BY ct.created_at ASC";
$tstmt = prepare_or_error($conn, $tsql);
$tstmt->bind_param("i", $complaint_id);
$tstmt->execute();
$tracking = $tstmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tstmt->close();

/* --- Feedback --- */
$feedback = null;
$fsql = "SELECT f.rating, f.comment, f.created_at, u.username AS student_username
         FROM feedback f
         LEFT JOIN users u ON f.user_id = u.id
         WHERE f.complaint_id = ? LIMIT 1";
$fstmt = prepare_or_error($conn, $fsql);
$fstmt->bind_param("i", $complaint_id);
$fstmt->execute();
$feedback = $fstmt->get_result()->fetch_assoc();
$fstmt->close();

/* --- Messages --- */
$msg_success = $_SESSION['msg_success'] ?? null;
$msg_err = $_SESSION['msg_err'] ?? null;
unset($_SESSION['msg_success'], $_SESSION['msg_err']);

/* --- Status map --- */
$statusMap = ['registered'=>1,'assigned'=>2,'in_progress'=>3,'action_taken'=>4,'resolved'=>5];
$currentStep = $statusMap[strtolower($compl['status'] ?? 'registered')] ?? 1;
function step_class(int $n,int $current): string { if ($n<$current) return 'step done'; if ($n=== $current) return 'step current'; return 'step todo'; }

/* --- Robust dash-bg resolution: try many places and fall back to a safe web path --- */
$candidates = [
    __DIR__ . '/assets/images/dash-bg.png',
    __DIR__ . '/../frontend/assets/images/dash-bg.png',
    __DIR__ . '/../assets/images/dash-bg.png',
    $_SERVER['DOCUMENT_ROOT'] . '/SwiftCampFix/frontend/assets/images/dash-bg.png',
    $_SERVER['DOCUMENT_ROOT'] . '/SwiftCampFix/assets/images/dash-bg.png',
    $_SERVER['DOCUMENT_ROOT'] . '/assets/images/dash-bg.png'
];

$bgUrl = '';
foreach ($candidates as $c) {
    if ($c && file_exists($c) && is_readable($c)) {
        $mapped = fs_path_to_web_url($c);
        if ($mapped !== null) {
            $bgUrl = $mapped;
        } else {
            // try to make a relative URL from the script path
            $rel = str_replace(str_replace('\\','/',__DIR__), '', str_replace('\\','/',$c));
            $rel = ltrim($rel, '/');
            $bgUrl = '/' . $rel;
        }
        break;
    }
}

// final fallback: use script directory + assets/images/dash-bg.png (works when frontend is routed under same folder)
if ($bgUrl === '') {
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $scriptDir = rtrim(str_replace('\\','/',$scriptDir), '/');
    if ($scriptDir === '') $scriptDir = '/';
    $maybe = $scriptDir . '/assets/images/dash-bg.png';
    // ensure leading slash
    if ($maybe[0] !== '/') $maybe = '/' . $maybe;
    $bgUrl = $maybe;
    // note: this may 404 if the file isn't present at that path, but it's a safe web URL fallback
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Admin — Complaint Details — Swift CampFix</title>

<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/admindashboard.css">
<link rel="stylesheet" href="assets/css/complaint-details.css">
<link rel="stylesheet" href="assets/css/studentdashboard.css">

<style>
  body { margin:0; font-family: Inter, Roboto, Arial, sans-serif; background:#bfbfbf; }
  .page {
    max-width:1400px; margin:24px auto; padding:22px; border-radius:18px; border:6px solid #d0d0d0;
    background-color:#f4f7f9; min-height:720px; position:relative; overflow:hidden;
  }
  .dash-bg { position:absolute; left:0; right:0; top:0; height:420px; overflow:hidden; z-index:1; pointer-events:none; }
  .dash-bg-img { width:100%; height:100%; object-fit:cover; display:block; opacity:0.96; }
  .page::after { content:""; position:absolute; left:0;right:0;top:0;bottom:0; background: linear-gradient(180deg, rgba(255,255,255,0.72) 0%, rgba(255,255,255,0.86) 45%, rgba(255,255,255,0.92) 100%); pointer-events:none; z-index:2; }
  .frame, .dash-shell, .dash-main, .details-card { position:relative; z-index:3; }
  .dash-shell{ display:flex; gap:20px; padding:18px; align-items:flex-start; }
  .dash-side{ width:260px; min-height:520px; background:rgba(255,255,255,0.88); border-radius:14px; padding:18px; box-shadow:0 6px 12px rgba(0,0,0,0.08); border:2px solid rgba(0,0,0,0.12); display:flex; flex-direction:column; gap:18px; }
  .details-card{ background:linear-gradient(180deg,#fff,#fafafa); border-radius:14px; padding:20px; border:2px solid rgba(0,0,0,0.12); box-shadow:0 8px 20px rgba(0,0,0,0.08); }
  .side-name a { text-decoration:none; color:inherit; font-weight:800; display:inline-block; }
  .side-name a:focus { outline: 3px solid rgba(11,99,255,0.18); outline-offset:3px; border-radius:6px; }
  .timeline-row{ display:flex; gap:16px; align-items:center; width:100%; flex-wrap:wrap; margin-top:6px; }
  .timeline{ list-style:none; padding:0; margin:0; display:flex; gap:10px; flex:1 1 auto; min-width:0; }
  .timeline li{ padding:8px 12px; border-radius:10px; border:2px solid rgba(0,0,0,0.12); background:#fff; font-weight:700; min-width:110px; text-align:center; white-space:nowrap;}
  .timeline li.done{ background:#e7f8ef; border-color:#a9e2bd;}
  .timeline li.current{ background:#fff4d6; border-color:#ffd47a;}
  .action-buttons{ display:flex; gap:12px; align-items:center; flex:0 0 auto; margin-left:12px; }
  .track-delete{ background:transparent; color:#b00000; border:1px solid rgba(176,0,0,0.12); padding:6px 10px; border-radius:8px; font-weight:700; cursor:pointer; }
  @media (max-width:980px){ .dash-shell{flex-direction:column;} .dash-side{width:100%} .dash-bg{height:220px;} }

  /* attachments */
  .attachment-thumb { width:140px; height:100px; border-radius:8px; overflow:hidden; border:1px solid rgba(0,0,0,0.06); display:inline-block; background:#fff; text-decoration:none; color:inherit; }
  .attachment-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
  .attachment-missing { padding:8px; border-radius:8px; background:#fff6f6; border:1px solid #ffdede; color:#a00; font-weight:700; display:inline-block; }
</style>

<script>
  function confirmDeleteTrack(trackId, complaintId) {
    if (!trackId) return false;
    if (confirm("Delete this tracking entry? This action cannot be undone.")) {
      var form = document.createElement('form'); form.method='POST'; form.style.display='none';
      var a = document.createElement('input'); a.name='action'; a.value='delete_tracking'; form.appendChild(a);
      var b = document.createElement('input'); b.name='track_id'; b.value=trackId; form.appendChild(b);
      var c = document.createElement('input'); c.name='complaint_id'; c.value=complaintId; form.appendChild(c);
      document.body.appendChild(form); form.submit();
    }
    return false;
  }
</script>
</head>
<body class="role-admin">
  <div class="page">

    <!-- background -->
    <div class="dash-bg">
      <img src="<?php echo h($bgUrl); ?>" alt="Background" class="dash-bg-img">
    </div>

    <div class="frame">
      <div class="dash-shell">
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar"><img src="assets/images/user-icon.png" alt="Avatar"></div>

            <!-- clickable admin name -> admindashboard.php -->
            <div class="side-name"><a href="admindashboard.php"><?php echo h($username); ?></a></div>
            <div class="side-role">ADMIN</div>
          </div>
          <nav class="side-nav">
            <a href="admin-all-complaints.php" class="side-link admin-only">CHECK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <main class="dash-main">
          <div class="details-card" role="main">
            <header style="display:flex;justify-content:space-between;align-items:center">
              <div><a href="admin-all-complaints.php" style="text-decoration:none">←</a><h1 style="display:inline;margin-left:8px">COMPLAINT DETAILS</h1></div>
              <div style="display:flex;gap:12px;align-items:center">
                <a href="admin-all-complaints.php" style="padding:8px 12px;background:#081226;color:#fff;border-radius:8px;text-decoration:none;font-weight:800;">View Complaints</a>
                <div>
                  <?php if ($msg_success): ?><div style="background:#e6fbef;padding:8px;border-radius:8px;border:1px solid #c9f0d6;"><?php echo h($msg_success); ?></div><?php endif; ?>
                  <?php if ($msg_err): ?><div style="background:#fff1f1;padding:8px;border-radius:8px;border:1px solid #ffd7d7;"><?php echo h($msg_err); ?></div><?php endif; ?>
                </div>
              </div>
            </header>

            <section style="display:flex;gap:18px;flex-wrap:wrap;margin-top:12px">
              <p><strong>Complaint ID:</strong> <?php echo 'CMP-' . (int)$compl['id']; ?></p>
              <p><strong>Submitted By:</strong> <?php echo h($compl['owner_username']); ?></p>
              <p><strong>Submitted On:</strong> <?php echo h(date('d M, Y H:i', strtotime($compl['created_at']))); ?></p>
              <p><strong>Status:</strong> <?php echo h(ucfirst(str_replace('_',' ', $compl['status']))); ?></p>
            </section>

            <h3 style="margin-top:12px">Title</h3>
            <div style="padding:12px;border:1px solid rgba(0,0,0,0.06);border-radius:8px"><?php echo h($compl['title']); ?></div>

            <h3 style="margin-top:16px">Complaint Progress</h3>
            <form method="post" action="">
              <div class="timeline-row">
                <ol class="timeline" aria-label="Complaint Progress">
                  <li class="<?php echo step_class(1,$currentStep); ?>">Registered</li>
                  <li class="<?php echo step_class(2,$currentStep); ?>">Assigned</li>
                  <li class="<?php echo step_class(3,$currentStep); ?>">In Progress</li>
                  <li class="<?php echo step_class(4,$currentStep); ?>">Action Taken</li>
                  <li class="<?php echo step_class(5,$currentStep); ?>">Resolved</li>
                </ol>

                <div class="action-buttons">
                  <a href="admin-feedback.php?id=<?php echo (int)$compl['id']; ?>" style="border:2px solid #2d84ff;padding:8px 12px;border-radius:8px;text-decoration:none;font-weight:700;color:#2d84ff;">VIEW FEEDBACK</a>
                  <button type="submit" name="action" value="update_status" style="padding:8px 12px;border-radius:8px;background:#0b6b3a;color:#fff;border:none;font-weight:800;">UPDATE STATUS</button>
                </div>
              </div>

              <fieldset style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap" aria-label="Set status">
                <label style="display:inline-flex;align-items:center"><input type="radio" name="status" value="registered" <?php if ($currentStep===1) echo 'checked'; ?>> Registered</label>
                <label style="display:inline-flex;align-items:center"><input type="radio" name="status" value="assigned" <?php if ($currentStep===2) echo 'checked'; ?>> Assigned</label>
                <label style="display:inline-flex;align-items:center"><input type="radio" name="status" value="in_progress" <?php if ($currentStep===3) echo 'checked'; ?>> In Progress</label>
                <label style="display:inline-flex;align-items:center"><input type="radio" name="status" value="action_taken" <?php if ($currentStep===4) echo 'checked'; ?>> Action Taken</label>
                <label style="display:inline-flex;align-items:center"><input type="radio" name="status" value="resolved" <?php if ($currentStep===5) echo 'checked'; ?>> Resolved</label>
              </fieldset>

              <div style="margin-top:12px">
                <label>Add note / update</label>
                <textarea name="note" rows="3" style="width:100%;padding:10px;border-radius:8px;border:1px solid #ddd"></textarea>
              </div>
            </form>

            <h3 style="margin-top:16px">Description</h3>
            <div style="padding:12px;border:1px solid rgba(0,0,0,0.06);border-radius:8px"><?php echo nl2br(h($compl['description'] ?? 'No description provided.')); ?></div>

            <h3 style="margin-top:16px">Attachments</h3>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <?php if (empty($attachments)): ?>
                <div style="padding:12px;border:1px solid rgba(0,0,0,0.06);border-radius:8px">No attachments uploaded.</div>
              <?php else: foreach($attachments as $att): ?>
                <?php if (!empty($att['url']) && $att['exists']): ?>
                  <a class="attachment-thumb" href="<?php echo h($att['url']); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo h($att['url']); ?>" alt="<?php echo h(basename($att['url'])); ?>">
                  </a>
                <?php elseif (!empty($att['url']) && !$att['exists']): ?>
                  <a class="attachment-thumb" href="<?php echo h($att['url']); ?>" target="_blank" rel="noopener" title="Open (may 404)">
                    <img src="assets/images/file-placeholder.png" alt="attachment">
                  </a>
                  <div style="display:block;font-size:12px;color:#a00;margin-top:6px;">(File missing on server: <?php echo h(basename($att['raw'])); ?>)</div>
                <?php else: ?>
                  <div class="attachment-missing">Attachment: <?php echo h(basename($att['raw'])); ?> (unresolved)</div>
                <?php endif; ?>
              <?php endforeach; endif; ?>
            </div>

            <h3 style="margin-top:16px">Tracking History</h3>
            <?php if (empty($tracking)): ?>
              <div style="padding:12px;border:1px solid rgba(0,0,0,0.06);border-radius:8px">No tracking history yet.</div>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:12px;margin-top:8px;">
                <?php foreach($tracking as $t): ?>
                  <div style="padding:12px;border-radius:10px;background:#fff;border:1px solid rgba(0,0,0,0.06);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                    <div style="flex:1 1 70%;min-width:0">
                      <div style="font-weight:800"><?php echo h(ucfirst(str_replace('_',' ',$t['status']))); ?> — <span style="font-weight:600;color:#666"><?php echo h(date('d M, Y H:i', strtotime($t['created_at']))); ?></span></div>
                      <?php if (!empty($t['note'])): ?><div style="margin-top:8px"><?php echo nl2br(h($t['note'])); ?></div><?php endif; ?>
                      <?php if (!empty($t['actor_username'])): ?><div style="color:#666;margin-top:8px">By: <?php echo h($t['actor_username']); ?></div><?php elseif (!empty($t['created_by'])): ?><div style="color:#666;margin-top:8px">By admin id: <?php echo (int)$t['created_by']; ?></div><?php endif; ?>
                    </div>
                    <div>
                      <button class="track-delete" type="button" onclick="confirmDeleteTrack(<?php echo (int)$t['id']; ?>, <?php echo (int)$complaint_id; ?>)">Delete</button>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </main>
      </div>
    </div>
  </div>
</body>
</html>
