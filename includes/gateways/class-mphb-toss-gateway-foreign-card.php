<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayForeignCard extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'foreign_card';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('해외 발행 신용카드 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('해외 발행 신용카드 (Visa, Master, JCB 등)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('해외에서 발행된 신용카드로 결제합니다. (토스페이먼츠)', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'CARD';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("Foreign Card Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->card)) {
            $cardInfo = $tossResult->card;
            mphb_toss_write_log(
                "Saving foreign card info: Company: " . ($cardInfo->company ?? 'N/A') . 
                ", ApproveNo: " . ($cardInfo->approveNo ?? 'N/A'),
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0);
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_is_foreign', true);
        } else {
            mphb_toss_write_log("Card object (for foreign card) not found in TossResult.", $log_context . '_Warning');
        }
    }
}
