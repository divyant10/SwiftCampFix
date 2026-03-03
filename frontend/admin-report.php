<?php
// admin-report.php
session_start();

// FOR LOCAL DEBUG: show errors (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../backend/config/db.php'; // adjust path if needed

// auth
if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.html");
    exit();
}

function h($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function validate_range($r){
    $ok = ['this_month','last_3_months','last_6_months','this_year',''];
    return in_array($r, $ok, true) ? $r : '';
}
function validate_filter($f){
    $ok = ['all','pending','resolved'];
    return in_array($f, $ok, true) ? $f : 'all';
}

$filter = validate_filter( strtolower(trim($_GET['filter'] ?? 'all')) );
$range  = validate_range( strtolower(trim($_GET['range'] ?? '')) );

$whereParts = [];
if ($filter === 'pending') {
    $whereParts[] = "(COALESCE(LOWER(status),'') NOT IN ('assigned','in_progress','action_taken','resolved') OR status IS NULL OR status = '')";
} elseif ($filter === 'resolved') {
    $whereParts[] = "LOWER(status) = 'resolved'";
}

if ($range === 'this_month') {
    $whereParts[] = "created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
} elseif ($range === 'last_3_months') {
    $whereParts[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
} elseif ($range === 'last_6_months') {
    $whereParts[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
} elseif ($range === 'this_year') {
    $whereParts[] = "YEAR(created_at) = YEAR(CURDATE())";
}

$where = '';
if (!empty($whereParts)) {
    $where = 'WHERE ' . implode(' AND ', $whereParts);
}

$sql = "SELECT c.id, c.title, c.category, COALESCE(c.status,'') as status, c.description, c.attachment, c.created_at, u.username
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        {$where}
        ORDER BY c.created_at DESC";
$res = $conn->query($sql);
if ($res === false) {
    http_response_code(500);
    echo "Database error: " . h($conn->error);
    exit();
}
$rows = $res->fetch_all(MYSQLI_ASSOC);

// path to composer autoload (adjust if your vendor lives elsewhere)
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
$hasDompdf = file_exists($autoloadPath);

if ($hasDompdf) {
    // require composer autoload
    require_once $autoloadPath;

    // instantiate Dompdf classes using fully-qualified names (avoid 'use' inside block)
    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);

        // build HTML
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Report</title>
            <style>
              body{font-family: DejaVu Sans, Arial, sans-serif; font-size:12px;}
              table{width:100%; border-collapse:collapse; margin-top:12px;}
              th,td{border:1px solid #ddd; padding:8px; text-align:left;}
              th{background:#f4f4f4; font-weight:800;}
              h1{font-size:18px;}
              .meta{font-size:12px;color:#666;}
            </style>
            </head><body>';
        $html .= '<h1>Complaints Report</h1>';
        $html .= '<div class="meta">Filter: ' . h(ucfirst($filter)) . ' | Range: ' . ($range?:'All time') . ' | Generated: ' . date('d M Y H:i') . '</div>';

        if (count($rows) === 0) {
            $html .= '<p style="margin-top:18px;font-weight:700;">No complaints found for selected filter/range.</p>';
        } else {
            $html .= '<table><thead><tr>
                        <th>#</th>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Submitted On</th>
                        <th>Attachment</th>
                        </tr></thead><tbody>';
            $i = 1;
            foreach ($rows as $r) {
                $att = $r['attachment'] ? h($r['attachment']) : '-';
                $html .= '<tr>';
                $html .= '<td>' . $i++ . '</td>';
                $html .= '<td>CMP-' . (int)$r['id'] . '</td>';
                $html .= '<td>' . h($r['title']) . '</td>';
                $html .= '<td>' . h($r['category']) . '</td>';
                $html .= '<td>' . h($r['status'] ?: 'pending') . '</td>';
                $html .= '<td>' . h($r['username'] ?? '-') . '</td>';
                $html .= '<td>' . h(date('d/m/Y H:i', strtotime($r['created_at']))) . '</td>';
                $html .= '<td>' . $att . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</body></html>';

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = "report_{$filter}_" . ($range?:'alltime') . '_' . date('Ymd_His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dompdf->output();
        exit();
    } catch (Exception $e) {
        error_log("Dompdf error: " . $e->getMessage());
        // fall through to CSV fallback
    }
}

// fallback: CSV download
$filename = "report_{$filter}_" . ($range?:'alltime') . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');
fputcsv($out, ['#','Complaint ID','Title','Category','Status','Submitted By','Submitted On','Attachment']);
$i = 1;
foreach ($rows as $r) {
    fputcsv($out, [
        $i++,
        'CMP-' . (int)$r['id'],
        $r['title'],
        $r['category'],
        $r['status'] ?: 'pending',
        $r['username'] ?? '',
        date('d/m/Y H:i', strtotime($r['created_at'])),
        $r['attachment'] ?? ''
    ]);
}
fclose($out);
exit();
