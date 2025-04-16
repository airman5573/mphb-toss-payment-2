<?php
namespace MPHB\Payments\Gateways;

// 워드프레스 & MPHB 코어
use MPHB\Payments\Gateways\Gateway;
use MPHB\Payments\Gateways\Toss\API;
use MPHB\Payments\Gateways\Toss\AdminFieldProvider;
use MPHB\Payments\Gateways\Toss\TossAdminRegistrar;

// 직접 접근 방지
if (!defined('ABSPATH')) {
    exit;
}


class TossGateway extends Gateway {
    const GATEWAY_ID = 'toss';

    protected $clientKey = '';
    protected $secretKey = '';
    protected $enabled = true;

    protected $adminRegistrar;

    public function __construct() {        
        // 의존성이 필요한 서비스 인스턴스 생성
        $adminFieldProvider = new TossAdminFieldProvider($this);
        $this->adminRegistrar = new TossAdminRegistrar($this, $adminFieldProvider, $this->statusChecker);

        parent::__construct(); // 부모 생성자 호출 -> setupProperties 호출됨

        // 훅 등록
        $this->registerHooks();
    }

    protected function initId(): string {
        return self::GATEWAY_ID;
    }

    protected function initDefaultOptions(): array {
        $options = array_merge(parent::initDefaultOptions(), [
            'title'       => __('The Toss Payments Credit Card', 'mphb-toss'),
            'description' => __('Pay with your credit card via Toss Payments.', 'mphb-toss'),
            'client_key'  => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1',
            'secret_key'  => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1',
        ]);
        
        return $options;
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('Toss Payments', 'mphb-toss');
        $this->clientKey = $this->getOption('client_key');
        $this->secretKey = $this->getOption('secret_key');

        // 관리자 설명은 AdminRegistrar 통해 설정
        $this->adminDescription = $this->adminRegistrar->getAdminDescription();

        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    public function processPayment(Booking $booking, Payment $payment): array {
        $payment_id = $payment->getId();
        $booking_id = $booking->getId();

        // redirect to '/toss-checkout' page
        $redirect_url = home_url('/toss-checkout');
        $return_url = add_query_arg(['payment_id' => $payment_id, 'booking_id' => $booking_id], $redirect_url);
        
        wp_redirect($return_url);
        exit;
    }
}