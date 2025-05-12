<?php
/**
 * Plugin Name:       MPHB Toss Payments Gateway
 * ...
 */

if (!defined('WPINC')) {
    exit;
}

define('MPHB_TOSS_PAYMENTS_VERSION', '1.0.0'); // Ensure this is 1.0.0 or your current version
define('MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__);

// Include core files
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-settings-tab.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-checkout-shortcode.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-refund.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/functions.php';
// require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-gateway.php'; // This seems to be an older base or general handler, ensure it doesn't conflict with TossGatewayBase logic. If it's not used, you might comment it out.

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
    // 1. Initialize Toss Payments Global Settings Tab
    if (class_exists('\MPHBTOSS\TossGlobalSettingsTab')) {
        $toss_settings_tab = new \MPHBTOSS\TossGlobalSettingsTab();
        $toss_settings_tab->init();
    }

    // 2. Register Individual Toss Payment Gateway Methods
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayCard')) { new \MPHBTOSS\Gateways\TossGatewayCard(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBank')) { new \MPHBTOSS\Gateways\TossGatewayBank(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayVbank')) { new \MPHBTOSS\Gateways\TossGatewayVbank(); }
    
    // Initialize newly added gateways
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayApplepay')) { new \MPHBTOSS\Gateways\TossGatewayApplepay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayEscrowBank')) { new \MPHBTOSS\Gateways\TossGatewayEscrowBank(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayForeignCard')) { new \MPHBTOSS\Gateways\TossGatewayForeignCard(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayKakaopay')) { new \MPHBTOSS\Gateways\TossGatewayKakaopay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayLpay')) { new \MPHBTOSS\Gateways\TossGatewayLpay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayNpay')) { new \MPHBTOSS\Gateways\TossGatewayNpay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayPayco')) { new \MPHBTOSS\Gateways\TossGatewayPayco(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayPaypal')) { new \MPHBTOSS\Gateways\TossGatewayPaypal(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayPhone')) { new \MPHBTOSS\Gateways\TossGatewayPhone(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewaySamsungpay')) { new \MPHBTOSS\Gateways\TossGatewaySamsungpay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewaySsgpay')) { new \MPHBTOSS\Gateways\TossGatewaySsgpay(); }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayTosspay')) { new \MPHBTOSS\Gateways\TossGatewayTosspay(); }


    // 3. Register common callback handler (only once)
    // Make sure TossGatewayBase is loaded before this action.
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBase')) {
        add_action('init', ['\MPHBTOSS\Gateways\TossGatewayBase', 'handleTossCallbackStatic'], 11);
    }

}, 9);


add_filter('mphb_gateway_has_sandbox', function ($isSandbox, $gatewayId) {
    // This filter might need adjustment if you implement test mode per gateway or globally.
    // For now, returning false for all.
    if (strpos($gatewayId, \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) === 0) {
        // Potentially check global test mode from TossGlobalSettingsTab::is_test_mode()
        // return \MPHBTOSS\TossGlobalSettingsTab::is_test_mode();
        return false; // Or handle sandbox mode based on your plugin's logic
    }
    return $isSandbox;
}, 10, 2); // Adjusted priority to 10
