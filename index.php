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
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-settings-tab.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-checkout-shortcode.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-refund.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/functions.php';

// Include gateway classes
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-base.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-card.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-bank.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-vbank.php';
// 만약 더 많은 결제 수단을 추가한다면 여기에 require_once를 추가합니다. (예: 휴대폰, 간편결제 등)

add_action('plugins_loaded', function () {
    // 1. Initialize Toss Payments Global Settings Tab
    if (class_exists('\MPHBTOSS\TossGlobalSettingsTab')) {
        $toss_settings_tab = new \MPHBTOSS\TossGlobalSettingsTab();
        $toss_settings_tab->init();
    }

    // 2. Register Individual Toss Payment Gateway Methods
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayCard')) {
        new \MPHBTOSS\Gateways\TossGatewayCard();
    }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBank')) {
        new \MPHBTOSS\Gateways\TossGatewayBank();
    }
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayVbank')) {
        new \MPHBTOSS\Gateways\TossGatewayVbank();
    }
    // 여기에 다른 게이트웨이 인스턴스 생성 코드를 추가합니다.

    // 3. Register common callback handler (only once)
    // Make sure TossGatewayBase is loaded before this action.
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBase')) {
        add_action('init', ['\MPHBTOSS\Gateways\TossGatewayBase', 'handleTossCallbackStatic'], 11);
    }

}, 9);


add_filter('mphb_gateway_has_sandbox', function ($isSandbox, $gatewayId) {
    return false;
}, 2, 9999);