<?php
session_start();

// Redirect if user not logged in
if (!isset($_SESSION['username'])) {
  header("Location: login.html");
  exit;
}

$username = $_SESSION['username'];
$role = "STUDENT";  // 🔥 FIXED — Always show STUDENT
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Register Complaint — Swift CampFix</title>

  <!-- global + dashboard styles -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/studentdashboard.css">
  <!-- page-specific -->
  <link rel="stylesheet" href="assets/css/register.css">

  <style>
    body {
      background: url("assets/images/dash-bg.png") no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      margin: 0;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }
    .f-row { margin-bottom:12px; }
    .hidden { display:none; }
  </style>
</head>

<body>
  <div class="page">
    <div class="frame">
      <div class="dash-shell">
        <div class="dash-glow" aria-hidden="true"></div>

        <!-- ========== SIDEBAR ========== -->
        <aside class="dash-side">
          <div class="side-card">
            <div class="side-avatar">
              <img src="assets/images/user-icon.png" alt="User Avatar">
            </div>

            <div class="side-name">
              <a href="studentdashboard.php" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($username); ?></a>
            </div>

            <!-- 🔥 USER replaced with STUDENT -->
            <div class="side-role">STUDENT</div>
          </div>

          <nav class="side-nav">
            <a href="register.php" class="side-link active">REGISTER COMPLAINTS</a>
            <a href="student-all-complaints.php" class="side-link">VIEW COMPLAINTS</a>
            <a href="track.php" class="side-link">TRACK COMPLAINTS</a>
            <a href="../backend/config/logout.php" class="side-link side-logout" style="color:#f44336;">LOG OUT</a>
          </nav>
        </aside>

        <!-- ========== MAIN CONTENT ========== -->
        <main class="register-main">
          <h1 class="register-title">REGISTER COMPLAINT</h1>

          <form class="complaint-card" action="../backend/complaints/create.php" method="POST" enctype="multipart/form-data" autocomplete="off">

            <div class="f-row">
              <label class="f-label" for="c-type">Type:</label>
              <div class="f-control">
                <select id="c-type" name="category" class="f-input f-select" required>
                  <option value="" disabled selected>Select Type</option>
                  <option value="Electrical">Electrical</option>
                  <option value="Plumbing">Plumbing</option>
                  <option value="Furniture">Furniture</option>
                  <option value="Wi-Fi / Internet">Wi-Fi / Internet</option>
                  <option value="Other">Other</option>
                </select>
                <span class="chev">▾</span>
              </div>
            </div>

            <div class="f-row hidden" id="location-row">
              <label class="f-label" for="c-location">Location (Floor / Room):</label>
              <div class="f-control">
                <select id="c-location" name="location" class="f-input f-select">
                  <option value="" disabled selected>Select Location</option>
                  <option value="Ground Floor">Ground Floor</option>
                  <option value="G1">G1</option>
                  <option value="G2">G2</option>
                  <option value="First Floor">First Floor</option>
                  <option value="F1">F1</option>
                  <option value="F2">F2</option>
                  <option value="F3">F3</option>
                  <option value="Second Floor">Second Floor</option>
                  <option value="S1">S1</option>
                  <option value="S2">S2</option>
                  <option value="S3">S3</option>
                  <option value="S4">S4</option>
                  <option value="Third Floor">Third Floor</option>
                  <option value="T1">T1</option>
                  <option value="T2">T2</option>
                  <option value="T3">T3</option>
                  <option value="Staff Room">Staff Room</option>
                  <option value="Canteen">Canteen</option>
                  <option value="Auditorium">Auditorium</option>
                </select>
                <span class="chev">▾</span>
              </div>
            </div>

            <div class="f-row">
              <label class="f-label" for="c-title">Title:</label>
              <input id="c-title" name="title" type="text" class="f-input" placeholder="Enter a short title" required>
            </div>

            <div class="f-row">
              <label class="f-label" for="c-desc">Description:</label>
              <textarea id="c-desc" name="description" class="f-input f-textarea" rows="4" placeholder="Describe the issue..." required></textarea>
            </div>

            <div class="f-row">
              <label class="f-label" for="c-photo">Attach Photo:</label>
              <input id="c-photo" name="photo" type="file" class="f-input f-file" accept="image/*">
            </div>

            <div class="f-row">
              <button type="submit" class="btn-submit">SUBMIT</button>
            </div>

          </form>
        </main>
      </div>
    </div>
  </div>

<script>
(function(){
  let typeSelect = document.getElementById('c-type');
  let locRow = document.getElementById('location-row');
  let locSelect = document.getElementById('c-location');

  function toggleLocation() {
    if (typeSelect.value !== "") {
      locRow.classList.remove("hidden");
      locSelect.required = true;
    } else {
      locRow.classList.add("hidden");
      locSelect.required = false;
      locSelect.selectedIndex = 0;
    }
  }

  document.addEventListener("DOMContentLoaded", toggleLocation);
  typeSelect.addEventListener("change", toggleLocation);
})();
</script>

</body>
</html>
