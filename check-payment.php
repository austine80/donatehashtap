<?php
// Allow CORS for your frontend
header('Access-Control-Allow-Origin: https://hashtapsolutions.netlify.app');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Helper: normalize phone (same logic as stkpush.php)
function normalizePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
        $phone = "254" . substr($phone, 1);
    } elseif (strlen($phone) == 9) {
        $phone = "254" . $phone;
    }
    return $phone;
}

// Validate input
$phone = $_GET['phone'] ?? '';
if (!$phone) {
    echo json_encode(['error' => 'Phone number is required']);
    exit;
}

$phone = normalizePhone($phone);
if (strlen($phone) !== 12 || substr($phone, 0, 3) !== "254") {
    echo json_encode(['error' => 'Invalid phone number format']);
    exit;
}

// Find the most recent pending/completed transaction for this phone
$files = glob('transactions/*.json');
if (!$files) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

// Sort by newest first
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$found = false;
foreach ($files as $file) {
    $txn = json_decode(file_get_contents($file), true);
    
    if (!$txn || !isset($txn['phone']) || $txn['phone'] !== $phone) {
        continue;
    }

    $age = time() - ($txn['created_at'] ?? 0);
    if ($age > 1800) { // older than 30 minutes → expired
        unlink($file); // clean up
        continue;
    }

    $found = true;
    $status = $txn['status'] ?? 'pending';

    if ($status === 'completed') {
        echo json_encode(['status' => 'completed']);
        exit;
    } elseif ($status === 'pending') {
        echo json_encode(['status' => 'pending']);
        exit;
    }
    break;
}

if (!$found) {
    echo json_encode(['status' => 'not_found']);
}
?>