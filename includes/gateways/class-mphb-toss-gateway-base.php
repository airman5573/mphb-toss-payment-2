<?php
// 파일 경로: includes/gateways/class-mphb-toss-gateway-base.php
namespace MPHBTOSS\Gateways; // 네임스페이스 선언

// MPHB 관련 클래스 사용
use MPHB\Admin\Fields\FieldFactory;
use MPHB\Admin\Groups\SettingsGroup;
use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
// 이 플러그인 내의 클래스 사용
use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;
use MPHBTOSS\TossGlobalSettingsTab;

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 모든 토스페이먼츠 게이트웨이의 기본 클래스입니다.
 * MPHB의 Gateway 클래스를 상속받습니다.
 */
abstract class TossGatewayBase extends \MPHB\Payments\Gateways\Gateway {

    // MPHB 게이트웨이 ID 접두사 상수
    const MPHB_GATEWAY_ID_PREFIX = 'toss_';

    /**
     * 생성자입니다.
     * 부모 클래스의 생성자를 호출합니다.
     */
    public function __construct() {
        parent::__construct();
        // mphb_toss_write_log("Constructed for Gateway ID: " . $this->getId(), get_class($this) . '::__construct'); // 로그 상세도 줄임
    }

    /**
     * 사용자에게 표시될 기본 결제수단 제목을 반환해야 하는 추상 메소드입니다.
     * @return string 결제수단 제목
     */
    abstract protected function getDefaultTitle(): string;

    /**
     * 사용자에게 표시될 기본 결제수단 설명을 반환해야 하는 추상 메소드입니다.
     * @return string 결제수단 설명
     */
    abstract protected function getDefaultDescription(): string;

    /**
     * 토스페이먼츠 API에 전달할 결제 수단 문자열을 반환해야 하는 추상 메소드입니다.
     * @return string 토스페이먼츠 결제 수단 (예: 'CARD', 'TRANSFER', 'VIRTUAL_ACCOUNT')
     */
    abstract public function getTossMethod(): string;

    /**
     * 게이트웨이 옵션 필드를 등록합니다.
     * 부모 클래스의 메소드를 호출합니다.
     * @param mixed $subTab 설정 하위 탭 객체
     */
    public function registerOptionsFields(&$subTab): void {
        parent::registerOptionsFields($subTab);
        // UI/설정 부분 로깅은 너무 장황할 수 있어 결제 흐름에 집중하기 위해 제거함.
    }

    /**
     * 게이트웨이가 현재 활성 상태인지 확인합니다.
     * 부모 클래스의 활성 상태, 통화, 전역 API 키 설정을 확인합니다.
     * @return bool 활성 상태 여부
     */
    public function isActive(): bool {
        $isParentActive = parent::isActive(); // 부모 클래스의 활성 상태
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode()); // 현재 설정된 통화 코드 (대문자)
        $global_client_key = TossGlobalSettingsTab::get_global_client_key(); // 전역 클라이언트 키
        $global_secret_key = TossGlobalSettingsTab::get_global_secret_key(); // 전역 시크릿 키

        // 활성 조건: 부모 활성, 클라이언트 키 존재, 시크릿 키 존재, 통화가 'KRW'
        $isActive = $isParentActive &&
               !empty($global_client_key) &&
               !empty($global_secret_key) &&
               $currency === 'KRW';
        
