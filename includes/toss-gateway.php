<?php
namespace MPHBTOSS;

use MPHB\Entities\Booking;
use MPHB\Entities\Payment;
use MPHB\Admin\Groups;
use MPHB\Admin\Fields;
use MPHB\PostTypes\PaymentCPT\Statuses as PaymentStatuses;
use MPHBTOSS\TossAPI;
use MPHBTOSS\TossException;

if (!defined('ABSPATH')) {
    exit;
}

class TossGateway extends \MPHB\Payments\Gateways\Gateway
{
    const GATEWAY_ID = 'toss'; // 이 상수는 이제 단일 게이트웨이 ID를 나타냅니다.
                               // 여러 "toss_card", "toss_bank" 등을 포괄하지 않습니다.
                               // 이 클래스가 모든 "toss_*" 콜백을 받는다면, 이 상수 사용은 부적절할 수 있습니다.

    protected $clientKey = '';
    protected $secretKey = '';
    protected $enabled = true;

    public function __construct()
    {
        parent::__construct();
        $this->registerHooks();
    }

    public function registerOptionsFields(&$subTab): void
    {
        $gatewayId = $this->getId();

        $mainGroupFields = [
            Fields\FieldFactory::create("mphb_payment_gateway_{$gatewayId}_title", [
                'type'         => 'text',
                'label'        => __('Title', 'motopress-hotel-booking'),
                'default'      => 'Toss Payments',
                'translatable' => true,
            ]),
            Fields\FieldFactory::create("mphb_payment_gateway_{$gatewayId}_description", [
                'type'         => 'textarea',
                'label'        => __('Description', 'motopress-hotel-booking'),
                'default'      => __('', 'motopress-hotel-booking'),
                'translatable' => true,
                'size'         => 'regular'
            ]),
        ];

        $mainGroup = new Groups\SettingsGroup(
            "mphb_payments_{$gatewayId}_main",
            '',
            $subTab->getOptionGroupName()
        );
        $mainGroup->addFields($mainGroupFields);
        $subTab->addGroup($mainGroup);

        if (!MPHB()->isSiteSSL()) {
            $sslWarn = __('<strong>권고:</strong> 사이트에 SSL(https://)을 적용해 주세요. Toss 결제는 SSL 환경에서만 정상적으로 동작합니다.', 'mphb-toss');
            // MPHB는 enable 필드를 자동으로 생성하므로, description을 직접 추가하기 어려울 수 있습니다.
            // $enableField = $subTab->findField("mphb_payment_gateway_{$gatewayId}_enable");
            // if ($enableField) {
            //     $currentDesc = $enableField->getDescription();
            //     $enableField->setDescription($currentDesc . '<p class="notice notice-warning" style="padding:1em;">' . wp_kses_post($sslWarn) . '</p>');
            // }
        }
    }

    protected function registerHooks(): void
    {
        // 이 콜백 핸들러가 여전히 사용된다고 가정합니다.
        add_action('init', [$this, 'handleTossCallback'], 11);
    }

    protected function initId(): string
    {
        // 이 ID는 MPHB 설정에서 이 게이트웨이가 어떤 ID로 등록될지를 결정합니다.
        // 만약 'toss'라는 단일 ID로 등록하고, 콜백에서 'toss_card' 등을 받는다면,
        // 콜백 검증 로직과 이 ID 설정 간의 관계를 명확히 해야 합니다.
        return self::GATEWAY_ID; // 'toss'
    }

    protected function initDefaultOptions(): array
    {
        return array_merge(parent::initDefaultOptions(), [
            'title'       => __('Toss Payments (General)', 'mphb-toss'), // 제목 변경 고려
            'description' => __('Pay via Toss Payments.', 'mphb-toss'),
        ]);
    }

    protected function setupProperties(): void
    {
        parent::setupProperties();
        $this->adminTitle    = __('Toss Payments (Main Handler)', 'mphb-toss'); // 관리자 제목 변경 고려
    }

    public function isActive()
    {
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        $global_client_key = TossGlobalSettingsTab::get_global_client_key();
        $global_secret_key = TossGlobalSettingsTab::get_global_secret_key();

        return parent::isActive() &&
            !empty($global_client_key) &&
            !empty($global_secret_key) &&
            $currency === 'KRW';
    }

    public function isEnabled()
    {
        return $this->isActive();
    }

    public function getClientKey()
    {
        return TossGlobalSettingsTab::get_global_client_key();
    }

