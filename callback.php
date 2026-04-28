<?php
// Custom logging function (writes to Render logs only)
function log_to_console($message) {
    error_log("[M-PESA CALLBACK] " . $message);
}

// Always return clean JSON
header("Content-Type: application/json");

// Read raw POST data from Safaricom
$rawInput = file_get_contents("php://input");

// Log raw input for debugging
log_to_console("\n" . str_repeat("=", 50));
log_to_console("INCOMING CALLBACK");
log_to_console("Raw: " . $rawInput);

// Decode JSON
$data = json_decode($rawInput, true);

// Default response to Safaricom
$response = [
    "ResultCode" => 0,
    "ResultDesc" => "Accepted"
];

// Validate JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    log_to_console("❌ ERROR: Invalid JSON");
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid JSON";
    echo json_encode($response);
    exit;
}

// Validate structure
if (!isset($data['Body']['stkCallback'])) {
    log_to_console("❌ ERROR: Invalid callback structure");
    $response["ResultCode"] = 1;
    $response["ResultDesc"] = "Invalid callback format";
    echo json_encode($response);
    exit;
}

$callback = $data['Body']['stkCallback'];
$resultCode = $callback['ResultCode'] ?? 'N/A';
$resultDesc = $callback['ResultDesc'] ?? 'No description';
$merchantRequestId = $callback['MerchantRequestID'] ?? 'N/A';
$checkoutRequestId = $callback['CheckoutRequestID'] ?? 'N/A';

// Log key info
log_to_console("🔑 ResultCode: {$resultCode} | Desc: {$resultDesc}");
log_to_console("🆔 MerchantRequestID: {$merchantRequestId}");
log_to_console("🆔 CheckoutRequestID: {$checkoutRequestId}");

// Handle SUCCESS (ResultCode == 0)
if ($resultCode == 0) {
    log_to_console("✅ PAYMENT SUCCESSFUL — Processing...");

    // Extract metadata
    if (isset($callback['CallbackMetadata']['Item'])) {
        $metadata = $callback['CallbackMetadata']['Item'];
        $details = [];

        foreach ($metadata as $item) {
            $name = $item['Name'] ?? 'Unknown';
            $value = $item['Value'] ?? 'N/A';
            $details[$name] = $value;
        }

        $phone = $details['PhoneNumber'] ?? null;
        $receipt = $details['MpesaReceiptNumber'] ?? null;
        $amount = $details['Amount'] ?? null;

        // Validate required fields
        if ($phone && $receipt && $amount) {
            log_to_console("📱 Phone: {$phone} | 🧾 Receipt: {$receipt} | 💰 Amount: {$amount}");

            // === UPDATE TRANSACTION STATUS ===
            $files = glob('transactions/*.json');
            $found = false;

            foreach ($files as $file) {
                $txn = json_decode(file_get_contents($file), true);
                if ($txn && $txn['status'] === 'pending' && $txn['phone'] == $phone) {
                    // Update to completed
                    $txn['status'] = 'completed';
                    $txn['receipt'] = $receipt;
                    $txn['amount_paid'] = $amount;
                    $txn['completed_at'] = time();

                    file_put_contents($file, json_encode($txn, JSON_PRETTY_PRINT));
                    log_to_console("🟢 Updated: " . basename($file));
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                log_to_console("⚠️ WARNING: No pending transaction found for phone {$phone}");
            }
        } else {
            log_to_console("❌ ERROR: Missing required payment metadata");
        }
    } else {
        log_to_console("❌ ERROR: No CallbackMetadata found");
    }
} else {
    // Handle FAILURE / CANCELLED
    log_to_console("❌ PAYMENT FAILED OR CANCELLED — Code: {$resultCode}");
    
    // Optional: mark any pending transaction as failed
    // (You can implement this if needed)
}

// Final log
log_to_console(str_repeat("=", 50) . "\n");

// Always respond to Safaricom
echo json_encode($response);
?>