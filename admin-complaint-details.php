<?php
/* ====================================================================
   Admin → Complaint Details (updated)
   - Small UI additions: FEEDBACK button added and Update Status button
     placed on the same row (right aligned) to match your screenshot.
   - No existing features removed.
   - Server-side demo logic remains the same (POST updates status_step).
   =================================================================== */

// ---------- DEV DATA (remove when you wire your DB) -------------------------
$idFromUrl = $_GET['id'] ?? 'CMP-1029';

$complaint = [
  'id'           => $idFromUrl,
  'title'        => 'Library Wi-Fi Down',
  'submitted_by' => 'Divyant Mayank',
  'submitted_on' => '15/6/25',
  'status_step'  => 3                           // 1..5  (registered→resolved)
];

// ---------- Local POST handling (so it updates immediately in demo) ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Normally you would validate CSRF + update DB here.
  $new = isset($_POST['status_step']) ? (int)$_POST['status_step'] : $complaint['status_step'];
  // Clamp to 1..5 to avoid invalid values
  $complaint['status_step'] = max(1, min(5, $new));
  // Optional: you could also change the title IF you later allow editing.
}

// ---------- Helpers ---------------------------------------------------------
$status = (int)$complaint['status_step'];       // current step 1..5

// returns the CSS class for each milestone based on current step
function step_class(int $n, int $current): string {
  if ($n < $current) return 'step done';        // green tick + green connector
  if ($n === $current) return 'step current';   // white dot with green ring
  return 'step todo';                           // grey dot/connector
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Complaint Details — Swift CampFix</title>

  <!-- Your global/base CSS -->
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="studentdashboard.css">
  <link rel="stylesheet" href="admindashboard.css">

  <!-- Page specific CSS for this screen -->
  <link rel="stylesheet" href="complaint-details.css">
  <style>
    /* Small page-only tweaks for the new buttons and label sizing */
    /* Ensure label sizes match screenshot consistency */
    .lbl { font-weight:900; margin:12px 0 8px; font-size:18px; }

    /* Button row: keep in-line with timeline and right aligned */
    .action-buttons {
      display:flex;
      gap:14px;
      justify-content:flex-end;
      align-items:center;
      margin-top:6px;
    }

    /* Feedback button styling (to match existing CTA style) */
    .btn-feedback {
      background:#2b78ff;
      color:#fff;
      padding:8px 18px;
      border-radius:12px;
      font-weight:900;
      text-decoration:none;
      box-shadow:0 8px 0 rgba(0,0,0,0.12);
    }
    .btn-feedback:active { transform:translateY(1px); }

    /* keep the update button consistent */
    .update-btn { background:#2b78ff; color:#fff; padding:10px 16px; border-radius:8px; border:0; font-weight:800; box-shadow:0 8px 0 rgba(0,0,0,0.12); }

    /* Positioning so timeline + buttons sit on the same visual row */
    .timeline-row { display:flex; align-items:center; justify-content:space-between; gap:12px; }

    /* Smaller responsive tweak to avoid crowded layout */
    @media (max-width:980px){
      .action-buttons { justify-content:flex-start; margin-top:12px; }
      .timeline-row{ flex-direction:column; align-items:stretch; gap:12px; }
    }
  </style>
</head>

<body class="role-admin">
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- ================= LEFT SIDEBAR ================= -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar">
              <!-- same icon as other pages -->
              <img src="user-icon.png" alt="Avatar">
            </div>
            <div class="side-name">BHUWAN TIWARI</div>
            <div class="side-role">ADMIN</div>
          </div>

          <nav class="side-nav">
            <a href="admin-all-complaints.php" class="side-link admin-only">CHECK COMPLAINTS</a>
            <a href="#" class="side-link side-logout">LOG OUT</a>
          </nav>
        </aside>

        <!-- ===================== MAIN ===================== -->
        <main class="dash-main details-card">
          <header class="details-head">
            <a href="admin-all-complaints.php" class="back-arrow" aria-label="Back">←</a>
            <h1>COMPLAINT DETAILS</h1>
          </header>

          <!--
            FORM posts back to THIS SAME FILE for the demo.
            In production set action="/admin/update-complaint.php" and redirect
            back after saving.
          -->
          <!-- Status form has id so we can reference it from the Update button -->
          <form id="statusForm" method="post" action="">
            <!-- Complaint Meta -->
            <section class="meta-grid" aria-label="Complaint meta">
              <p><strong>Complaint ID:</strong> <span><?= htmlspecialchars($complaint['id']) ?></span></p>
              <p><strong>Submitted By:</strong> <span><?= htmlspecialchars($complaint['submitted_by']) ?></span></p>
              <p><strong>Submitted On:</strong> <span><?= htmlspecialchars($complaint['submitted_on']) ?></span></p>
            </section>

            <!-- Title (READ-ONLY, not editable) -->
            <label class="lbl">Title</label>
            <div class="readout" aria-readonly="true">
              <?= htmlspecialchars($complaint['title']) ?>
            </div>

            <h3 class="lbl">Complaint Progress:</h3>

            <!-- ===== TIMELINE + ACTIONS (server rendered states) ===== -->
            <div class="timeline-row">
              <ol class="timeline" aria-label="Complaint Progress" style="margin-right:auto">
                <li class="<?= step_class(1,$status) ?>" aria-current="<?= $status===1 ? 'step' : 'false' ?>">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="label">Registered</span>
                </li>
                <li class="<?= step_class(2,$status) ?>" aria-current="<?= $status===2 ? 'step' : 'false' ?>">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="label">Assigned</span>
                </li>
                <li class="<?= step_class(3,$status) ?>" aria-current="<?= $status===3 ? 'step' : 'false' ?>">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="label">In Progress</span>
                </li>
                <li class="<?= step_class(4,$status) ?>" aria-current="<?= $status===4 ? 'step' : 'false' ?>">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="label">Action Taken</span>
                </li>
                <li class="<?= step_class(5,$status) ?>" aria-current="<?= $status===5 ? 'step' : 'false' ?>">
                  <span class="dot" aria-hidden="true"></span>
                  <span class="label">Resolved</span>
                </li>
              </ol>

              <!-- Right aligned action buttons -->
              <div class="action-buttons">
                <!-- FEEDBACK: link to feedback page or anchor (adjust href as needed) -->
                <a href="student-feedback.php?id=<?= urlencode($complaint['id']) ?>" class="btn-feedback" title="View / Add feedback">FEEDBACK</a>

                <!-- Update Status button submits the same form (statusForm) -->
                <button type="submit" form="statusForm" class="update-btn">UPDATE STATUS</button>
              </div>
            </div>

            <!-- ===== Status selection (unchanged) =====
                 Pick a milestone and submit; on POST the server re-renders
                 with the new status so ticks + green line move forward. -->
            <fieldset class="status-picker" style="margin-top:12px">
              <legend class="lbl">Set Status</legend>

              <label class="radio-pill">
                <input type="radio" name="status_step" value="1" <?= $status===1?'checked':'' ?>>
                <span>Registered</span>
              </label>
              <label class="radio-pill">
                <input type="radio" name="status_step" value="2" <?= $status===2?'checked':'' ?>>
                <span>Assigned</span>
              </label>
              <label class="radio-pill">
                <input type="radio" name="status_step" value="3" <?= $status===3?'checked':'' ?>>
                <span>In Progress</span>
              </label>
              <label class="radio-pill">
                <input type="radio" name="status_step" value="4" <?= $status===4?'checked':'' ?>>
                <span>Action Taken</span>
              </label>
              <label class="radio-pill">
                <input type="radio" name="status_step" value="5" <?= $status===5?'checked':'' ?>>
                <span>Resolved</span>
              </label>
            </fieldset>

            <!-- Attachments area (kept as before - render backend images here) -->
            <div style="margin-top:18px;">
              <label class="lbl">Attachments:</label>
              <div class="attachments">
                <!-- Backend should output attachment thumbnails/links here -->
                <!-- Example thumbnail -->
                <a href="attachment-full.jpg" target="_blank" style="display:inline-block; text-decoration:none;">
                  <img src="attachment-thumb.jpg" alt="attachment 1" style="width:120px; height:90px; border-radius:8px; border:3px solid #000;">
                </a>
              </div>
            </div>

          </form>
        </main>
      </div>
    </div>
  </div>
</body>
</html>
