<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayNpay extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'npay';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('네이버페이 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('네이버페이', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('네이버페이로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    /**
     * 토스페이먼츠 JS SDK에 전달할 `method` 값을 반환합니다.
     * 네이버페이는 CARD 메소드의 easyPay 옵션으로 처리됩니다.
     */
    public function getTossMethod(): string {
        return 'CARD';
    }

    /**
     * 네이버페이 easyPay 코드를 반환합니다.
     * @return string
     */
    public function getEasyPayProviderCode(): string {
        return 'NAVERPAY';
    }

    /**
     * 네이버페이 결제 시 사용할 flowMode를 반환합니다.
     * 'DIRECT': 네이버페이 앱/페이지 바로 연결 (권장)
     * 'DEFAULT': 통합결제창 (네이버페이 선택 가능).
     * 공식문서에 따르면 flowMode:DEFAULT 일 때 easyPay 파라미터는 통합결제창에 영향을 주지 않을 수 있습니다.
     * 네이버페이를 명시적으로 사용하려면 'DIRECT'가 적합합니다.
     * @return string
     */
    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'NaverPay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card)) { // easyPay 결과가 card 정보로 올 수도 있음
            $cardInfo = $tossResult->card;
            // 네이버페이 결제 시 카드 정보가 반환될 경우, company 필드 등이 '네이버페이' 관련 값으로 올 수 있습니다.
            // 필요하다면 추가 정보를 저장합니다.
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'NaverPay');
        }
    }
}

