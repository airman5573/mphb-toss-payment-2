<?php

/**
 * 예약 엔티티에서 진행 중인 결제 엔티티를 조회하는 함수
 * 
 * @param \MPHB\Entities\Booking $bookingEntity 예약 엔티티 객체
 * @return \MPHB\Entities\Payment 결제 엔티티 객체
 * @throws Exception 예약이 결제 대기 상태가 아니거나 연결된 결제 정보가 없을 경우
 */
function mphb_get_pending_payment_from_booking($bookingEntity) {
    // 1. 예약 엔티티에서 기대 결제 ID 조회
    $expectedPaymentId = $bookingEntity->getExpectPaymentId();
    if (!$expectedPaymentId || $expectedPaymentId <= 0) {
        // 결제 대기가 아닌 경우 예외
        throw new Exception('[결제 조회 실패] 예약에서 결제 대기(payment pending) 상태가 아닙니다.');
    }
    // 2. 결제 리포지토리에서 결제 엔티티 조회
    $paymentRepository = MPHB()->getPaymentRepository();
    $paymentEntity = $paymentRepository->findById($expectedPaymentId);
    // 3. 결제 엔티티가 없거나 연결이 맞지 않으면 예외
    if (!$paymentEntity || $paymentEntity->getBookingId() != $bookingEntity->getId()) {
        throw new Exception('[결제 조회 실패] 해당 예약에 연결된 결제 정보가 없습니다.');
    }
    return $paymentEntity;
}

/**
 * Toss 결제 위젯에 필요한 파라미터를 생성하는 함수
 *
 * @param \MPHB\Entities\Booking $bookingEntity 예약 엔티티 객체
 * @param \MPHB\Entities\Payment $paymentEntity 결제 엔티티 객체
 * @param object $gatewayEntity 게이트웨이 엔티티 객체 (Toss결제 게이트웨이)
 * @param string $bookingKey 예약 고유 키
 * @param int $bookingId 예약 ID
 * @return array 생성된 결제 파라미터 배열
 */
function mphb_create_toss_payment_parameters($bookingEntity, $paymentEntity, $gatewayEntity, $bookingKey, $bookingId) {
    // 투숙객, 고객키, 상품명 등 결제 데이터 구성
    $customer = $bookingEntity->getCustomer();
    $customerEmail = $customer ? sanitize_email($customer->getEmail()) : '';
    $customerName = $customer ? sanitize_text_field(trim($customer->getFirstName() . ' ' . $customer->getLastName())) : '';
    $sessionId = (new \MPHB\Session())->get_id();
    $tossCustomerKey = ($customer && $customer->getCustomerId()) ? 'cust_' . $customer->getCustomerId() : 'sid_' . $sessionId . '_' . $bookingEntity->getId();
    $tossCustomerKey = mphbTossSanitizeCustomerKey($tossCustomerKey);
    $productName = $gatewayEntity->generateItemName($bookingEntity);
    $orderId = sprintf('mphb_%d_%d', $bookingEntity->getId(), $paymentEntity->getId());
    return [
        'client_key'     => $gatewayEntity->getClientKey(),
        'customer_key'   => $tossCustomerKey,
        'amount'         => (float)$bookingEntity->getTotalPrice(),
        'order_id'       => $orderId,
        'order_name'     => $productName,
        'customer_email' => $customerEmail,
        'customer_name'  => $customerName,
        'success_url'    => mphb_create_toss_callback_url('success', $bookingKey, $bookingId),
        'fail_url'       => mphb_create_toss_callback_url('fail', $bookingKey, $bookingId),
    ];
}

/**
 * Toss 결제 후 콜백 URL을 생성하는 함수
 *
 * @param string $callbackType 콜백 유형 ('success' 또는 'fail')
 * @param string $bookingKey 예약 고유 키
 * @param int $bookingId 예약 ID
 * @return string 생성된 콜백 URL
 */
function mphb_create_toss_callback_url($callbackType, $bookingKey, $bookingId) {
    // 지정된 파라미터로 콜백 URL 생성
    return add_query_arg(
        [
            'callback_type'        => $callbackType,
            'mphb_payment_gateway' => 'toss',
            'booking_key'          => $bookingKey,
            'booking_id'           => $bookingId,
        ],
        home_url()
    );
}

/**
 * MPHB Toss Payments 체크아웃 숏코드 함수
 * 
 * - Booking Hotel 스타일 디자인과 동일한 클래스 구조 적용
 * - 자동 결제와 수동 재시도 지원
 * - 강력한 오류 처리
 *
 * @return string 숏코드 출력 HTML
 */
