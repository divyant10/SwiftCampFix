<?php
session_start();
include "../backend/config/db.php";

// sirf admin allowed (optional but recommended)
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit("Forbidden");
}

$filter = $_GET['filter'] ?? 'all';      // all | pending | resolved
$range  = $_GET['range'] ?? 'all_time';  // all_time | this_month | last_3_months | last_6_months | this_year

// Base query
$sql = "SELECT c.id, c.title, c.status, c.created_at 
        FROM complaints c
        WHERE 1=1";

// Pending = same logic as admin-all-complaints
if ($filter === 'pending') {
    $sql .= " AND (COALESCE(LOWER(c.status),'') NOT IN ('assigned','in_progress','action_taken','resolved') 
              OR c.status IS NULL OR c.status='')";
} elseif ($filter === 'resolved') {
    $sql .= " AND LOWER(c.status)='resolved'";
}

// Date filters
if ($range === 'this_month') {
    $sql .= " AND c.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
} elseif ($range === 'last_3_months') {
    $sql .= " AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($range === 'last_6_months') {
    $sql .= " AND c.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
} elseif ($range === 'this_year') {
    $sql .= " AND YEAR(c.created_at) = YEAR(CURDATE())";
}

// Order by newest
$sql .= " ORDER BY c.created_at DESC";

$result = $conn->query($sql);
if (!$result) {
    die("Query error: " . $conn->error);
}

// CSV headers
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=complaints_" . date("Ymd_His") . ".csv");

$out = fopen("php://output", "w");

// CSV header row - apne columns ke hisaab se yahan add kar sakta hai
fputcsv($out, ['ID', 'Title', 'Status', 'Created At']);

while ($row = $result->fetch_assoc()) {
    fputcsv($out, [
        $row['id'],
        $row['title'],
        $row['status'],
        $row['created_at'],
    ]);
}

fclose($out);
exit;
