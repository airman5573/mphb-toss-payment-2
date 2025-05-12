<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayTosspay extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'tosspay';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('토스페이 (토스머니) (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('토스페이 (토스머니)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('토스페이(토스머니)로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getTossMethod(): string {
        return 'TOSSPAY';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'TossPay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        }
        // Add specific fields if TossPay returns unique data under $tossResult->tosspay or $tossResult->easyPay
    }
}
