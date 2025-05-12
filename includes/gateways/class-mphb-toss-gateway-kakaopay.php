<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayKakaopay extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'kakaopay';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('카카오페이 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('카카오페이', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('카카오페이로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'CARD'; // Changed from 'KAKAOPAY' to 'CARD'
    }

    public function getEasyPayProviderCode(): string {
        return 'KAKAOPAY';
    }

    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'KakaoPay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card)) { 
            $cardInfo = $tossResult->card;
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'KakaoPay');
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? '');
        }
    }
}

