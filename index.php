<?php
/**
 * Plugin Name:       MPHB Toss Payments Gateway
 * ...
 */

if (!defined('WPINC')) {
    exit;
}

# 토스페이먼츠 결제 했을때 결제수단 나오도록
# toss-checkout에서 예약 정보 보여주기 + 결제창 먼저 띄우고 취소해도 다시 버튼 누르면 결제할 수 있도록
# 환불해주는 함수 만들기
# booking id와 payment id 둘다 넣지 말고 booking_id & booking_key 이렇게 2개만 쓰자

define('MPHB_TOSS_PAYMENTS_VERSION', '1.0.0');
define('MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__);

// Include core files
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-gateway.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-checkout-shortcode.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-refund.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/functions.php';

// Register Toss Payments Gateway with MPHB
add_action('plugins_loaded', function () {
    new \MPHBTOSS\TossGateway();
}, 9);