    public function getSecretKey()
    {
        return TossGlobalSettingsTab::get_global_secret_key();
    }

    /**
     * 결제 처리 시작.
     * 여기서 어떤 `mphb_selected_gateway_id`와 `mphb_gateway_method`로 리다이렉트할지 결정해야 합니다.
     * 현재는 단일 'toss' 게이트웨이로 동작하므로, 특정 결제 수단(예: 카드)으로 고정하거나,
     * 사용자에게 선택 UI를 제공하고 그 값을 사용해야 합니다.
     * 지금은 예시로 'toss_card'와 'CARD'를 사용합니다.
     */
    public function processPayment(Booking $booking, Payment $payment): array
    {
        $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl();
        if (empty($checkoutPageUrl)) {
            $checkoutPageUrl = home_url('/toss-checkout/');
        }

        $returnUrl = add_query_arg([
            'booking_id'               => $booking->getId(),
            'booking_key'              => $booking->getKey(),
            'mphb_gateway_method'      => 'CARD', // 예시: 기본값으로 카드 사용
            'mphb_selected_gateway_id' => 'toss_card' // 예시: 기본값으로 카드 게이트웨이 ID 사용
                                                   // 실제로는 이 값을 동적으로 결정해야 함
        ], $checkoutPageUrl);

        wp_redirect($returnUrl);
        exit;
    }

    /**
     * Toss 결제 콜백 ("성공", "실패" 리다이렉트 모두 처리)
     * - booking_id와 booking_key 모두를 이용.
     * - mphb_selected_gateway_id가 "toss_"로 시작하는지 확인.
     */
    public function handleTossCallback()
    {
        // 콜백 요청인지, 그리고 mphb_selected_gateway_id가 "toss_"로 시작하는지 확인
        if (
            !isset($_GET['mphb_selected_gateway_id']) ||
            strpos(sanitize_text_field($_GET['mphb_selected_gateway_id']), 'toss_') !== 0 || // <<< 여기가 수정된 부분입니다.
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key'])
        ) {
            // 조건에 맞지 않으면 처리 중단
            // 디버깅 목적으로 로그를 남길 수 있습니다.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $log_msg = '[MPHB TossGateway] Callback skipped. Missing params or invalid mphb_selected_gateway_id. ';
                if(isset($_GET['mphb_selected_gateway_id'])) $log_msg .= 'mphb_selected_gateway_id: ' . sanitize_text_field($_GET['mphb_selected_gateway_id']);
                error_log($log_msg);
            }
            return;
        }

        // URL에서 전달된 실제 게이트웨이 ID (예: "toss_card")
        $selected_gateway_id_from_url = sanitize_text_field($_GET['mphb_selected_gateway_id']);

        // --- 중요 ---
        // 이 TossGateway 클래스는 initId()에서 'toss'라는 단일 ID로 자신을 등록합니다.
        // 하지만 콜백은 'toss_card', 'toss_bank' 등 구체적인 ID로 올 수 있습니다.
        // 이 경우, 이 클래스가 모든 'toss_*' 콜백을 처리하는 "대표" 핸들러 역할을 해야 합니다.
        // 또는, 각 'toss_card', 'toss_bank' 등이 별도의 게이트웨이 클래스로 등록되고
        // 각자 자신의 콜백을 처리하도록 구조를 변경해야 합니다. (이전 제안 방식)
        //
        // 현재 코드는 이 TossGateway 클래스가 모든 'toss_*' 콜백을 받는다고 가정하고 진행합니다.
        // 이 경우, $selected_gateway_id_from_url을 사용하여 어떤 결제 방식이었는지
        // 내부적으로 구분하여 처리해야 합니다.

