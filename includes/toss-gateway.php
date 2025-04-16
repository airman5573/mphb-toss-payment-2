<?php
namespace MPHB\Payments\Gateways;

// 워드프레스 & MPHB 코어
use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHB\Payments\Gateways\Gateway;
use MPHB\Payments\Gateways\Toss\TossAPI;
// Removed use statement for non-existent TossException

// 직접 접근 방지
if (!defined('ABSPATH')) {
    exit;
}


class TossGateway extends Gateway {
    const GATEWAY_ID = 'toss';

    protected $clientKey = '';
    protected $secretKey = '';
    protected $enabled = true;

    // protected $adminRegistrar;

    public function __construct() {        
        parent::__construct(); // 부모 생성자 호출 -> setupProperties 호출됨

        // 훅 등록 (registerHooks 호출 추가)
        $this->registerHooks();
    }

    /**
     * Register WordPress hooks.
     */
    protected function registerHooks(): void {
        add_action('init', [$this, 'handle_toss_callback']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']); // Moved from setupProperties
    }


    protected function initId(): string {
        return self::GATEWAY_ID;
    }

    protected function initDefaultOptions(): array {
        $options = array_merge(parent::initDefaultOptions(), [
            'title'       => __('The Toss Payments Credit Card', 'mphb-toss'),
            'description' => __('Pay with your credit card via Toss Payments.', 'mphb-toss'),
            'client_key'  => 'test_ck_ma60RZblrqo5YwQmZd6z3wzYWBn1',
            'secret_key'  => 'test_sk_6BYq7GWPVv2Ryd2QGEm4VNE5vbo1',
        ]);
        
        return $options;
    }

    protected function setupProperties(): void {
        parent::setupProperties();
        $this->adminTitle = __('Toss Payments', 'mphb-toss');
        $this->clientKey = $this->getOption('client_key');
        $this->secretKey = $this->getOption('secret_key');

        // 관리자 설명은 AdminRegistrar 통해 설정
        // $this->adminDescription = $this->adminRegistrar->getAdminDescription();

        // Note: wp_enqueue_scripts hook moved to registerHooks
    }

    /**
     * Enqueue necessary scripts.
     * Placeholder - implement if needed.
     */
    public function enqueueScripts() {
        // Enqueue scripts specific to Toss checkout if necessary
    }

    public function processPayment(Booking $booking, Payment $payment): array {
        $payment_id = $payment->getId();
        $booking_id = $booking->getId();

        // redirect to '/toss-checkout' page
        $redirect_url = home_url('/toss-checkout');
        $return_url = add_query_arg(['payment_id' => $payment_id, 'booking_id' => $booking_id], $redirect_url);
        
        wp_redirect($return_url);
        exit;
    }

    /**
     * Handles the callback from Toss Payments after successful payment authorization.
     * Hooked to 'init'.
     */
    public function handle_toss_callback() {
        // Check if this is a Toss callback request
        if (!isset($_GET['mphb_toss_return']) || $_GET['mphb_toss_return'] !== '1' || !isset($_GET['paymentKey'])) {
            return; // Not our request, bail early
        }

        // Check if gateway is enabled
        if (!$this->isEnabled()) {
             error_log('[MPHB Toss] Callback received but gateway is disabled.');
             // Optionally redirect to a generic error page or home
             // wp_safe_redirect(home_url('/')); exit;
             return; // Or just stop processing
        }


        // --- Parameter Retrieval ---
        $payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
        $booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
        $paymentKey = isset($_GET['paymentKey']) ? sanitize_text_field($_GET['paymentKey']) : '';
        $tossOrderId = isset($_GET['orderId']) ? sanitize_text_field($_GET['orderId']) : '';
        $amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

        // --- Basic Validation ---
        if (empty($payment_id) || empty($booking_id) || empty($paymentKey) || empty($tossOrderId) || empty($amount)) {
            MPHB()->log()->error(sprintf('[%s] Missing required parameters in Toss callback.', __METHOD__), $_GET);
            wp_die(__('Invalid payment callback request. Missing parameters.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 400]);
            exit;
        }

        // --- Load API ---
        $secretKey = $this->getOption('secret_key');
        $isDebug = MPHB()->settings()->main()->isDebugMode();

        try {
            // Use the imported TossAPI class
            $tossApi = new TossAPI($secretKey, $isDebug);
        } catch (\Exception $e) {
            MPHB()->log()->error(sprintf('[%s] Failed to initialize Toss API: %s', __METHOD__, $e->getMessage()));
            wp_die(__('Failed to initialize payment service.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 500]);
            exit;
        }

        // --- Load Booking and Payment ---
        $payment = MPHB()->paymentRepository()->findById($payment_id);
        $booking = MPHB()->bookingRepository()->findById($booking_id);

        if (!$payment || !$booking || $payment->getBookingId() !== $booking->getId()) {
            MPHB()->log()->error(sprintf('[%s] Invalid booking or payment ID in Toss callback. Booking ID: %d, Payment ID: %d', __METHOD__, $booking_id, $payment_id));
            wp_die(__('Invalid booking or payment information.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 404]);
            exit;
        }

        // --- Security and Status Checks ---

        // Check if payment is already completed
        if ($payment->getStatus() === Payment::STATUS_COMPLETED) {
            MPHB()->log()->info(sprintf('[%s] Payment %d already completed. Redirecting to booking confirmation.', __METHOD__, $payment_id));
            wp_safe_redirect($booking->getBookingConfirmationUrl());
            exit;
        }

        // Verify amount
        $expected_amount = round(floatval($payment->getAmount())); // Compare rounded integers (KRW)
        if ($expected_amount !== round($amount)) {
            MPHB()->log()->error(sprintf('[%s] Amount mismatch for Payment ID %d. Expected: %s, Received: %s', __METHOD__, $payment_id, $expected_amount, $amount));
            wp_die(__('Payment amount mismatch.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 400]);
            exit;
        }

        // Verify Order ID (adjust based on actual generation)
        $expectedOrderId = sprintf('mphb-booking-%d-payment-%d', $booking_id, $payment_id); // Example format
        if ($tossOrderId !== $expectedOrderId) {
             MPHB()->log()->warning(sprintf('[%s] Toss Order ID mismatch for Payment ID %d. Expected: %s, Received: %s. Proceeding anyway, but check generation logic.', __METHOD__, $payment_id, $expectedOrderId, $tossOrderId));
             // Consider if this should be fatal
        }

        // --- Confirm Payment with Toss API ---
        try {
            MPHB()->log()->info(sprintf('[%s] Attempting to confirm Toss payment for Payment ID %d, Toss Order ID %s', __METHOD__, $payment_id, $tossOrderId));

            $confirmationResponse = $tossApi->confirmPayment($paymentKey, $tossOrderId, $amount);

            if ($confirmationResponse && isset($confirmationResponse->status) && $confirmationResponse->status === 'DONE') {
                // --- Payment Success ---
                MPHB()->log()->info(sprintf('[%s] Toss payment confirmed successfully for Payment ID %d. Toss Payment Key: %s', __METHOD__, $payment_id, $paymentKey));

                $payment->setStatus(Payment::STATUS_COMPLETED);
                $payment->setTransactionId($paymentKey);
                $payment->setGatewayMode($this->isTestMode() ? Payment::MODE_TEST : Payment::MODE_LIVE); // Use $this
                $payment->save();
                $payment->addNote(sprintf(__('Toss payment completed successfully. Payment Key: %s', 'mphb-toss'), $paymentKey));

                $booking->confirm();
                MPHB()->log()->info(sprintf('[%s] Booking ID %d confirmed.', __METHOD__, $booking_id));

                wp_safe_redirect($booking->getBookingConfirmationUrl());
                exit;

            } else {
                // --- Payment Failure (Confirmation Response) ---
                $status = $confirmationResponse->status ?? 'UNKNOWN';
                $failReason = $confirmationResponse->failure->message ?? 'Unknown reason';
                MPHB()->log()->error(sprintf('[%s] Toss payment confirmation failed for Payment ID %d. Status: %s, Reason: %s', __METHOD__, $payment_id, $status, $failReason));

                $payment->setStatus(Payment::STATUS_FAILED);
                $payment->addNote(sprintf(__('Toss payment confirmation failed. Status: %s, Reason: %s', 'mphb-toss'), $status, $failReason));
                $payment->save();

                $failure_url = add_query_arg(['payment_status' => 'failed', 'reason' => urlencode($failReason)], $booking->getCheckoutUrl());
                wp_safe_redirect($failure_url);
                exit;
            }

        // Removed specific catch for non-existent TossException. 
        // The generic \Exception catch below will handle API errors.

        } catch (\Exception $e) { 
            // --- Payment Failure (API or General Exception) ---
            // Log potentially more specific error if available from TossAPI response, otherwise log general message
            $errorCode = method_exists($e, 'getErrorCode') ? $e->getErrorCode() : 'GENERAL_ERROR'; // Check if method exists
            MPHB()->log()->error(sprintf('[%s] Exception during confirmation for Payment ID %d: [%s] %s', __METHOD__, $payment_id, $errorCode, $e->getMessage()));

            $payment->setStatus(Payment::STATUS_FAILED);
            // Provide a slightly more informative note if possible
            $note = sprintf(__('Toss payment confirmation failed. Error: [%s] %s', 'mphb-toss'), $errorCode, $e->getMessage());
            $payment->addNote($note);
            $payment->save();

            // Redirect to failure URL instead of wp_die for better user experience
            $failure_url = add_query_arg(['payment_status' => 'failed', 'reason' => urlencode($e->getMessage())], $booking->getCheckoutUrl());
            wp_safe_redirect($failure_url);
            exit;
            
            // wp_die(__('An unexpected error occurred during payment processing.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 500]); // Replaced with redirect
            // exit; // exit is called by wp_safe_redirect
        }
    } // end handle_toss_callback

} // end class TossGateway
