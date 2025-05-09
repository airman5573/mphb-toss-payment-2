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
        $gatewayId = $this->getId(); // 예: "toss_card"

        $mainGroupFields = [
            FieldFactory::create("mphb_payment_gateway_{$gatewayId}_title", [
                'type'         => 'text',
                'label'        => __('Title', 'motopress-hotel-booking'),
                'default'      => $this->getDefaultTitle(),
                'translatable' => true,
            ]),
            FieldFactory::create("mphb_payment_gateway_{$gatewayId}_description", [
                'type'         => 'textarea',
                'label'        => __('Description', 'motopress-hotel-booking'),
                'default'      => $this->getDefaultDescription(),
                'translatable' => true,
                'size'         => 'regular'
            ]),
            // 여기에 각 게이트웨이별 특정 옵션을 추가할 수 있습니다.
            // 예를 들어 가상계좌의 경우 입금 기한 설정 등
        ];

        // SSL 경고 (필요시)
        if (!MPHB()->isSiteSSL()) {
            $sslWarn = '<strong>' . __('Warning:', 'mphb-toss-payments') . '</strong> ' . __('Toss Payments requires an SSL certificate (HTTPS) to function correctly. Please secure your site.', 'mphb-toss-payments');
            // MPHB 에는 enable 필드가 기본으로 생성되므로, description을 추가하는 방식은 다를 수 있습니다.
            // 만약 enable 필드를 직접 생성한다면 거기에 description을 추가.
            // 여기서는 일단 주석 처리.
            // $enableField = $subTab->findField("mphb_payment_gateway_{$gatewayId}_enable");
            // if ($enableField) {
            //    $current_desc = $enableField->getDescription();
            //    $enableField->setDescription($current_desc . '<p class="notice notice-warning" style="padding:1em;">' . $sslWarn . '</p>');
            // }
        }


        $mainGroup = new SettingsGroup(
            "mphb_payments_{$gatewayId}_main_settings", // 그룹 ID를 고유하게 변경
            '', // 그룹 제목 (비워둠)
            $subTab->getOptionGroupName()
        );
        $mainGroup->addFields($mainGroupFields);
        $subTab->addGroup($mainGroup);
    }

    /**
     * 게이트웨이가 활성화될 수 있는 조건인지 확인합니다. (MPHB에 의해 호출됨)
     * 전역 API 키 설정 및 통화(KRW) 확인
     */
    public function isActive(): bool {
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        $global_client_key = TossGlobalSettingsTab::get_global_client_key();
        $global_secret_key = TossGlobalSettingsTab::get_global_secret_key();

        return parent::isActive() && // MPHB의 기본 활성화 조건 (enabled 체크 등)
               !empty($global_client_key) &&
               !empty($global_secret_key) &&
               $currency === 'KRW';
    }

    /**
     * 게이트웨이가 실제로 활성화되어 있는지 여부. (MPHB에 의해 호출됨)
     * isActive()와 동일하게 사용될 수 있음.
     */
    public function isEnabled(): bool {
        return $this->isActive();
    }

    /**
     * 전역 설정에서 클라이언트 키를 가져옵니다.
     */
    public function getClientKey(): string {
        return TossGlobalSettingsTab::get_global_client_key();
    }

    /**
     * 전역 설정에서 시크릿 키를 가져옵니다.
     * 테스트 모드에 따라 다른 키를 반환하도록 확장 가능.
     */
    public function getSecretKey(): string {
        // 예시: 테스트 모드에 따라 다른 키를 반환하려면
        // if (TossGlobalSettingsTab::is_test_mode()) {
        //     return get_option('mphb_toss_global_test_secret_key', ''); // 별도 테스트 시크릿 키 옵션 필요
        // }
        return TossGlobalSettingsTab::get_global_secret_key();
    }

    /**
     * 결제 처리 시작. 체크아웃 숏코드 페이지로 리다이렉트합니다.
     * 각 하위 게이트웨이는 이 메소드를 사용하여 자신의 정보(toss method, gateway_id)를 전달합니다.
     */
    public function processPayment(Booking $booking, Payment $payment): array {
        // MPHB는 이 메소드가 배열을 반환하기를 기대하지 않지만, 역사적으로 일부 게이트웨이는 그랬습니다.
        // 안전하게 빈 배열이나 리다이렉트 후 exit 하도록 합니다.

        $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl(); // MPHB 체크아웃 페이지 URL
        if (empty($checkoutPageUrl)) {
            $checkoutPageUrl = home_url('/toss-checkout/'); // 사용자 정의 숏코드 페이지 URL로 대체
        }


        $returnUrl = add_query_arg([
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),      // 예: "CARD"
            'mphb_selected_gateway_id' => $this->getId()               // 예: "toss_card"
        ], $checkoutPageUrl);

        wp_redirect($returnUrl);
        exit;
    }

    /**
     * 모든 토스페이먼츠 콜백을 처리하는 정적 핸들러입니다.
     * URL의 mphb_payment_gateway 값을 보고 적절한 게이트웨이 인스턴스를 찾아 처리를 위임합니다.
     */
    public static function handleTossCallbackStatic() {
        // 콜백 요청인지 확인 (필수 파라미터 체크)
        if (
            !isset($_GET['mphb_payment_gateway']) ||
            strpos($_GET['mphb_payment_gateway'], self::MPHB_GATEWAY_ID_PREFIX) !== 0 || // "toss_"로 시작하는지
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key'])
        ) {
            return;
        }

        $gatewayIdFromUrl = sanitize_text_field($_GET['mphb_payment_gateway']);
        $gatewayInstance = MPHB()->gatewayManager()->getGateway($gatewayIdFromUrl);

        if (!$gatewayInstance || !($gatewayInstance instanceof self)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MPHB Toss Payments] Callback Error: Gateway instance not found or not a TossGatewayBase for ID: ' . $gatewayIdFromUrl);
            }
            // 오류 페이지로 리다이렉트하거나 wp_die 처리
            wp_die(__('Invalid payment gateway specified in callback.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
            return;
        }

        // 해당 게이트웨이 인스턴스의 콜백 처리 메소드 호출
        $gatewayInstance->handleInstanceTossCallback();
    }

    /**
     * 실제 콜백 로직을 처리하는 인스턴스 메소드입니다.
     */
    public function handleInstanceTossCallback() {
        $callbackType = sanitize_text_field($_GET['callback_type']);
        $bookingId    = absint($_GET['booking_id']);
        $bookingKey   = sanitize_text_field($_GET['booking_key']);

        // 예약 및 결제 객체 로드 및 검증
        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (!$booking || $booking->getKey() !== $bookingKey) {
            wp_die(__('Toss Callback: Booking validation failed.', 'mphb-toss-payments'), __('Booking Error', 'mphb-toss-payments'), ['response' => 403]);
        }

        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) {
             wp_die(__('Toss Callback: No pending payment found for this booking.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 404]);
        }
        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) {
            wp_die(__('Toss Callback: Payment to Booking mismatch.', 'mphb-toss-payments'), __('Payment Error', 'mphb-toss-payments'), ['response' => 400]);
        }

        // 실패 콜백 처리
        if ($callbackType === 'fail') {
            $errorCode = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : 'USER_CANCEL';
            $errorMessage = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : __('Payment was canceled or failed.', 'mphb-toss-payments');
            $failLog = sprintf(__('Toss Payment Failed. Code: %s, Message: %s', 'mphb-toss-payments'), $errorCode, $errorMessage);

            MPHB()->paymentManager()->failPayment($payment, $failLog);
            $booking->addLog($failLog);

            do_action('mphb_toss_payment_failed', $booking, $payment, ['code' => $errorCode, 'message' => $errorMessage], $this->getId());

            // 체크아웃 페이지로 에러 코드와 메시지를 전달하며 리다이렉트
            $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, [
                'code'    => $errorCode,
                'message' => urlencode($errorMessage) // URL 인코딩
            ]);
            wp_safe_redirect($redirectUrl);
            exit;
        }

        // 성공 콜백 처리 (필수 파라미터 확인)
        if (
            $callbackType === 'success' &&
            isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            $paymentKey  = sanitize_text_field($_GET['paymentKey']);
            $tossOrderId = sanitize_text_field($_GET['orderId']);
            $receivedAmount = round((float)$_GET['amount']); // 토스에서 온 금액
            $expectedAmount = round((float)$payment->getAmount()); // MPHB 결제 금액

            // 주문 ID 및 금액 검증
            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());
            if ($receivedAmount !== $expectedAmount || $tossOrderId !== $expectedOrderId) {
                $validationErrorLog = sprintf(
                    __('Toss Payment Mismatch. Received Amount: %s (Expected: %s). Received OrderID: %s (Expected: %s)', 'mphb-toss-payments'),
                    $receivedAmount, $expectedAmount, $tossOrderId, $expectedOrderId
                );
                MPHB()->paymentManager()->failPayment($payment, $validationErrorLog);
                $booking->addLog($validationErrorLog);
                wp_die($validationErrorLog, __('Payment Validation Error', 'mphb-toss-payments'), ['response' => 400]);
            }

            try {
                $tossApi = new TossAPI($this->getSecretKey(), TossGlobalSettingsTab::is_debug_mode());
                $result = $tossApi->confirmPayment($paymentKey, $tossOrderId, (float)$expectedAmount);

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    $payment->setTransactionId($paymentKey); // 토스 거래 ID (paymentKey) 저장
                    $note = sprintf(
                        __('Toss Payment Approved (%s). Payment Key: %s', 'mphb-toss-payments'),
                        $this->getTitleForUser(), // 사용자에게 보여질 게이트웨이 이름
                        $paymentKey
                    );

                    // API 응답에서 실제 사용된 결제 수단명 추출 및 저장
                    $paymentMethodName = $this->getPaymentMethodNameFromResult($result);
                    update_post_meta($payment->getId(), '_mphb_payment_type', $paymentMethodName); // 결제 방식 저장 (예: 신용카드, 계좌이체)
                    update_post_meta($payment->getId(), '_mphb_toss_payment_details', $result); // 전체 응답 저장 (디버깅 및 추가 정보 활용)

                    // 각 게이트웨이별 추가 처리 (예: 가상계좌 정보 저장)
                    $this->afterPaymentConfirmation($payment, $booking, $result);

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);
                    // $booking->confirm(); // 예약 확정 (필요시)

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result, $this->getId());

                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    wp_safe_redirect($reservationReceivedPageUrl);
                    exit;
                } else {
                    // API 응답이 DONE이 아닌 경우
                    $apiErrorCode = $result->code ?? 'UNKNOWN';
                    $apiErrorMessage = $result->message ?? __('Toss API did not confirm the payment.', 'mphb-toss-payments');
                    throw new TossException($apiErrorMessage, $apiErrorCode);
                }
            } catch (\Exception $e) {
                $errorLog = '[Toss API Exception during confirmation]: ' . $e->getMessage();
                if ($e instanceof TossException) {
                    $errorLog .= ' (Code: ' . $e->getErrorCode() . ')';
                }
                MPHB()->paymentManager()->failPayment($payment, $errorLog);
                $booking->addLog($errorLog);

                do_action('mphb_toss_payment_failed', $booking, $payment, $e, $this->getId());

                $redirectParams = ['message' => urlencode($e->getMessage())];
                if ($e instanceof TossException) {
                    $redirectParams['code'] = $e->getErrorCode();
                }
                $redirectUrl = $this->getCheckoutRedirectUrlWithParams($booking, $redirectParams);
                wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        // 그 외의 경우 (예: callback_type이 success/fail이 아닌 경우)
        wp_die(__('Invalid callback parameters.', 'mphb-toss-payments'), __('Callback Error', 'mphb-toss-payments'), ['response' => 400]);
    }


    /**
     * 체크아웃 페이지로 파라미터와 함께 리다이렉트할 URL을 생성합니다.
     */
    protected function getCheckoutRedirectUrlWithParams(Booking $booking, array $params = []): string {
        $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl();
        if (empty($checkoutPageUrl)) {
            $checkoutPageUrl = home_url('/toss-checkout/'); // 사용자 정의 숏코드 페이지 URL
        }

        $defaultParams = [
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => $this->getTossMethod(),
            'mphb_selected_gateway_id' => $this->getId()
        ];
        return add_query_arg(array_merge($defaultParams, $params), $checkoutPageUrl);
    }

    /**
     * 토스페이먼츠 API 응답에서 사용자에게 보여줄 결제 수단 이름을 추출합니다.
     */
    protected function getPaymentMethodNameFromResult($result): string {
        if (isset($result->method)) {
            switch (strtoupper($result->method)) {
                case 'CARD':
                    return !empty($result->card->company) ? $result->card->company : __('신용카드', 'mphb-toss-payments');
                case 'TRANSFER':
                    return __('계좌이체', 'mphb-toss-payments');
                case 'VIRTUAL_ACCOUNT':
                    return __('가상계좌', 'mphb-toss-payments');
                case 'MOBILE_PHONE':
                    return __('휴대폰 소액결제', 'mphb-toss-payments');
                case 'TOSSPAY':
                    return __('토스페이', 'mphb-toss-payments');
                // 더 많은 간편결제 (카카오페이, 네이버페이 등) case 추가 가능
                default:
                    return ucfirst(strtolower(str_replace("_", " ", $result->method))); // 예: Gift_Certificate -> Gift Certificate
            }
        }
        return $this->getTitleForUser(); // 기본값으로 게이트웨이 제목 사용
    }

    /**
     * 결제 승인 후 각 하위 게이트웨이별로 추가적인 처리를 할 수 있는 메소드입니다.
     * (예: 가상계좌 정보 저장 등)
     * 하위 클래스에서 필요에 따라 오버라이드합니다.
     */
    protected function afterPaymentConfirmation(Payment $payment, Booking $booking, $tossResult) {
        // 기본적으로 아무 작업도 하지 않음.
        // 예시: 가상계좌 정보 저장
        if (strtoupper($this->getTossMethod()) === 'VIRTUAL_ACCOUNT' && isset($tossResult->virtualAccount)) {
            update_post_meta($payment->getId(), '_mphb_toss_vbank_account_number', $tossResult->virtualAccount->accountNumber);
            update_post_meta($payment->getId(), '_mphb_toss_vbank_bank_code', $tossResult->virtualAccount->bankCode);
            update_post_meta($payment->getId(), '_mphb_toss_vbank_holder_name', $tossResult->virtualAccount->customerName); // 예금주
            update_post_meta($payment->getId(), '_mphb_toss_vbank_due_date', $tossResult->virtualAccount->dueDate); // 입금 기한
        }
    }

    /**
     * 사용자에게 보여질 게이트웨이의 제목을 반환합니다. (MPHB 설정값 우선)
     */
    public function getTitleForUser(): string {
        return $this->get_option('title', $this->getDefaultTitle());
    }

    /**
     * 사용자에게 보여질 게이트웨이 설명을 반환합니다. (MPHB 설정값 우선)
     */
    public function getDescriptionForUser(): string {
        return $this->get_option('description', $this->getDefaultDescription());
    }

}
