<?php
/**
 * Plugin Name:       MPHB 토스페이먼츠 게이트웨이
 * Plugin URI:        https://shoplic.kr/ (플러그인 정보 페이지 주소)
 * Description:       MotoPress 호텔 예약 플러그인(MPHB)에 토스페이먼츠 결제 시스템을 연동합니다. 카드, 계좌이체, 가상계좌 및 다양한 간편결제를 지원합니다.
 * Version:           1.0.0
 * Requires at least: 6.2 (플러그인이 정상 작동하는 최소 워드프레스 버전)
 * Requires PHP:      7.4 (플러그인이 정상 작동하는 최소 PHP 버전)
 * Author:            Shoplic
 * Author URI:        https://shoplic.kr
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mphb-toss-payments
 * Domain Path:       /languages
 *
 * @package           MPHB_Toss_Payments
 */

// 워드프레스 핵심 파일(WPINC)이 정의되지 않은 경우, 즉 워드프레스를 통해 직접 실행된 것이 아니라면 접근을 차단합니다.
if (!defined('WPINC')) {
    exit;
}

// 플러그인 버전 정보를 상수로 정의합니다.
define('MPHB_TOSS_PAYMENTS_VERSION', '1.0.0');
// 플러그인 디렉토리 경로를 상수로 정의합니다.
define('MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
// 플러그인 디렉토리 URL을 상수로 정의합니다.
define('MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
// 플러그인 메인 파일 경로를 상수로 정의합니다.
define('MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__);

// 핵심 기능 파일들을 포함합니다.
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/functions.php'; // 공용 함수 파일
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php'; // 토스 예외 처리 클래스 파일
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-settings-tab.php'; // 토스 설정 탭 관련 파일
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php'; // 토스 API 연동 클래스 파일
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-checkout-shortcode.php'; // 토스 결제 숏코드 관련 파일
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-refund.php'; // 환불 처리 관련 파일 (이 파일이 포함되었는지 확인)

// 결제 게이트웨이 클래스 파일들을 포함합니다.
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-base.php'; // 토스 게이트웨이 기본 클래스
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-card.php'; // 카드 결제 게이트웨이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-bank.php'; // 계좌이체 게이트웨이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-vbank.php'; // 가상계좌 게이트웨이

// 새로 추가된 결제 게이트웨이 클래스 파일들을 포함합니다.
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-applepay.php'; // 애플페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-escrow-bank.php'; // 에스크로 계좌이체
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-foreign-card.php'; // 해외 카드
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-kakaopay.php'; // 카카오페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-lpay.php'; // 엘페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-npay.php'; // 네이버페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-payco.php'; // 페이코
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-paypal.php'; // 페이팔
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-phone.php'; // 휴대폰 소액결제
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-samsungpay.php'; // 삼성페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-ssgpay.php'; // 쓱페이
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/gateways/class-mphb-toss-gateway-tosspay.php'; // 토스페이(토스머니)


// 워드프레스 플러그인이 로드된 후 실행될 액션을 등록합니다. 우선순위 9.
add_action('plugins_loaded', function () {
    // 플러그인 로드 시작 로그를 기록합니다.
    mphb_toss_write_log('MPHB Toss Payments plugin "plugins_loaded" action hook.', 'PluginInitialization');

    // 1. 토스페이먼츠 전역 설정 탭을 초기화합니다.
    if (class_exists('\MPHBTOSS\TossGlobalSettingsTab')) { // 설정 탭 클래스가 존재하는지 확인
        mphb_toss_write_log('Initializing TossGlobalSettingsTab. Debug mode: ' . (\MPHBTOSS\TossGlobalSettingsTab::is_debug_mode() ? 'Enabled' : 'Disabled'), 'PluginInitialization');
        $toss_settings_tab = new \MPHBTOSS\TossGlobalSettingsTab(); // 설정 탭 객체 생성
        $toss_settings_tab->init(); // 설정 탭 초기화 메소드 호출
    } else {
        mphb_toss_write_log('TossGlobalSettingsTab class NOT FOUND.', 'PluginInitialization_Error');
    }

    // 2. 개별 토스 결제 게이트웨이 메소드를 등록합니다.
    $gateways_to_init = [ // 초기화할 게이트웨이 클래스 이름 배열
        '\MPHBTOSS\Gateways\TossGatewayCard', '\MPHBTOSS\Gateways\TossGatewayBank', '\MPHBTOSS\Gateways\TossGatewayVbank',
        '\MPHBTOSS\Gateways\TossGatewayApplepay', '\MPHBTOSS\Gateways\TossGatewayEscrowBank', '\MPHBTOSS\Gateways\TossGatewayForeignCard',
        '\MPHBTOSS\Gateways\TossGatewayKakaopay', '\MPHBTOSS\Gateways\TossGatewayLpay', '\MPHBTOSS\Gateways\TossGatewayNpay',
        '\MPHBTOSS\Gateways\TossGatewayPayco', '\MPHBTOSS\Gateways\TossGatewayPaypal', '\MPHBTOSS\Gateways\TossGatewayPhone',
        '\MPHBTOSS\Gateways\TossGatewaySamsungpay', '\MPHBTOSS\Gateways\TossGatewaySsgpay', '\MPHBTOSS\Gateways\TossGatewayTosspay',
    ];

    foreach ($gateways_to_init as $gateway_class) { // 각 게이트웨이 클래스에 대해 반복
        if (class_exists($gateway_class)) { // 클래스가 존재하는지 확인
            new $gateway_class(); // 게이트웨이 객체 생성 (생성자에서 필요한 초기화 진행)
        } else {
            mphb_toss_write_log("Gateway class NOT FOUND: {$gateway_class}", 'GatewayInitialization_Error');
        }
    }
    
    // 3. 공용 콜백 핸들러를 등록합니다.
    if (class_exists('\MPHBTOSS\Gateways\TossGatewayBase')) { // 기본 게이트웨이 클래스가 존재하는지 확인
        mphb_toss_write_log('Adding static callback handler for TossGatewayBase.', 'PluginInitialization');
        // 워드프레스 'init' 액션에 토스 콜백을 처리하는 정적 메소드를 연결합니다. 우선순위 11.
        add_action('init', ['\MPHBTOSS\Gateways\TossGatewayBase', 'handleTossCallbackStatic'], 11);
    } else {
        mphb_toss_write_log('TossGatewayBase class NOT FOUND for static callback.', 'PluginInitialization_Error');
    }
}, 9);


// MPHB 게이트웨이가 샌드박스 모드를 지원하는지 여부를 필터링합니다.
// 토스페이먼츠는 전역 설정에서 테스트 모드를 관리하므로, 개별 게이트웨이의 샌드박스 설정을 비활성화합니다.
add_filter('mphb_gateway_has_sandbox', function ($isSandbox, $gatewayId) {
    // 게이트웨이 ID가 토스페이먼츠 접두사로 시작하는 경우
    if (strpos($gatewayId, \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX) === 0) {
        return false; // 토스 게이트웨이는 자체 샌드박스 설정을 사용하지 않음 (항상 false 반환)
    }
    return $isSandbox; // 그 외 게이트웨이는 기존 샌드박스 설정 유지
}, 10, 2);

