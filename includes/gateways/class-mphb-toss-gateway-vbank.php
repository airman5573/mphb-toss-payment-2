<?php
namespace MPHBTOSS\Gateways;

use MPHB\Admin\Fields\FieldFactory;
use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayVbank extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'vbank';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('가상계좌 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('안내되는 가상계좌로 입금하여 결제를 완료합니다.', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'VIRTUAL_ACCOUNT';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        // Base class (parent) handles logging for VIRTUAL_ACCOUNT method and saves common virtual account details.
        $log_context = get_class($this) . '::afterPaymentConfirmation (VBank Child)';
        mphb_toss_write_log("VBank Gateway - Payment ID: " . $payment->getId() . ". Calling parent for VAccount details.", $log_context);
        
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        // If there were any *additional* VBank-specific details not covered by the base class's
        // VIRTUAL_ACCOUNT handling, they would be processed and logged here.
        // For now, the base class covers the standard virtualAccount object fields.
        if (!isset($tossResult->virtualAccount)) {
            mphb_toss_write_log("VBank specific: virtualAccount object was expected but not found in TossResult for Payment ID: " . $payment->getId() . ". This might indicate an issue or an unexpected response structure.", $log_context . '_Warning');
        }
    }
}
