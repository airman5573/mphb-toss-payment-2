<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayApplepay extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'applepay';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('Apple Pay (Toss Payments)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('Apple Pay', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('Pay with Apple Pay via Toss Payments.', 'mphb-toss-payments');
    }

    public function getTossMethod(): string {
        return 'CARD'; // Changed from 'APPLEPAY' to 'CARD'
    }

    public function getEasyPayProviderCode(): string {
        return 'APPLEPAY';
    }

    public function getPreferredFlowMode(): string {
        return 'DIRECT';
    }

    public function isEnabled(): bool {
        if (!parent::isEnabled()) {
            return false;
        }

        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

        if (wp_is_mobile()) {
            return (bool) preg_match("/(iPhone|iPad)/i", $userAgent);
        } else {
            return (strpos($userAgent, 'Macintosh') !== false &&
                    strpos($userAgent, 'Safari/') !== false &&
                    strpos($userAgent, 'Chrome/') === false &&
                    strpos($userAgent, 'Edg/') === false);
        }
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->easyPay)) {
            $easyPayInfo = $tossResult->easyPay;
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_provider', $easyPayInfo->provider ?? 'ApplePay');
            update_post_meta($payment->getId(), '_mphb_toss_easy_pay_discount_amount', $easyPayInfo->discountAmount ?? 0);
        } elseif (isset($tossResult->card)) { 
            $cardInfo = $tossResult->card;
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'ApplePay'); // Card company might be Apple Pay specific
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0);
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? '');
        }
    }
}

