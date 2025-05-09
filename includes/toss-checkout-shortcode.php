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
        throw new Exception(__('결제 대기 중인 예약이 아닙니다. (Expected Payment ID 없음)', 'mphb-toss-payments'));
    }
    // 2. 결제 리포지토리에서 결제 엔티티 조회
    $paymentRepository = MPHB()->getPaymentRepository();
    $paymentEntity = $paymentRepository->findById($expectedPaymentId);
    // 3. 결제 엔티티가 없거나 연결이 맞지 않으면 예외
    if (!$paymentEntity || $paymentEntity->getBookingId() != $bookingEntity->getId()) {
        throw new Exception(__('예약에 연결된 결제 정보를 찾을 수 없습니다.', 'mphb-toss-payments'));
    }
    return $paymentEntity;
}

/**
 * Toss 결제 위젯에 필요한 파라미터를 생성하는 함수
 *
 * @param \MPHB\Entities\Booking $bookingEntity 예약 엔티티 객체
 * @param \MPHB\Entities\Payment $paymentEntity 결제 엔티티 객체
 * @param \MPHBTOSS\Gateways\TossGatewayBase $gatewayEntity 실제 선택된 게이트웨이 객체 (제목, 설명 등에 사용)
 * @param string $bookingKey 예약 고유 키
 * @param int $bookingId 예약 ID
 * @param string $selectedGatewayId 실제 선택된 MPHB 게이트웨이 ID (예: "toss_card")
 * @return array 생성된 결제 파라미터 배열
 */
function mphb_create_toss_payment_parameters($bookingEntity, $paymentEntity, $gatewayEntity, $bookingKey, $bookingId, $selectedGatewayId) {
    $customer = $bookingEntity->getCustomer();
    $customerEmail = $customer ? sanitize_email($customer->getEmail()) : '';
    $customerName = $customer ? sanitize_text_field(trim($customer->getFirstName() . ' ' . $customer->getLastName())) : '';

    $tossCustomerKey = '';
    if ($customer && $customer->getCustomerId() > 0) {
        $tossCustomerKey = 'cust_' . $customer->getCustomerId();
    } else {
        if (MPHB()->session() && method_exists(MPHB()->session(), 'get_id')) {
            $sessionId = MPHB()->session()->get_id();
            if ($sessionId) {
                 $tossCustomerKey = 'sid_' . $sessionId . '_' . $bookingEntity->getId();
            } else {
                 $tossCustomerKey = 'bkng_' . $bookingEntity->getId() . '_' . uniqid();
            }
        } else {
            $tossCustomerKey = 'bkng_' . $bookingEntity->getId() . '_' . uniqid();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MPHB Toss] mphb_create_toss_payment_parameters: MPHB session or get_id method not available.');
            }
        }
    }

    if (function_exists('mphbTossSanitizeCustomerKey')) {
        $tossCustomerKey = mphbTossSanitizeCustomerKey($tossCustomerKey);
    } else {
        $tossCustomerKey = preg_replace('/[^a-zA-Z0-9\-_]/', '', $tossCustomerKey);
        $tossCustomerKey = substr($tossCustomerKey, 0, 50);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MPHB Toss] mphb_create_toss_payment_parameters: mphbTossSanitizeCustomerKey function not found. Using basic sanitization.');
        }
    }

    $reservedRooms = $bookingEntity->getReservedRooms();
    $productName = __('Reservation', 'mphb-toss-payments');
    if (!empty($reservedRooms)) {
        $firstRoomType = $reservedRooms[0]->getRoomType();
        if ($firstRoomType) {
            $firstRoomTypeTitle = $firstRoomType->getTitle();
            if (count($reservedRooms) > 1) {
                $productName = sprintf(__('%s and %d other(s)', 'mphb-toss-payments'), $firstRoomTypeTitle, count($reservedRooms) - 1);
            } else {
                $productName = $firstRoomTypeTitle;
            }
        }
    }
    $productName = mb_substr(sanitize_text_field($productName), 0, 100);
    $orderId = sprintf('mphb_%d_%d_%s', $bookingEntity->getId(), $paymentEntity->getId(), uniqid('', true));

    // --- Client Key를 TossGlobalSettingsTab에서 직접 가져오도록 수정 ---
    $clientKey = '';
    if (class_exists('\MPHBTOSS\TossGlobalSettingsTab') && method_exists('\MPHBTOSS\TossGlobalSettingsTab', 'get_global_client_key')) {
        $clientKey = \MPHBTOSS\TossGlobalSettingsTab::get_global_client_key();
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MPHB Toss] mphb_create_toss_payment_parameters: TossGlobalSettingsTab class or get_global_client_key method not found.');
        }
        // 이 경우 심각한 오류이므로, 숏코드 상단에서 미리 확인하고 예외 처리하는 것이 더 좋습니다.
        // 여기서는 빈 문자열을 반환하지만, JS에서 client_key가 없으면 오류를 발생시킵니다.
    }

    return [
        'client_key'     => $clientKey, // 전역 설정에서 가져온 클라이언트 키
        'customer_key'   => $tossCustomerKey,
        'amount'         => (float)$payment_entity->getAmount(),
        'order_id'       => $orderId,
        'order_name'     => $productName,
        'customer_email' => $customerEmail,
        'customer_name'  => $customerName,
        'success_url'    => mphb_create_toss_callback_url('success', $bookingKey, $bookingId, $selectedGatewayId),
        'fail_url'       => mphb_create_toss_callback_url('fail', $bookingKey, $bookingId, $selectedGatewayId),
    ];
}