        mphb_toss_write_log(
            "isActive check for Gateway ID: " . $this->getId() . 
            ". ParentActive: " . ($isParentActive ? 'true' : 'false') . 
            ", ClientKeySet: " . (!empty($global_client_key) ? 'true' : 'false') . 
            // ", SecretKeySet: " . (!empty($global_secret_key) ? 'true' : 'false') . // 시크릿 키 자체는 민감 정보
            ", Currency: {$currency}. Result: " . ($isActive ? 'true' : 'false'),
            get_class($this) . '::isActive'
        );
        return $isActive;
    }

    /**
     * 게이트웨이가 사용 가능한 상태인지 확인합니다. (isActive의 별칭처럼 사용)
     * @return bool 사용 가능 여부
     */
    public function isEnabled(): bool {
        $enabled = $this->isActive(); 
        return $enabled;
    }

    /**
     * 토스페이먼츠 클라이언트 키를 반환합니다.
     * @return string 클라이언트 키
     */
    public function getClientKey(): string {
        $clientKey = TossGlobalSettingsTab::get_global_client_key();
        return $clientKey;
    }

    /**
     * 토스페이먼츠 시크릿 키를 반환합니다.
     * @return string 시크릿 키
     */
    public function getSecretKey(): string {
        $secretKey = TossGlobalSettingsTab::get_global_secret_key();
        return $secretKey;
    }

    /**
     * 결제를 처리하고 사용자를 토스페이먼츠 결제 페이지로 리디렉션합니다.
     * @param Booking $booking 예약 객체
     * @param Payment $payment 결제 객체
     * @return array 리디렉션 URL을 포함한 배열 (실제로는 exit으로 종료됨)
     */
    public function processPayment(Booking $booking, Payment $payment): array {
        $log_context = get_class($this) . '::processPayment';
        mphb_toss_write_log(
            "Initiated. Gateway ID: " . $this->getId() . ". Booking ID: " . $booking->getId() . ", Payment ID: " . $payment->getId() . ", Amount: " . $payment->getAmount(),
            $log_context
        );

        // 토스페이먼츠 결제 처리 페이지 URL (숏코드가 있는 페이지)
        $checkoutPageUrl = home_url('/toss-checkout/');
        
        // 리디렉션 URL에 필요한 파라미터
        $params = [
            'booking_id'               => $booking->getId(), // 예약 ID
            'booking_key'              => $booking->getKey(), // 예약 키 (보안용)
            'mphb_gateway_method'      => $this->getTossMethod(), // 토스 결제 방식
            'mphb_selected_gateway_id' => $this->getId() // 선택된 MPHB 게이트웨이 ID
        ];

        // 쿼리 파라미터를 추가하여 최종 리디렉션 URL 생성
        $returnUrl = add_query_arg($params, $checkoutPageUrl);
        mphb_toss_write_log("Generated Redirect URL: " . $returnUrl, $log_context);

        // HTTP 헤더가 이미 전송되었는지 확인
        if (headers_sent($file, $line)) {
            mphb_toss_write_log("Headers already sent. Cannot redirect. Output started at {$file}:{$line}. URL: {$returnUrl}", $log_context . '_Error');
            // 헤더가 이미 전송된 경우 JavaScript로 리디렉션 시도
            echo "<script>window.location.href = '" . esc_url_raw($returnUrl) . "';</script>";
            exit;
        } else {
            // 헤더 전송 전이면 PHP 리디렉션 사용
            wp_redirect($returnUrl);
            exit;
        }
    }

    /**
     * 토스페이먼츠로부터의 콜백 요청을 처리하는 정적 메소드입니다.
     * 이 메소드는 GET 파라미터를 분석하여 적절한 게이트웨이 인스턴스의 콜백 처리 메소드로 위임합니다.
     */
    public static function handleTossCallbackStatic() {
        $log_context = __CLASS__ . '::handleTossCallbackStatic';
        mphb_toss_write_log("Invoked. GET Params: " . print_r($_GET, true), $log_context);

        // 필수 GET 파라미터 존재 여부 및 유효성 검사
        if (
            !isset($_GET['mphb_payment_gateway']) || // MPHB 결제 게이트웨이 ID
            strpos($_GET['mphb_payment_gateway'], self::MPHB_GATEWAY_ID_PREFIX) !== 0 || // 토스 접두사 확인
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key']) // 콜백 타입, 예약 ID, 예약 키
        ) {
            mphb_toss_write_log("Missing or invalid required GET parameters. Exiting.", $log_context . '_Error');
            return; // 필수 파라미터 없으면 종료
        }

        // URL에서 게이트웨이 ID 추출 및 살균
        $gatewayIdFromUrl = sanitize_text_field($_GET['mphb_payment_gateway']);
        mphb_toss_write_log("Gateway ID from URL: " . $gatewayIdFromUrl, $log_context);

        // MPHB 게이트웨이 관리자를 통해 해당 ID의 게이트웨이 인스턴스를 가져옵니다.
        $gatewayInstance = MPHB()->gatewayManager()->getGateway($gatewayIdFromUrl);

        // 게이트웨이 인스턴스를 찾지 못했거나, 예상한 TossGatewayBase 타입이 아닌 경우 오류 처리
        if (!$gatewayInstance || !($gatewayInstance instanceof self)) {
            mphb_toss_write_log("Gateway instance NOT found or NOT a TossGatewayBase for ID: " . $gatewayIdFromUrl, $log_context . '_Error');
            if (defined('WP_DEBUG') && WP_DEBUG) { // WP_DEBUG 모드일 때 PHP 에러 로그 기록
                error_log('[MPHB Toss Payments] Callback Error: Gateway instance not found or not a TossGatewayBase for ID: ' . $gatewayIdFromUrl);
            }
            // 사용자에게 오류 메시지 표시 후 종료
            wp_die(__('Invalid payment gateway specified in callback.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
            return;
        }
        // 해당 게이트웨이 인스턴스의 콜백 처리 메소드 호출
        mphb_toss_write_log("Delegating to handleInstanceTossCallback for ID: " . $gatewayIdFromUrl, $log_context);
        $gatewayInstance->handleInstanceTossCallback();
    }

    /**
     * 개별 게이트웨이 인스턴스에서 토스페이먼츠 콜백을 처리합니다.
     * 결제 성공/실패에 따라 예약 및 결제 상태를 업데이트합니다.
     */
    public function handleInstanceTossCallback() {
        $log_context = get_class($this) . '::handleInstanceTossCallback - GatewayID: ' . $this->getId();
        mphb_toss_write_log("Invoked. GET Params: " . print_r($_GET, true), $log_context);

        // GET 파라미터 추출 및 살균
        $callbackType = isset($_GET['callback_type']) ? sanitize_text_field($_GET['callback_type']) : null; // 콜백 타입 (success, fail)
        $bookingId    = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0; // 예약 ID (양의 정수)
        $bookingKey   = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : ''; // 예약 키
        mphb_toss_write_log("Parsed GET Params: Type={$callbackType}, BookingID={$bookingId}, BookingKey={$bookingKey}", $log_context);

        // 예약 정보 로드 및 유효성 검사
        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking || $booking->getKey() !== $bookingKey) { // 예약 없거나 키 불일치
            mphb_toss_write_log("Booking validation failed. ID: {$bookingId}, Key: {$bookingKey}. Found Key: " . ($booking ? $booking->getKey() : 'N/A'), $log_context . '_Error');
            wp_die(__('Toss Callback: Booking validation failed.', 'mphb-toss-payments'), __('Booking Error', 'mphb-toss-payments'), ['response' => 403]);
        }
        mphb_toss_write_log("Booking validation successful. ID: {$bookingId}", $log_context);

        // 예상 결제 ID (처리해야 할 결제 ID) 가져오기
        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) { // 예상 결제 ID가 없는 경우 (이미 처리되었거나 잘못된 접근)
            mphb_toss_write_log("No pending payment (ExpectPaymentId) for Booking ID: {$bookingId}", $log_context . '_Error');
            wp_die(__('Toss Callback: No pending payment found for this booking.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 404]);
        }
        mphb_toss_write_log("Expected Payment ID: {$expectPaymentId}", $log_context);

        // 결제 정보 로드 및 유효성 검사
        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) { // 결제 없거나 예약 ID 불일치
            mphb_toss_write_log("Payment validation failed. Payment Booking ID: " . ($payment ? $payment->getBookingId() : 'N/A') . ", Current Booking ID: {$booking->getId()}", $log_context . '_Error');
            wp_die(__('Toss Callback: Payment to Booking mismatch.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
        }
        mphb_toss_write_log("Payment validation successful. Payment ID: {$expectPaymentId}", $log_context);

        // 콜백 타입이 'fail' (실패)인 경우
        if ($callbackType === 'fail') {
            $errorCode = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : 'USER_CANCEL'; // 오류 코드
            $errorMessage = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : __('Payment was canceled or failed.', 'mphb-toss-payments'); // 오류 메시지
            $failLog = sprintf(__('Toss Payment Failed. Code: %s, Message: %s', 'mphb-toss-payments'), $errorCode, $errorMessage); // 로그 메시지 생성
            mphb_toss_write_log("Handling FAIL callback. Payment ID: {$payment->getId()}. Code: {$errorCode}, Message: {$errorMessage}", $log_context);

            MPHB()->paymentManager()->failPayment($payment, $failLog); // MPHB 결제 실패 처리
            $booking->addLog($failLog); // 예약 로그에 실패 기록
            // 토스 결제 실패 액션 훅 실행
            do_action('mphb_toss_payment_failed', $booking, $payment, ['code' => $errorCode, 'message' => $errorMessage], $this->getId());
            // 실패 시 리디렉션할 URL 생성 (체크아웃 페이지로 에러 코드/메시지와 함께)
            $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, ['code' => $errorCode, 'message' => urlencode($errorMessage)]);
            mphb_toss_write_log("Redirecting to fail URL: {$redirectUrl}", $log_context);
            wp_safe_redirect($redirectUrl); // 안전한 리디렉션
            exit;
        }

        // 콜백 타입이 'success' (성공)이고 필수 파라미터(paymentKey, orderId, amount)가 있는 경우
        if (
            $callbackType === 'success' &&
            isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            $paymentKey  = sanitize_text_field($_GET['paymentKey']); // 토스 결제 키
            $tossOrderIdFromUrl = sanitize_text_field($_GET['orderId']); // 토스 주문 ID (URL에서 받은 값)
            $receivedAmount = round((float)$_GET['amount']); // URL에서 받은 결제 금액 (반올림)
            $expectedAmount = round((float)$payment->getAmount()); // MPHB에 기록된 예상 결제 금액 (반올림)
            mphb_toss_write_log("Handling SUCCESS callback. PaymentKey: {$paymentKey}, TossOrderID: {$tossOrderIdFromUrl}, ReceivedAmount: {$receivedAmount}, ExpectedAmount: {$expectedAmount}, PaymentID: {$payment->getId()}", $log_context);

            // 내부적으로 생성했던 예상 주문 ID
            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());
            mphb_toss_write_log("Expected Order ID for validation: {$expectedOrderId}", $log_context);

            // 금액 또는 주문 ID가 일치하지 않는 경우 (위변조 방지)
            if ($receivedAmount !== $expectedAmount || $tossOrderIdFromUrl !== $expectedOrderId) {
                $validationErrorLog = sprintf(__('Toss Payment Mismatch. Received Amount: %s (Expected: %s). Received OrderID: %s (Expected: %s)', 'mphb-toss-payments'), $receivedAmount, $expectedAmount, $tossOrderIdFromUrl, $expectedOrderId);
                mphb_toss_write_log("Payment Mismatch Error: {$validationErrorLog}. PaymentID: {$payment->getId()}", $log_context . '_Error');
                MPHB()->paymentManager()->failPayment($payment, $validationErrorLog); // 결제 실패 처리
                $booking->addLog($validationErrorLog); // 예약 로그 기록
                wp_die($validationErrorLog, __('Payment Validation Error', 'mphb-toss-payments'), ['response' => 400]); // 오류 메시지 표시 후 종료
            }
            mphb_toss_write_log("Amount and Order ID validation successful. PaymentID: {$payment->getId()}", $log_context);

            try {
                mphb_toss_write_log("Attempting to confirm payment via API. PaymentKey: {$paymentKey}, OrderID: {$tossOrderIdFromUrl}, Amount: {$expectedAmount}", $log_context);
                $is_debug_mode = TossGlobalSettingsTab::is_debug_mode(); // 디버그 모드 여부
                $tossApi = new TossAPI($this->getSecretKey(), $is_debug_mode); // 토스 API 객체 생성
                mphb_toss_write_log('TossAPI Instantiated for confirm. Secret Key (prefix): '.substr($this->getSecretKey(),0,10).'..., Debug Mode: '.($is_debug_mode?'Yes':'No'), $log_context);

                // 토스 API를 통해 결제 승인 요청
                $result = $tossApi->confirmPayment($paymentKey, $tossOrderIdFromUrl, (float)$expectedAmount);
                mphb_toss_write_log("Toss API confirmPayment Response: " . print_r(mphb_toss_sanitize_log_data($result), true), $log_context);

                // API 응답이 성공적이고 상태가 'DONE' (승인 완료)인 경우
                if ($result && isset($result->status) && $result->status === 'DONE') {
                    mphb_toss_write_log("Payment successfully confirmed by Toss API (Status: DONE). PaymentKey: {$paymentKey}, PaymentID: {$payment->getId()}", $log_context);
                    $payment->setTransactionId($paymentKey); // MPHB 결제 객체에 트랜잭션 ID(토스 결제 키) 설정
                    $note = sprintf(__('Toss Payment Approved (%s). Payment Key: %s', 'mphb-toss-payments'), $this->getTitleForUser(), $paymentKey); // 결제 완료 메모
                    
                    // API 결과로부터 실제 결제 수단 이름 가져오기
                    $paymentMethodNameFromResult = $this->getPaymentMethodNameFromResult($result);
                    // 결제 포스트 메타에 결제 타입 및 토스 결제 상세 정보 저장
                    update_post_meta($payment->getId(), '_mphb_payment_type', $paymentMethodNameFromResult);
                    update_post_meta($payment->getId(), '_mphb_toss_payment_details', $result); // API 응답 전체 저장 (민감 정보는 살균됨)
                    mphb_toss_write_log("Payment meta updated. PaymentID: {$payment->getId()}", $log_context);

                    // 각 게이트웨이별 결제 승인 후 추가 작업 처리
                    $this->afterPaymentConfirmation($payment, $booking, $result); 

                    MPHB()->paymentManager()->completePayment($payment, $note); // MPHB 결제 완료 처리
                    $booking->addLog($note); // 예약 로그에 완료 기록
                    mphb_toss_write_log("MPHB Payment completed and logged. PaymentID: {$payment->getId()}", $log_context);

                    // 토스 결제 확정 액션 훅 실행
                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result, $this->getId());

                    // 예약 완료 페이지 URL 가져오기
                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    mphb_toss_write_log("Redirecting to reservation received page: {$reservationReceivedPageUrl}", $log_context);
                    wp_safe_redirect($reservationReceivedPageUrl); // 예약 완료 페이지로 리디렉션
                    exit;
                } else { // API에서 승인 실패 또는 상태가 'DONE'이 아닌 경우
                    $apiErrorCode = $result->code ?? 'UNKNOWN_API_ERROR';
                    $apiErrorMessage = $result->message ?? __('Toss API did not confirm the payment or status was not DONE.', 'mphb-toss-payments');
                    mphb_toss_write_log("Toss API did not confirm payment or status not DONE. Code: {$apiErrorCode}, Message: {$apiErrorMessage}. PaymentID: {$payment->getId()}", $log_context . '_Error');
                    throw new TossException($apiErrorMessage, $apiErrorCode); // 토스 예외 발생
                }
            } catch (\Exception $e) { // 예외 발생 시 (TossException 포함)
                $errorLogMessage = '[Toss API Exception during confirmation]: ' . $e->getMessage();
                if ($e instanceof TossException) { // TossException인 경우 오류 코드 추가
                    $errorLogMessage .= ' (Code: ' . $e->getErrorCode() . ')';
                }
                mphb_toss_write_log("Exception during payment confirmation: {$errorLogMessage}. PaymentID: {$payment->getId()}", $log_context . '_Error', $e);

                MPHB()->paymentManager()->failPayment($payment, $errorLogMessage); // 결제 실패 처리
                $booking->addLog($errorLogMessage); // 예약 로그 기록
                // 토스 결제 실패 액션 훅 실행
                do_action('mphb_toss_payment_failed', $booking, $payment, $e, $this->getId());
                // 오류 메시지와 함께 체크아웃 페이지로 리디렉션할 파라미터 준비
                $redirectParams = ['message' => urlencode($e->getMessage())];
                if ($e instanceof TossException) {
                    $redirectParams['code'] = $e->getErrorCode();
                }
                $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, $redirectParams);
                mphb_toss_write_log("Redirecting to checkout with error: {$redirectUrl}", $log_context . '_Error');
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        // 위 조건들에 해당하지 않는 잘못된 콜백 파라미터나 타입인 경우
        mphb_toss_write_log("Invalid callback parameters or type. Callback Type: " . esc_html($callbackType) . ". GET Params: " . print_r($_GET, true), $log_context . '_Error');
        wp_die(__('Invalid callback parameters.', 'mphb-toss-payments'), __('Callback Error', 'mphb-toss-payments'), ['response' => 400]);
    }


    /**
     * 예약 정보 및 추가 파라미터를 사용하여 체크아웃 페이지로 리디렉션할 URL을 생성합니다.
     * @param Booking $booking 예약 객체
     * @param array $params 추가할 쿼리 파라미터 배열
     * @return string 생성된 URL
     */
    protected function getCheckoutRedirectUrlWithParams(Booking $booking, array $params = []): string {
        // 이 URL은 보통 호출하는 함수(성공 또는 실패 리디렉션)에서 로그로 남겨집니다.
        $checkoutPageUrl = home_url('/toss-checkout/'); // 체크아웃 페이지 기본 URL
        // 기본 리디렉션 파라미터
        $defaultParams = [
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),
            'mphb_selected_gateway_id' => $this->getId()
        ];
        // 기본 파라미터와 추가 파라미터를 병합하여 URL 생성
        $finalUrl = add_query_arg(array_merge($defaultParams, $params), $checkoutPageUrl);
        return $finalUrl;
    }

    /**
     * 토스페이먼츠 API 응답 결과로부터 결제 수단 이름을 추출합니다.
     * @param object $result 토스 API 응답 객체
     * @return string 결제 수단 이름
     */
    protected function getPaymentMethodNameFromResult($result): string {
        // 이 함수는 유틸리티 성격이 강하므로, 일반적인 결제 흐름 로깅에는 너무 상세할 수 있습니다.
        if (isset($result->method)) { // API 응답에 'method' 필드가 있는 경우
            $method = strtoupper($result->method); // 결제 수단 (대문자)
            $paymentMethodName = $this->getTitleForUser(); // 기본값은 게이트웨이 사용자 표시 제목
            switch ($method) {
                case 'CARD': // 카드
                    $name = __('Credit Card', 'mphb-toss-payments');
                    if (!empty($result->card->company)) $name = $result->card->company; // 카드사 정보
                    if (!empty($result->card->cardType)) $name .= ' (' . ucfirst(strtolower($result->card->cardType)) . ')'; // 카드 종류 (신용/체크 등)
                    $paymentMethodName = $name;
                    break;
                case 'TRANSFER': $paymentMethodName = __('Bank Transfer', 'mphb-toss-payments'); break; // 계좌이체
                case 'VIRTUAL_ACCOUNT': $paymentMethodName = __('Virtual Account', 'mphb-toss-payments'); break; // 가상계좌
                case 'MOBILE_PHONE': $paymentMethodName = __('Mobile Phone Payment', 'mphb-toss-payments'); break; // 휴대폰 결제
                case 'EASY_PAY': // 간편결제
                    $paymentMethodName = isset($result->easyPay->provider) ? $result->easyPay->provider : __('Easy Pay', 'mphb-toss-payments'); // 간편결제 제공사
                    break;
                case 'TOSSPAY': $paymentMethodName = __('TossPay', 'mphb-toss-payments'); break; // 토스페이
                case 'NAVERPAY': $paymentMethodName = __('Naver Pay', 'mphb-toss-payments'); break; // 네이버페이
                case 'KAKAOPAY': $paymentMethodName = __('Kakao Pay', 'mphb-toss-payments'); break; // 카카오페이
                default: // 그 외의 경우, 메소드 이름을 보기 좋게 변환
                    $paymentMethodName = ucwords(strtolower(str_replace("_", " ", $method))); 
                    break;
            }
            return $paymentMethodName;
        }
        // 'method' 필드가 없으면 게이트웨이 사용자 표시 제목을 사용
        $fallbackTitle = $this->getTitleForUser();
        return $fallbackTitle;
    }

    /**
     * 결제 승인 후 공통적으로 처리해야 할 작업을 수행합니다.
     * 특히 가상계좌의 경우 관련 정보를 저장합니다. 자식 클래스에서 호출될 수 있습니다.
     * @param Payment $payment 결제 객체
     * @param Booking $booking 예약 객체
     * @param object $tossResult 토스페이먼츠 API 응답 객체
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        $log_context = get_class($this) . '::afterPaymentConfirmation (Base)';
        mphb_toss_write_log(
            "Base class method. Gateway ID: " . $this->getId() . ". Payment ID: " . $payment->getId() . ". Toss Method: " . $this->getTossMethod(),
            $log_context
        );

        // 현재 게이트웨이의 토스 결제 방식이 'VIRTUAL_ACCOUNT' (가상계좌)이고, API 응답에 가상계좌 정보가 있는 경우
        if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && isset($tossResult->virtualAccount)) {
            $vAccount = $tossResult->virtualAccount; // 가상계좌 정보 객체
            mphb_toss_write_log("Base: Processing VIRTUAL_ACCOUNT data. AccountNumber: " . ($vAccount->accountNumber ?? 'N/A') . ", DueDate: " . ($vAccount->dueDate ?? 'N/A'), $log_context);
            // 결제 포스트 메타에 가상계좌 관련 정보 저장
            update_post_meta($payment->getId(), '_mphb_toss_vbank_account_number', $vAccount->accountNumber ?? ''); // 계좌번호
            update_post_meta($payment->getId(), '_mphb_toss_vbank_bank_code', $vAccount->bankCode ?? ''); // 은행 코드
            update_post_meta($payment->getId(), '_mphb_toss_vbank_customer_name', $vAccount->customerName ?? ''); // 예금주
            update_post_meta($payment->getId(), '_mphb_toss_vbank_due_date', $vAccount->dueDate ?? ''); // 입금 기한
            update_post_meta($payment->getId(), '_mphb_toss_vbank_status', $vAccount->status ?? ''); // 가상계좌 상태
        } else {
            // 가상계좌 방식인데 가상계좌 정보가 없는 경우 (경고 로그)
            if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && !isset($tossResult->virtualAccount)) {
                mphb_toss_write_log("Base: VIRTUAL_ACCOUNT method but virtualAccount object not found in TossResult.", $log_context . "_Warning");
            }
        }
    }

    /**
     * 사용자에게 표시될 게이트웨이 제목을 반환합니다.
     * 설정에서 저장된 값을 우선 사용하고, 없으면 기본값을 사용합니다.
     * @return string 게이트웨이 제목
     */
    public function getTitleForUser(): string {
        $title = $this->get_gateway_option('title', $this->getDefaultTitle());
        return $title;
    }

    /**
     * 사용자에게 표시될 게이트웨이 설명을 반환합니다.
     * 설정에서 저장된 값을 우선 사용하고, 없으면 기본값을 사용합니다.
     * @return string 게이트웨이 설명
     */
    public function getDescriptionForUser(): string {
        $description = $this->get_gateway_option('description', $this->getDefaultDescription());
        return $description;
    }

    /**
     * 게이트웨이 옵션 값을 가져오는 헬퍼 함수입니다.
     * MPHB의 getOption 메소드를 호출합니다.
     * @param string $optionName 옵션 이름
     * @param mixed $defaultValue 기본값
     * @return mixed 옵션 값
     */
    public function get_gateway_option(string $optionName, $defaultValue = null) {
        return $this->getOption($optionName, $defaultValue);
    }
}

