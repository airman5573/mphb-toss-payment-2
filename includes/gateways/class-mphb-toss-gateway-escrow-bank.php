<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayEscrowBank extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'escrow_bank';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('실시간 계좌이체 (에스크로) (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('실시간 계좌이체 (에스크로)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('토스페이먼츠 에스크로를 통해 계좌이체로 안전하게 결제합니다.', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'TRANSFER';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("Escrow Bank Transfer Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->transfer)) {
            $transferInfo = $tossResult->transfer;
            // Escrow status might be part of transfer object or a separate escrow object in future.
            $escrowStatus = $transferInfo->escrowStatus ?? ($tossResult->escrow->status ?? 'N/A'); // Example of checking both
            mphb_toss_write_log(
                "Saving escrow bank transfer info: BankCode: " . ($transferInfo->bankCode ?? 'N/A') . 
                ", SettlementStatus: " . ($transferInfo->settlementStatus ?? 'N/A') .
                ", EscrowStatus: " . $escrowStatus,
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_transfer_bank_code', $transferInfo->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_transfer_settlement_status', $transferInfo->settlementStatus ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_escrow_status', $escrowStatus);
        } else {
            mphb_toss_write_log("Transfer object (for escrow) not found in TossResult.", $log_context . '_Warning');
        }
    }
}
