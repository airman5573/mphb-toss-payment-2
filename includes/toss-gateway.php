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
        $redirectUrl = home_url('/toss-checkout');
        $returnUrl = add_query_arg([
            'payment_id' => $payment->getId(),
            'booking_id' => $booking->getId(),
        ], $redirectUrl);

        wp_redirect($returnUrl);
        exit;
    }

    /**
     * Toss 결제 콜백 ("성공", "실패" 리다이렉트 모두 처리)
     */
    public function handleTossCallback()
    {
        if (
            !isset($_GET['mphb_payment_gateway']) ||
            $_GET['mphb_payment_gateway'] !== self::GATEWAY_ID ||
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['payment_id'])
        ) {
            return;
        }

        $callbackType = sanitize_text_field($_GET['callback_type']);
        $bookingId    = absint($_GET['booking_id']);
        $paymentId    = absint($_GET['payment_id']);

        $booking = MPHB()->getBookingRepository()->findById($bookingId);
        $payment = MPHB()->getPaymentRepository()->findById($paymentId);

        if (!$booking || !$payment || $payment->getBookingId() !== $booking->getId()) {
            function_exists('ray') && ray('[TossGateway::handleTossCallback] 예약-결제 ID 불일치', $_GET);
            wp_die(__('Invalid booking/payment relation.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 404]);
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
        if ($callbackType === 'success' && isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])) {
            $paymentKey    = sanitize_text_field($_GET['paymentKey']);
            $tossOrderId   = sanitize_text_field($_GET['orderId']);
            $receivedAmt   = round(floatval($_GET['amount']));
            $expectedAmt   = round(floatval($payment->getAmount()));
            $expectedOrderId = sprintf('mphb_%d_%d', $bookingId, $paymentId);

            if ($receivedAmt !== $expectedAmt || $tossOrderId !== $expectedOrderId) {
                wp_die(__('Toss 결제 정보 불일치(금액 또는 주문ID)', 'mphb-toss'), __('Payment Error', 'mphb-toss'), ['response' => 400]);
            }
            try {
                $tossApi = new TossAPI($this->getSecretKey(), true);
                $result = $tossApi->confirmPayment($paymentKey, $tossOrderId, (float)$expectedAmt);

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    $payment->setTransactionId($paymentKey);
                    $note = sprintf(__('Toss 결제 승인 성공. 결제키:%s', 'mphb-toss'), $paymentKey);

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);

                    // Don't call $booking->confirm(), not supported! Only manage status by MPHB paymentManager

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result);
                    wp_safe_redirect($booking->getBookingConfirmationUrl());
                    exit;
                } else {
                    throw new TossException("Toss API 결과 비정상: " . print_r($result, true));
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
