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

    protected function getTossMethod(): string {
        return 'APPLEPAY'; // Or 'CARD' if ApplePay is a flow of CARD method. Assuming 'APPLEPAY' is a distinct method for SDK.
    }

    public function isEnabled(): bool {
        if (!parent::isEnabled()) {
            return false;
        }

        // Apple Pay specific availability check
        // This check should ideally happen where user agent is reliably available.
        // Server-side check might not be 100% accurate for all setups (e.g., caching, proxies).
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false; // Cannot determine without User Agent
        }
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'];

        if (wp_is_mobile()) {
            // Check for iPhone or iPad
            return (bool) preg_match("/(iPhone|iPad)/i", $userAgent);
        } else {
            // Check for Safari on Mac (but not Chrome or other browsers on Mac)
            return (strpos($userAgent, 'Macintosh') !== false &&
                    strpos($userAgent, 'Safari/') !== false &&
                    strpos($userAgent, 'Chrome/') === false &&
                    strpos($userAgent, 'Edg/') === false); // Exclude Edge on Mac too
        }
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        parent::afterPaymentConfirmation($payment, $booking, $tossResult);

        if (isset($tossResult->card)) { // Apple Pay transactions are often reported as card transactions
            $cardInfo = $tossResult->card;
            update_post_meta($payment->getId(), '_mphb_toss_card_company', $cardInfo->company ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_number_masked', $cardInfo->number ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_installment_plan_months', $cardInfo->installmentPlanMonths ?? 0);
            update_post_meta($payment->getId(), '_mphb_toss_card_approve_no', $cardInfo->approveNo ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_type', $cardInfo->cardType ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_card_owner_type', $cardInfo->ownerType ?? '');
        }
        // If Toss API returns specific applePay object:
        // elseif (isset($tossResult->applePay)) {
        //    $applePayInfo = $tossResult->applePay;
        //    // save relevant applePayInfo fields
        // }
    }
}