function mphbTossCheckoutShortcode() {
    ob_start();

    try {
        // 1. 매개변수 가져오기 및 유효성 검사
        $booking_id = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
        $booking_key = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : '';

        if (!$booking_id || !$booking_key) {
            throw new Exception(__('예약번호 또는 예약고유키 파라미터가 누락되었습니다.', 'mphb-toss-payments'));
        }

        // 2. 예약 데이터 로드 및 소유권 검증
        $booking_repo = MPHB()->getBookingRepository();
        $booking = $booking_repo->findById($booking_id);
        if (!$booking || $booking->getKey() !== $booking_key) {
            throw new Exception(__('예약 정보를 확인할 수 없습니다. (번호/키 불일치)', 'mphb-toss-payments'));
        }

        // 3. 결제/게이트웨이 확인
        $payment_entity = mphb_get_pending_payment_from_booking($booking);
        $toss_gateway = MPHB()->gatewayManager()->getGateway('toss');

        if (!$toss_gateway) throw new Exception(__('Toss 결제 게이트웨이 미설정', 'mphb-toss-payments'));
        if (!$toss_gateway->isEnabled() || !$toss_gateway->isActive()) throw new Exception(__('Toss 결제 게이트웨이가 현재 비활성화 상태입니다.', 'mphb-toss-payments'));
        if (empty($toss_gateway->getClientKey())) throw new Exception(__('Toss 클라이언트 키가 누락되었습니다.', 'mphb-toss-payments'));
        if ((float)$booking->getTotalPrice() <= 0) throw new Exception(__('결제 금액이 0원 이하입니다.', 'mphb-toss-payments'));

        // 4. 체크인/체크아웃 날짜 포맷팅
        $checkInDateObj = $booking->getCheckInDate(true);   // DateTime 객체이거나 string
        $checkOutDateObj = $booking->getCheckOutDate(true); // DateTime 객체이거나 string

        if ($checkInDateObj instanceof DateTime) {
            $checkInDateFormatted = date_i18n(get_option('date_format'), $checkInDateObj->getTimestamp());
        } else {
            $checkInDateFormatted = date_i18n(get_option('date_format'), strtotime($checkInDateObj));
        }

        if ($checkOutDateObj instanceof DateTime) {
            $checkOutDateFormatted = date_i18n(get_option('date_format'), $checkOutDateObj->getTimestamp());
        } else {
            $checkOutDateFormatted = date_i18n(get_option('date_format'), strtotime($checkOutDateObj));
        }

        // 객실 세부 정보 준비
        $accommodations = '';
        if (function_exists('mphb_get_reserved_rooms_details')) {
            $accommodations = mphb_get_reserved_rooms_details($booking);
        } elseif (method_exists($booking, 'getRooms')) {
            foreach ($booking->getRooms() as $room) {
                $accommodations .= esc_html($room->getTitle()) . ' x ' . intval($room->getPersons()) . '<br>';
            }
        }

        // 5. 결제 위젯 파라미터
        $payment_params = mphb_create_toss_payment_parameters($booking, $payment_entity, $toss_gateway, $booking_key, $booking_id);

        ?>
        <style>

            .page-header .entry-title {
                display: none !important;
            }
            /* === Base Styles (Apply within the checkout form context if needed) === */
            .mphb_sc_checkout-form {
                font-family: "Pretendard", Sans-serif;
                font-size: 18px; /* Adjust base font size if needed */
                font-weight: 300;
                line-height: 1.5; /* Consistent line height */
                color: rgb(134, 142, 150);

                min-height: 60vh;
                display: flex;
                flex-direction: column;
                justify-content: center;
                max-width: 900px;
                margin: 0 auto;
            }

            .mphb_sc_checkout-form * {
                color: rgb(134, 142, 150);
            }

            .mphb_sc_checkout-form * {
                box-sizing: border-box;
            }

            .mphb_sc_checkout-form h3 {
                margin-block-start: 0.5rem; 
                margin-block-end: 1rem; 
                font-weight: 700; /* Use bold for titles */
                line-height: 1.2; 
                font-size: 1.625rem; /* Match confirmation title size */
                margin-bottom: .91em; /* Match confirmation title bottom margin */
            }

            .mphb_sc_checkout-form p {
                margin-block-start: 0; 
                margin-block-end: 0.9rem;
                font-weight: normal; /* Regular weight for paragraphs */
                margin: 0 0 1em 0; /* Standard paragraph margin */
            }

            .mphb_sc_checkout-form ul {
                list-style: none; 
                margin: 0; 
                padding: 0;
            }

            .mphb_sc_checkout-form li {
                margin-block-start: 0; 
                margin-block-end: 0; 
            }

            .mphb_sc_checkout-form a {
                text-decoration: none;
            }

            .mphb_sc_checkout-form a:hover,
            .mphb_sc_checkout-form a:active {
            }


            /* === Checkout Form Specific Styles (Mimicking Confirmation) === */

            /* Spacing between sections */
            .mphb_sc_checkout-form > .mphb-checkout-section:not(:first-of-type),
            .mphb_sc_checkout-form > .mphb-booking-details-section + .mphb-checkout-payment-section {
                margin-top: 2em; /* Use the confirmation section spacing */
            }

            /* Booking Details Section */
            .mphb_sc_checkout-form .mphb-booking-details-section.booking {
                /* Add background, padding etc. if the confirmation section has it */
                /* Example: background: #f9f9f9; padding: 2em; border-radius: 8px; */
            }

            /* Details Title */
            .mphb_sc_checkout-form .mphb-booking-details-title,
            .mphb_sc_checkout-form .mphb-gateway-chooser-title {
                /* Styles inherited from h3 */
            }

            /* Details List (ul) - Flexbox Layout */
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
                flex-wrap: wrap;
            }

            /* List Item (li) */
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li {
                flex: 1 0 100%; /* Mobile: one item per line */
                padding-left: 0;
                margin: 0 0 0.5em 0; /* Vertical margin between items */
            }

            /* Desktop & larger screens (>= 768px) */
            @media screen and (min-width: 768px) {
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li {
                    flex: 1 0 auto; /* Allow items to sit side-by-side */
                    margin: 0 1.5em 1.5em 0; /* Right and bottom margin */
                    padding-right: 1.5em; /* Space before the border */
                    border-right: 1px dashed #d3ced2; /* Dashed divider */
                }
            }

            /* Last List Item - remove border and margin */
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li:last-of-type {
                border-right: none;
                margin-right: 0;
                padding-right: 0;
            }

            /* Label Styling */
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.label {
                /* Inherits base text color (#868E96) and font-weight (300) */
            }

            /* Desktop & larger screens (>= 768px) - Label becomes a block */
            @media screen and (min-width: 768px) {
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.label {
                    display: block;
                    font-size: 0.85em; /* Smaller font size for label */
                    margin-bottom: 0.2em; /* Space below label */
                }
            }

            /* Value Styling */
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.value {
                font-weight: bold; /* Bold value */
            }

            /* Price specific styling inside value */
            .mphb_sc_checkout-form .mphb-price {
                /* Styles for price wrapper if any */
            }
            .mphb_sc_checkout-form .mphb-currency {
                /* Styles for currency symbol if any */
            }

            /* Accommodations Section Styling */
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations {
                margin-top: 1em; /* Space above accommodations details */
                clear: both; /* Ensure it clears floated elements if any */
            }

            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-title {
                display: block;
                font-weight: 500; /* Semi-bold for this title */
                margin-bottom: 0.3em;
            }

            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list {
                display: block;
            }

            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details li {
                list-style: none;
            }

            /* Payment Section Specifics */
            .mphb_sc_checkout-form .mphb-checkout-payment-section {
                /* Add specific padding/background if different from booking section */
            }

            .mphb_sc_checkout-form .mphb-checkout-payment-section .mphb-gateway-description {
                margin-bottom: 1.5em;
            }

            /* Wrapper for payment elements */
            .mphb_sc_checkout-form .mphb-billing-fields-wrapper {
                /* Styles for this wrapper if needed */
            }

            #mphb-toss-payment-widget {
                /* Placeholder for Toss widget - Add styles if needed */
                margin-bottom: 1em;
            }

            /* Terms Wrapper (Used for button and message area) */
            .mphb_sc_checkout-form .mphb-checkout-terms-wrapper {
                margin-top: 2em;
                text-align: center;
            }

            #mphb-toss-pay-btn {
                /* Use theme's button styles or define custom ones */
                /* Example from PHP: min-width: 200px; padding: 14px 36px; font-size: 1.1em; */
                cursor: pointer;
                /* Add theme button colors/borders here if needed */
                color: white;
            }

            #toss-payment-message {
                margin-top: 15px;
                min-height: 22px;
                font-size: 1em;
            }

            #toss-payment-message.mphb-error {
                color: red; /* Error message color */
                font-weight: bold;
            }

            /* Hide unnecessary elements from original checkout form structure if they exist */
            .mphb_sc_checkout-form .mphb-billing-fields,
            .mphb_sc_checkout-form .mphb-terms-and-conditions {
                /* display: none; /* Uncomment if these specific containers are not needed */
            }
        </style>

        <div class="mphb_sc_checkout-form">
            <div class="mphb-booking-details-section booking">
                <h3 class="mphb-booking-details-title"><?php esc_html_e('예약 세부 정보', 'mphb-toss-payments'); ?></h3>
                <ul class="mphb-booking-details">
                    <li class="booking-number">
                        <span class="label"><?php esc_html_e('예약:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo esc_html($booking->getId()); ?></span>
                    </li>
                    <li class="booking-check-in">
                        <span class="label"><?php esc_html_e('체크인:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo esc_html($checkInDateFormatted); ?></span>
                    </li>
                    <li class="booking-check-out">
                        <span class="label"><?php esc_html_e('체크아웃:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo esc_html($checkOutDateFormatted); ?></span>
                    </li>
                    <li class="booking-price">
                        <span class="label"><?php esc_html_e('합계:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo mphb_format_price($booking->getTotalPrice()); ?></span>
                    </li>
                    <li class="booking-status">
                        <span class="label"><?php esc_html_e('상태:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo esc_html(mphb_get_status_label($booking->getStatus())); ?></span>
                    </li>
                </ul>
                <?php if (!empty($accommodations)) : ?>
                    <div class="accommodations">
                        <span class="accommodations-title"><?php esc_html_e('세부 정보:', 'mphb-toss-payments'); ?></span>
                        <span class="accommodations-list">
                            <?php echo $accommodations; ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mphb-checkout-payment-section">
                <h3 class="mphb-gateway-chooser-title"><?php esc_html_e('결제', 'mphb-toss-payments'); ?></h3>
                
                <div class="mphb-gateway-description">
                    <p><?php esc_html_e('결제를 완료하려면 아래 버튼을 클릭하세요.', 'mphb-toss-payments'); ?></p>
                </div>
                
                <div id="mphb-billing-details-wrapper" class="mphb-billing-fields-wrapper">
                    <div id="toss-payment-widget"></div>
                    <p class="mphb-gateway-description">
                        <?php // echo esc_html($toss_gateway->getDescription()); ?>
                    </p>
                </div>
                
                <div class="mphb-checkout-terms-wrapper">
                    <button type="button" id="mphb-toss-pay-btn" class="button mphb-button mphb-confirm-reservation">
                        <?php echo esc_html__('결제하기', 'mphb-toss-payments'); ?>
                    </button>
                    <p id="toss-payment-message" class="mphb-error"></p>
                </div>
            </div>
        </div>

        <script src="https://js.tosspayments.com/v2/standard"></script>
        <script>
            jQuery(function ($) {
                const payButton = $('#mphb-toss-pay-btn');
                const messageArea = $('#toss-payment-message');
                let isProcessing = false;

                if (typeof TossPayments !== 'function') {
                    messageArea.text('<?php echo esc_js(__('토스페이먼츠 JS 로딩 실패, 새로고침 해주세요.', 'mphb-toss-payments')); ?>').css('color','red');
                    payButton.prop('disabled', true);
                    return;
                }

                const params = <?php echo wp_json_encode($payment_params); ?>;
                if (!params || !params.client_key) {
                    messageArea.text('<?php echo esc_js(__('결제 설정 오류입니다.', 'mphb-toss-payments')); ?>').css('color','red');
                    payButton.prop('disabled', true);
                    return;
                }

                const toss = TossPayments(params.client_key);
                const payment = toss.payment({ customerKey: params.customer_key });

                function openTossPayment() {
                    if (isProcessing) return;
                    isProcessing = true;
                    payButton.prop('disabled', true).text('<?php echo esc_js(__('결제 진행 중...', 'mphb-toss-payments')); ?>');
                    messageArea.text('<?php echo esc_js(__('결제창을 여는 중입니다...', 'mphb-toss-payments')); ?>');

                    payment.requestPayment({
                        method: "CARD",
                        amount: { currency: "KRW", value: params.amount },
                        orderId: params.order_id,
                        orderName: params.order_name,
                        successUrl: params.success_url,
                        failUrl: params.fail_url,
                        customerEmail: params.customer_email,
                        customerName: params.customer_name,
                        card: {
                            useEscrow: false,
                            flowMode: "DEFAULT",
                            useCardPoint: false,
                            useAppCardOnly: false,
                        }
                    }).catch(function(error) {
                        const msg = (error && error.message)
                            ? error.message
                            : '<?php echo esc_js(__('결제가 취소되었거나 오류가 발생했습니다. 다시 시도해 주세요.', 'mphb-toss-payments')); ?>';
                        messageArea.text(msg).css('color','red');
                    }).finally(function() {
                        isProcessing = false;
                        payButton.prop('disabled', false).text('<?php echo esc_js(__('결제하기', 'mphb-toss-payments')); ?>');
                    });
                }

                const autoOpenTimeout = setTimeout(function() {
                    if (!isProcessing) openTossPayment();
                }, 850);

                payButton.on('click', function(e) {
                    e.preventDefault();
                    clearTimeout(autoOpenTimeout);
                    openTossPayment();
                });
            });
        </script>
        <?php
    } catch (Exception $e) {
        echo '<div class="mphb-errors-wrapper"><p class="mphb-error">' . esc_html($e->getMessage()) . '</p></div>';
    }

    return ob_get_clean();
}
add_shortcode('mphb_toss_checkout', 'mphbTossCheckoutShortcode');
