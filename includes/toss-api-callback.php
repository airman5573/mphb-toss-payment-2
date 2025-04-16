<?php

use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHB\Payments\Gateways\TossGateway;
use MPHB\Payments\Gateways\Toss\Service\TossAPI;
use MPHB\Payments\Gateways\Toss\TossException;

/**
 * Handles the callback from Toss Payments after successful payment authorization.
 * Confirms the payment with Toss API and updates the booking status.
 */

// Ensure WordPress environment is loaded
// Adjust the path based on your actual WordPress installation structure if needed
$wp_load_path = dirname(__FILE__, 6) . '/wp-load.php'; // Adjust depth as necessary
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    // Fallback or error handling if wp-load.php is not found
    error_log('Could not load wp-load.php from toss-api-callback.php');
    wp_die('WordPress environment could not be loaded.', 'Configuration Error', ['response' => 500]);
}

// Check if MPHB is active
if (!function_exists('MPHB')) {
    wp_die('MotorPress Hotel Booking plugin is not active.', 'Plugin Error', ['response' => 500]);
    exit;
}

// --- Parameter Retrieval ---
$payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$paymentKey = isset($_GET['paymentKey']) ? sanitize_text_field($_GET['paymentKey']) : '';
$tossOrderId = isset($_GET['orderId']) ? sanitize_text_field($_GET['orderId']) : '';
$amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

