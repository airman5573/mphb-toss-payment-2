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

    public function getTossMethod(): string {
        return 'PAYPAL';
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);
        $log_context = get_class($this) . '::afterPaymentConfirmation';
        mphb_toss_write_log("PayPal Gateway - Payment ID: " . $payment->getId(), $log_context);

        if (isset($tossResult->paypal)) {
            $paypalInfo = $tossResult->paypal;
            mphb_toss_write_log(
                "Saving PayPal info: PayerID: " . ($paypalInfo->payerId ?? 'N/A') . 
                ", TransactionID (from PayPal obj): " . ($paypalInfo->transactionId ?? 'N/A'),
                $log_context
            );
            update_post_meta($payment->getId(), '_mphb_toss_paypal_payer_id', $paypalInfo->payerId ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_paypal_transaction_id', $paypalInfo->transactionId ?? ($tossResult->paymentKey ?? ''));
        } elseif (isset($tossResult->foreignCardDetails)) { // Fallback if processed like a foreign card
             mphb_toss_write_log("PayPal object not found, saving ForeignCardDetails as PayPal. Company: " . ($tossResult->foreignCardDetails->company ?? 'PayPal'), $log_context);
             $cardInfo = $tossResult->foreignCardDetails;
             update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? 'PayPal');
        } else {
            mphb_toss_write_log("Neither paypal nor foreignCardDetails object found in TossResult for PayPal. Method: " . ($tossResult->method ?? 'N/A'), $log_context . '_Warning');
            update_post_meta($payment->getId(), '_mphb_toss_payment_method_details', $tossResult->method ?? 'PayPal');
        }
    }
}
