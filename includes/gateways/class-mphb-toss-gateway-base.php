<?php
// File: includes/gateways/class-mphb-toss-gateway-base.php
namespace MPHBTOSS\Gateways;

use MPHB\Admin\Fields\FieldFactory;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHBTOSS\TossGlobalSettingsTab;

if (!defined('ABSPATH')) {
    exit;
}

abstract class TossGatewayBase extends \MPHB\Payments\Gateways\Gateway {

    const MPHB_GATEWAY_ID_PREFIX = 'toss_';

    public function __construct() {
        parent::__construct();
        // mphb_toss_write_log("Constructed for Gateway ID: " . $this->getId(), get_class($this) . '::__construct'); // Reduced verbosity
    }

    abstract protected function getDefaultTitle(): string;
    abstract protected function getDefaultDescription(): string;
    abstract public function getTossMethod(): string;

    public function registerOptionsFields(&$subTab): void {
        parent::registerOptionsFields($subTab);
        // Logging for UI/settings parts can be too verbose, removed for payment focus.
    }

    public function isActive(): bool {
        $isParentActive = parent::isActive();
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        $global_client_key = TossGlobalSettingsTab::get_global_client_key();
        $global_secret_key = TossGlobalSettingsTab::get_global_secret_key();

        $isActive = $isParentActive &&
               !empty($global_client_key) &&
               !empty($global_secret_key) &&
               $currency === 'KRW';
        
        mphb_toss_write_log(
            "isActive check for Gateway ID: " . $this->getId() . 
            ". ParentActive: " . ($isParentActive ? 'true' : 'false') . 
            ", ClientKeySet: " . (!empty($global_client_key) ? 'true' : 'false') . 
            // ", SecretKeySet: " . (!empty($global_secret_key) ? 'true' : 'false') . // Secret key itself is sensitive
            ", Currency: {$currency}. Result: " . ($isActive ? 'true' : 'false'),
            get_class($this) . '::isActive'
        );
        return $isActive;
    }