// --- Basic Validation ---
if (empty($payment_id) || empty($booking_id) || empty($paymentKey) || empty($tossOrderId) || empty($amount)) {
    MPHB()->log()->error(sprintf('[%s] Missing required parameters in Toss callback.', __FILE__), $_GET);
    wp_die(__('Invalid payment callback request. Missing parameters.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 400]);
    exit;
}

// --- Load Gateway and API ---
$gateway = MPHB()->paymentGateways()->getGateway(TossGateway::GATEWAY_ID);

if (!$gateway || !$gateway->isEnabled()) {
    MPHB()->log()->error(sprintf('[%s] Toss Gateway is not enabled or found.', __FILE__));
    wp_die(__('Payment gateway is not available.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 500]);
    exit;
}

$secretKey = $gateway->getOption('secret_key');
$isDebug = MPHB()->settings()->main()->isDebugMode(); // Check if MPHB debug mode is on

try {
    $tossApi = new TossAPI($secretKey, $isDebug);
} catch (\Exception $e) {
    MPHB()->log()->error(sprintf('[%s] Failed to initialize Toss API: %s', __FILE__, $e->getMessage()));
    wp_die(__('Failed to initialize payment service.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 500]);
    exit;
}

// --- Load Booking and Payment ---
$payment = MPHB()->paymentRepository()->findById($payment_id);
$booking = MPHB()->bookingRepository()->findById($booking_id);

if (!$payment || !$booking || $payment->getBookingId() !== $booking->getId()) {
    MPHB()->log()->error(sprintf('[%s] Invalid booking or payment ID in Toss callback. Booking ID: %d, Payment ID: %d', __FILE__, $booking_id, $payment_id));
    wp_die(__('Invalid booking or payment information.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 404]);
    exit;
}

// --- Security and Status Checks ---

// Check if payment is already completed
if ($payment->getStatus() === Payment::STATUS_COMPLETED) {
    MPHB()->log()->info(sprintf('[%s] Payment %d already completed. Redirecting to booking confirmation.', __FILE__, $payment_id));
    wp_safe_redirect($booking->getBookingConfirmationUrl());
    exit;
}

// Verify amount
$expected_amount = round(floatval($payment->getAmount())); // Compare rounded integers (KRW)
if ($expected_amount !== round($amount)) {
    MPHB()->log()->error(sprintf('[%s] Amount mismatch for Payment ID %d. Expected: %s, Received: %s', __FILE__, $payment_id, $expected_amount, $amount));
    // Optionally, cancel the payment attempt with Toss here if possible/needed
    wp_die(__('Payment amount mismatch.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 400]);
    exit;
}

// Verify Order ID (assuming it was generated based on booking/payment IDs)
// IMPORTANT: Adjust this logic based on how orderId is actually generated in your checkout process (e.g., in checkout.php template)
$expectedOrderId = sprintf('mphb-booking-%d-payment-%d', $booking_id, $payment_id); // Example format
if ($tossOrderId !== $expectedOrderId) {
     MPHB()->log()->warning(sprintf('[%s] Toss Order ID mismatch for Payment ID %d. Expected: %s, Received: %s. Proceeding anyway, but check generation logic.', __FILE__, $payment_id, $expectedOrderId, $tossOrderId));
     // Decide if this should be a fatal error or just a warning
     // wp_die(__('Payment order ID mismatch.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 400]);
     // exit;
}


// --- Confirm Payment with Toss API ---
try {
    MPHB()->log()->info(sprintf('[%s] Attempting to confirm Toss payment for Payment ID %d, Toss Order ID %s', __FILE__, $payment_id, $tossOrderId));

    $confirmationResponse = $tossApi->confirmPayment($paymentKey, $tossOrderId, $amount);

    if ($confirmationResponse && isset($confirmationResponse->status) && $confirmationResponse->status === 'DONE') {
        // --- Payment Success ---
        MPHB()->log()->info(sprintf('[%s] Toss payment confirmed successfully for Payment ID %d. Toss Payment Key: %s', __FILE__, $payment_id, $paymentKey));

        // Update Payment Status
        $payment->setStatus(Payment::STATUS_COMPLETED);
        $payment->setTransactionId($paymentKey); // Store Toss Payment Key as transaction ID
        $payment->setGatewayMode($gateway->isTestMode() ? Payment::MODE_TEST : Payment::MODE_LIVE);
        $payment->save();

        // Add Payment Note
        $payment->addNote(sprintf(__('Toss payment completed successfully. Payment Key: %s', 'mphb-toss'), $paymentKey));

        // Update Booking Status (if applicable based on payment)
        // This might trigger confirmation emails etc.
        $booking->confirm(); // This handles status update and potentially emails
        MPHB()->log()->info(sprintf('[%s] Booking ID %d confirmed.', __FILE__, $booking_id));


        // Redirect to Booking Confirmation page
        wp_safe_redirect($booking->getBookingConfirmationUrl());
        exit;

    } else {
        // Confirmation response indicates failure or unexpected status
        $status = $confirmationResponse->status ?? 'UNKNOWN';
        $failReason = $confirmationResponse->failure->message ?? 'Unknown reason';
        MPHB()->log()->error(sprintf('[%s] Toss payment confirmation failed for Payment ID %d. Status: %s, Reason: %s', __FILE__, $payment_id, $status, $failReason));

        // Update payment status to failed
        $payment->setStatus(Payment::STATUS_FAILED);
        $payment->addNote(sprintf(__('Toss payment confirmation failed. Status: %s, Reason: %s', 'mphb-toss'), $status, $failReason));
        $payment->save();

        // Redirect to a failure page or checkout page with an error message
        // TODO: Implement a proper failure redirection URL
        $failure_url = add_query_arg(['payment_status' => 'failed', 'reason' => urlencode($failReason)], $booking->getCheckoutUrl());
        wp_safe_redirect($failure_url);
        exit;
    }

} catch (TossException $e) {
    // API communication error or specific Toss error
    MPHB()->log()->error(sprintf('[%s] Toss API Exception during confirmation for Payment ID %d: [%s] %s', __FILE__, $payment_id, $e->getErrorCode(), $e->getMessage()));

    // Update payment status to failed
    $payment->setStatus(Payment::STATUS_FAILED);
    $payment->addNote(sprintf(__('Toss payment confirmation failed. Error: [%s] %s', 'mphb-toss'), $e->getErrorCode(), $e->getMessage()));
    $payment->save();

    // Redirect to a failure page
    // TODO: Implement a proper failure redirection URL
    $failure_url = add_query_arg(['payment_status' => 'failed', 'reason' => urlencode($e->getMessage())], $booking->getCheckoutUrl());
    wp_safe_redirect($failure_url);
    exit;

} catch (\Exception $e) {
    // General PHP error
    MPHB()->log()->error(sprintf('[%s] General Exception during confirmation for Payment ID %d: %s', __FILE__, $payment_id, $e->getMessage()));

    // Update payment status to failed (optional, might indicate a system issue rather than payment)
    $payment->setStatus(Payment::STATUS_FAILED);
     $payment->addNote(sprintf(__('An unexpected error occurred during payment confirmation: %s', 'mphb-toss'), $e->getMessage()));
    $payment->save();

    wp_die(__('An unexpected error occurred during payment processing.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 500]);
    exit;
}

?>