        $callbackType = sanitize_text_field($_GET['callback_type']);
        $bookingId    = absint($_GET['booking_id']);
        $bookingKey   = sanitize_text_field($_GET['booking_key']);

        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (
            !$booking ||
            !($booking instanceof \MPHB\Entities\Booking) ||
            $booking->getKey() !== $bookingKey
        ) {
            wp_die(
                __('Toss Callback: Booking validation failed.', 'mphb-toss-payments'),
                __('Booking Error', 'mphb-toss-payments'),
                ['response' => 403]
            );
        }

        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) {
             wp_die(
                __('Toss Callback: No pending payment found for this booking.', 'mphb-toss-payments'),
                __('Payment Error', 'mphb-toss-payments'),
                ['response' => 404]
            );
        }
        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) {
            wp_die(
                __('Toss Callback: Payment to Booking mismatch.', 'mphb-toss-payments'),
                __('Payment Error', 'mphb-toss-payments'),
                ['response' => 400]
            );
        }

        if ($callbackType === 'fail') {
            $errorCode = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : 'USER_CANCEL';
            $errorMessage = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : __('Payment was canceled or failed by user.', 'mphb-toss-payments');
            $failLog = sprintf(__('Toss Payment Failed via %s. Code: %s, Message: %s', 'mphb-toss-payments'), $selected_gateway_id_from_url, $errorCode, $errorMessage);

            MPHB()->paymentManager()->failPayment($payment, $failLog);
            $booking->addLog($failLog);

            do_action('mphb_toss_payment_failed', $booking, $payment, ['code' => $errorCode, 'message' => $errorMessage], $selected_gateway_id_from_url);

            $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl();
            if (empty($checkoutPageUrl)) {
                $checkoutPageUrl = home_url('/toss-checkout/');
            }
            $returnUrl = add_query_arg([
                'booking_id'               => $booking->getId(),
                'booking_key'              => $booking->getKey(),
                'mphb_gateway_method'      => isset($_GET['mphb_gateway_method']) ? sanitize_text_field($_GET['mphb_gateway_method']) : '', // 실패 시에도 이 값들을 유지하여 재시도 UI에 활용
                'mphb_selected_gateway_id' => $selected_gateway_id_from_url,
                'code'                     => $errorCode,
                'message'                  => urlencode($errorMessage),
            ], $checkoutPageUrl);
            wp_safe_redirect($returnUrl);
            exit;
        }

        if (
            $callbackType === 'success'
            && isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            $paymentKey  = sanitize_text_field($_GET['paymentKey']);
            $tossOrderId = sanitize_text_field($_GET['orderId']);
            $receivedAmt = round(floatval($_GET['amount']));
            $expectedAmt = round(floatval($payment->getAmount()));

            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());

            if ($receivedAmt !== $expectedAmt || $tossOrderId !== $expectedOrderId) {
                $validationErrorLog = sprintf(
                    __('Toss Payment Mismatch for %s. Received Amount: %s (Expected: %s). Received OrderID: %s (Expected: %s)', 'mphb-toss-payments'),
                    $selected_gateway_id_from_url, $receivedAmt, $expectedAmt, $tossOrderId, $expectedOrderId
                );
                MPHB()->paymentManager()->failPayment($payment, $validationErrorLog);
                $booking->addLog($validationErrorLog);
                wp_die($validationErrorLog, __('Payment Validation Error', 'mphb-toss-payments'), ['response' => 400]);
            }
            try {
                // 시크릿 키는 전역 설정을 사용합니다.
                $tossApi = new TossAPI(TossGlobalSettingsTab::get_global_secret_key(), TossGlobalSettingsTab::is_debug_mode());
                $result = $tossApi->confirmPayment($paymentKey, $tossOrderId, (float)$expectedAmt);
                function_exists('ray') && ray('[TossGateway::handleTossCallback] Confirm Result for ' . $selected_gateway_id_from_url, $result);


                if ($result && isset($result->status) && $result->status === 'DONE') {
                    $payment->setTransactionId($paymentKey);

                    // 실제 사용된 결제 방식에 따라 노트와 메타데이터를 다르게 기록할 수 있습니다.
                    $paymentMethodName = $result->method ?? 'Toss Payments'; // API 응답의 method 사용
                    $cardInfoString = '';
                    if (strtoupper($paymentMethodName) === 'CARD' && isset($result->card)) {
                        $card = $result->card;
                        $paymentMethodName = $card->company ?? __('Card', 'mphb-toss-payments'); // 카드사 이름으로
                        if(!empty($card->cardType)) $paymentMethodName .= ' (' . $card->cardType . ')';
                        $cardInfoString = sprintf(' (%s: %s, Inst: %d m)', $card->company ?? '', $card->number ?? 'N/A', $card->installmentPlanMonths ?? 0);
                        // 카드 관련 메타데이터 저장
                        update_post_meta($payment->getId(), '_mphb_toss_card_details', $card);
                    } elseif (strtoupper($paymentMethodName) === 'VIRTUAL_ACCOUNT' && isset($result->virtualAccount)) {
                        $vAccount = $result->virtualAccount;
                        $paymentMethodName = __('Virtual Account', 'mphb-toss-payments');
                        $cardInfoString = sprintf(' (%s %s, Holder: %s, Due: %s)', $vAccount->bankCode ?? '', $vAccount->accountNumber ?? '', $vAccount->customerName ?? '', $vAccount->dueDate ?? '');
                         // 가상계좌 관련 메타데이터 저장
                        update_post_meta($payment->getId(), '_mphb_toss_virtual_account_details', $vAccount);
                    }
                    // TODO: 다른 결제 수단(계좌이체 등)에 대한 상세 정보 처리 추가

                    $note = sprintf(__('Toss Payment Approved via %s. Payment Key: %s%s', 'mphb-toss-payments'), $paymentMethodName, $paymentKey, $cardInfoString);

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);

                    update_post_meta( $payment->getId(), '_mphb_payment_type', $paymentMethodName ); // 주 결제 방식 저장
                    update_post_meta( $payment->getId(), '_mphb_toss_selected_gateway', $selected_gateway_id_from_url); // 어떤 MPHB 게이트웨이 ID로 들어왔는지 기록
                    update_post_meta( $payment->getId(), '_mphb_toss_api_response', $result); // 전체 응답 저장 (디버깅 및 추후 활용)

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result, $selected_gateway_id_from_url);

                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    wp_safe_redirect($reservationReceivedPageUrl);
                    exit;
                } else {
                    $apiErrorCode = $result->code ?? 'UNKNOWN';
                    $apiErrorMessage = $result->message ?? __('Toss API did not confirm the payment.', 'mphb-toss-payments');
                    throw new TossException($apiErrorMessage, $apiErrorCode);
                }
            } catch (\Exception $e) {
                $errorLog = sprintf('[Toss API Exception for %s]: ', $selected_gateway_id_from_url) . $e->getMessage();
                if ($e instanceof TossException) {
                    $errorLog .= ' (Code: ' . $e->getErrorCode() . ')';
                }

                MPHB()->paymentManager()->failPayment($payment, $errorLog);
                $booking->addLog($errorLog);

                do_action('mphb_toss_payment_failed', $booking, $payment, $e, $selected_gateway_id_from_url);

                $checkoutPageUrl = MPHB()->settings()->pages()->getCheckoutPageUrl();
                if (empty($checkoutPageUrl)) {
                    $checkoutPageUrl = home_url('/toss-checkout/');
                }
                $returnUrl = add_query_arg([
                    'booking_id'  => $booking->getId(),
                    'booking_key' => $booking->getKey(),
                    'mphb_gateway_method'      => isset($_GET['mphb_gateway_method']) ? sanitize_text_field($_GET['mphb_gateway_method']) : '',
                    'mphb_selected_gateway_id' => $selected_gateway_id_from_url,
                    'code'        => ($e instanceof TossException) ? $e->getErrorCode() : 'EXCEPTION',
                    'message'     => urlencode($e->getMessage()),
                ], $checkoutPageUrl);
                wp_safe_redirect($returnUrl);
                exit;
            }
        }

        // 위의 조건들에 해당하지 않는 경우 (예: callback_type이 success/fail이 아니거나, 필수 파라미터 누락)
        wp_die(__('Invalid callback parameters from Toss Payments.', 'mphb-toss-payments'), __('Callback Error', 'mphb-toss-payments'), ['response' => 400]);
    }

    // getFailureRedirectUrl 메소드는 이 클래스에서 직접 사용되지 않을 수 있으나, 유틸리티로 남겨둘 수 있습니다.
    protected function getFailureRedirectUrl(?Booking $booking, string $reason): string
    {
        // ... (이전과 동일) ...
        $pages = MPHB()->settings()->pages();
        $url = method_exists($pages, 'getPaymentFailedPageUrl') ? $pages->getPaymentFailedPageUrl() : '';

        if (!$url && $booking instanceof Booking) {
            $url = $booking->getCheckoutUrl(); // MPHB 체크아웃 페이지
        }
        if (!$url) { // 그래도 없으면 사용자 정의 숏코드 페이지
            $url = home_url('/toss-checkout/');
        }
        if (!$url) { // 최후의 보루
            $url = home_url('/');
        }

        $args = [
            'mphb_payment_status' => 'failed',
            'mphb_gateway'        => $this->getId(), // 이 게이트웨이의 ID ('toss')
            'reason'              => urlencode($reason),
        ];
        if ($booking) {
            $args['booking_id'] = $booking->getId();
            $args['booking_key'] = $booking->getKey();
        }

        return add_query_arg($args, $url);
    }
}
