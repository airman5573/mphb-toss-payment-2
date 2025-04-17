<?php

/**
 * 예약 엔티티에서 진행 중 결제 엔티티를 조회한다.
 *
 * @param \MPHB\Entities\Booking $예약엔티티
 * @return \MPHB\Entities\Payment
 */
function mphb_예약엔티티에서진행중결제엔티티조회($예약엔티티) {
    $기대결제ID = $예약엔티티->getExpectPaymentId();
    if (!$기대결제ID || $기대결제ID <= 0) {
        throw new Exception('[결제 조회 실패] 예약에서 결제 대기(payment pending) 상태가 아닙니다.');
    }
    $결제리포지토리 = MPHB()->getPaymentRepository();
    $결제엔티티 = $결제리포지토리->findById($기대결제ID);
    if (!$결제엔티티 || $결제엔티티->getBookingId() != $예약엔티티->getId()) {
        throw new Exception('[결제 조회 실패] 해당 예약에 연결된 결제 정보가 없습니다.');
    }
    return $결제엔티티;
}

/**
 * 예약 정보를 테이블 형식의 HTML로 반환한다.
 *
 * @param \MPHB\Entities\Booking $예약엔티티
 * @return string
 */
function mphb_예약정보를테이블HTML로렌더($예약엔티티) {
    $투숙객 = $예약엔티티->getCustomer();
    $투숙객이름 = $투숙객 ? esc_html(trim($투숙객->getFirstName() . ' ' . $투숙객->getLastName())) : '';
    $투숙객이메일 = $투숙객 ? esc_html($투숙객->getEmail()) : '';
    ob_start();
    ?>
    <div id="mphb-toss-booking-info" style="max-width:600px; margin:32px auto;">
        <h2><?php echo esc_html__('예약 정보', 'mphb-toss-payments'); ?></h2>
        <table class="mphb-toss-table" style="width:100%; border-collapse:collapse; margin-bottom:24px;">
            <style>
                .mphb-toss-table th, .mphb-toss-table td { padding:10px 12px; border-bottom:1px solid #eee; text-align:left;}
                .mphb-toss-table th { background:#f8f8fa; width:110px;}
            </style>
            <tr>
                <th><?php esc_html_e('예약번호', 'mphb-toss-payments'); ?></th>
                <td><?php echo esc_html($예약엔티티->getId()); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('투숙일', 'mphb-toss-payments'); ?></th>
                <td>
                    <?php
                        echo esc_html($예약엔티티->getCheckInDate()->format('Y-m-d'));
                        echo ' ~ ';
                        echo esc_html($예약엔티티->getCheckOutDate()->format('Y-m-d'));
                        printf(' (%d박)', $예약엔티티->getNightsCount());
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('투숙객', 'mphb-toss-payments'); ?></th>
                <td><?php echo $투숙객이름; ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('이메일', 'mphb-toss-payments'); ?></th>
                <td><?php echo $투숙객이메일; ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('예약상태', 'mphb-toss-payments'); ?></th>
                <td><?php echo esc_html(mphb_get_status_label($예약엔티티->getStatus())); ?></td>
            </tr>
            <?php
            $예약된룸들 = $예약엔티티->getReservedRooms();
            foreach ($예약된룸들 as $객실순번 => $객실엔티티) {
                $객실타입엔티티 = mphb_get_room_type($객실엔티티->getRoomTypeId());
                ?>
                <tr>
                    <th>
                        <?php
                        printf(__('객실 %d', 'mphb-toss-payments'), $객실순번 + 1);
                        if ($객실타입엔티티) {
                            echo '<br><span style="color:#888;font-size:14px;font-weight:normal;">' . esc_html($객실타입엔티티->getTitle()) . '</span>';
                        }
                        ?>
                    </th>
                    <td>
                        <?php
                        printf(__('성인 %d명', 'mphb-toss-payments'), $객실엔티티->getAdults());
                        if ($객실엔티티->getChildren() > 0) {
                            echo ', ';
                            printf(__('어린이 %d명', 'mphb-toss-payments'), $객실엔티티->getChildren());
                        }
                        ?>
                    </td>
                </tr>
                <?php
            }
            ?>
            <tr>
                <th style="font-weight:bold;"><?php esc_html_e('총 결제금액', 'mphb-toss-payments'); ?></th>
                <td style="font-weight:bold;"><?php echo mphb_format_price($예약엔티티->getTotalPrice()); ?></td>
            </tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Toss 결제 위젯용 요청 파라미터를 생성한다.
 *
 * @param \MPHB\Entities\Booking $예약엔티티
 * @param \MPHB\Entities\Payment $결제엔티티
 * @param object $게이트웨이엔티티
 * @param string $예약고유키
 * @return array
 */
function mphb_토스결제위젯파라미터생성($예약엔티티, $결제엔티티, $게이트웨이엔티티, $예약고유키, $예약ID) {
    $투숙객 = $예약엔티티->getCustomer();
    $투숙객이메일 = $투숙객 ? sanitize_email($투숙객->getEmail()) : '';
    $투숙객이름 = $투숙객 ? sanitize_text_field(trim($투숙객->getFirstName() . ' ' . $투숙객->getLastName())) : '';
    $세션아이디 = (new \MPHB\Session())->get_id();
    $토스고객식별키 = ($투숙객 && $투숙객->getCustomerId()) ? 'cust_' . $투숙객->getCustomerId() : 'sid_' . $세션아이디 . '_' . $예약엔티티->getId();
    $토스고객식별키 = mphbTossSanitizeCustomerKey($토스고객식별키);
    $상품명 = $게이트웨이엔티티->generateItemName($예약엔티티);
    $orderId = sprintf('mphb_%d_%d', $예약엔티티->getId(), $결제엔티티->getId());
    return [
        'client_key'     => $게이트웨이엔티티->getClientKey(),
        'customer_key'   => $토스고객식별키,
        'amount'         => (float)$예약엔티티->getTotalPrice(),
        'order_id'       => $orderId,
        'order_name'     => $상품명,
        'customer_email' => $투숙객이메일,
        'customer_name'  => $투숙객이름,
        'success_url'    => mphb_토스콜백URL생성('success', $예약고유키, $예약ID),
        'fail_url'       => mphb_토스콜백URL생성('fail', $예약고유키, $예약ID),
    ];
}

/**
 * Toss 콜백 URL 생성 (payment_id 없이)
 */
function mphb_토스콜백URL생성($콜백타입, $예약키, $예약ID) {
    return add_query_arg(
        [
            'callback_type'        => $콜백타입,
            'mphb_payment_gateway' => 'toss',
            'booking_key'          => $예약키,
            'booking_id'           => $예약ID,
        ],
        home_url()
    );
}

// ────── SHORTCODE 메인 ─────────────
function mphbTossCheckoutShortcode() {
    ob_start();

    try {
        // STEP 1: ID, KEY 쌍으로 받기
        $파라미터_예약ID = isset($_GET['booking_id']) ? absint($_GET['booking_id']) : 0;
        $파라미터_예약고유키 = isset($_GET['booking_key']) ? sanitize_text_field($_GET['booking_key']) : '';
        if (!$파라미터_예약ID || !$파라미터_예약고유키) {
            throw new Exception('예약번호 또는 예약고유키 파라미터가 누락되었습니다.');
        }

        // STEP 2: ID로 엔티티 찾고, 키로 소유검증
        $예약리포지토리 = MPHB()->getBookingRepository();
        $예약엔티티 = $예약리포지토리->findById($파라미터_예약ID);
        if (!$예약엔티티 || $예약엔티티->getKey() !== $파라미터_예약고유키) {
            throw new Exception('예약 정보를 확인할 수 없습니다. (번호/키 불일치)');
        }

        // 이하 진행중 결제, Toss 게이트웨이 등 기존 로직과 동일!
        $결제엔티티 = mphb_예약엔티티에서진행중결제엔티티조회($예약엔티티);

        $토스게이트웨이엔티티 = MPHB()->gatewayManager()->getGateway('toss');
        if (!$토스게이트웨이엔티티) throw new Exception('Toss 결제 게이트웨이 미설정');
        if (!$토스게이트웨이엔티티->isEnabled() || !$토스게이트웨이엔티티->isActive())
            throw new Exception('Toss 결제 게이트웨이가 현재 비활성화 상태입니다.');
        if (empty($토스게이트웨이엔티티->getClientKey()))
            throw new Exception('Toss 클라이언트 키가 누락되었습니다.');
        if ((float)$예약엔티티->getTotalPrice()<=0)
            throw new Exception('결제 금액이 0원 이하입니다.');

        echo mphb_예약정보를테이블HTML로렌더($예약엔티티);

        $결제위젯파라미터 = mphb_토스결제위젯파라미터생성($예약엔티티, $결제엔티티, $토스게이트웨이엔티티, $파라미터_예약고유키, $파라미터_예약ID);

        ?>
        <div id="toss-payment-widget" style="max-width:600px; margin:0 auto 32px auto;"></div>
        <div style="max-width:600px; margin:0 auto 48px auto; text-align:center;">
            <button type="button" id="mphb-toss-pay-btn"
                style="padding:14px 36px;font-size:20px;background:#005be2;color:#fff;border:none;border-radius:8px;cursor:pointer;min-width:200px;">
                <?php echo esc_html__('결제하기', 'mphb-toss-payments'); ?>
            </button>
            <p id="toss-payment-message" style="margin-top:20px; color:#606060;"></p>
        </div>
        <script src="https://js.tosspayments.com/v2/standard"></script>
        <script>
        jQuery(function ($) {
            const 버튼결제하기 = $('#mphb-toss-pay-btn');
            const 메시지영역  = $('#toss-payment-message');
            let   진행중여부   = false;

            if (typeof TossPayments !== 'function') {
                메시지영역.text('토스페이먼츠 JS 로딩 실패, 새로고침 해주세요.').css('color','red');
                버튼결제하기.prop('disabled', true);
                return;
            }
            const 결제파라미터 = <?php echo wp_json_encode($결제위젯파라미터); ?>;
            const toss = TossPayments(결제파라미터.client_key);
            const payment = toss.payment({ customerKey: 결제파라미터.customer_key });

            function 토스결제창열기() {
                if (진행중여부) return;
                진행중여부 = true;
                버튼결제하기.prop('disabled', true).text('결제 진행 중...');
                메시지영역.text('결제창을 여는 중입니다...');
                payment.requestPayment({
                    method: "CARD",
                    amount: { currency: "KRW", value: 결제파라미터.amount },
                    orderId: 결제파라미터.order_id,
                    orderName: 결제파라미터.order_name,
                    successUrl: 결제파라미터.success_url,
                    failUrl: 결제파라미터.fail_url,
                    customerEmail: 결제파라미터.customer_email,
                    customerName: 결제파라미터.customer_name,
                    card: {
                        useEscrow: false,
                        flowMode: "DEFAULT",
                        useCardPoint: false,
                        useAppCardOnly: false,
                    }
                }).catch(function(에러){
                    메시지영역.text((에러 && 에러.message) ? 에러.message : '결제가 취소되었거나 오류가 발생했습니다. 다시 시도해 주세요.').css('color','red');
                }).finally(function(){
                    진행중여부 = false;
                    버튼결제하기.prop('disabled', false).text('결제하기');
                });
            }

            setTimeout(function(){
                if (!진행중여부) 토스결제창열기();
            }, 850);
            버튼결제하기.on('click', function(e){
                e.preventDefault();
                토스결제창열기();
            });
        });
        </script>
        <?php

    } catch (Exception $예외객체) {
        echo '<div style="color:red; margin: 32px 0;">'.esc_html($예외객체->getMessage()).'</div>';
    }

    return ob_get_clean();
}
add_shortcode('mphb_toss_checkout', 'mphbTossCheckoutShortcode');
