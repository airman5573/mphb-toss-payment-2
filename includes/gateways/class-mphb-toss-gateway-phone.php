<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayPhone extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'phone';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('휴대폰 소액결제 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('휴대폰 소액결제', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('휴대폰 소액결제로 간편하게 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'MOBILE_PHONE';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->mobilePhone)) {
            $phoneInfo = $tossResult->mobilePhone;
            update_post_meta($payment->getId(), '_mphb_toss_phone_customer_mobile', $phoneInfo->customerMobilePhone ?? ''); // customerMobilePhone might not be in confirm response.
            update_post_meta($payment->getId(), '_mphb_toss_phone_settlement_status', $phoneInfo->settlementStatus ?? '');
            // The 'receiptUrl' is typically available in $tossResult directly if applicable
            // update_post_meta($payment->getId(), '_mphb_toss_receipt_url', $tossResult->receiptUrl ?? '');
        }
    }
}
