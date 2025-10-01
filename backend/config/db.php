<?php
// Database configuration
$host     = "localhost";   // XAMPP default host
$username = "root";        // XAMPP default user
$password = "";            // XAMPP default password (blank hota hai)
$database = "swiftcampfix_db";  // Tumhara DB name

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
} else {
    // (Optional) echo for testing only, baad me hata dena
    // echo "✅ Database connected successfully!";
}
?>
