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

    protected $client_key = '';
    protected $secret_key = '';
    protected $enabled = true;

    public function __construct()
    {
        parent::__construct();
        $this->register_hooks();
    }

    /**
     * Registers option fields in the admin settings screen.
     *
     * @param object $sub_tab Settings tab object
     */
    public function register_options_fields(&$sub_tab): void
    {
        $gateway_id = $this->getId();

        // Main group fields
        $main_group_fields = [
            Fields\FieldFactory::create("mphb_payment_gateway_{$gateway_id}_title", [
                'type'         => 'text',
                'label'        => __('Title', 'motopress-hotel-booking'),
                'default'      => 'Toss Payments',
                'translatable' => true,
            ]),
            Fields\FieldFactory::create("mphb_payment_gateway_{$gateway_id}_description", [
                'type'         => 'textarea',
                'label'        => __('Description', 'motopress-hotel-booking'),
                'default'      => __('Pay with Toss Payments.', 'motopress-hotel-booking'),
                'translatable' => true,
            ]),
        ];

        $main_group = new Groups\SettingsGroup(
            "mphb_payments_{$gateway_id}_main",
            '',
            $sub_tab->getOptionGroupName()
        );
        $main_group->addFields($main_group_fields);
        $sub_tab->addGroup($main_group);

        // API group fields
        $api_group_fields = [
            Fields\FieldFactory::create("mphb_payment_gateway_{$gateway_id}_client_key", [
                'type'        => 'text',
                'label'       => __('Client Key', 'mphb-toss'),
                'default'     => '',
                'description' => __('Enter your Toss Payments Client Key.', 'mphb-toss'),
            ]),
            Fields\FieldFactory::create("mphb_payment_gateway_{$gateway_id}_secret_key", [
                'type'        => 'text',
                'label'       => __('Secret Key', 'mphb-toss'),
                'default'     => '',
                'description' => __('Enter your Toss Payments Secret Key.', 'mphb-toss'),
            ]),
        ];

        $api_group = new Groups\SettingsGroup(
            "mphb_payments_{$gateway_id}_api",
            __('API Settings', 'mphb-toss'),
            $sub_tab->getOptionGroupName()
        );
        $api_group->addFields($api_group_fields);
        $sub_tab->addGroup($api_group);

        // SSL Recommendation
        if (!MPHB()->isSiteSSL()) {
            $ssl_warn = __('<strong>권고:</strong> 사이트에 SSL(https://)을 적용해 주세요. Toss 결제는 SSL 환경에서만 정상적으로 동작합니다.', 'mphb-toss');
            $enable_field = $sub_tab->findField("mphb_payment_gateway_{$gateway_id}_enable");
            if ($enable_field) {
                $enable_field->setDescription($ssl_warn);
            }
        }
    }

    /**
     * Register WordPress hooks.
     */
    protected function register_hooks(): void
    {
        add_action('init', [$this, 'handle_toss_callback'], 11);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    protected function init_id(): string
    {
        return self::GATEWAY_ID;
    }

    protected function init_default_options(): array
    {
        return array_merge(parent::initDefaultOptions(), [
            'title'       => __('The Toss Payments Credit Card', 'mphb-toss'),
            'description' => __('Pay with your credit card via Toss Payments.', 'mphb-toss'),
            'client_key'  => '',
            'secret_key'  => '',
        ]);
    }

    protected function setup_properties(): void
    {
        parent::setupProperties();
        $this->admin_title    = __('Toss Payments', 'mphb-toss');
        $this->client_key     = $this->getOption('client_key');
        $this->secret_key     = $this->getOption('secret_key');
    }

    // Toss 활성화 조건: 필수키 및 통화까지 체크
    public function is_active()
    {
        $currency = strtoupper(MPHB()->settings()->currency()->getCurrencyCode());
        return parent::isActive() &&
            !empty($this->get_client_key()) &&
            !empty($this->get_secret_key()) &&
            $currency === 'KRW';
    }

    public function is_enabled()
    {
        return $this->is_active();
    }

    public function get_client_key()
    {
        return $this->getOption('client_key');
    }

    public function get_secret_key()
    {
        return $this->getOption('secret_key');
    }

    public function enqueue_scripts()
    {
        if (function_exists('is_page') && is_page('toss-checkout')) {
            wp_enqueue_script(
                'mphb-toss-payments',
                plugins_url('assets/js/toss-checkout.js', MPHB_TOSS_PAYMENTS_PLUGIN_FILE),
                ['jquery'],
                MPHB_TOSS_PAYMENTS_VERSION,
                true
            );

            $booking_id = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
            $payment_id = isset($_GET['payment_id']) ? absint($_GET['payment_id']) : 0;
            $booking    = $booking_id ? MPHB()->getBookingRepository()->findById($booking_id) : null;
            $payment    = $payment_id ? MPHB()->getPaymentRepository()->findById($payment_id) : null;
            $gateway    = MPHB()->gatewayManager()->getGateway('toss');

            if ($booking && $payment && $gateway && $gateway->isEnabled()) {
                $customer  = $booking->getCustomer();
                $orderId   = sprintf('mphb_%d_%d', $booking->getId(), $payment->getId());

                $checkout_data = [
                    'clientKey'     => $gateway->getClientKey(),
                    'amount'        => (float) $booking->getTotalPrice(),
                    'orderId'       => $orderId,
                    'orderName'     => method_exists($gateway, 'generateItemName')
                        ? $gateway->generateItemName($booking)
                        : sprintf(__('Booking #%d Payment', 'mphb-toss'), $booking->getId()),
                    'customerEmail' => $customer ? $customer->getEmail() : '',
                    'customerName'  => $customer ? $customer->getName() : '',
                    'successUrl'    => add_query_arg([
                        'callback_type'         => 'success',
                        'mphb_payment_gateway'  => 'toss',
                        'booking_id'            => $booking->getId(),
                        'payment_id'            => $payment->getId()
                    ], home_url('/')),
                    'failUrl'       => add_query_arg([
                        'callback_type'         => 'fail',
                        'mphb_payment_gateway'  => 'toss',
                        'booking_id'            => $booking->getId(),
                        'payment_id'            => $payment->getId()
                    ], home_url('/')),
                    'i18n' => [
                        'pay_by_toss' => __('토스로 결제하기','mphb-toss'),
                        'init_error'  => __('TossPayments 라이브러리 로딩 오류', 'mphb-toss')
                    ]
                ];

                wp_localize_script('mphb-toss-payments', 'MPHBTossCheckoutData', $checkout_data);
            }
        }
    }

    /**
     * 결제 시작시 Toss Checkout(프론트 결제창)으로 리디렉트
     */
    public function process_payment(Booking $booking, Payment $payment): array
    {
        $redirect_url = home_url('/toss-checkout');
        $return_url = add_query_arg([
            'payment_id' => $payment->getId(),
            'booking_id' => $booking->getId(),
        ], $redirect_url);

        wp_redirect($return_url);
        exit;
    }

    /**
     * Toss 결제 콜백 ("성공", "실패" 리다이렉트 모두 처리)
     */
    public function handle_toss_callback()
    {
        if (
            !isset($_GET['mphb_payment_gateway']) ||
            $_GET['mphb_payment_gateway'] !== self::GATEWAY_ID ||
            !isset($_GET['callback_type'], $_GET['booking_id'], $_GET['payment_id'])
        ) {
            return;
        }

        $callback_type = sanitize_text_field($_GET['callback_type']);
        $booking_id    = absint($_GET['booking_id']);
        $payment_id    = absint($_GET['payment_id']);

        $booking = MPHB()->getBookingRepository()->findById($booking_id);
        $payment = MPHB()->getPaymentRepository()->findById($payment_id);

        if (!$booking || !$payment || $payment->getBookingId() !== $booking->getId()) {
            MPHB()->log()->error("[TossCallback] 예약-결제 ID 불일치", $_GET);
            wp_die(__('Invalid booking/payment relation.', 'mphb-toss'), __('Error', 'mphb-toss'), ['response' => 404]);
        }

        if ($callback_type === 'fail') {
            $fail_log = __('사용자가 결제를 중단하거나 실패했습니다.','mphb-toss');
            MPHB()->paymentManager()->failPayment($payment, $fail_log);
            $booking->addLog('Toss 실패 콜백: ' . $fail_log);

            do_action('mphb_toss_payment_failed', $booking, $payment, null);

            $fail_url = $this->get_failure_redirect_url($booking, $fail_log);
            wp_safe_redirect($fail_url);
            exit;
        }

        // 성공 콜백
        if ($callback_type === 'success' && isset($_GET['paymentKey'], $_GET['orderId'], $_GET['amount'])) {
            $payment_key    = sanitize_text_field($_GET['paymentKey']);
            $toss_order_id  = sanitize_text_field($_GET['orderId']);
            $received_amt   = round(floatval($_GET['amount']));
            $expected_amt   = round(floatval($payment->getAmount()));
            $expected_order_id = sprintf('mphb_%d_%d', $booking_id, $payment_id);

            if ($received_amt !== $expected_amt || $toss_order_id !== $expected_order_id) {
                wp_die(__('Toss 결제 정보 불일치(금액 또는 주문ID)','mphb-toss'), __('Payment Error','mphb-toss'), ['response' => 400]);
            }
            try {
                $toss_api = new TossAPI($this->get_secret_key(), MPHB()->settings()->main()->isDebugMode());
                $result = $toss_api->confirmPayment($payment_key, $toss_order_id, (float)$expected_amt);

                if ($result && isset($result->status) && $result->status === 'DONE') {
                    $payment->setTransactionId($payment_key);
                    $note = sprintf(__('Toss 결제 승인 성공. 결제키:%s', 'mphb-toss'), $payment_key);

                    MPHB()->paymentManager()->completePayment($payment, $note);
                    $booking->addLog($note);
                    $booking->confirm();

                    do_action('mphb_toss_payment_confirmed', $booking, $payment, $result);
                    wp_safe_redirect($booking->getBookingConfirmationUrl());
                    exit;
                } else {
                    throw new TossException("Toss API 결과 비정상: " . print_r($result, true));
                }
            } catch (\Exception $e) {
                $err_msg = $e->getMessage();
                MPHB()->paymentManager()->failPayment($payment, '[Toss API 예외]: ' . $err_msg);
                $booking->addLog('[Toss] 승인 예외: ' . $err_msg);

                do_action('mphb_toss_payment_failed', $booking, $payment, null);

                $fail_url = $this->get_failure_redirect_url($booking, $err_msg);
                wp_safe_redirect($fail_url);
                exit;
            }
        }
    }

    /**
     * 결제 실패(또는 예외)시 리다이렉트될 URL 반환
     *
     * @param Booking|null $booking
     * @param string $reason
     * @return string
     */
    protected function get_failure_redirect_url(?Booking $booking, string $reason): string
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
