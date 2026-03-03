<?php
// DEBUG (optional during dev)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// 1) DB connect (this file is in backend/config alongside db.php)
include 'db.php';

// 2) Accept only POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Direct access? Send back to home
    header("Location: /SwiftCampFix/frontend/index.php");
    exit;
}

// 3) Inputs
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$confirm_password = trim($_POST['confirm_password'] ?? '');

// 4) Basic validations
if ($username === '' || $password === '' || $confirm_password === '') {
    echo "<script>alert('All fields are required.'); window.history.back();</script>";
    exit;
}

if ($password !== $confirm_password) {
    echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
    exit;
}

// (Optional) username rule: 3–20 chars, letters/numbers/._-
// If you want to enforce:
// if (!preg_match('/^[A-Za-z0-9._-]{3,20}$/', $username)) {
//     echo "<script>alert('Username must be 3-20 chars (letters, numbers, . _ -).'); window.history.back();</script>";
//     exit;
// }

// 5) Check if username exists (efficient: select only id)
$checkUser = $conn->prepare("SELECT id FROM users WHERE username = ?");
$checkUser->bind_param("s", $username);
$checkUser->execute();
$checkUser->store_result();

if ($checkUser->num_rows > 0) {
    $checkUser->close();
    echo "<script>alert('Username already exists. Choose another.'); window.history.back();</script>";
    exit;
}
$checkUser->close();

// 6) Hash password and insert
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$insertUser = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'user')");
$insertUser->bind_param("ss", $username, $hashedPassword);

if ($insertUser->execute()) {
    $insertUser->close();
    $conn->close();
    // ✅ Success: go to LOGIN page
    header("Location: /SwiftCampFix/frontend/login.html");
    exit;
} else {
    $err = $insertUser->error;
    $insertUser->close();
    $conn->close();
    echo "<script>alert('Something went wrong. Please try again.'); window.history.back();</script>";
    exit;
}
