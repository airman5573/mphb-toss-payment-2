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
    const GATEWAY_ID = 'toss';

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
                'default'      => __('Pay with Toss Payments.', 'motopress-hotel-booking'),
                'translatable' => true,
            ]),
        ];

        $mainGroup = new Groups\SettingsGroup(
            "mphb_payments_{$gatewayId}_main",
            '',
            $subTab->getOptionGroupName()
        );
        $mainGroup->addFields($mainGroupFields);
        $subTab->addGroup($mainGroup);

        $apiGroupFields = [
            Fields\FieldFactory::create("mphb_payment_gateway_{$gatewayId}_client_key", [
                'type'        => 'text',
                'label'       => __('Client Key', 'mphb-toss'),
                'default'     => '',
                'description' => __('Enter your Toss Payments Client Key.', 'mphb-toss'),
            ]),
            Fields\FieldFactory::create("mphb_payment_gateway_{$gatewayId}_secret_key", [
                'type'        => 'text',
                'label'       => __('Secret Key', 'mphb-toss'),
                'default'     => '',
                'description' => __('Enter your Toss Payments Secret Key.', 'mphb-toss'),
            ]),
        ];

        $apiGroup = new Groups\SettingsGroup(
            "mphb_payments_{$gatewayId}_api",
            __('API Settings', 'mphb-toss'),
            $subTab->getOptionGroupName()
        );
        $apiGroup->addFields($apiGroupFields);
        $subTab->addGroup($apiGroup);

        if (!MPHB()->isSiteSSL()) {
            $sslWarn = __('<strong>권고:</strong> 사이트에 SSL(https://)을 적용해 주세요. Toss 결제는 SSL 환경에서만 정상적으로 동작합니다.', 'mphb-toss');
            $enableField = $subTab->findField("mphb_payment_gateway_{$gatewayId}_enable");
            if ($enableField) {
                $enableField->setDescription($sslWarn);
            }
        }
    }

    protected function registerHooks(): void
    {
        add_action('init', [$this, 'handleTossCallback'], 11);
    }

    protected function initId(): string
    {
        return self::GATEWAY_ID;
    }

    protected function initDefaultOptions(): array
    {
        return array_merge(parent::initDefaultOptions(), [
            'title'       => __('The Toss Payments Credit Card', 'mphb-toss'),
            'description' => __('Pay with your credit card via Toss Payments.', 'mphb-toss'),
            'client_key'  => '',
            'secret_key'  => '',
        ]);
    }

    protected function setupProperties(): void
    {
        parent::setupProperties();
        $this->adminTitle    = __('Toss Payments', 'mphb-toss');
        $this->clientKey     = $this->getOption('client_key');
        $this->secretKey     = $this->getOption('secret_key');
    }

    public function isActive()
    {
        return true;
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        return parent::isActive() &&
            !empty($this->getClientKey()) &&
            !empty($this->getSecretKey()) &&
            $currency === 'KRW';
    }

    public function isEnabled()
    {
        return $this->isActive();
    }

    public function getClientKey()
    {
        return $this->getOption('client_key');
    }

    public function getSecretKey()
    {
        return $this->getOption('secret_key');
    }

    public function processPayment(Booking $booking, Payment $payment): array
    {
        $returnUrl = add_query_arg([
            'booking_id'  => $booking->getId(),
            'booking_key' => $booking->getKey(),
        ], home_url('/toss-checkout'));
        wp_redirect($returnUrl);
        exit;
        
    }

    /**
     * Toss 결제 콜백 ("성공", "실패" 리다이렉트 모두 처리)
     * - booking_id와 booking_key 모두를 이용.
     */
    public function handleTossCallback()
    {
        // booking_id + booking_key 쌍 필수
        if (
            !isset($_GET['mphb_payment_gateway']) ||
            $_GET['mphb_payment_gateway'] !== self::GATEWAY_ID ||
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['booking_key'])
        ) {
            return;
        }

        $callbackType = sanitize_text_field($_GET['callback_type']);
        $bookingId    = absint($_GET['booking_id']);
        $bookingKey   = sanitize_text_field($_GET['booking_key']);

        // 예약 찾기 및 철저 검증
        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        if (
            !$booking ||
            !($booking instanceof \MPHB\Entities\Booking) ||
            $booking->getKey() !== $bookingKey
        ) {
            wp_die(
                __('Toss 콜백: 예약정보 불일치 또는 접근 권한 없음.', 'mphb-toss'),
                __('예약 오류', 'mphb-toss'),
                ['response' => 404]
            );
        }

        // 결제 찾기: 예약 엔티티의 getExpectPaymentId()
        $expectPaymentId = $booking->getExpectPaymentId();
        if (!$expectPaymentId) {
            wp_die(
                __('Toss 콜백: 이 예약에 진행 중 결제가 존재하지 않습니다.', 'mphb-toss'),
                __('결제 없음', 'mphb-toss'),
                ['response' => 404]
            );
        }
        $payment = MPHB()->getPaymentRepository()->findById($expectPaymentId);
        if (!$payment || $payment->getBookingId() !== $booking->getId()) {
            wp_die(
                __('Toss 콜백: 결제-예약 ID 불일치.', 'mphb-toss'),
                __('잘못된 결제', 'mphb-toss'),
                ['response' => 404]
            );
        }

        if ($callbackType === 'fail') {
            $failLog = __('사용자가 결제를 중단하거나 실패했습니다.', 'mphb-toss');
            MPHB()->paymentManager()->failPayment($payment, $failLog);
            $booking->addLog('Toss 실패 콜백: ' . $failLog);

            do_action('mphb_toss_payment_failed', $booking, $payment, null);

            $failUrl = $this->getFailureRedirectUrl($booking, $failLog);
            wp_safe_redirect($failUrl);
            exit;
        }

        // 성공 콜백
        if (
            $callbackType === 'success'
            && isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])
        ) {
            $paymentKey  = sanitize_text_field($_GET['paymentKey']);
            $tossOrderId = sanitize_text_field($_GET['orderId']);
            $receivedAmt = round(floatval($_GET['amount']));
            $expectedAmt = round(floatval($payment->getAmount()));

            // orderId는 반드시 [mphb_{booking_id}_{payment_id}] 포맷이어야 함
            $expectedOrderId = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());

            if ($receivedAmt !== $expectedAmt || $tossOrderId !== $expectedOrderId) {
                wp_die(
                    __('Toss 결제 정보 불일치(금액 또는 주문ID)', 'mphb-toss'),
                    __('Payment Error', 'mphb-toss'),
                    ['response' => 400]
                );
            }
            try {
                $tossApi = new TossAPI($this->getSecretKey(), true);
                $result = $tossApi->confirmPayment($paymentKey, $tossOrderId, (float)$expectedAmt);
                function_exists('ray') && ray('[TossGateway] > [handleTossCallback] confirm result', $result);
                

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    $payment->setTransactionId($paymentKey);
                    $note = sprintf(__('Toss 결제 승인 성공. 결제키:%s', 'mphb-toss'), $paymentKey);
                    $paymentType = $result->method ?? '토스 페이먼츠';
                    if ($paymentType === '카드') {
                        $card = $result->card;
                        if (!empty($card)) {
                            $paymentType = $card->cardType . '카드';
                        }
                    }

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);

                    update_post_meta( $payment->getId(), '_mphb_payment_type', $paymentType );

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result);

                    $reservationReceivedPageUrl = MPHB()->settings()->pages()->getReservationReceivedPageUrl($payment);
                    
                    wp_safe_redirect($reservationReceivedPageUrl);
                    exit;
                } else {
                    throw new TossException("Toss API 승인 결과 오류: " . print_r($result, true));
                }
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
                MPHB()->paymentManager()->failPayment($payment, '[Toss API 예외]: ' . $errMsg);
                $booking->addLog('[Toss] 승인 예외: ' . $errMsg);

                do_action('mphb_toss_payment_failed', $booking, $payment, null);

                $failUrl = $this->getFailureRedirectUrl($booking, $errMsg);
                wp_safe_redirect($failUrl);
                exit;
            }
        }
    }

    protected function getFailureRedirectUrl(?Booking $booking, string $reason): string
    {
        $pages = MPHB()->settings()->pages();
        $url = method_exists($pages, 'getPaymentFailedPageUrl') ? $pages->getPaymentFailedPageUrl() : '';

        if (!$url && $booking instanceof Booking) {
            $url = $booking->getCheckoutUrl();
        }
        if (!$url) {
            $url = home_url('/');
        }
        return add_query_arg([
            'mphb_payment_status' => 'failed',
            'mphb_gateway'        => $this->getId(),
            'reason'              => urlencode($reason),
            'booking_id'          => $booking ? $booking->getId() : '',
        ], $url);
    }
}
