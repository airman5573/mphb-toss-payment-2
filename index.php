<?php
/**
 * Plugin Name:       MPHB Toss Payments Gateway
 * Plugin URI:        #
 * Description:       Integrates Toss Payments with MotoPress Hotel Booking plugin.
 * Version:           1.0.0
 * Author:            Shoplic
 * Author URI:        #
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mphb-toss-payments
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'WPINC' ) ) {
    exit;
}

/**
 * Define plugin constants
 */
define( 'MPHB_TOSS_PAYMENTS_VERSION', '1.0.0' );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__ );

/**
 * Include core files
 */
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-exception.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-gateway.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';

/**
 * Register Toss Payments Gateway with MPHB
 */
add_action( 'plugins_loaded', function() {
    new \MPHBTOSS\TossGateway();
}, 9 );

function mphb_toss_checkout_shortcode() {
    ob_start();

    // GET 파라미터에서 ID를 안전하게 추출
    $booking_id = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
    $payment_id = isset($_GET['payment_id']) ? absint($_GET['payment_id']) : 0;

    if ($booking_id <= 0) {
        echo '<p>' . esc_html__('Error: Invalid or missing Booking ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }
    if ($payment_id <= 0) {
        echo '<p>' . esc_html__('Error: Invalid or missing Payment ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // Booking 엔티티 획득 (공식 Repository 사용)
    $bookingRepository = MPHB()->getBookingRepository();
    $booking = $bookingRepository->findById($booking_id);

    if ( !$booking || !($booking instanceof \MPHB\Entities\Booking) ) {
        echo '<p>' . esc_html__('Error: Booking not found for the provided Booking ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // Payment 엔티티 획득 (공식 Repository 사용)
    $paymentRepository = MPHB()->getPaymentRepository();
    $payment = $paymentRepository->findById($payment_id);

    if ( !$payment || !($payment instanceof \MPHB\Entities\Payment) ) {
        echo '<p>' . esc_html__('Error: Payment not found for the provided Payment ID.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // 해당 Payment가 실제 Booking과 매칭되는지 체크 (데이터 정합성 강화)
    if ( method_exists( $payment, 'getBookingId') && $payment->getBookingId() != $booking_id ) {
        echo '<p>' . esc_html__('Error: Payment does not match the Booking.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // Gateway 객체를 공식적으로 추출
    $gateway = MPHB()->gatewayManager()->getGateway('toss');
    if (!$gateway || !$gateway->isEnabled() || !$gateway->isActive()) {
        echo '<p>' . esc_html__('Error: Toss Payments gateway is not enabled or configured.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }
    $client_key = trim($gateway->getClientKey());
    if (empty($client_key)) {
        echo '<p>' . esc_html__('Error: Toss Payments Client Key is missing.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // 결제 금액 추출 (booking 기준)
    $amount = (float) $booking->getTotalPrice();
    if ($amount <= 0) {
        echo '<p>' . esc_html__('Error: Payment amount must be greater than 0.', 'mphb-toss-payments') . '</p>';
        return ob_get_clean();
    }

    // 고객 정보 추출
    $customer = $booking->getCustomer();
    $customer_email = $customer ? sanitize_email($customer->getEmail()) : '';
    $customer_name  = $customer ? sanitize_text_field( trim($customer->getFirstName() . ' ' . $customer->getLastName() ) ) : '';

    // 세션 아이디 공식 메서드 활용
    $session_id = (new \MPHB\Session())->get_id();
    // 고객키 생성: booking에 customer id가 있다면, 없으면 세션 기반
    $customer_key = $customer && method_exists($customer, 'getCustomerId') && $customer->getCustomerId()
        ? 'cust_' . $customer->getCustomerId()
        : 'sid_' . $session_id . '_' . $booking_id;

    // 오더ID 생성: mphb_{booking_id}_{payment_id}
    $order_id = sprintf('mphb_%d_%d', $booking_id, $payment_id);

    // 오더이름은 gateway 객체로 생성 (getTitle 사용도 가능)
    $order_name = method_exists($gateway, 'generateItemName')
        ? $gateway->generateItemName($booking)
        : sprintf(__('Booking #%d Payment', 'mphb-toss-payments'), $booking_id);

    // 성공/실패 URL (공식 메서드 + add_query_arg)
    $settings = MPHB()->settings()->pages();
    $success_url = add_query_arg(
        [
            'callback_type' => 'success',
            'mphb_payment_gateway' => 'toss',
            'booking_id' => $booking_id,
            'payment_id' => $payment_id
        ],
        home_url()
    );
    $fail_url = add_query_arg(
        [
            'callback_type' => 'fail',
            'mphb_payment_gateway' => 'toss',
            'booking_id' => $booking_id,
            'payment_id' => $payment_id
        ],
        home_url()
    );

    // Toss 체크아웃에 필요한 데이터 구성
    $payment_data = [
        'client_key'     => $client_key,
        'customer_key'   => $customer_key,
        'amount'         => $amount,
        'order_id'       => $order_id,
        'order_name'     => $order_name,
        'customer_email' => $customer_email,
        'customer_name'  => $customer_name,
        'success_url'    => $success_url,
        'fail_url'       => $fail_url,
    ];

    function_exists('ray') && ray('paymentData', $paymentData);
    
    ?>
    <div id="toss-payment-widget"></div>
    <script src="https://js.tosspayments.com/v2/standard"></script>
    <script>
    jQuery(function($) {
        // 안전하게 TossPayments 로드 확인
        if (typeof TossPayments !== 'function') {
            alert('<?php echo esc_js(__('TossPayments 라이브러리를 불러오지 못했습니다. 새로고침 해주세요.', 'mphb-toss-payments')); ?>');
            return;
        }

        // PHP → JS, 데이터 안전 전달
        const clientKey     = <?php echo wp_json_encode($client_key); ?>;
        const customerKey   = <?php echo wp_json_encode($customer_key); ?>;
        const amount        = { currency: "KRW", value: <?php echo (float) $payment_data['amount']; ?> };
        const orderId     = <?php echo wp_json_encode((string) $payment_data['order_id']); ?>;
        const orderName   = <?php echo wp_json_encode((string) $payment_data['order_name']); ?>;
        const successUrl    = <?php echo wp_json_encode($payment_data['success_url']); ?>;
        const failUrl       = <?php echo wp_json_encode($payment_data['fail_url']); ?>;
        const customerEmail = <?php echo wp_json_encode($payment_data['customer_email']); ?>;
        const customerName  = <?php echo wp_json_encode($payment_data['customer_name']); ?>;

        console.log('payment data', [
            clientKey,
            customerKey,
            amount,
            orderId,
            orderName,
            successUrl,
            failUrl,
            customerEmail,
            customerName
        ]);

        // 결제버튼 생성
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

                // 비동기 결제 요청(최신 방식)
                (async function() {
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
                        // 콘솔 및 postMessage 에러 처리
                        console.error('[TossPayments] requestPayment error:', error);
                        window.parent.postMessage({
                            tosspayments: true,
                            type: 'fail',
                            message: error.message || '<?= esc_js(__('결제 요청 중 오류가 발생했습니다.', 'mphb-toss-payments')); ?>'
                        }, '*');
                        alert(error.message || '<?php echo esc_js(__('결제 요청 중 오류가 발생했습니다.', 'mphb-toss-payments')); ?>');
                    }
                })();

            } catch (initError) {
                // 초기화 자체가 오류일 때
                alert(initError.message || '<?php echo esc_js(__('토스 페이먼츠 초기화 오류입니다.', 'mphb-toss-payments')); ?>');
            }
        });
    });
    </script>
    <?php

    return ob_get_clean();
}
add_shortcode('mphb_toss_checkout', 'mphb_toss_checkout_shortcode');
