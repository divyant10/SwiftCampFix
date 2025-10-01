<?php
include("db.php");

// Simple query to check if table is working
$sql = "SELECT * FROM users";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "✅ User Found: " . $row["username"] . " | Role: " . $row["role"] . "<br>";
    }
} else {
    echo "❌ No users found in the table.";
}
?>
