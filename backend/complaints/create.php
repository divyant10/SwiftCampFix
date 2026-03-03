<?php
session_start();

// Only logged-in users can submit
if (!isset($_SESSION['username'])) {
  header("Location: /SwiftCampFix/frontend/login.html");
  exit;
}

include '../config/db.php';

$username    = $_SESSION['username'];
$category    = trim($_POST['category'] ?? '');   // register.php: name="category"
$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');

// --- Validation ---
if ($category === '' || $title === '' || $description === '') {
  echo "<script>alert('⚠️ Please fill in all required fields.'); history.back();</script>";
  exit;
}

// --- Find user_id from username ---
$userQuery = $conn->prepare("SELECT id FROM users WHERE username=?");
$userQuery->bind_param("s", $username);
$userQuery->execute();
$result = $userQuery->get_result();

if ($result->num_rows !== 1) {
  echo "<script>alert('User not found.'); history.back();</script>";
  exit;
}
$user_id = (int)$result->fetch_assoc()['id'];
$userQuery->close();

// --- Handle optional file upload ---
$inputName    = 'photo'; // register.php me <input name="photo" ...> use ho raha hai
$storedPath   = null;    // DB me yahi save hoga: e.g. "complaints/cmp_xxx.png"
$uploadDirDisk = __DIR__ . '/../../uploads/complaints/'; // disk path (C:\xampp\htdocs\SwiftCampFix\uploads\complaints\)

if (!is_dir($uploadDirDisk)) {
  @mkdir($uploadDirDisk, 0775, true);
}

if (!empty($_FILES[$inputName]['name'])) {
  $origName = $_FILES[$inputName]['name'];
  $tmp      = $_FILES[$inputName]['tmp_name'];
  $size     = $_FILES[$inputName]['size'];
  $errCode  = $_FILES[$inputName]['error'];

  if ($errCode === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','gif','webp','pdf'];
    if (!in_array($ext, $allowed, true)) {
      echo "<script>alert('❌ Invalid file type. Allowed: png, jpg, jpeg, gif, webp, pdf'); history.back();</script>";
      exit;
    }
    if ($size > (8 * 1024 * 1024)) { // 8 MB
      echo "<script>alert('❌ File too large. Max 8MB'); history.back();</script>";
      exit;
    }

    $newName  = 'cmp_' . uniqid() . '.' . $ext;
    $diskPath = $uploadDirDisk . $newName;

    if (move_uploaded_file($tmp, $diskPath)) {
      // ✅ DB me sirf RELATIVE path store kar rahe: no leading slash, no SwiftCampFix prefix
      $storedPath = 'complaints/' . $newName;
    } else {
      echo "<script>alert('❌ Failed to move uploaded file.'); history.back();</script>";
      exit;
    }
  } else {
    echo "<script>alert('❌ Upload error (code: {$errCode}).'); history.back();</script>";
    exit;
  }
}

// --- Insert into complaints table ---
// start status as 'registered' (timeline: registered → assigned → in_progress → action_taken → resolved)
$stmt = $conn->prepare("
  INSERT INTO complaints (user_id, title, category, description, attachment, status)
  VALUES (?,?,?,?,?, 'registered')
");
$stmt->bind_param("issss", $user_id, $title, $category, $description, $storedPath);

if ($stmt->execute()) {
  echo "<script>
          alert('✅ Complaint submitted successfully!');
          window.location.href='/SwiftCampFix/frontend/studentdashboard.php';
        </script>";
} else {
  $err = $stmt->error;
  echo "<script>alert('❌ Failed to save complaint: " . htmlspecialchars($err) . "'); history.back();</script>";
}

$stmt->close();
$conn->close();
