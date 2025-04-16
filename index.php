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

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define constants
 */
define( 'MPHB_TOSS_PAYMENTS_VERSION', '1.0.0' );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPHB_TOSS_PAYMENTS_PLUGIN_FILE', __FILE__ );

/**
 * Include necessary files
 */
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-gateway.php';
require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-api.php';
// require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/toss-callbacks.php'; // File missing, commented out to prevent fatal error

// Admin specific includes
if ( is_admin() ) {
    require_once MPHB_TOSS_PAYMENTS_PLUGIN_DIR . 'includes/admin/toss-admin-setup.php';
}

/**
 * Register Toss Payments Gateway with MPHB
 *
 * @param array $gateways
 * @return array
 */
function mphb_toss_register_gateway( $gateways ) {
    $gateways['toss'] = 'MPHB\Payments\Gateways\TossGateway'; // Corrected namespace
    return $gateways;
}
add_filter( 'mphb_payment_gateways', 'mphb_toss_register_gateway' );

/**
 * Initialize admin features
 */
function mphb_toss_init_admin() {
    if ( is_admin() ) {
        // Assuming TossAdminSetup is in the same Admin sub-namespace
        new MPHB\Payments\Gateways\Toss\Admin\TossAdminSetup(); 
    }
}
add_action( 'plugins_loaded', 'mphb_toss_init_admin' );

/**
 * Add Toss Checkout Shortcode
 * This shortcode retrieves payment details based on payment_id from the URL
 * and initiates the Toss Payments SDK flow, using both booking_id and payment_id from URL.
 */
