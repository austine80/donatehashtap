<?php

// ===== DEBUG LOGGER =====
function log_to_console($message) {
    error_log("[M-PESA DEBUG] " . $message);
}

// ===== HEADERS =====
header('Access-Control-Allow-Origin: https://hashtapsolutions.netlify.app');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('Africa/Nairobi');

// ===== LOAD ENV =====
$consumerKey = getenv('CONSUMER_KEY');
$consumerSecret = getenv('CONSUMER_SECRET');
$shortCode = getenv('SHORTCODE');
$tillnumber = getenv('TILLNUMBER');
$passkey = getenv('PASSKEY');

if (!$consumerKey || !$consumerSecret || !$shortCode || !$tillnumber || !$passkey) {
    log_to_console("FATAL: Missing environment variables.");
    echo json_encode(["error" => "Server misconfigured"]);
    exit;
}

// ===== INPUT VALIDATION =====
$phone = $_POST['phone'] ?? '';
$amount = $_POST['amount'] ?? '';

if (!$phone || !$amount) {
    echo json_encode(["error" => "Phone and amount are required"]);
    exit;
}

// Clean phone
$phone = preg_replace('/[^0-9]/', '', $phone);

if (strlen($phone) == 10 && substr($phone, 0, 1) == "0") {
    $phone = "254" . substr($phone, 1);
} elseif (strlen($phone) == 9) {
    $phone = "254" . $phone;
}

if (!preg_match('/^254(7|1)\d{8}$/', $phone)) {
    echo json_encode(["error" => "Invalid phone format"]);
    exit;
}

// Validate amount
$amount = filter_var($amount, FILTER_VALIDATE_FLOAT);

if (!$amount || $amount < 10) {
    echo json_encode(["error" => "Minimum amount is KES 10"]);
    exit;
}


// Convert to integer (M-Pesa requirement)
$amount = intval($amount);

// ===== TRANSACTION =====
$txn_id = bin2hex(random_bytes(16));

// Ensure storage folder
if (!is_dir('transactions')) {
    mkdir('transactions', 0777, true);
}

// Save transaction
file_put_contents("transactions/{$txn_id}.json", json_encode([
    'phone' => $phone,
    'amount' => $amount,
    'status' => 'pending',
    'created_at' => time()
]));

log_to_console("INIT STK | Phone={$phone} | Amount={$amount} | Txn={$txn_id}");

// ===== CALLBACK =====
$callbackUrl = "https://solutionsbackend-uv0s.onrender.com/callback.php";

// ===== GET ACCESS TOKEN =====
$credentials = base64_encode($consumerKey . ":" . $consumerSecret);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials",
    CURLOPT_HTTPHEADER => ["Authorization: Basic " . $credentials],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);

$tokenResponse = curl_exec($curl);

if (curl_errno($curl)) {
    log_to_console("TOKEN CURL ERROR: " . curl_error($curl));
    echo json_encode(["error" => "Network error"]);
    exit;
}

curl_close($curl);

$response = json_decode($tokenResponse, true);

if (!isset($response['access_token'])) {
    log_to_console("TOKEN FAIL: " . $tokenResponse);
    echo json_encode(["error" => "Auth failed"]);
    exit;
}

$access_token = $response['access_token'];

// ===== STK PUSH =====
$timestamp = date("YmdHis");
$password = base64_encode($shortCode . $passkey . $timestamp);

$payload = [
    "BusinessShortCode" => (int)$shortCode,
    "Password" => $password,
    "Timestamp" => $timestamp,
    "TransactionType" => "CustomerBuyGoodsOnline",
    "Amount" => $amount,
    "PartyA" => $phone,
    "PartyB" => (int)$tillnumber,
    "PhoneNumber" => $phone,
    "CallBackURL" => $callbackUrl,
    "AccountReference" => $txn_id, // IMPORTANT
    "TransactionDesc" => "User Payment"
];

// Send request
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $access_token,
        "Content-Type: application/json"
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);

$stkResponse = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

if (curl_errno($curl)) {
    log_to_console("STK CURL ERROR: " . curl_error($curl));
    echo json_encode(["error" => "Payment request failed"]);
    exit;
}

curl_close($curl);

log_to_console("STK RESPONSE ({$httpCode}): " . $stkResponse);

// ===== RESPONSE =====
$result = json_decode($stkResponse, true);

if (!$result || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        "error" => "Invalid response from M-Pesa",
        "txn_id" => $txn_id
    ]);
    exit;
}

// Attach txn_id for frontend tracking
$result['txn_id'] = $txn_id;

echo json_encode($result);