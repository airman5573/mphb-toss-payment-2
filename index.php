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
 * Handles the MPHB booking status changed action.
 * If a booking is cancelled, attempts to refund associated Toss Payments.
 *
 * @param \MPHB\Entities\Booking $booking The booking object whose status changed.
 * @param string $oldStatus The old status of the booking.
 */
add_action( 'mphb_booking_status_changed', 'mphb_toss_handle_booking_status_changed_hook', 10, 2 );
function mphb_toss_handle_booking_status_changed_hook( \MPHB\Entities\Booking $booking, string $oldStatus ) {
    $log_context = 'mphb_toss_handle_booking_status_changed_hook';
    $bookingId = $booking->getId();
    $newStatus = $booking->getStatus(); // Get the new status from the booking object

    mphb_toss_write_log("MPHB Booking Status Changed Hook. Booking ID: {$bookingId}, Old Status: {$oldStatus}, New Status: {$newStatus}", $log_context);

    // Only proceed if the new status is 'cancelled'
    if ($newStatus !== \MPHB\PostTypes\BookingCPT\Statuses::STATUS_CANCELLED) {
        mphb_toss_write_log("Booking ID: {$bookingId} new status is '{$newStatus}', not 'cancelled'. No Toss refund attempted.", $log_context);
        return;
    }

    mphb_toss_write_log("Booking ID: {$bookingId} was cancelled. Attempting to find and refund associated Toss payments.", $log_context);

    // Find payments associated with this booking
    // MPHB doesn't have a direct PaymentRepository::findAllByBookingId(). We can use WP_Query or get_posts.
    $payment_args = array(
        'post_type'      => MPHB()->postTypes()->payment()->getPostType(),
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'   => '_mphb_booking_id',
                'value' => $bookingId,
            ),
        ),
        'fields'         => 'ids', // Get only post IDs for efficiency
    );

    $payment_ids = get_posts($payment_args);

    if (empty($payment_ids)) {
        mphb_toss_write_log("No payments found associated with cancelled Booking ID: {$bookingId}.", $log_context);
        return;
    }

    $paymentRepository = MPHB()->getPaymentRepository();

    foreach ($payment_ids as $payment_id) {
        $payment = $paymentRepository->findById($payment_id);

        if (!$payment) {
            mphb_toss_write_log("Could not retrieve payment object for Payment ID: {$payment_id} (Booking ID: {$bookingId}). Skipping.", $log_context . '_Warning');
            continue;
        }

        $paymentStatus = $payment->getStatus();
        $paymentGatewayId = $payment->getGatewayId();
        $tossPaymentKey = $payment->getTransactionId();

        mphb_toss_write_log("Checking Payment ID: {$payment->getId()} for Booking ID: {$bookingId}. Status: {$paymentStatus}, Gateway: {$paymentGatewayId}, TxN ID: {$tossPaymentKey}", $log_context);

        // 1. Check if it's a Toss payment
        if (strpos($paymentGatewayId, \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) !== 0) {
            mphb_toss_write_log("Payment ID: {$payment->getId()} is not a Toss payment. Skipping refund.", $log_context);
            continue;
        }

        // 2. Check if it has a Toss PaymentKey
        if (empty($tossPaymentKey)) {
            mphb_toss_write_log("Payment ID: {$payment->getId()} (Toss Payment) has no Transaction ID (PaymentKey). Skipping refund.", $log_context);
            continue;
        }

        // 3. Check if the payment status is one that can typically be refunded
        //    (e.g., Completed or On-Hold). Avoid refunding pending, failed, or already refunded/cancelled payments.
        $refundableStatuses = [
            \MPHB\PostTypes\PaymentCPT\Statuses::STATUS_COMPLETED,
            \MPHB\PostTypes\PaymentCPT\Statuses::STATUS_ON_HOLD,
        ];
        if (!in_array($paymentStatus, $refundableStatuses, true)) {
            mphb_toss_write_log("Payment ID: {$payment->getId()} has status '{$paymentStatus}', which is not typically refundable via this automated flow. Skipping refund.", $log_context);
            continue;
        }
        
        // Assume full refund for this payment if the associated booking is cancelled
        $refundAmount = (float) $payment->getAmount();
        if ($refundAmount <= 0) {
            mphb_toss_write_log("Payment ID: {$payment->getId()} has zero or negative amount ({$refundAmount}). Skipping refund.", $log_context);
            continue;
        }

        $refundLog = sprintf('Associated booking #%d was cancelled. Automatic refund initiated.', $bookingId);
        mphb_toss_write_log("Attempting Toss refund for Payment ID: {$payment->getId()}. Amount: {$refundAmount}. Log: {$refundLog}", $log_context);

        list($success, $message) = mphb_toss_refund($payment, $refundAmount, $refundLog);

        if ($success) {
            mphb_toss_write_log("Successfully processed Toss refund for Payment ID: {$payment->getId()}. Message: {$message}", $log_context);
        } else {
            mphb_toss_write_log("Failed to process Toss refund for Payment ID: {$payment->getId()}. Error: {$message}", $log_context . '_Error');
            // Consider additional admin notification for failed automated refunds
        }
    }
}