function mphb_toss_checkout_shortcode() {
    // Check for both booking_id and payment_id
    if ( ! isset( $_GET['booking_id'] ) || ! is_numeric( $_GET['booking_id'] ) ) {
        return '<p>' . esc_html__( 'Error: Invalid or missing Booking ID.', 'mphb-toss-payments' ) . '</p>';
    }
    if ( ! isset( $_GET['payment_id'] ) || ! is_numeric( $_GET['payment_id'] ) ) {
        // Decide how critical payment_id is here. If it's just for the order_id, maybe allow proceeding?
        // For now, let's require it as per the user's statement.
        return '<p>' . esc_html__( 'Error: Invalid or missing Payment ID.', 'mphb-toss-payments' ) . '</p>';
    }

    $booking_id = intval( $_GET['booking_id'] );
    $payment_id = intval( $_GET['payment_id'] ); // Store payment_id as well
    $booking = MPHB\Entities\Booking::findById( $booking_id ); // Fetch booking using booking_id

    if ( ! $booking ) {
        return '<p>' . esc_html__( 'Error: Booking not found for the provided Booking ID.', 'mphb-toss-payments' ) . '</p>';
    }

    // Ensure the booking status is appropriate for payment (e.g., pending confirmation)
    // Add status check if needed, e.g.:
    // if ($booking->getStatus() !== 'pending_confirmation') { ... }

    $gateway = MPHB()->settings()->gateways()->getGateway( 'toss' );
    if ( ! $gateway || ! $gateway->isEnabled() ) {
        return '<p>' . esc_html__( 'Error: Toss Payments gateway is not enabled or configured.', 'mphb-toss-payments' ) . '</p>';
    }

    $client_key = $gateway->getOption( 'client_key' );
    // Generate a unique customer key, e.g., based on booking ID or user ID
    $customer_key = 'cust_' . ($booking->getCustomerId() ? $booking->getCustomerId() : $booking_id . '_' . time()); // Use customer ID if available, otherwise generate one based on booking_id


    if ( empty( $client_key ) ) {
         return '<p>' . esc_html__( 'Error: Toss Payments Client Key is missing.', 'mphb-toss-payments' ) . '</p>';
    }

    $amount = $booking->getTotalPrice();
    $order_name = sprintf( __( 'Booking #%d Payment', 'mphb-toss-payments' ), $booking_id );
    $customer = $booking->getCustomer();
    $customer_email = $customer ? $customer->getEmail() : '';
    $customer_name = $customer ? trim( $customer->getFirstName() . ' ' . $customer->getLastName() ) : '';

    // Construct Success and Fail URLs
    // Success URL: Where Toss redirects after successful payment. Usually the booking confirmation page.
    // Fail URL: Where Toss redirects after failed payment. Could be the checkout page again or a specific failure page.
    $success_url = add_query_arg( [
        'callback_type' => 'success',
        'mphb_payment_gateway' => 'toss',
        'booking_id' => $booking_id, // Pass booking_id back
        'payment_id' => $payment_id, // Pass payment_id back
        // Toss will append paymentKey, orderId, amount automatically
    ], MPHB()->settings()->pages()->getBookingConfirmationPageUrl() ); // Use MPHB's confirmation page URL

    // Fail URL - Redirect back to the page with the shortcode, perhaps with an error flag
    $fail_url = add_query_arg( [
        'callback_type' => 'fail',
        'mphb_payment_gateway' => 'toss',
        'booking_id' => $booking_id, // Pass booking_id back
        // Toss will append errorCode, errorMessage, orderId automatically
    ], get_permalink() ); // Redirect back to the current page (where the shortcode is)

    $paymentData = [
        'client_key'     => $client_key,
        'customer_key'   => $customer_key,
        'amount'         => $amount,
        // Combine booking_id, payment_id, and timestamp for a unique and informative order_id
        'order_id'       => sprintf( 'mphb_%d_%d_%d', $booking_id, $payment_id, time() ),
        'order_name'     => $order_name,
        'customer_email' => $customer_email,
        'customer_name'  => $customer_name,
        'success_url'    => $success_url,
        'fail_url'       => $fail_url,
    ];

    ob_start();
    ?>
    <div id="toss-payment-widget"></div>
    <script src="https://js.tosspayments.com/v1"></script>
    <script>
        jQuery(function($) {
            (async function() {
                try {
                    // 필수 데이터 검증
                    const paymentData = <?php echo wp_json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                    const requiredKeys = ['client_key', 'customer_key', 'amount', 'order_id', 'order_name', 'success_url', 'fail_url'];
                    for (const key of requiredKeys) {
                        if (!paymentData[key]) throw new Error(`Missing required payment data: ${key}`);
                    }
                    const amountValue = parseFloat(paymentData.amount);
                    if (isNaN(amountValue) || amountValue <= 0) throw new Error('Invalid amount provided.');

                    // TossPayments SDK 체크 및 초기화
                    if (typeof window.TossPayments !== 'function') throw new Error('TossPayments SDK not loaded.');
                    const tossPayments = TossPayments(paymentData.client_key);

                    // ------ Payment Method: CARD ------
                    await tossPayments.requestPayment({ // <- '카드' for Card method
                        method: "CARD",
                        amount: { currency: "KRW", value: amountValue },
                        orderId: paymentData.order_id,
                        orderName: paymentData.order_name,
                        customerEmail: paymentData.customer_email || '',
                        customerName: paymentData.customer_name || '',
                        successUrl: paymentData.success_url,
                        failUrl: paymentData.fail_url,
                        card: {
                            useEscrow: false,
                            flowMode: "DEFAULT",
                        }
                        // flowMode: 'DIRECT', // Optional: For specific card flow modes if needed
                        // cardCompany: null, // Optional: Pre-select card company
                        // cardInstallmentPlan: null, // Optional: Set installment plan
                        // maxCardInstallmentPlan: null, // Optional: Limit max installments
                        // useAppCardOnly: false, // Optional: Force app card usage
                        // useInternationalCardOnly: false, // Optional: Allow only international cards
                        // safePay: null, // Optional: For SafePay integration
                        // useCardPoint: false, // Optional: Enable card points usage
                        // customerKey: paymentData.customer_key // Pass customerKey here if using Billing Key features later
                    });

                    // ------ Alternative: Payment Widget ------
                    // If you want to use the Payment Widget instead of direct card payment:
                    /*
                    const paymentWidget = tossPayments.paymentWidget({ customerKey: paymentData.customer_key });
                    paymentWidget.renderPaymentMethods('#toss-payment-widget', { value: amountValue });
                    // You would then need a button to trigger:
                    // paymentWidget.requestPayment({ ... paymentData ... });
                    */

                } catch (error) {
                    console.error('[Toss Script] requestPayment error:', error);
                    // Optionally display error to user or redirect
                     alert('결제 요청 중 오류가 발생했습니다: ' + error.message);
                     // Redirect to fail URL might be better UX
                     // window.location.href = paymentData.fail_url + '&errorCode=' + encodeURIComponent(error.code || 'CLIENT_ERROR') + '&errorMessage=' + encodeURIComponent(error.message);
                }
            })();
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'mphb_toss_checkout', 'mphb_toss_checkout_shortcode' );
