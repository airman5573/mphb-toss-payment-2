<?php
namespace MPHBTOSS\Gateways;

use MPHB\Entities\Payment;
use MPHB\Entities\Booking;

if (!defined('ABSPATH')) {
    exit;
}

class TossGatewayPaypal extends TossGatewayBase {

    protected function initId(): string {
        return self::MPHB_GATEWAY_ID_PREFIX . 'paypal';
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('페이팔 (토스페이먼츠)', 'mphb-toss-payments');
    }

    protected function getDefaultTitle(): string {
        return __('페이팔 (PayPal)', 'mphb-toss-payments');
    }

    protected function getDefaultDescription(): string {
        return __('페이팔을 통해 결제합니다. (토스페이먼츠 연동)', 'mphb-toss-payments');
    }

    protected function getTossMethod(): string {
        // This method string needs to be confirmed from Toss Payments documentation for PayPal integration.
        // It could be 'PAYPAL' or might be processed as a 'CARD' type transaction by Toss.
        // Assuming 'PAYPAL' for now.
        return 'PAYPAL';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        // Toss API response for PayPal might be generic or have specific fields.
        // Example:
        if (isset($tossResult->paypal)) { // If Toss returns a specific 'paypal' object
            $paypalInfo = $tossResult->paypal;
            update_post_meta($payment->getId(), '_mphb_toss_paypal_payer_id', $paypalInfo->payerId ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_paypal_transaction_id', $paypalInfo->transactionId ?? ($tossResult->paymentKey ?? ''));
        } elseif (isset($tossResult->foreignCardDetails)) { // If processed like a foreign card
             $cardInfo = $tossResult->foreignCardDetails;
             update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'PayPal');
        } else {
            // Generic logging if specific fields are not known
            update_post_meta($payment->getId(), '_mphb_toss_payment_method_details', $tossResult->method ?? 'PayPal');
        }
    }
}
