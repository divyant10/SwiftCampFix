<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Fetch user by username
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();

        // Verify password
        if (password_verify($password, $row['password'])) {
            session_regenerate_id(true); // security best practice
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];

            // ✅ Redirect based on role
            if ($row['role'] === 'admin') {
                header("Location: /SwiftCampFix/frontend/admindashboard.php");
            } else {
                header("Location: /SwiftCampFix/frontend/studentdashboard.php");
            }
            exit;

        } else {
            echo "<script>alert('Incorrect password'); window.history.back();</script>";
            exit;
        }
    } else {
        echo "<script>alert('User not found'); window.history.back();</script>";
        exit;
    }
}
?>