/**
 * Toss 결제 후 콜백 URL을 생성하는 함수
 * (변경 없음)
 */
function mphb_create_toss_callback_url($callbackType, $bookingKey, $bookingId, $gatewayId) {
    return add_query_arg(
        [
            'callback_type'        => $callbackType,
            'mphb_payment_gateway' => $gatewayId,
            'booking_key'          => $bookingKey,
            'booking_id'           => $bookingId,
        ],
        home_url('/')
    );
}

/**
 * MPHB Toss Payments 체크아웃 숏코드 함수
 */
function mphbTossCheckoutShortcode() {
    ob_start();
    function_exists('ray') && ray('called')->label('[mphbTossCheckoutShortcode]');

    try {
        // --- 전역 Client Key 존재 여부 먼저 확인 ---
        if (!class_exists('\MPHBTOSS\TossGlobalSettingsTab') || !method_exists('\MPHBTOSS\TossGlobalSettingsTab', 'get_global_client_key') || empty(\MPHBTOSS\TossGlobalSettingsTab::get_global_client_key())) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[MPHB Toss] Checkout Error: TossGlobalSettingsTab not available or Global Client Key is empty. Payment cannot proceed.');
            }
            throw new Exception(__('Toss Payments 클라이언트 키가 설정되지 않았습니다. 사이트 관리자에게 문의하여 주십시오. (오류 코드: GCK01)', 'mphb-toss-payments'));
        }


        $error_code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $error_message = isset($_GET['message']) ? sanitize_text_field(urldecode($_GET['message'])) : '';

        $booking_id = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
        $booking_key = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : '';

        $mphb_gateway_method = isset($_GET['mphb_gateway_method']) ? sanitize_text_field(strtoupper($_GET['mphb_gateway_method'])) : '';
        $mphb_selected_gateway_id = isset($_GET['mphb_selected_gateway_id']) ? sanitize_text_field($_GET['mphb_selected_gateway_id']) : '';

        if (!$booking_id || !$booking_key) {
            throw new Exception(__('잘못된 접근입니다. 예약 정보를 확인할 수 없습니다. (ID/Key 누락)', 'mphb-toss-payments'));
        }
        if (empty($mphb_gateway_method) || empty($mphb_selected_gateway_id)) {
            throw new Exception(__('잘못된 접근입니다. 결제 수단 정보를 확인할 수 없습니다. (Method/Gateway ID 누락)', 'mphb-toss-payments'));
        }

        $booking_repo = MPHB()->getBookingRepository();
        $booking = $booking_repo->findById($booking_id);
        if (!$booking || !($booking instanceof \MPHB\Entities\Booking) || $booking->getKey() !== $booking_key) {
            throw new Exception(__('예약 정보를 찾을 수 없거나 접근 권한이 없습니다.', 'mphb-toss-payments'));
        }

        $payment_entity = mphb_get_pending_payment_from_booking($booking);

        $selected_toss_gateway_object = MPHB()->gatewayManager()->getGateway($mphb_selected_gateway_id);

        if (!$selected_toss_gateway_object || !($selected_toss_gateway_object instanceof \MPHBTOSS\Gateways\TossGatewayBase)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[MPHB Toss] Checkout Error: Could not load Toss Gateway object for ID: %s. Booking ID: %d', $mphb_selected_gateway_id, $booking_id));
            }
            throw new Exception(sprintf(__('선택하신 결제 수단(%s)을 현재 사용할 수 없습니다. 사이트 관리자에게 문의해 주십시오.', 'mphb-toss-payments'), esc_html($mphb_selected_gateway_id)));
        }

        if (!$selected_toss_gateway_object->isEnabled() || !$selected_toss_gateway_object->isActive()) {
            throw new Exception(sprintf(__('%s 결제 수단이 현재 비활성화되어 있습니다.', 'mphb-toss-payments'), $selected_toss_gateway_object->getTitleForUser()));
        }

        // Client Key는 전역 설정에서 가져오므로, 게이트웨이 객체에서 직접 확인하지 않아도 됨 (이미 상단에서 확인)
        // if (empty($selected_toss_gateway_object->getClientKey())) { ... } 이 부분 제거

        if ((float)$payment_entity->getAmount() <= 0) {
            throw new Exception(__('결제할 금액이 없습니다. 예약 내용을 다시 확인해 주십시오.', 'mphb-toss-payments'));
        }

        $checkInDateFormatted = '';
        if ($booking->getCheckInDate()) {
            $checkInDateFormatted = date_i18n(get_option('date_format'), strtotime($booking->getCheckInDate()));
        }
        $checkOutDateFormatted = '';
        if ($booking->getCheckOutDate()) {
            $checkOutDateFormatted = date_i18n(get_option('date_format'), strtotime($booking->getCheckOutDate()));
        }

        $reserved_rooms_details_html = '';
        if (method_exists($booking, 'getReservedRooms')) {
            $reservedRooms = $booking->getReservedRooms();
            if (!empty($reservedRooms)) {
                $details_list = [];
                foreach ($reservedRooms as $reservedRoom) {
                    $roomType = $reservedRoom->getRoomType();
                    if ($roomType) {
                        $details_list[] = '<li>' . esc_html($roomType->getTitle()) . '</li>';
                    }
                }
                if (!empty($details_list)) {
                    $reserved_rooms_details_html = '<ul>' . implode('', $details_list) . '</ul>';
                }
            }
        }
        if (empty($reserved_rooms_details_html) && function_exists('mphb_get_reserved_rooms_details_list')) {
            $reserved_rooms_details_html = mphb_get_reserved_rooms_details_list($booking, ['use_links' => false]);
        }

        $payment_params = mphb_create_toss_payment_parameters(
            $booking,
            $payment_entity,
            $selected_toss_gateway_object, // 게이트웨이 객체는 제목/설명 등에 여전히 필요
            $booking_key,
            $booking_id,
            $mphb_selected_gateway_id
        );

        ?>
        <style>
            /* 이전과 동일한 스타일 코드가 여기에 위치합니다. */
            .page-header .entry-title { display: none !important; }
            .mphb_sc_checkout-form { font-family: "Pretendard", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 16px; font-weight: 400; line-height: 1.6; color: #495057; min-height: 60vh; display: flex; flex-direction: column; justify-content: center; max-width: 760px; margin: 2em auto; padding: 1.5em; border: 1px solid #e9ecef; border-radius: 0.3rem; background-color: #fff; }
            .mphb_sc_checkout-form * { box-sizing: border-box; }
            .mphb_sc_checkout-form h3 { font-size: 1.75rem; font-weight: 600; color: #343a40; margin-top: 0; margin-bottom: 1.2em; line-height: 1.3; }
            .mphb_sc_checkout-form p { margin-bottom: 1em; }
            .mphb_sc_checkout-form ul { list-style: none; padding-left: 0; margin-bottom: 1em; }
            .mphb_sc_checkout-form .mphb-booking-details-section, .mphb_sc_checkout-form .mphb-checkout-payment-section { margin-bottom: 2em; }
            .mphb_sc_checkout-form .mphb-booking-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1em; padding: 1em; background-color: #f8f9fa; border-radius: 0.25rem; }
            .mphb_sc_checkout-form .mphb-booking-details > li { margin-bottom: 0.5em; padding-bottom: 0.5em; border-bottom: 1px solid #dee2e6; }
            .mphb_sc_checkout-form .mphb-booking-details > li:last-child { border-bottom: none; margin-bottom: 0; }
            .mphb_sc_checkout-form .mphb-booking-details span.label { display: block; font-size: 0.9em; color: #6c757d; margin-bottom: 0.25em; }
            .mphb_sc_checkout-form .mphb-booking-details span.value { font-weight: 500; color: #212529; }
            .mphb_sc_checkout-form .accommodations { margin-top: 1em; padding-top: 1em; border-top: 1px dashed #ced4da; }
            .mphb_sc_checkout-form .accommodations-title { font-weight: 500; margin-bottom: 0.5em; display: block; }
            .mphb_sc_checkout-form .accommodations-list ul { margin-bottom: 0; }
            .mphb_sc_checkout-form .accommodations-list li { font-size: 0.95em; }
            .mphb_sc_checkout-form .mphb-gateway-description p { font-size: 0.95em; margin-bottom: 1.5em; }
            .mphb_sc_checkout-form .mphb-checkout-terms-wrapper { text-align: center; margin-top: 1.5em; }
            #mphb-toss-pay-btn { background-color: #007bff; border-color: #007bff; color: white; padding: 0.75em 1.5em; font-size: 1em; font-weight: 500; border-radius: 0.25rem; cursor: pointer; transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out; min-width: 180px; }
            #mphb-toss-pay-btn:hover { background-color: #0069d9; border-color: #0062cc; }
            #mphb-toss-pay-btn:disabled { background-color: #6c757d; border-color: #6c757d; cursor: not-allowed; opacity: 0.65; }
            #toss-payment-message { margin-top: 1em; min-height: 1.5em; font-size: 0.9em; }
            #toss-payment-message.mphb-error { color: #dc3545; font-weight: 500; }
            .mphb-errors-wrapper { padding: 1em; background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 0.25rem; margin: 1em 0; }
            .mphb-errors-wrapper p { margin-bottom: 0.5em; }
            .mphb-errors-wrapper p:last-child { margin-bottom: 0; }
        </style>

        <div class="mphb_sc_checkout-form">
            <div class="mphb-booking-details-section booking">
                <h3 class="mphb-booking-details-title"><?php esc_html_e('예약 세부 정보', 'mphb-toss-payments'); ?></h3>
                <ul class="mphb-booking-details">
                    <li class="booking-number">
                        <span class="label"><?php esc_html_e('예약 번호:', 'mphb-toss-payments'); ?></span>
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
                        <span class="label"><?php esc_html_e('총 금액:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo mphb_format_price($payment_entity->getAmount()); ?></span>
                    </li>
                    <li class="booking-status">
                        <span class="label"><?php esc_html_e('예약 상태:', 'mphb-toss-payments'); ?></span>
                        <span class="value"><?php echo esc_html(mphb_get_status_label($booking->getStatus())); ?></span>
                    </li>
                </ul>
                <?php if (!empty($reserved_rooms_details_html)) : ?>
                    <div class="accommodations">
                        <span class="accommodations-title"><?php esc_html_e('객실 정보:', 'mphb-toss-payments'); ?></span>
                        <div class="accommodations-list">
                            <?php echo wp_kses_post($reserved_rooms_details_html); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mphb-checkout-payment-section">
                <h3 class="mphb-gateway-chooser-title"><?php echo esc_html($selected_toss_gateway_object->getTitleForUser()); ?></h3>
                <div class="mphb-gateway-description">
                    <p><?php echo wp_kses_post($selected_toss_gateway_object->getDescriptionForUser()); ?></p>
                </div>

                <div id="mphb-billing-details-wrapper" class="mphb-billing-fields-wrapper">
                    <?php /* 토스페이먼츠 v2 SDK는 별도의 iframe 마운트 영역이 필요하지 않습니다. */ ?>
                </div>

                <div class="mphb-checkout-terms-wrapper">
                    <button type="button" id="mphb-toss-pay-btn" class="button mphb-button mphb-confirm-reservation">
                        <?php echo esc_html__('결제하기', 'mphb-toss-payments'); ?>
                    </button>
                    <p id="toss-payment-message" class="<?php if ($error_code || $error_message) echo 'mphb-error'; ?>"><?php
                        if ($error_message) {
                            echo esc_html($error_message);
                        }
                    ?></p>
                </div>
            </div>
        </div>

        <script src="https://js.tosspayments.com/v2/standard"></script>
        <script>
            jQuery(function ($) {
                const payButton = $('#mphb-toss-pay-btn');
                const messageArea = $('#toss-payment-message');
                let isProcessing = false;
                const isErrorFromServer = <?php echo json_encode(!empty($error_code) || !empty($error_message)); ?>;

                if (typeof TossPayments !== 'function') {
                    messageArea.text('<?php echo esc_js(__('TossPayments JS SDK 로딩에 실패했습니다. 페이지를 새로고침하거나 관리자에게 문의하세요.', 'mphb-toss-payments')); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).css('display', 'none');
                    return;
                }

                const paymentParams = <?php echo wp_json_encode($payment_params); ?>;

                if (!paymentParams || typeof paymentParams !== 'object' || !paymentParams.client_key) {
                    console.error("MPHB Toss Checkout: Invalid paymentParams or client_key is missing.", paymentParams);
                    messageArea.text('<?php echo esc_js(__('결제 초기화 정보가 올바르지 않습니다. (오류 코드: JSEP01). 관리자에게 문의하세요.', 'mphb-toss-payments')); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).css('display', 'none');
                    return;
                }

                const tossMethod = "<?php echo esc_js($mphb_gateway_method); ?>";
                if (!tossMethod) {
                    console.error("MPHB Toss Checkout: tossMethod (payment method) is missing from URL parameters.");
                    messageArea.text('<?php echo esc_js(__('결제 방식 정보가 누락되었습니다. (오류 코드: JSEP02). 관리자에게 문의하세요.', 'mphb-toss-payments')); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).css('display', 'none');
                    return;
                }

                try {
                    const toss = TossPayments(paymentParams.client_key); // 전역 client_key 사용
                    const paymentWidgetInstance = toss.payment(paymentParams.customer_key ? { customerKey: paymentParams.customer_key } : {});

                    function requestTossPayment() {
                        if (isProcessing) {
                            console.log("MPHB Toss Checkout: Payment already in progress.");
                            return;
                        }
                        isProcessing = true;
                        payButton.prop('disabled', true).text('<?php echo esc_js(__('결제 처리 중...', 'mphb-toss-payments')); ?>');
                        messageArea.text('').removeClass('mphb-error');

                        let paymentDataPayload = {
                            amount: { currency: "KRW", value: parseFloat(paymentParams.amount) },
                            orderId: paymentParams.order_id,
                            orderName: paymentParams.order_name,
                            successUrl: paymentParams.success_url,
                            failUrl: paymentParams.fail_url,
                            customerEmail: paymentParams.customer_email,
                            customerName: paymentParams.customer_name,
                        };

                        if (tossMethod === "CARD") {
                            paymentDataPayload.card = {
                                useEscrow: false,
                                flowMode: "DEFAULT",
                                useCardPoint: false,
                                useAppCardOnly: false,
                            };
                        }
                        // Add other method-specific options if needed

                        console.log("MPHB Toss Checkout: Requesting payment with data:", { method: tossMethod, ...paymentDataPayload });

                        paymentWidgetInstance.requestPayment({ method: tossMethod, ...paymentDataPayload })
                            .catch(function(error) {
                                console.error("MPHB Toss Payments SDK Error during requestPayment:", error);
                                const msg = (error && error.message) ? error.message : '<?php echo esc_js(__('결제가 취소되었거나 알 수 없는 오류가 발생했습니다. 다시 시도해 주세요.', 'mphb-toss-payments')); ?>';
                                messageArea.text(msg).addClass('mphb-error');
                            })
                            .finally(function() {
                                isProcessing = false;
                                payButton.prop('disabled', false).text('<?php echo esc_js(__('결제하기', 'mphb-toss-payments')); ?>');
                            });
                    }

                    payButton.prop('disabled', false);
                    payButton.on('click', function(e) {
                        e.preventDefault();
                        requestTossPayment();
                    });

                } catch (sdkInitializationError) {
                    console.error("MPHB Toss Checkout: Failed to initialize TossPayments SDK.", sdkInitializationError);
                    messageArea.text('<?php echo esc_js(__('TossPayments SDK 초기화 중 오류가 발생했습니다. (오류 코드: JSEI01). 페이지를 새로고침하거나 관리자에게 문의하세요.', 'mphb-toss-payments')); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).css('display', 'none');
                }
            });
        </script>
        <?php
    } catch (Exception $e) {
        echo '<div class="mphb-errors-wrapper"><p class="mphb-error">' . esc_html($e->getMessage()) . '</p><p><a href="' . esc_url(home_url('/')) . '" class="button">' . esc_html__('홈으로 돌아가기', 'mphb-toss-payments') . '</a></p></div>';
    }

    return ob_get_clean();
}
add_shortcode('mphb_toss_checkout', 'mphbTossCheckoutShortcode');