    public function isEnabled(): bool {
        $enabled = $this->isActive(); 
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] isEnabled called for Gateway ID: ' . $this->getId() . ', Result: ' . ($enabled ? 'true' : 'false'))->blue()->label('ACTIVATION');
        return $enabled;
    }

    public function getClientKey(): string {
        $clientKey = TossGlobalSettingsTab::get_global_client_key();
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getClientKey called. Key: ' . substr($clientKey, 0, 10) . '...')->blue()->label('KEYS');
        return $clientKey;
    }

    public function getSecretKey(): string {
        $secretKey = TossGlobalSettingsTab::get_global_secret_key();
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getSecretKey called. Key: ' . substr($secretKey, 0, 10) . '...')->blue()->label('KEYS');
        return $secretKey;
    }

    public function processPayment(Booking $booking, Payment $payment): array {
        $log_context = get_class($this) . '::processPayment';
        mphb_toss_write_log(
            "Initiated. Gateway ID: " . $this->getId() . ". Booking ID: " . $booking->getId() . ", Payment ID: " . $payment->getId() . ", Amount: " . $payment->getAmount(),
            $log_context
        );
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] processPayment initiated for Gateway ID: ' . $this->getId())->green()->label('PROCESS_PAYMENT');
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Payment Object:', $payment)->purple();

        $checkoutPageUrl = home_url('/toss-checkout/');
        function_exists('ray') && ray('Using hardcoded checkout page URL for Toss:', $checkoutPageUrl)->orange();
        
        $params = [
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),
            'mphb_selected_gateway_id' => $this->getId()
        ];
        function_exists('ray') && ray('Parameters for redirect URL:', $params)->purple();

        $returnUrl = add_query_arg($params, $checkoutPageUrl);
        mphb_toss_write_log("Generated Redirect URL: " . $returnUrl, $log_context);
        function_exists('ray') && ray('Generated Redirect URL:', $returnUrl)->green();

        if (headers_sent($file, $line)) {
            mphb_toss_write_log("Headers already sent. Cannot redirect. Output started at {$file}:{$line}. URL: {$returnUrl}", $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Headers already sent. Output started at ' . $file . ':' . $line . '. URL: ' . $returnUrl)->red()->label('PROCESS_PAYMENT_ERROR');
            echo "<script>window.location.href = '" . esc_url_raw($returnUrl) . "';</script>";
            exit;
        } else {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to: ' . $returnUrl)->green();
            wp_redirect($returnUrl);
            exit;
        }
    }

    public static function handleTossCallbackStatic() {
        $log_context = __CLASS__ . '::handleTossCallbackStatic';
        mphb_toss_write_log("Invoked. GET Params: " . print_r($_GET, true), $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] handleTossCallbackStatic invoked.')->label('STATIC_CALLBACK')->blue();
        function_exists('ray') && ray('$_GET parameters:', $_GET)->purple();

        if (
            !isset($_GET['mphb_payment_gateway']) ||
            strpos($_GET['mphb_payment_gateway'], self::MPHB_GATEWAY_ID_PREFIX) !== 0 ||
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key'])
        ) {
            mphb_toss_write_log("Missing or invalid required GET parameters. Exiting.", $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Missing or invalid GET parameters.', $_GET)->orange();
            return;
        }

        $gatewayIdFromUrl = sanitize_text_field($_GET['mphb_payment_gateway']);
        mphb_toss_write_log("Gateway ID from URL: " . $gatewayIdFromUrl, $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway ID from URL: ' . $gatewayIdFromUrl)->blue();

        $gatewayInstance = MPHB()->gatewayManager()->getGateway($gatewayIdFromUrl);

        if (!$gatewayInstance || !($gatewayInstance instanceof self)) {
            mphb_toss_write_log("Gateway instance NOT found or NOT a TossGatewayBase for ID: " . $gatewayIdFromUrl, $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway instance NOT found for ID: ' . $gatewayIdFromUrl)->red();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MPHB Toss Payments] Callback Error: Gateway instance not found or not a TossGatewayBase for ID: ' . $gatewayIdFromUrl);
            }
            wp_die(__('Invalid payment gateway specified in callback.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
            return;
        }
        mphb_toss_write_log("Delegating to handleInstanceTossCallback for ID: " . $gatewayIdFromUrl, $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway instance found. Delegating.')->green();
        $gatewayInstance->handleInstanceTossCallback();
    }

    public function handleInstanceTossCallback() {
        $log_context = get_class($this) . '::handleInstanceTossCallback - GatewayID: ' . $this->getId();
        mphb_toss_write_log("Invoked. GET Params: " . print_r($_GET, true), $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] handleInstanceTossCallback invoked for Gateway ID: ' . $this->getId())->label('INSTANCE_CALLBACK')->blue();
        function_exists('ray') && ray('$_GET parameters:', $_GET)->purple();

        $callbackType = isset($_GET['callback_type']) ? sanitize_text_field($_GET['callback_type']) : null;
        $bookingId    = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
        $bookingKey   = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : '';
        mphb_toss_write_log("Parsed GET Params: Type={$callbackType}, BookingID={$bookingId}, BookingKey={$bookingKey}", $log_context);
        function_exists('ray') && ray(['callbackType' => $callbackType, 'bookingId' => $bookingId, 'bookingKey' => $bookingKey,])->purple()->label('Parsed GET Params');

        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking || $booking->getKey() !== $bookingKey) {
            mphb_toss_write_log("Booking validation failed. ID: {$bookingId}, Key: {$bookingKey}. Found Key: " . ($booking ? $booking->getKey() : 'N/A'), $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Booking validation failed.')->red();
            wp_die(__('Toss Callback: Booking validation failed.', 'mphb-toss-payments'), __('Booking Error', 'mphb-toss-payments'), ['response' => 403]);
        }
        mphb_toss_write_log("Booking validation successful. ID: {$bookingId}", $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Booking validation successful.', $booking)->green();

        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) {
            mphb_toss_write_log("No pending payment (ExpectPaymentId) for Booking ID: {$bookingId}", $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] No pending payment (ExpectPaymentId) found.')->red();
            wp_die(__('Toss Callback: No pending payment found for this booking.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 404]);
        }
        mphb_toss_write_log("Expected Payment ID: {$expectPaymentId}", $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Expected Payment ID: ' . $expectPaymentId)->blue();

        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) {
            mphb_toss_write_log("Payment validation failed. Payment Booking ID: " . ($payment ? $payment->getBookingId() : 'N/A') . ", Current Booking ID: {$booking->getId()}", $log_context . '_Error');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment to Booking mismatch.')->red();
            wp_die(__('Toss Callback: Payment to Booking mismatch.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
        }
        mphb_toss_write_log("Payment validation successful. Payment ID: {$expectPaymentId}", $log_context);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment validation successful.', $payment)->green();

        if ($callbackType === 'fail') {
            $errorCode = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : 'USER_CANCEL';
            $errorMessage = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : __('Payment was canceled or failed.', 'mphb-toss-payments');
            $failLog = sprintf(__('Toss Payment Failed. Code: %s, Message: %s', 'mphb-toss-payments'), $errorCode, $errorMessage);
            mphb_toss_write_log("Handling FAIL callback. Payment ID: {$payment->getId()}. Code: {$errorCode}, Message: {$errorMessage}", $log_context);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Handling FAIL callback.')->orange();
            function_exists('ray') && ray(['errorCode' => $errorCode, 'errorMessage' => $errorMessage, 'failLog' => $failLog])->purple();

            MPHB()->paymentManager()->failPayment($payment, $failLog);
            $booking->addLog($failLog);
            do_action('mphb_toss_payment_failed', $booking, $payment, ['code' => $errorCode, 'message' => $errorMessage], $this->getId());
            $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, ['code' => $errorCode, 'message' => urlencode($errorMessage)]);
            mphb_toss_write_log("Redirecting to fail URL: {$redirectUrl}", $log_context);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to fail URL: ' . $redirectUrl)->orange();
            wp_safe_redirect($redirectUrl);
            exit;
        }

        if (
            $callbackType === 'success' &&
            isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            $paymentKey  = sanitize_text_field($_GET['paymentKey']);
            $tossOrderIdFromUrl = sanitize_text_field($_GET['orderId']);
            $receivedAmount = round((float)$_GET['amount']);
            $expectedAmount = round((float)$payment->getAmount());
            mphb_toss_write_log("Handling SUCCESS callback. PaymentKey: {$paymentKey}, TossOrderID: {$tossOrderIdFromUrl}, ReceivedAmount: {$receivedAmount}, ExpectedAmount: {$expectedAmount}, PaymentID: {$payment->getId()}", $log_context);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Handling SUCCESS callback.')->green();
            function_exists('ray') && ray(['paymentKey' => $paymentKey, 'tossOrderIdFromUrl' => $tossOrderIdFromUrl, 'receivedAmount' => $receivedAmount, 'expectedAmount' => $expectedAmount])->purple();

            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());
            mphb_toss_write_log("Expected Order ID for validation: {$expectedOrderId}", $log_context);
            function_exists('ray') && ray('Expected Order ID for validation: ' . $expectedOrderId)->blue();

            if ($receivedAmount !== $expectedAmount || $tossOrderIdFromUrl !== $expectedOrderId) {
                $validationErrorLog = sprintf(__('Toss Payment Mismatch. Received Amount: %s (Expected: %s). Received OrderID: %s (Expected: %s)', 'mphb-toss-payments'), $receivedAmount, $expectedAmount, $tossOrderIdFromUrl, $expectedOrderId);
                mphb_toss_write_log("Payment Mismatch Error: {$validationErrorLog}. PaymentID: {$payment->getId()}", $log_context . '_Error');
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment Mismatch.', $validationErrorLog)->red();
                MPHB()->paymentManager()->failPayment($payment, $validationErrorLog);
                $booking->addLog($validationErrorLog);
                wp_die($validationErrorLog, __('Payment Validation Error', 'mphb-toss-payments'), ['response' => 400]);
            }
            mphb_toss_write_log("Amount and Order ID validation successful. PaymentID: {$payment->getId()}", $log_context);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Amount and Order ID validation successful.')->green();

            try {
                mphb_toss_write_log("Attempting to confirm payment via API. PaymentKey: {$paymentKey}, OrderID: {$tossOrderIdFromUrl}, Amount: {$expectedAmount}", $log_context);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Attempting to confirm payment via API.')->blue();
                $is_debug_mode = TossGlobalSettingsTab::is_debug_mode();
                $tossApi = new TossAPI($this->getSecretKey(), $is_debug_mode);
                mphb_toss_write_log('TossAPI Instantiated for confirm. Secret Key (prefix): '.substr($this->getSecretKey(),0,10).'..., Debug Mode: '.($is_debug_mode?'Yes':'No'), $log_context);
                function_exists('ray') && ray('TossAPI Instantiated. Secret Key (prefix): '.substr($this->getSecretKey(),0,10).'..., Debug Mode: '.($is_debug_mode?'Yes':'No'))->purple();

                $result = $tossApi->confirmPayment($paymentKey, $tossOrderIdFromUrl, (float)$expectedAmount); // API will log its own request/response
                mphb_toss_write_log("Toss API confirmPayment Response: " . print_r(mphb_toss_sanitize_log_data($result), true), $log_context);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Toss API confirmPayment Response:', $result)->purple();

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    mphb_toss_write_log("Payment successfully confirmed by Toss API (Status: DONE). PaymentKey: {$paymentKey}, PaymentID: {$payment->getId()}", $log_context);
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment successfully confirmed (Status: DONE).')->green();
                    $payment->setTransactionId($paymentKey);
                    $note = sprintf(__('Toss Payment Approved (%s). Payment Key: %s', 'mphb-toss-payments'), $this->getTitleForUser(), $paymentKey);
                    
                    $paymentMethodNameFromResult = $this->getPaymentMethodNameFromResult($result);
                    update_post_meta($payment->getId(), '_mphb_payment_type', $paymentMethodNameFromResult);
                    update_post_meta($payment->getId(), '_mphb_toss_payment_details', $result);
                    mphb_toss_write_log("Payment meta updated. PaymentID: {$payment->getId()}", $log_context);
                    function_exists('ray') && ray('Payment meta updated.')->blue();

                    $this->afterPaymentConfirmation($payment, $booking, $result); // This method in child or base will log

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);
                    mphb_toss_write_log("MPHB Payment completed and logged. PaymentID: {$payment->getId()}", $log_context);
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] MPHB Payment completed.')->green();

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result, $this->getId());

                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    mphb_toss_write_log("Redirecting to reservation received page: {$reservationReceivedPageUrl}", $log_context);
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to reservation received page: ' . $reservationReceivedPageUrl)->green();
                    wp_safe_redirect($reservationReceivedPageUrl);
                    exit;
                } else {
                    $apiErrorCode = $result->code ?? 'UNKNOWN_API_ERROR';
                    $apiErrorMessage = $result->message ?? __('Toss API did not confirm the payment or status was not DONE.', 'mphb-toss-payments');
                    mphb_toss_write_log("Toss API did not confirm payment or status not DONE. Code: {$apiErrorCode}, Message: {$apiErrorMessage}. PaymentID: {$payment->getId()}", $log_context . '_Error');
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Toss API confirm failed or status not DONE.', ['code' => $apiErrorCode, 'message' => $apiErrorMessage, 'full_result' => $result])->red();
                    throw new TossException($apiErrorMessage, $apiErrorCode);
                }
            } catch (\Exception $e) {
                $errorLogMessage = '[Toss API Exception during confirmation]: ' . $e->getMessage();
                if ($e instanceof TossException) {
                    $errorLogMessage .= ' (Code: ' . $e->getErrorCode() . ')';
                }
                mphb_toss_write_log("Exception during payment confirmation: {$errorLogMessage}. PaymentID: {$payment->getId()}", $log_context . '_Error', $e);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Exception during payment confirmation.', $errorLogMessage, $e)->red();

                MPHB()->paymentManager()->failPayment($payment, $errorLogMessage);
                $booking->addLog($errorLogMessage);
                do_action('mphb_toss_payment_failed', $booking, $payment, $e, $this->getId());
                $redirectParams = ['message' => urlencode($e->getMessage())];
                if ($e instanceof TossException) {
                    $redirectParams['code'] = $e->getErrorCode();
                }
                $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, $redirectParams);
                mphb_toss_write_log("Redirecting to checkout with error: {$redirectUrl}", $log_context . '_Error');
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to checkout with error: ' . $redirectUrl)->red();
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        mphb_toss_write_log("Invalid callback parameters or type. Callback Type: " . esc_html($callbackType) . ". GET Params: " . print_r($_GET, true), $log_context . '_Error');
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Invalid callback parameters or type.', $_GET)->red();
        wp_die(__('Invalid callback parameters.', 'mphb-toss-payments'), __('Callback Error', 'mphb-toss-payments'), ['response' => 400]);
    }


    protected function getCheckoutRedirectUrlWithParams(Booking $booking, array $params = []): string {
        // This URL is typically logged by the calling function (fail or success redirect)
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getCheckoutRedirectUrlWithParams called for Gateway ID: ' . $this->getId())->blue()->label('URL_BUILDER');
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Additional Params:', $params)->purple();
        $checkoutPageUrl = home_url('/toss-checkout/');
        function_exists('ray') && ray('Using hardcoded checkout page URL for redirect: ' . $checkoutPageUrl)->orange();
        $defaultParams = [
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),
            'mphb_selected_gateway_id' => $this->getId()
        ];
        function_exists('ray') && ray('Default Params for URL:', $defaultParams)->purple();
        $finalUrl = add_query_arg(array_merge($defaultParams, $params), $checkoutPageUrl);
        function_exists('ray') && ray('Final Redirect URL with Params:', $finalUrl)->green();
        return $finalUrl;
    }

    protected function getPaymentMethodNameFromResult($result): string {
        // This is more of a utility, logging might be too verbose for general payment flow.
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getPaymentMethodNameFromResult called.')->blue()->label('UTILS');
        function_exists('ray') && ray('API Result for method extraction:', $result)->purple();
        if (isset($result->method)) {
            $method = strtoupper($result->method);
            function_exists('ray') && ray('Toss API Method: ' . $method)->blue();
            $paymentMethodName = $this->getTitleForUser(); 
            switch ($method) {
                case 'CARD':
                    $name = __('Credit Card', 'mphb-toss-payments');
                    if (!empty($result->card->company)) $name = $result->card->company;
                    if (!empty($result->card->cardType)) $name .= ' (' . ucfirst(strtolower($result->card->cardType)) . ')';
                    $paymentMethodName = $name;
                    break;
                case 'TRANSFER': $paymentMethodName = __('Bank Transfer', 'mphb-toss-payments'); break;
                case 'VIRTUAL_ACCOUNT': $paymentMethodName = __('Virtual Account', 'mphb-toss-payments'); break;
                case 'MOBILE_PHONE': $paymentMethodName = __('Mobile Phone Payment', 'mphb-toss-payments'); break;
                case 'EASY_PAY': $paymentMethodName = isset($result->easyPay->provider) ? $result->easyPay->provider : __('Easy Pay', 'mphb-toss-payments'); break;
                case 'TOSSPAY': $paymentMethodName = __('TossPay', 'mphb-toss-payments'); break;
                case 'NAVERPAY': $paymentMethodName = __('Naver Pay', 'mphb-toss-payments'); break;
                case 'KAKAOPAY': $paymentMethodName = __('Kakao Pay', 'mphb-toss-payments'); break;
                default: $paymentMethodName = ucwords(strtolower(str_replace("_", " ", $method))); break;
            }
            function_exists('ray') && ray('Determined Payment Method Name: ' . $paymentMethodName)->green();
            return $paymentMethodName;
        }
        $fallbackTitle = $this->getTitleForUser();
        function_exists('ray') && ray('No method in result, falling back to gateway title: ' . $fallbackTitle)->orange();
        return $fallbackTitle;
    }

    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        $log_context = get_class($this) . '::afterPaymentConfirmation (Base)';
        mphb_toss_write_log(
            "Base class method. Gateway ID: " . $this->getId() . ". Payment ID: " . $payment->getId() . ". Toss Method: " . $this->getTossMethod(),
            $log_context
        );
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] afterPaymentConfirmation called for Gateway ID: ' . $this->getId())->blue()->label('POST_CONFIRMATION');
        function_exists('ray') && ray('Payment Object:', $payment)->purple();
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Toss Result:', $tossResult)->purple();

        if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && isset($tossResult->virtualAccount)) {
            $vAccount = $tossResult->virtualAccount;
            mphb_toss_write_log("Base: Processing VIRTUAL_ACCOUNT data. AccountNumber: " . ($vAccount->accountNumber ?? 'N/A') . ", DueDate: " . ($vAccount->dueDate ?? 'N/A'), $log_context);
            function_exists('ray') && ray('Base: Processing VIRTUAL_ACCOUNT specific data.')->green();
            update_post_meta($payment->getId(), '_mphb_toss_vbank_account_number', $vAccount->accountNumber ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_bank_code', $vAccount->bankCode ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_customer_name', $vAccount->customerName ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_due_date', $vAccount->dueDate ?? '');
            update_post_meta($payment->getId(), '_mphb_toss_vbank_status', $vAccount->status ?? '');
            function_exists('ray') && ray('Base: Virtual account details saved to payment meta.', $vAccount)->purple();
        } else {
            if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && !isset($tossResult->virtualAccount)) {
                mphb_toss_write_log("Base: VIRTUAL_ACCOUNT method but virtualAccount object not found in TossResult.", $log_context . "_Warning");
            }
            function_exists('ray') && ray('Base: No generic VIRTUAL_ACCOUNT actions for this gateway type or data missing. Toss Method: '.$this->getTossMethod())->blue();
        }
    }

    public function getTitleForUser(): string {
        $title = $this->get_gateway_option('title', $this->getDefaultTitle());
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getTitleForUser called for Gateway ID: ' . $this->getId() . '. Title: ' . $title)->blue()->label('DISPLAY');
        return $title;
    }

    public function getDescriptionForUser(): string {
        $description = $this->get_gateway_option('description', $this->getDefaultDescription());
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getDescriptionForUser called for Gateway ID: ' . $this->getId())->blue()->label('DISPLAY');
        return $description;
    }

    public function get_gateway_option(string $optionName, $defaultValue = null) {
        return $this->getOption($optionName, $defaultValue);
    }
}
