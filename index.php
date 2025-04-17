<?php
/**
 * Plugin Name:       MPHB Toss Payments Gateway
 * ...
 */

if (!defined('WPINC')) {
    exit;
}

# 토스페이먼츠 결제 했을때 결제수단 나오도록
# toss-checkout에서 예약 정보 보여주기 + 결제창 먼저 띄우고 취소해도 다시 버튼 누르면 결제할 수 있도록
# 환불해주는 함수 만들기

define('MPHB_TOSS_PAYMENTS_VERSION', '1.0.0');
define('MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__);

// Include core files
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-gateway.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';

// Register Toss Payments Gateway with MPHB
add_action('plugins_loaded', function () {
    new \MPHBTOSS\TossGateway();
}, 9);

function mphbTossCheckoutShortcode() {
    ob_start();

    $bookingId = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
    $paymentId = isset($_GET['payment_id']) ? absint($_GET['payment_id']) : 0;

    if ($bookingId <= 0) {
        echo '<p>' . esc_html__('Error: Invalid or missing Booking ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }
    if ($paymentId <= 0) {
        echo '<p>' . esc_html__('Error: Invalid or missing Payment ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    $bookingRepository = MPHB()->getBookingRepository();
    $booking = $bookingRepository->findById($bookingId);

    if (!$booking || !($booking instanceof \MPHB\Entities\Booking)) {
        echo '<p>' . esc_html__('Error: Booking not found for the provided Booking ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    $paymentRepository = MPHB()->getPaymentRepository();
    $payment = $paymentRepository->findById($paymentId);

    if (!$payment || !($payment instanceof \MPHB\Entities\Payment)) {
        echo '<p>' . esc_html__('Error: Payment not found for the provided Payment ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    if (method_exists($payment, 'getBookingId') && $payment->getBookingId() != $bookingId) {
        echo '<p>' . esc_html__('Error: Payment does not match the Booking.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    $gateway = MPHB()->gatewayManager()->getGateway('toss');
    if (!$gateway || !$gateway->isEnabled() || !$gateway->isActive()) {
        echo '<p>' . esc_html__('Error: Toss Payments gateway is not enabled or configured.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }
    $clientKey = trim($gateway->getClientKey());
    if (empty($clientKey)) {
        echo '<p>' . esc_html__('Error: Toss Payments Client Key is missing.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    $amount = (float)$booking->getTotalPrice();
    if ($amount <= 0) {
        echo '<p>' . esc_html__('Error: Payment amount must be greater than 0.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    $customer = $booking->getCustomer();
    $customerEmail = $customer ? sanitize_email($customer->getEmail()) : '';
    $customerName  = $customer ? sanitize_text_field(trim($customer->getFirstName() . ' ' . $customer->getLastName())) : '';

    $sessionId = (new \MPHB\Session())->get_id();

    $customerKeyRaw = ($customer && method_exists($customer, 'getCustomerId') && $customer->getCustomerId())
        ? 'cust_' . $customer->getCustomerId()
        : 'sid_' . $sessionId . '_' . $bookingId;
    $customerKey = mphbTossSanitizeCustomerKey($customerKeyRaw);
    // $customerKey = 'dsf1231wqd';

    $orderId = sprintf('mphb_%d_%d', $bookingId, $paymentId);

    $orderName = method_exists($gateway, 'generateItemName')
        ? $gateway->generateItemName($booking)
        : sprintf(__('Booking #%d Payment', 'mphb-toss-payments'), $bookingId);

    $settings = MPHB()->settings()->pages();
    $successUrl = add_query_arg([
        'callback_type'        => 'success',
        'mphb_payment_gateway' => 'toss',
        'booking_id'           => $bookingId,
        'payment_id'           => $paymentId
    ], home_url());
    $failUrl = add_query_arg([
        'callback_type'        => 'fail',
        'mphb_payment_gateway' => 'toss',
        'booking_id'           => $bookingId,
        'payment_id'           => $paymentId
    ], home_url());

    $paymentData = [
        'client_key'     => $clientKey,
        'customer_key'   => $customerKey,
        'amount'         => $amount,
        'order_id'       => $orderId,
        'order_name'     => $orderName,
        'customer_email' => $customerEmail,
        'customer_name'  => $customerName,
        'success_url'    => $successUrl,
        'fail_url'       => $failUrl,
    ];

    ?>
    <div id="toss-payment-widget"></div>
    <script src="https://js.tosspayments.com/v2/standard"></script>
    <script>
    jQuery(function ($) {
        if (typeof TossPayments !== 'function') {
            alert('<?php echo esc_js(__('TossPayments 라이브러리를 불러오지 못했습니다. 새로고침 해주세요.', 'mphb-toss-payments')); ?>');
            return;
        }

        const clientKey     = <?php echo wp_json_encode($clientKey); ?>;
        const customerKey   = <?php echo wp_json_encode($customerKey); ?>;
        const amount        = { currency: "KRW", value: <?php echo (float) $paymentData['amount']; ?> };
        const orderId       = <?php echo wp_json_encode((string)$paymentData['order_id']); ?>;
        const orderName     = <?php echo wp_json_encode((string)$paymentData['order_name']); ?>;
        const successUrl    = <?php echo wp_json_encode($paymentData['success_url']); ?>;
        const failUrl       = <?php echo wp_json_encode($paymentData['fail_url']); ?>;
        const customerEmail = <?php echo wp_json_encode($paymentData['customer_email']); ?>;
        const customerName  = <?php echo wp_json_encode($paymentData['customer_name']); ?>;

        const paymentBtn = $('<button>')
            .text('<?php echo esc_js(__('토스로 결제하기', 'mphb-toss-payments')); ?>')
            .css({
                padding: '12px 28px',
                fontSize: '18px',
                cursor: 'pointer',
                margin: '32px auto',
                display: 'block',
                borderRadius: '8px',
                background: '#005be2',
                color: '#fff',
                border: 'none'
            });

        $('#toss-payment-widget').append(paymentBtn);

        paymentBtn.on('click', function () {
            try {
                const tossPayments = TossPayments(clientKey);
                const payment = tossPayments.payment({customerKey: customerKey});

                (async function () {
                    try {
                        await payment.requestPayment({
                            method: "CARD",
                            amount,
                            orderId,
                            orderName,
                            successUrl,
                            failUrl,
                            customerEmail,
                            customerName,
                            card: {
                                useEscrow: false,
                                flowMode: "DEFAULT",
                                useCardPoint: false,
                                useAppCardOnly: false,
                            }
                        });
                    } catch (error) {
                        window.parent.postMessage({
                            tosspayments: true,
                            type: 'fail',
                            message: error.message || '<?php echo esc_js(__('결제 요청 중 오류가 발생했습니다.', 'mphb-toss-payments')); ?>'
                        }, '*');
                        alert(error.message || '<?php echo esc_js(__('결제 요청 중 오류가 발생했습니다.', 'mphb-toss-payments')); ?>');
                    }
                })();

            } catch (initError) {
                alert(initError.message || '<?php echo esc_js(__('토스 페이먼츠 초기화 오류입니다.', 'mphb-toss-payments')); ?>');
            }
        });
    });
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode('mphb_toss_checkout', 'mphbTossCheckoutShortcode');

function mphbTossSanitizeCustomerKey($raw) {
    $key = preg_replace('/[^a-zA-Z0-9\-\_\=\.\@]/', '', $raw ?: '');
    $key = substr($key, 0, 50);
    if (strlen($key) < 2) $key = str_pad($key, 2, '0');
    return $key;
}
