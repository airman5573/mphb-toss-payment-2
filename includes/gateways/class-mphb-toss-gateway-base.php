<?php
namespace MPHBTOSS\Gateways;

use MPHB\Admin\Fields\FieldFactory;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHBTOSS\TossGlobalSettingsTab; // 전역 설정 클래스 사용

if (!defined('ABSPATH')) {
    exit;
}

abstract class TossGatewayBase extends \MPHB\Payments\Gateways\Gateway {

    const MPHB_GATEWAY_ID_PREFIX = 'toss_'; // 모든 토스 서브 게이트웨이 ID의 접두사

    public function __construct() {
        parent::__construct();
        // Base hooks can be registered here if needed, but the main callback is static.
    }

    // 각 하위 클래스에서 이 메소드들을 구현해야 합니다.
    abstract protected function getDefaultTitle(): string;
    abstract protected function getDefaultDescription(): string;
    abstract protected function getTossMethod(): string; // 예: "CARD", "TRANSFER", "VIRTUAL_ACCOUNT"

    /**
     * MPHB 설정 탭에 게이트웨이 옵션 필드를 등록합니다.
     * (활성화, 제목, 설명 등 공통 필드)
     */
    public function registerOptionsFields(&$subTab): void {
        parent::registerOptionsFields($subTab); // 공통 필드 등록
        $gatewayId = $this->getId();

        // SSL 경고 (필요시)
        if (!MPHB()->isSiteSSL()) {
            $sslWarn = '<strong>' . __('Warning:', 'mphb-toss-payments') . '</strong> ' . __('Toss Payments requires an SSL certificate (HTTPS) to function correctly. Please secure your site.', 'mphb-toss-payments');
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] SSL Warning will be shown for Gateway ID: ' . $gatewayId, $sslWarn)->orange()->label('SETTINGS');
            // MPHB 에는 enable 필드가 기본으로 생성되므로, description을 추가하는 방식은 다를 수 있습니다.
            // 만약 enable 필드를 직접 생성한다면 거기에 description을 추가.
            // 여기서는 일단 주석 처리.
            // $enableField = $subTab->findField("mphb_payment_gateway_{$gatewayId}_enable");
            // if ($enableField) {
            //    $current_desc = $enableField->getDescription();
            //    $enableField->setDescription($current_desc . '<p class="notice notice-warning" style="padding:1em;">' . $sslWarn . '</p>');
            // }
        }
    }

    /**
     * 게이트웨이가 활성화될 수 있는 조건인지 확인합니다. (MPHB에 의해 호출됨)
     * 전역 API 키 설정 및 통화(KRW) 확인
     */
    public function isActive(): bool {
        $isParentActive = parent::isActive();
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        $global_client_key = TossGlobalSettingsTab::get_global_client_key();
        $global_secret_key = TossGlobalSettingsTab::get_global_secret_key();

        $isActive = $isParentActive &&
               !empty($global_client_key) &&
               !empty($global_secret_key) &&
               $currency === 'KRW';

        return $isActive;
    }

    /**
     * 게이트웨이가 실제로 활성화되어 있는지 여부. (MPHB에 의해 호출됨)
     * isActive()와 동일하게 사용될 수 있음.
     */
    public function isEnabled(): bool {
        $enabled = $this->isActive();
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] isEnabled called for Gateway ID: ' . $this->getId() . ', Result: ' . ($enabled ? 'true' : 'false'))->blue()->label('ACTIVATION');
        return $enabled;
    }

    /**
     * 전역 설정에서 클라이언트 키를 가져옵니다.
     */
    public function getClientKey(): string {
        $clientKey = TossGlobalSettingsTab::get_global_client_key();
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getClientKey called. Key: ' . substr($clientKey, 0, 10) . '...')->blue()->label('KEYS');
        return $clientKey;
    }

    /**
     * 전역 설정에서 시크릿 키를 가져옵니다.
     * 테스트 모드에 따라 다른 키를 반환하도록 확장 가능.
     */
    public function getSecretKey(): string {
        $secretKey = TossGlobalSettingsTab::get_global_secret_key();
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getSecretKey called. Key: ' . substr($secretKey, 0, 10) . '...')->blue()->label('KEYS');
        return $secretKey;
    }

    /**
     * 결제 처리 시작. 체크아웃 숏코드 페이지로 리다이렉트합니다.
     * 각 하위 게이트웨이는 이 메소드를 사용하여 자신의 정보(toss method, gateway_id)를 전달합니다.
     */
    public function processPayment(Booking $booking, Payment $payment): array {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] processPayment initiated for Gateway ID: ' . $this->getId())->green()->label('PROCESS_PAYMENT');
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Payment Object:', $payment)->purple();

        $checkoutPageUrl = home_url('/toss-checkout/');
        function_exists('ray') && ray('Using hardcoded checkout page URL:', $checkoutPageUrl)->orange();
        // $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl(); // MPHB 체크아웃 페이지 URL
        // if (empty($checkoutPageUrl)) {
        //     function_exists('ray') && ray('MPHB Checkout Page URL is empty, falling back to hardcoded /toss-checkout/')->orange();
        //     $checkoutPageUrl = home_url('/toss-checkout/');
        // } else {
        //     function_exists('ray') && ray('Using MPHB Checkout Page URL:', $checkoutPageUrl)->green();
        // }

        $params = [
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),
            'mphb_selected_gateway_id' => $this->getId()
        ];
        function_exists('ray') && ray('Parameters for redirect URL:', $params)->purple();

        $returnUrl = add_query_arg($params, $checkoutPageUrl);
        function_exists('ray') && ray('Generated Redirect URL:', $returnUrl)->green();

        if (headers_sent()) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Headers already sent. Cannot redirect via wp_redirect. URL: ' . $returnUrl)->red()->label('PROCESS_PAYMENT_ERROR');
            // Consider an alternative way to inform the user or log this critical error.
            // For instance, displaying a JS redirect or an error message.
            echo "<script>window.location.href = '" . esc_url_raw($returnUrl) . "';</script>";
            exit;
        } else {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to: ' . $returnUrl)->green();
            wp_redirect($returnUrl);
            exit;
        }
        // MPHB expects an array, but since we exit, it's okay for now.
        // However, to be strictly compliant if exit wasn't used, it should be:
        // return ['result' => 'success', 'redirect' => $returnUrl];
    }

    /**
     * 모든 토스페이먼츠 콜백을 처리하는 정적 핸들러입니다.
     * URL의 mphb_payment_gateway 값을 보고 적절한 게이트웨이 인스턴스를 찾아 처리를 위임합니다.
     */
    public static function handleTossCallbackStatic() {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] handleTossCallbackStatic invoked.')->label('STATIC_CALLBACK')->blue();
        function_exists('ray') && ray('$_GET parameters:', $_GET)->purple();

        if (
            !isset($_GET['mphb_payment_gateway']) ||
            strpos($_GET['mphb_payment_gateway'], self::MPHB_GATEWAY_ID_PREFIX) !== 0 ||
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key'])
        ) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Missing or invalid required GET parameters. Exiting.')->orange();
            return;
        }

        $gatewayIdFromUrl = sanitize_text_field($_GET['mphb_payment_gateway']);
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway ID from URL: ' . $gatewayIdFromUrl)->blue();

        $gatewayInstance = MPHB()->gatewayManager()->getGateway($gatewayIdFromUrl);

        if (!$gatewayInstance || !($gatewayInstance instanceof self)) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway instance NOT found or NOT a TossGatewayBase for ID: ' . $gatewayIdFromUrl)->red();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MPHB Toss Payments] Callback Error: Gateway instance not found or not a TossGatewayBase for ID: ' . $gatewayIdFromUrl);
            }
            wp_die(__('Invalid payment gateway specified in callback.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
            return;
        }
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Static callback: Gateway instance found. Delegating to handleInstanceTossCallback.')->green();
        $gatewayInstance->handleInstanceTossCallback();
    }

    /**
     * 실제 콜백 로직을 처리하는 인스턴스 메소드입니다.
     */
    public function handleInstanceTossCallback() {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] handleInstanceTossCallback invoked for Gateway ID: ' . $this->getId())->label('INSTANCE_CALLBACK')->blue();
        function_exists('ray') && ray('$_GET parameters:', $_GET)->purple();

        $callbackType = isset($_GET['callback_type']) ? sanitize_text_field($_GET['callback_type']) : null;
        $bookingId    = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
        $bookingKey   = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : '';

        function_exists('ray') && ray([
            'callbackType' => $callbackType,
            'bookingId' => $bookingId,
            'bookingKey' => $bookingKey,
        ])->purple()->label('Parsed GET Params');


        // 예약 및 결제 객체 로드 및 검증
        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking || $booking->getKey() !== $bookingKey) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Booking validation failed. Booking ID: ' . $bookingId . ', Key: ' . $bookingKey . '. Found Booking Key: ' . ($booking ? $booking->getKey() : 'N/A'))->red();
            wp_die(__('Toss Callback: Booking validation failed.', 'mphb-toss-payments'), __('Booking Error', 'mphb-toss-payments'), ['response' => 403]);
        }
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Booking validation successful.', $booking)->green();

        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] No pending payment (ExpectPaymentId) found for this booking. ID: ' . $bookingId)->red();
             wp_die(__('Toss Callback: No pending payment found for this booking.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 404]);
        }
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Expected Payment ID: ' . $expectPaymentId)->blue();

        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment to Booking mismatch. Payment Booking ID: ' . ($payment ? $payment->getBookingId() : 'N/A') . ', Current Booking ID: ' . $booking->getId())->red();
            wp_die(__('Toss Callback: Payment to Booking mismatch.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
        }
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment validation successful.', $payment)->green();

        // 실패 콜백 처리
        if ($callbackType === 'fail') {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Handling FAIL callback.')->orange();
            $errorCode = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : 'USER_CANCEL';
            $errorMessage = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : __('Payment was canceled or failed.', 'mphb-toss-payments');
            $failLog = sprintf(__('Toss Payment Failed. Code: %s, Message: %s', 'mphb-toss-payments'), $errorCode, $errorMessage);

            function_exists('ray') && ray(['errorCode' => $errorCode, 'errorMessage' => $errorMessage, 'failLog' => $failLog])->purple();

            MPHB()->paymentManager()->failPayment($payment, $failLog);
            $booking->addLog($failLog);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment failed and logged.')->orange();

            do_action('mphb_toss_payment_failed', $booking, $payment, ['code' => $errorCode, 'message' => $errorMessage], $this->getId());

            $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, [
                'code'    => $errorCode,
                'message' => urlencode($errorMessage)
            ]);
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to fail URL: ' . $redirectUrl)->orange();
            wp_safe_redirect($redirectUrl);
            exit;
        }

        // 성공 콜백 처리 (필수 파라미터 확인)
        if (
            $callbackType === 'success' &&
            isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Handling SUCCESS callback.')->green();
            $paymentKey  = sanitize_text_field($_GET['paymentKey']);
            $tossOrderId = sanitize_text_field($_GET['orderId']);
            $receivedAmount = round((float)$_GET['amount']);
            $expectedAmount = round((float)$payment->getAmount());

            function_exists('ray') && ray([
                'paymentKey' => $paymentKey,
                'tossOrderId' => $tossOrderId,
                'receivedAmount' => $receivedAmount,
                'expectedAmount' => $expectedAmount
            ])->purple();

            // 주문 ID 및 금액 검증
            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId()); // This was the format in toss-checkout-shortcode.php before uniqid
                                                                                          // If your toss-checkout-shortcode still appends uniqid, this will fail.
                                                                                          // Let's assume it does not append uniqid as per this base class's expectation for now.
                                                                                          // If it does, the orderId from JS should be like mphb_BOOKINGID_PAYMENTID only
            function_exists('ray') && ray('Expected Order ID for validation: ' . $expectedOrderId)->blue();

            if ($receivedAmount !== $expectedAmount || $tossOrderId !== $expectedOrderId) {
                $validationErrorLog = sprintf(
                    __('Toss Payment Mismatch. Received Amount: %s (Expected: %s). Received OrderID: %s (Expected: %s)', 'mphb-toss-payments'),
                    $receivedAmount, $expectedAmount, $tossOrderId, $expectedOrderId
                );
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment Mismatch.', $validationErrorLog)->red();
                MPHB()->paymentManager()->failPayment($payment, $validationErrorLog);
                $booking->addLog($validationErrorLog);
                wp_die($validationErrorLog, __('Payment Validation Error', 'mphb-toss-payments'), ['response' => 400]);
            }
            function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Amount and Order ID validation successful.')->green();

            try {
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Attempting to confirm payment via API.')->blue();
                $tossApi = new TossAPI($this->getSecretKey(), TossGlobalSettingsTab::is_debug_mode());
                function_exists('ray') && ray('TossAPI Instantiated. Secret Key (prefix): '.substr($this->getSecretKey(),0,10).'..., Debug Mode: '.(TossGlobalSettingsTab::is_debug_mode()?'Yes':'No'))->purple();

                $result = $tossApi->confirmPayment($paymentKey, $tossOrderId, (float)$expectedAmount);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Toss API confirmPayment Response:', $result)->purple();

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment successfully confirmed by Toss API (Status: DONE).')->green();
                    $payment->setTransactionId($paymentKey);
                    $note = sprintf(
                        __('Toss Payment Approved (%s). Payment Key: %s', 'mphb-toss-payments'),
                        $this->getTitleForUser(),
                        $paymentKey
                    );
                    function_exists('ray') && ray('Payment Note:', $note)->blue();

                    $paymentMethodName = $this->getPaymentMethodNameFromResult($result);
                    function_exists('ray') && ray('Payment Method Name from Result:', $paymentMethodName)->blue();
                    update_post_meta($payment->getId(), '_mphb_payment_type', $paymentMethodName);
                    update_post_meta($payment->getId(), '_mphb_toss_payment_details', $result);
                    function_exists('ray') && ray('Payment meta updated: _mphb_payment_type, _mphb_toss_payment_details')->blue();

                    $this->afterPaymentConfirmation($payment, $booking, $result);

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] MPHB Payment completed and logged.')->green();

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result, $this->getId());

                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to reservation received page: ' . $reservationReceivedPageUrl)->green();
                    wp_safe_redirect($reservationReceivedPageUrl);
                    exit;
                } else {
                    $apiErrorCode = $result->code ?? 'UNKNOWN_API_ERROR';
                    $apiErrorMessage = $result->message ?? __('Toss API did not confirm the payment or status was not DONE.', 'mphb-toss-payments');
                    function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Toss API did not confirm payment or status not DONE.', ['code' => $apiErrorCode, 'message' => $apiErrorMessage, 'full_result' => $result])->red();
                    throw new TossException($apiErrorMessage, $apiErrorCode);
                }
            } catch (\Exception $e) {
                $errorLog = '[Toss API Exception during confirmation]: ' . $e->getMessage();
                if ($e instanceof TossException) {
                    $errorLog .= ' (Code: ' . $e->getErrorCode() . ')';
                }
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Exception during payment confirmation.', $errorLog, $e)->red();

                MPHB()->paymentManager()->failPayment($payment, $errorLog);
                $booking->addLog($errorLog);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Payment failed due to exception and logged.')->red();

                do_action('mphb_toss_payment_failed', $booking, $payment, $e, $this->getId());

                $redirectParams = ['message' => urlencode($e->getMessage())];
                if ($e instanceof TossException) {
                    $redirectParams['code'] = $e->getErrorCode();
                }
                $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, $redirectParams);
                function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Redirecting to checkout with error: ' . $redirectUrl)->red();
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        // 그 외의 경우 (예: callback_type이 success/fail이 아니거나, 필수 파라미터 누락)
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] Invalid callback parameters or type. Callback Type: ' . $callbackType . '. GET Params: ', $_GET)->red();
        wp_die(__('Invalid callback parameters.', 'mphb-toss-payments'), __('Callback Error', 'mphb-toss-payments'), ['response' => 400]);
    }


    /**
     * 체크아웃 페이지로 파라미터와 함께 리다이렉트할 URL을 생성합니다.
     */
    protected function getCheckoutRedirectUrlWithParams(Booking $booking, array $params = []): string {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getCheckoutRedirectUrlWithParams called for Gateway ID: ' . $this->getId())->blue()->label('URL_BUILDER');
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Additional Params:', $params)->purple();

        $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl();
        if (empty($checkoutPageUrl)) {
            $checkoutPageUrl = home_url('/toss-checkout/');
            function_exists('ray') && ray('MPHB Checkout Page URL empty, using hardcoded: ' . $checkoutPageUrl)->orange();
        } else {
            function_exists('ray') && ray('Using MPHB Checkout Page URL: ' . $checkoutPageUrl)->green();
        }


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

    /**
     * 토스페이먼츠 API 응답에서 사용자에게 보여줄 결제 수단 이름을 추출합니다.
     */
    protected function getPaymentMethodNameFromResult($result): string {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getPaymentMethodNameFromResult called.')->blue()->label('UTILS');
        function_exists('ray') && ray('API Result for method extraction:', $result)->purple();

        if (isset($result->method)) {
            $method = strtoupper($result->method);
            function_exists('ray') && ray('Toss API Method: ' . $method)->blue();
            switch ($method) {
                case 'CARD':
                    $name = !empty($result->card->company) ? $result->card->company : __('신용카드', 'mphb-toss-payments');
                    function_exists('ray') && ray('Determined Method Name: CARD - ' . $name)->green();
                    return $name;
                case 'TRANSFER':
                    function_exists('ray') && ray('Determined Method Name: TRANSFER - 계좌이체')->green();
                    return __('계좌이체', 'mphb-toss-payments');
                case 'VIRTUAL_ACCOUNT':
                    function_exists('ray') && ray('Determined Method Name: VIRTUAL_ACCOUNT - 가상계좌')->green();
                    return __('가상계좌', 'mphb-toss-payments');
                case 'MOBILE_PHONE':
                    function_exists('ray') && ray('Determined Method Name: MOBILE_PHONE - 휴대폰 소액결제')->green();
                    return __('휴대폰 소액결제', 'mphb-toss-payments');
                case 'TOSSPAY':
                    function_exists('ray') && ray('Determined Method Name: TOSSPAY - 토스페이')->green();
                    return __('토스페이', 'mphb-toss-payments');
                default:
                    $defaultName = ucfirst(strtolower(str_replace("_", " ", $result->method)));
                    function_exists('ray') && ray('Determined Method Name: DEFAULT - ' . $defaultName)->green();
                    return $defaultName;
            }
        }
        $fallbackTitle = $this->getTitleForUser();
        function_exists('ray') && ray('No method in result, falling back to gateway title: ' . $fallbackTitle)->orange();
        return $fallbackTitle;
    }

    /**
     * 결제 승인 후 각 하위 게이트웨이별로 추가적인 처리를 할 수 있는 메소드입니다.
     * (예: 가상계좌 정보 저장 등)
     * 하위 클래스에서 필요에 따라 오버라이드합니다.
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] afterPaymentConfirmation called for Gateway ID: ' . $this->getId())->blue()->label('POST_CONFIRMATION');
        function_exists('ray') && ray('Payment Object:', $payment)->purple();
        function_exists('ray') && ray('Booking Object:', $booking)->purple();
        function_exists('ray') && ray('Toss Result:', $tossResult)->purple();

        // 기본적으로 아무 작업도 하지 않음.
        // 예시: 가상계좌 정보 저장
        if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && isset($tossResult->virtualAccount)) {
            function_exists('ray') && ray('Processing VIRTUAL_ACCOUNT specific data.')->green();
            update_post_meta($payment->getId(), '_mphb_toss_vbank_account_number', $tossResult->virtualAccount->accountNumber);
            update_post_meta($payment->getId(), '_mphb_toss_vbank_bank_code', $tossResult->virtualAccount->bankCode);
            update_post_meta($payment->getId(), '_mphb_toss_vbank_holder_name', $tossResult->virtualAccount->customerName);
            update_post_meta($payment->getId(), '_mphb_toss_vbank_due_date', $tossResult->virtualAccount->dueDate);
            function_exists('ray') && ray('Virtual account details saved to payment meta.', $tossResult->virtualAccount)->purple();
        } else {
            function_exists('ray') && ray('No specific afterPaymentConfirmation actions for this gateway type or data missing. Toss Method: '.$this->getTossMethod())->blue();
        }
    }

    /**
     * 사용자에게 보여질 게이트웨이의 제목을 반환합니다. (MPHB 설정값 우선)
     */
    public function getTitleForUser(): string {
        $title = $this->getOption('title', $this->getDefaultTitle());
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getTitleForUser called for Gateway ID: ' . $this->getId() . '. Title: ' . $title)->blue()->label('DISPLAY');
        return $title;
    }

    /**
     * 사용자에게 보여질 게이트웨이 설명을 반환합니다. (MPHB 설정값 우선)
     */
    public function getDescriptionForUser(): string {
        $description = $this->getOption('description', $this->getDefaultDescription());
        function_exists('ray') && ray('[TOSS_GATEWAY_BASE] getDescriptionForUser called for Gateway ID: ' . $this->getId())->blue()->label('DISPLAY');
        // function_exists('ray') && ray('Description:', $description)->text(); // Can be long
        return $description;
    }
}
