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
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("Mobile Phone Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->mobilePhone)) {
            $phoneInfo = $tossResult->mobilePhone;
            mphb_toss_write_log(
                "Saving mobile phone info: SettlementStatus: " . ($phoneInfo->settlementStatus ?? 'N/A'), // customerMobilePhone might not be in confirm response
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_phone_customer_mobile', $phoneInfo->customerMobilePhone ?? ''); 
            update_post_meta($payment->getId(), '_mphb_toss_phone_settlement_status', $phoneInfo->settlementStatus ?? '');
            // Receipt URL is usually in $tossResult->receipt->url if available
            if(isset($tossResult->receipt->url)){
                 update_post_meta($payment->getId(), '_mphb_toss_receipt_url', $tossResult->receipt->url);
            }
        } else {
            mphb_toss_write_log("MobilePhone object not found in TossResult.", $log_context . '_Warning');
        }
    }
}
