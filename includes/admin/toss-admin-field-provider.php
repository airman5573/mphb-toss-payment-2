<?php
namespace MPHB\Payments\Gateways\Toss\Service;

use MPHB\Admin\Fields\FieldFactory;
use MPHB\Payments\Gateways\TossGateway;

/**
 * 관리자 설정 필드 정의 서비스
 */
class TossAdminFieldProvider {

    private $gateway;

    /**
     * 생성자
     * 
     * @param TossGateway $gateway 토스 게이트웨이 인스턴스
     */
    public function __construct(TossGateway $gateway) {
        $this->gateway = $gateway;
    }

    /**
     * 기본 설정 그룹 필드 생성
     * 
     * @return array 필드 배열
     */
    public function createMainGroupFields(): array {
        return [
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_title", [
                'type' => 'text', 
                'label' => __('Title', 'motopress-hotel-booking'),
                'default' => 'Toss Payments',
                'translatable' => true,
            ]),
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_description", [
                'type' => 'textarea', 
                'label' => __('Description', 'motopress-hotel-booking'),
                'default' => __('Pay with Toss Payments.', 'motopress-hotel-booking'),
                'translatable' => true,
            ]),
        ];
    }

    /**
     * API 설정 그룹 필드 생성
     * 
     * @return array 필드 배열
     */
    public function createApiGroupFields(): array {
        return [
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_client_key", [
                'type' => 'text', 
                'label' => __('Client Key', 'mphb-toss'),
                'default' => '',
                'description' => __('Enter your Toss Payments Client Key.', 'mphb-toss'),
            ]),
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_secret_key", [
                'type' => 'text', 
                'label' => __('Secret Key', 'mphb-toss'),
                'default' => '',
                'description' => __('Enter your Toss Payments Secret Key.', 'mphb-toss'),
            ]),
        ];
    }
}
