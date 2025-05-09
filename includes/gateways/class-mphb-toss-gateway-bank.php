<?php
namespace MPHBTOSS\Gateways;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayBank extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'bank'; // 예: "toss_bank"
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('Toss Payments - Bank Transfer', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('실시간 계좌이체 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('토스페이먼츠를 통해 계좌이체로 결제합니다.', 'mphb-toss-payments');
    }

    protected function getTossMethod(): string {
        return 'TRANSFER'; // 토스페이먼츠 계좌이체 메소드
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        if (isset($tossResult->transfer)) {
            $transferInfo = $tossResult->transfer;
            update_post_meta($payment->getId(), '_mphb_toss_transfer_bank_code', $transferInfo->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_transfer_settlement_status', $transferInfo->settlementStatus ?? '');
        }
    }
}
