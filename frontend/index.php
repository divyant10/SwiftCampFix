<?php
session_start();

$isLoggedIn   = isset($_SESSION['username']);
$username     = $isLoggedIn ? $_SESSION['username'] : null;

$loginUrl     = 'login.html';
$signupUrl    = 'signup.html';
$dashboardUrl = 'studentdashboard.php';
$trackUrl     = 'track.php';
$registerUrl  = 'register.php';

// if logged in → correct pages, else → login
$linkComplaints = $isLoggedIn ? $registerUrl  : $loginUrl;
$linkTrack      = $isLoggedIn ? $trackUrl     : $loginUrl;
$linkReport     = $isLoggedIn ? $registerUrl  : $loginUrl;
$linkStatus     = $isLoggedIn ? $trackUrl     : $loginUrl;
$linkConf       = $isLoggedIn ? $registerUrl  : $loginUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>SwiftCampFix — Home</title>

  <!-- Fonts + Styles -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css"/>
</head>

<body>
  <div class="page">
    <div class="frame">
      <!-- HEADER -->
      <header class="topbar">
        <div class="brand">
          <img src="assets/images/logo.png" alt="SwiftCampFix Logo" class="brand-logo">
        </div>

        <!-- NAVIGATION -->
        <nav class="nav">
          <a href="index.php">Home</a>
          <a href="<?= $linkComplaints ?>">Complaints</a>
          <a href="<?= $linkTrack ?>">Track Complaints</a>
        </nav>

        <!-- LOGIN / LOGOUT STATE -->
        <?php if ($isLoggedIn): ?>
          <div class="right-actions" style="display:flex; gap:8px; align-items:center;">
            <span style="font-weight:600;">Hi, <?= htmlspecialchars($username) ?></span>
            <button class="btn-login" onclick="window.location.href='<?= $dashboardUrl ?>'">Dashboard</button>
            <button class="btn-login" onclick="window.location.href='../backend/config/logout.php'">Logout</button>
          </div>
        <?php else: ?>
          <div class="right-actions" style="display:flex; gap:8px;">
            <button class="btn-login" onclick="window.location.href='<?= $loginUrl ?>'">Login</button>
            <button class="btn-login" onclick="window.location.href='<?= $signupUrl ?>'">Sign Up</button>
          </div>
        <?php endif; ?>
      </header>

      <!-- HERO SECTION -->
      <section class="hero">
        <div class="hero-left">
          <h1 class="headline">
            <span class="blk">Report</span>
            <span class="red">Issues</span>
            <span class="blk">Easily</span>
          </h1>
          <p class="subtext">
            Report infrastructure issues, sensitive complaints confidentially and track their status
            with our digital complaint management system.
          </p>

          <!-- FEATURE CARDS -->
          <div class="feature-row">
            <a href="<?= $linkReport ?>" class="feature-card">
              <img src="assets/images/camera-icon.png" class="feat-ico" alt="">
              <div class="feat-text">
                <div class="feat-title">Report Issues</div>
                <div class="feat-sub">Submit complaints with a few clicks</div>
              </div>
            </a>

            <a href="<?= $linkStatus ?>" class="feature-card">
              <img src="assets/images/search-icon.png" class="feat-ico" alt="">
              <div class="feat-text">
                <div class="feat-title">Track Status</div>
                <div class="feat-sub">Check progress easily</div>
              </div>
            </a>

            <a href="<?= $linkConf ?>" class="feature-card">
              <img src="assets/images/lock-icon.png" class="feat-ico" alt="">
              <div class="feat-text">
                <div class="feat-title">Confidentiality</div>
                <div class="feat-sub">Report sensitive issues safely</div>
              </div>
            </a>
          </div>
        </div>

        <div class="hero-right">
          <img src="assets/images/workers.png" alt="Workers & Gears" class="hero-art">
        </div>
      </section>
    </div>
  </div>
</body>
</html>
