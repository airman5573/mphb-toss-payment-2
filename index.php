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
 * Handles the MPHB payment cancelled action to attempt a refund via Toss Payments.
 * It's better to name the hook callback function with a suffix like '_hook' or similar
 * to avoid potential conflicts if 'mphb_toss_handle_mphb_payment_cancelled' is intended for other direct calls.
 *
 * @param \MPHB\Entities\Payment $payment The payment object that was cancelled.
 */
add_action( 'mphb_payment_cancelled', 'mphb_toss_handle_mphb_payment_cancelled_hook', 10, 1 );
function mphb_toss_handle_mphb_payment_cancelled_hook( \MPHB\Entities\Payment $payment ) {
    $log_context = 'mphb_toss_handle_mphb_payment_cancelled_hook'; // Updated context
    $paymentId = $payment->getId();
    mphb_toss_write_log("MPHB Payment Cancelled Hook Triggered. Payment ID: " . $paymentId, $log_context);

    var_dump($paymentId);

    // Check if the payment was made through a Toss gateway
    if (strpos($payment->getGatewayId(), \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) !== 0) {
        mphb_toss_write_log("Payment ID: " . $paymentId . " was not made through Toss Payments. Gateway: " . $payment->getGatewayId() . ". No Toss refund attempted.", $log_context);
        return;
    }

    // Check if there's a transaction ID (Toss PaymentKey)
    $tossPaymentKey = $payment->getTransactionId();
    var_dump($tossPaymentKey);

    if (empty($tossPaymentKey)) {
        mphb_toss_write_log("Payment ID: " . $paymentId . " does not have a Toss PaymentKey (Transaction ID). No Toss refund attempted.", $log_context);
        return;
    }
    
    // It's generally assumed that if a payment is "cancelled" in MPHB, a full refund is intended.
    $refundAmount = (float) $payment->getAmount();

    mphb_toss_write_log("Attempting Toss refund for cancelled MPHB Payment ID: " . $paymentId . ". Amount: " . $refundAmount, $log_context);

    // Call the existing refund function, now passing the Payment object
    list($success, $message) = mphb_toss_refund($payment, $refundAmount, 'MPHB 결제 취소로 인한 자동 환불 처리');

    if ($success) {
        mphb_toss_write_log("Successfully processed Toss refund for MPHB Payment ID: " . $paymentId . ". Message: " . $message, $log_context);
    } else {
        mphb_toss_write_log("Failed to process Toss refund for MPHB Payment ID: " . $paymentId . ". Error: " . $message, $log_context . '_Error');
        // Consider adding more robust error notification for admins here if automated refund fails.
    }
}
