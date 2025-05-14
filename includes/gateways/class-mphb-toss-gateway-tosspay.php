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

    public function getTossMethod(): string {
        return 'CARD'; // This was 'TOSSPAY', but often EasyPays use 'CARD' method with provider code. Confirm with Toss.
                       // If Toss Payments requires 'TOSSPAY' as method, change it back.
    }

    public function getEasyPayProviderCode(): string {
        return 'TOSSPAY';
    }

    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("TossPay Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            mphb_toss_write_log(
                "Saving EasyPay (TossPay) info: Provider: " . ($easyPayInfo->provider ?? 'N/A'),
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'TossPay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card) && $this->getTossMethod() === 'CARD') { // If using CARD method for TossPay
             mphb_toss_write_log("EasyPay object not found, saving Card info as TossPay. Company: " . ($tossResult->card->company ?? 'TossPay'), $log_context);
            $cardInfo = $tossResult->card;
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'TossPay');
        } else {
            mphb_toss_write_log("EasyPay (or relevant for TossPay method) object not found in TossResult.", $log_context . '_Warning');
        }
    }
}
