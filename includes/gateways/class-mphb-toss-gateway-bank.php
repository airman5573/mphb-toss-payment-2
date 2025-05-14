<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayBank extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'bank';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('실시간 계좌이체 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('실시간 계좌이체 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('토스페이먼츠를 통해 계좌이체로 결제합니다.', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'TRANSFER';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("Bank Transfer Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->transfer)) {
            $transferInfo = $tossResult->transfer;
            mphb_toss_write_log(
                "Saving bank transfer info: BankCode: " . ($transferInfo->bankCode ?? 'N/A') . 
                ", SettlementStatus: " . ($transferInfo->settlementStatus ?? 'N/A'),
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_transfer_bank_code', $transferInfo->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_transfer_settlement_status', $transferInfo->settlementStatus ?? '');
        } else {
            mphb_toss_write_log("Transfer object not found in TossResult.", $log_context . '_Warning');
        }
    }
}
