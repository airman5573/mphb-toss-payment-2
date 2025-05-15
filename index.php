<?php
/**
 * Plugin Name:       MPHB Toss Payments Gateway
 * ...
 */

if (!defined('WPINC')) {
    exit;
}

define('MPHB_TOSS_PAYMENTS_VERSION', '1.0.0');
define('MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__);

// Include core files
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/functions.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-settings-tab.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-checkout-shortcode.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-refund.php'; // Ensure this is included

// Include gateway classes
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-base.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-card.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-bank.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-vbank.php';

// Newly added gateways
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-applepay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-escrow-bank.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-foreign-card.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-kakaopay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-lpay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-npay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-payco.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-paypal.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-phone.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-samsungpay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-ssgpay.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-tosspay.php';


add_action('plugins_loaded', function () {
    mphb_toss_write_log('MPHB Toss Payments plugin "plugins_loaded" action hook.', 'PluginInitialization');

    // 1. Initialize Toss Payments Global Settings Tab
    if (class_exists('\MPHBTOSS\TossGlobalSettingsTab')) {
        mphb_toss_write_log('Initializing TossGlobalSettingsTab. Debug mode: ' . (\MPHBTOSS\TossGlobalSettingsTab::is_debug_mode() ? 'Enabled' : 'Disabled'), 'PluginInitialization');
        $toss_settings_tab = new \MPHBTOSS\TossGlobalSettingsTab();
        $toss_settings_tab->init();
    } else {
        mphb_toss_write_log('TossGlobalSettingsTab class NOT FOUND.', 'PluginInitialization_Error');
    }

    // 2. Register Individual Toss Payment Gateway Methods
    $gateways_to_init = [
        '\MPHBTOSS\Gateways\TossGatewayCard', '\MPHBTOSS\Gateways\TossGatewayBank', '\MPHBTOSS\Gateways\TossGatewayVbank',
        '\MPHBTOSS\Gateways\TossGatewayApplepay', '\MPHBTOSS\Gateways\TossGatewayEscrowBank', '\MPHBTOSS\Gateways\TossGatewayForeignCard',
        '\MPHBTOSS\Gateways\TossGatewayKakaopay', '\MPHBTOSS\Gateways\TossGatewayLpay', '\MPHBTOSS\Gateways\TossGatewayNpay',
        '\MPHBTOSS\Gateways\TossGatewayPayco', '\MPHBTOSS\Gateways\TossGatewayPaypal', '\MPHBTOSS\Gateways\TossGatewayPhone',
        '\MPHBTOSS\Gateways\TossGatewaySamsungpay', '\MPHBTOSS\Gateways\TossGatewaySsgpay', '\MPHBTOSS\Gateways\TossGatewayTosspay',
    ];

    foreach ($gateways_to_init as $gateway_class) {
        if (class_exists($gateway_class)) {
            new $gateway_class();
        } else {
            mphb_toss_write_log("Gateway class NOT FOUND: {$gateway_class}", 'GatewayInitialization_Error');
        }
    }
    
    // 3. Register common callback handler
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBase')) {
        mphb_toss_write_log('Adding static callback handler for TossGatewayBase.', 'PluginInitialization');
        add_action('init', ['\MPHBTOSS\Gateways\TossGatewayBase', 'handleTossCallbackStatic'], 11);
    } else {
        mphb_toss_write_log('TossGatewayBase class NOT FOUND for static callback.', 'PluginInitialization_Error');
    }
}, 9);


add_filter('mphb_gateway_has_sandbox', function ($isSandbox, $gatewayId) {
    if (strpos($gatewayId, \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) === 0) {
        return false;
    }
    return $isSandbox;
}, 10, 2);

/**
 * Handles the MPHB booking cancelled action.
 * Attempts to refund associated Toss Payments when a booking is cancelled.
 *
 * @param \MPHB\Entities\Booking $booking The booking object that was cancelled.
 * @param string $oldStatus The status of the booking before it was cancelled.
 */
add_action( 'mphb_booking_cancelled', 'mphb_toss_handle_booking_cancelled_hook', 10, 2 );
function mphb_toss_handle_booking_cancelled_hook( \MPHB\Entities\Booking $booking, string $oldStatus ) {
    function_exists('ray') && ray('mphb_toss_handle_booking_cancelled_hook: Entry', ['booking' => $booking, 'oldStatus' => $oldStatus]);
    $log_context = 'mphb_toss_handle_booking_cancelled_hook';
    $bookingId = $booking->getId();
    $currentStatusForLog = $booking->getStatus();

    function_exists('ray') && ray('Hook mphb_booking_cancelled triggered', [
        'bookingId' => $bookingId,
        'oldStatus' => $oldStatus,
        'currentStatus' => $currentStatusForLog,
    ]);
    mphb_toss_write_log("MPHB Booking Cancelled Hook. Booking ID: {$bookingId}, Old Status: {$oldStatus}, Current Status: {$currentStatusForLog}", $log_context);

    function_exists('ray') && ray('Booking ID: ' . $bookingId . ' was cancelled. Attempting to find and refund associated Toss payments.', ['bookingId' => $bookingId]);
    mphb_toss_write_log("Booking ID: {$bookingId} was cancelled (via mphb_booking_cancelled hook). Attempting to find and refund associated Toss payments.", $log_context);

    // --- 수정된 결제 정보 가져오기 시작 ---
    /** @var \MPHB\Entities\Payment[] $payments */
    $payments = [];
    $paymentRepository = MPHB()->getPaymentRepository(); // PaymentRepository 인스턴스 가져오기

    if ($paymentRepository && method_exists($paymentRepository, 'findAll')) {
        function_exists('ray') && ray('Attempting to find payments using MPHB()->getPaymentRepository()->findAll()', ['bookingId' => $bookingId]);
        // PaymentRepository의 findAll 메소드를 사용하여 booking_id로 결제 검색
        $payments = $paymentRepository->findAll(array('booking_id' => $bookingId));
    } else {
        // PaymentRepository 또는 findAll 메소드를 사용할 수 없는 경우의 대체 로직 (거의 발생하지 않음)
        function_exists('ray') && ray('MPHB()->getPaymentRepository()->findAll() not available. Falling back to get_posts.', ['bookingId' => $bookingId])->red();
        mphb_toss_write_log("MPHB PaymentRepository or findAll method not available. Falling back to get_posts.", $log_context . '_Error');
        $payment_args = array(
            'post_type'      => MPHB()->postTypes()->payment()->getPostType(),
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'   => '_mphb_booking_id',
                    'value' => $bookingId,
                ),
            ),
            'fields'         => 'ids',
        );
        $payment_ids = get_posts($payment_args);
        if (!empty($payment_ids) && $paymentRepository) { // $paymentRepository가 유효한지 다시 확인
            foreach ($payment_ids as $payment_id) {
                $payment_obj = $paymentRepository->findById($payment_id);
                if ($payment_obj) {
                    $payments[] = $payment_obj;
                }
            }
        }
    }
    // --- 수정된 결제 정보 가져오기 끝 ---

    function_exists('ray') && ray('Found payments', ['payments_count' => count($payments), 'payments_data' => $payments, 'bookingId' => $bookingId]);

    if (empty($payments)) {
        function_exists('ray') && ray('No payments found for booking.', ['bookingId' => $bookingId]);
        mphb_toss_write_log("No payments found associated with cancelled Booking ID: {$bookingId}.", $log_context);
        return;
    }

    foreach ($payments as $payment) {
        if (!$payment instanceof \MPHB\Entities\Payment) {
            function_exists('ray') && ray('Invalid payment object encountered in loop', ['item' => $payment, 'bookingId' => $bookingId]);
            mphb_toss_write_log("Invalid payment object encountered for Booking ID: {$bookingId}. Skipping.", $log_context . '_Warning');
            continue;
        }

        function_exists('ray') && ray('Processing payment object', ['payment_id' => $payment->getId(), 'bookingId' => $bookingId]);

        $paymentStatus = $payment->getStatus();
        $paymentGatewayId = $payment->getGatewayId();
        $tossPaymentKey = $payment->getTransactionId();

        function_exists('ray') && ray('Payment details', [
            'paymentId' => $payment->getId(),
            'bookingId' => $bookingId,
            'paymentStatus' => $paymentStatus,
            'paymentGatewayId' => $paymentGatewayId,
            'tossPaymentKey' => $tossPaymentKey,
        ]);
        mphb_toss_write_log("Checking Payment ID: {$payment->getId()} for Booking ID: {$bookingId}. Status: {$paymentStatus}, Gateway: {$paymentGatewayId}, TxN ID: {$tossPaymentKey}", $log_context);

        // 1. Check if it's a Toss payment
        if (strpos($paymentGatewayId, \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) !== 0) {
            function_exists('ray') && ray('Not a Toss payment, skipping.', [
                'paymentId' => $payment->getId(),
                'paymentGatewayId' => $paymentGatewayId,
                'tossPrefix' => \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX,
            ]);
            mphb_toss_write_log("Payment ID: {$payment->getId()} is not a Toss payment. Skipping refund.", $log_context);
            continue;
        }

        // 2. Check if it has a Toss PaymentKey
        if (empty($tossPaymentKey)) {
            function_exists('ray') && ray('Toss PaymentKey (Transaction ID) is empty, skipping.', [
                'paymentId' => $payment->getId(),
            ]);
            mphb_toss_write_log("Payment ID: {$payment->getId()} (Toss Payment) has no Transaction ID (PaymentKey). Skipping refund.", $log_context);
            continue;
        }

        // 3. Check if the payment status is one that can typically be refunded
        $refundableStatuses = [
            \MPHB\PostTypes\PaymentCPT\Statuses::STATUS_COMPLETED,
            \MPHB\PostTypes\PaymentCPT\Statuses::STATUS_ON_HOLD,
        ];
        if (!in_array($paymentStatus, $refundableStatuses, true)) {
            function_exists('ray') && ray('Payment status not refundable via this flow, skipping.', [
                'paymentId' => $payment->getId(),
                'paymentStatus' => $paymentStatus,
                'refundableStatuses' => $refundableStatuses,
            ]);
            mphb_toss_write_log("Payment ID: {$payment->getId()} has status '{$paymentStatus}', which is not typically refundable via this automated flow. Skipping refund.", $log_context);
            continue;
        }

        $refundAmount = (float) $payment->getAmount();
        if ($refundAmount <= 0) {
            function_exists('ray') && ray('Refund amount is zero or negative, skipping.', [
                'paymentId' => $payment->getId(),
                'refundAmount' => $refundAmount,
            ]);
            mphb_toss_write_log("Payment ID: {$payment->getId()} has zero or negative amount ({$refundAmount}). Skipping refund.", $log_context);
            continue;
        }

        $refundLog = sprintf('Associated booking #%d was cancelled. Automatic refund initiated.', $bookingId);
        function_exists('ray') && ray('Attempting Toss refund', [
            'paymentId' => $payment->getId(),
            'refundAmount' => $refundAmount,
            'refundLog' => $refundLog,
            'tossPaymentKey' => $tossPaymentKey,
        ]);
        mphb_toss_write_log("Attempting Toss refund for Payment ID: {$payment->getId()}. Amount: {$refundAmount}. Log: {$refundLog}", $log_context);

        list($success, $message) = mphb_toss_refund($payment, $refundAmount, $refundLog);
        function_exists('ray') && ray('Toss refund result', [
            'paymentId' => $payment->getId(),
            'success' => $success,
            'message' => $message,
        ]);

        if ($success) {
            mphb_toss_write_log("Successfully processed Toss refund for Payment ID: {$payment->getId()}. Message: {$message}", $log_context);
        } else {
            mphb_toss_write_log("Failed to process Toss refund for Payment ID: {$payment->getId()}. Error: {$message}", $log_context . '_Error');
        }
    }
    function_exists('ray') && ray('mphb_toss_handle_booking_cancelled_hook: Exit', ['bookingId' => $bookingId]);
}

