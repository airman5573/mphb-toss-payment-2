<?php
// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if ( ! defined( 'ABSPATH' ) ) {
    exit; 
}

/**
 * 토스 체크아웃 과정에서 발생하는 특정 예외를 위한 클래스입니다.
 */
class MPHBTossCheckoutException extends \Exception {}

/**
 * 토스페이먼츠 콜백 URL 생성기 클래스입니다.
 */
class MPHBTossCallbackUrlGenerator {
    /**
     * 성공/실패 콜백 URL을 생성합니다.
     * @param string $callback_type 콜백 타입 ('success' 또는 'fail')
     * @param string $booking_key 예약 키
     * @param int $booking_id 예약 ID
     * @param string $gateway_id 사용된 게이트웨이 ID
     * @return string 생성된 콜백 URL
     */
    public static function generate( string $callback_type, string $booking_key, int $booking_id, string $gateway_id ): string {
        // 홈 URL을 기준으로 쿼리 파라미터를 추가하여 URL을 만듭니다.
        $url = add_query_arg(
            [
                'callback_type'        => $callback_type,        // 콜백 타입
                'mphb_payment_gateway' => $gateway_id,           // MPHB 게이트웨이 ID
                'booking_key'          => $booking_key,          // 예약 키
                'booking_id'           => $booking_id,           // 예약 ID
            ],
            home_url( '/' ) // 사이트 홈 URL
        );
        return $url;
    }
}

/**
 * 토스페이먼츠 체크아웃에 필요한 데이터를 제공하고 유효성을 검사하는 클래스입니다.
 */
class MPHBTossCheckoutDataProvider {
    private array $request_params; // 요청 파라미터 배열
    private \MPHB\Entities\Booking $booking; // 예약 엔티티 객체
    private \MPHB\Entities\Payment $payment_entity; // 결제 엔티티 객체
    private \MPHBTOSS\Gateways\TossGatewayBase $selected_toss_gateway_object; // 선택된 토스 게이트웨이 객체
    private string $mphb_gateway_method; // MPHB 게이트웨이 메소드 (토스 API용)
    private string $error_code = ''; // 오류 코드 (URL에서 전달받을 수 있음)
    private string $error_message = ''; // 오류 메시지 (URL에서 전달받을 수 있음)
    private string $booking_key = ''; // 예약 키
    private int $booking_id = 0; // 예약 ID
    private string $mphb_selected_gateway_id = ''; // 선택된 MPHB 게이트웨이 ID

    /**
     * 생성자입니다.
     * @param array $request_params GET 또는 POST 요청 파라미터 배열
     */
    public function __construct( array $request_params ) {
        $this->request_params = $request_params;
    }

    /**
     * 체크아웃에 필요한 데이터를 준비하고 유효성을 검사합니다.
     * @return bool 데이터 준비 성공 시 true, 실패 시 MPHBTossCheckoutException 발생
     * @throws MPHBTossCheckoutException 데이터 유효성 검사 실패 시
     */
    public function prepare_data(): bool {
        $log_context = __CLASS__ . '::prepare_data';
        mphb_toss_write_log("Starting data preparation.", $log_context);
        try {
            $this->validate_global_settings(); // 전역 설정 유효성 검사
            $this->extract_and_sanitize_request_params(); // 요청 파라미터 추출 및 살균
            mphb_toss_write_log("Request params extracted: " . print_r([ 
                'error_code' => $this->error_code, 'booking_id' => $this->booking_id, 
                'method' => $this->mphb_gateway_method, 'gateway_id' => $this->mphb_selected_gateway_id
            ], true), $log_context);
            $this->validate_request_params(); // 요청 파라미터 유효성 검사
            $this->load_and_validate_booking(); // 예약 정보 로드 및 유효성 검사
            $this->load_and_validate_payment(); // 결제 정보 로드 및 유효성 검사
            $this->load_and_validate_gateway(); // 게이트웨이 정보 로드 및 유효성 검사
        } catch (MPHBTossCheckoutException $e) { // 이 클래스에서 정의한 예외 처리
            mphb_toss_write_log("MPHBTossCheckoutException during data preparation: " . $e->getMessage(), $log_context . '_Error');
            throw $e; // 예외를 다시 던져 상위에서 처리하도록 함
        }
        mphb_toss_write_log("Data preparation completed successfully.", $log_context);
        return true;
    }

    /**
     * 토스페이먼츠 전역 설정(클라이언트 키 등)의 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 클라이언트 키가 설정되지 않은 경우
     */
    private function validate_global_settings(): void {
        if ( empty( \MPHBTOSS\TossGlobalSettingsTab::get_global_client_key() ) ) { 
            $error_msg = __( 'Toss Payments 클라이언트 키가 설정되지 않았습니다. (오류 코드: GCK01)', 'mphb-toss-payments' );
            mphb_toss_write_log("Global settings validation failed: Client key empty.", __CLASS__ . '::validate_global_settings_Error');
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { // WP_DEBUG 모드일 때 PHP 에러 로그 기록
                error_log( '[MPHB Toss] DataProvider Error: Global Client Key is empty.' );
            }
            throw new MPHBTossCheckoutException( $error_msg );
        }
    }

    /**
     * 요청 파라미터($this->request_params)에서 필요한 값들을 추출하고 살균하여 멤버 변수에 할당합니다.
     */
    private function extract_and_sanitize_request_params(): void {
        $this->error_code                 = isset( $this->request_params['code'] ) ? sanitize_text_field( $this->request_params['code'] ) : '';
        $this->error_message              = isset( $this->request_params['message'] ) ? sanitize_text_field( urldecode( $this->request_params['message'] ) ) : ''; // URL 디코딩 후 살균
        $this->booking_id                 = isset( $this->request_params['booking_id'] ) ? absint( $this->request_params['booking_id'] ) : 0; // 양의 정수로 변환
        $this->booking_key                = isset( $this->request_params['booking_key'] ) ? sanitize_text_field( $this->request_params['booking_key'] ) : '';
        $this->mphb_gateway_method        = isset( $this->request_params['mphb_gateway_method'] ) ? sanitize_text_field( strtoupper( $this->request_params['mphb_gateway_method'] ) ) : ''; // 대문자로 변환
        $this->mphb_selected_gateway_id   = isset( $this->request_params['mphb_selected_gateway_id'] ) ? sanitize_text_field( $this->request_params['mphb_selected_gateway_id'] ) : '';
    }
    
    /**
     * 추출된 요청 파라미터들의 필수 여부를 검사합니다.
     * @throws MPHBTossCheckoutException 필수 파라미터가 누락된 경우
     */
    private function validate_request_params(): void {
        // 예약 ID 또는 예약 키가 없는 경우
        if ( ! $this->booking_id || ! $this->booking_key ) {
            mphb_toss_write_log("Request param validation failed: Booking ID/Key missing.", __CLASS__ . '::validate_request_params_Error');
            throw new MPHBTossCheckoutException( __( '잘못된 접근입니다. 예약 정보를 확인할 수 없습니다. (ID/Key 누락)', 'mphb-toss-payments' ) );
        }
        // 게이트웨이 메소드 또는 선택된 게이트웨이 ID가 없는 경우
        if ( empty( $this->mphb_gateway_method ) || empty( $this->mphb_selected_gateway_id ) ) {
             mphb_toss_write_log("Request param validation failed: Gateway Method/ID missing.", __CLASS__ . '::validate_request_params_Error');
            throw new MPHBTossCheckoutException( __( '잘못된 접근입니다. 결제 수단 정보를 확인할 수 없습니다. (Method/Gateway ID 누락)', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 예약 ID와 키를 사용하여 예약 정보를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 예약 정보를 찾을 수 없거나 유효하지 않은 경우
     */
    private function load_and_validate_booking(): void {
        $booking_repo  = \MPHB()->getBookingRepository(); // MPHB 예약 저장소
        $this->booking = $booking_repo->findById( $this->booking_id ); // ID로 예약 검색
        // 예약이 없거나 예약 키가 일치하지 않는 경우
        if ( ! $this->booking || $this->booking->getKey() !== $this->booking_key ) { 
            mphb_toss_write_log("Booking validation failed. Requested ID: {$this->booking_id}, Key: {$this->booking_key}. Found: " . ($this->booking ? "ID: {$this->booking->getId()}, Key: {$this->booking->getKey()}" : "Not Found"), __CLASS__ . '::load_and_validate_booking_Error');
            throw new MPHBTossCheckoutException( __( '예약 정보를 찾을 수 없거나 접근 권한이 없습니다.', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 로드된 예약 정보에서 예상 결제 ID를 가져와 결제 정보를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 결제 정보를 찾을 수 없거나 유효하지 않은 경우
     */
    private function load_and_validate_payment(): void {
        $expected_payment_id = $this->booking->getExpectPaymentId(); // 예약에서 처리해야 할 결제 ID 가져오기
        // 예상 결제 ID가 없거나 유효하지 않은 경우
        if ( ! $expected_payment_id || $expected_payment_id <= 0 ) {
            mphb_toss_write_log("Payment validation failed: No expected payment ID for Booking ID {$this->booking->getId()}", __CLASS__ . '::load_and_validate_payment_Error');
            throw new MPHBTossCheckoutException( __( '결제 대기 중인 예약이 아닙니다. (Expected Payment ID 없음)', 'mphb-toss-payments' ) );
        }
        $payment_repository = \MPHB()->getPaymentRepository(); // MPHB 결제 저장소
        $this->payment_entity = $payment_repository->findById( $expected_payment_id ); // ID로 결제 검색
        // 결제가 없거나, 결제의 예약 ID가 현재 예약 ID와 일치하지 않는 경우
        if ( ! $this->payment_entity || $this->payment_entity->getBookingId() != $this->booking->getId() ) {
            mphb_toss_write_log("Payment validation failed: Payment entity not found or booking ID mismatch. Expected Payment ID: {$expected_payment_id}", __CLASS__ . '::load_and_validate_payment_Error');
            throw new MPHBTossCheckoutException( __( '예약에 연결된 결제 정보를 찾을 수 없습니다.', 'mphb-toss-payments' ) );
        }
        // 결제 금액이 0 이하인 경우
        if ( (float) $this->payment_entity->getAmount() <= 0 ) {
            mphb_toss_write_log("Payment validation failed: Payment amount is zero or less. Payment ID: {$this->payment_entity->getId()}", __CLASS__ . '::load_and_validate_payment_Error');
            throw new MPHBTossCheckoutException( __( '결제할 금액이 없습니다. 예약 내용을 다시 확인해 주십시오.', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 선택된 게이트웨이 ID를 사용하여 게이트웨이 객체를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 게이트웨이 객체를 로드할 수 없거나 활성화되지 않은 경우
     */
    private function load_and_validate_gateway(): void {
        // MPHB 게이트웨이 관리자를 통해 게이트웨이 객체 가져오기
        $this->selected_toss_gateway_object = \MPHB()->gatewayManager()->getGateway( $this->mphb_selected_gateway_id );
        // 게이트웨이 객체가 없거나, 예상한 TossGatewayBase 타입이 아닌 경우
        if ( ! $this->selected_toss_gateway_object || ! ( $this->selected_toss_gateway_object instanceof \MPHBTOSS\Gateways\TossGatewayBase ) ) {
            mphb_toss_write_log("Gateway validation failed: Could not load Toss Gateway object for ID: {$this->mphb_selected_gateway_id}.", __CLASS__ . '::load_and_validate_gateway_Error');
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { // WP_DEBUG 모드일 때 PHP 에러 로그 기록
                error_log( sprintf( '[MPHB Toss] DataProvider Error: Could not load Toss Gateway object for ID: %s.', $this->mphb_selected_gateway_id ) );
            }
            throw new MPHBTossCheckoutException( sprintf( __( '선택하신 결제 수단(%s)을 현재 사용할 수 없습니다.', 'mphb-toss-payments' ), esc_html( $this->mphb_selected_gateway_id ) ) );
        }
        // 게이트웨이가 활성화(enabled)되어 있지 않은 경우
        if ( ! $this->selected_toss_gateway_object->isEnabled() ) { 
             mphb_toss_write_log("Gateway validation failed: Gateway {$this->mphb_selected_gateway_id} is not enabled/active.", __CLASS__ . '::load_and_validate_gateway_Error');
            throw new MPHBTossCheckoutException( sprintf( __( '%s 결제 수단이 현재 비활성화되어 있습니다.', 'mphb-toss-payments' ), $this->selected_toss_gateway_object->getTitleForUser() ) );
        }
    }
    // 각 멤버 변수에 대한 getter 메소드들
    public function get_booking(): \MPHB\Entities\Booking { return $this->booking; }
    public function get_payment_entity(): \MPHB\Entities\Payment { return $this->payment_entity; }
    public function get_selected_toss_gateway_object(): \MPHBTOSS\Gateways\TossGatewayBase { return $this->selected_toss_gateway_object; }
    public function get_mphb_gateway_method(): string { return $this->mphb_gateway_method; }
    public function get_error_code(): string { return $this->error_code; }
    public function get_error_message(): string { return $this->error_message; }
    public function get_booking_key(): string { return $this->booking_key; }
    public function get_booking_id(): int { return $this->booking_id; }
    public function get_mphb_selected_gateway_id(): string { return $this->mphb_selected_gateway_id; }
}

/**
 * 토스페이먼츠 결제 요청에 필요한 파라미터를 구성하는 클래스입니다.
 */
class MPHBTossPaymentParamsBuilder {
    private \MPHB\Entities\Booking $booking_entity; // 예약 엔티티
    private \MPHB\Entities\Payment $payment_entity; // 결제 엔티티
    private string $booking_key; // 예약 키
    private int $booking_id; // 예약 ID
    private \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object; // 선택된 토스 게이트웨이 객체
    private string $selected_gateway_id; // 선택된 게이트웨이 ID

    /**
     * 생성자입니다.
     * @param \MPHB\Entities\Booking $booking_entity
     * @param \MPHB\Entities\Payment $payment_entity
     * @param string $booking_key
     * @param int $booking_id
     * @param \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object
     */
    public function __construct(
        \MPHB\Entities\Booking $booking_entity, \MPHB\Entities\Payment $payment_entity,
        string $booking_key, int $booking_id, \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object
    ) {
        $this->booking_entity = $booking_entity; $this->payment_entity = $payment_entity;
        $this->booking_key = $booking_key; $this->booking_id = $booking_id;
        $this->selected_gateway_object = $selected_gateway_object;
        $this->selected_gateway_id = $selected_gateway_object->getId();
    }

    /**
     * 토스페이먼츠 JS SDK에 전달할 파라미터 배열을 빌드합니다.
     * @return array 결제 파라미터 배열
     * @throws MPHBTossCheckoutException 클라이언트 키가 없는 경우
     */
    public function build(): array {
        $log_context = __CLASS__ . '::build';
        $customer = $this->booking_entity->getCustomer(); // 예약 고객 정보
        // 고객 이메일 (있으면 살균, 없으면 빈 문자열)
        $customerEmail = $customer && $customer->getEmail() ? sanitize_email( $customer->getEmail() ) : '';
        // 고객 이름 (성+이름, 없으면 표시 이름, 그것도 없으면 기본값)
        $customerName  = $customer && ($customer->getFirstName() || $customer->getLastName()) ? sanitize_text_field( trim( $customer->getFirstName() . ' ' . $customer->getLastName() ) ) : '';
        if (empty($customerName) && $customer && $customer->getDisplayName()){ $customerName = sanitize_text_field($customer->getDisplayName()); }
        if (empty($customerName)) { $customerName = __('Customer', 'mphb-toss-payments'); } // 기본 고객 이름
        // 고객 전화번호 (있으면 살균, 없으면 빈 문자열)
        $customerPhone = $customer && $customer->getPhone() ? sanitize_text_field( $customer->getPhone() ) : '';
        // 전화번호에서 숫자만 추출
        $customerPhoneClean = preg_replace('/\D/', '', $customerPhone);

        $tossCustomerKey = $this->generate_customer_key(); // 토스용 고객 키 생성
        $productName = $this->generate_order_name();     // 주문 이름(상품명) 생성
        $orderId = $this->generate_order_id();           // 주문 ID 생성
        $clientKey = $this->get_global_client_key();      // 전역 클라이언트 키 가져오기

        // JS SDK에 전달할 기본 파라미터 구성
        $params = [
            'client_key'           => $clientKey, // 클라이언트 키 (JS에서 사용)
            'customer_key'         => $tossCustomerKey, // 토스 고객 키
            'amount'               => (float) $this->payment_entity->getAmount(), // 결제 금액
            'order_id'             => $orderId, // 주문 ID
            'order_name'           => $productName, // 상품명
            'customer_email'       => $customerEmail, // 고객 이메일
            'customer_name'        => $customerName,  // 고객 이름
            'customer_mobile_phone'=> $customerPhoneClean, // 고객 휴대폰 번호 (숫자만)
            // 성공 시 콜백 URL
            'success_url'          => MPHBTossCallbackUrlGenerator::generate( 'success', $this->booking_key, $this->booking_id, $this->selected_gateway_id ),
            // 실패 시 콜백 URL
            'fail_url'             => MPHBTossCallbackUrlGenerator::generate( 'fail', $this->booking_key, $this->booking_id, $this->selected_gateway_id ),
            'selected_gateway_id'  => $this->selected_gateway_id, // 선택된 MPHB 게이트웨이 ID
            'toss_method'          => $this->selected_gateway_object->getTossMethod(), // 토스 결제 방식 (CARD, TRANSFER 등)
        ];
        // 특정 게이트웨이에 따른 JS 플래그 추가
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'foreign_card') $params['js_flags_is_foreign_card_only'] = true; // 해외 카드 전용
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'escrow_bank') $params['js_flags_is_escrow_transfer'] = true; // 에스크로 계좌이체
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'vbank') { // 가상계좌
            $params['js_flags_vbank_cash_receipt_type'] = $this->selected_gateway_object->get_gateway_option('cash_receipt_type', '미발행'); // 현금영수증 발행 타입
        }
        // 토스 결제 방식이 'CARD'이고, 간편결제 제공사 코드가 있는 경우
        if ($this->selected_gateway_object->getTossMethod() === 'CARD') {
            if (method_exists($this->selected_gateway_object, 'getEasyPayProviderCode') && !empty($this->selected_gateway_object->getEasyPayProviderCode())) {
                $params['js_easy_pay_provider_code'] = $this->selected_gateway_object->getEasyPayProviderCode(); // 간편결제 제공사 코드
                // 선호하는 결제 흐름 모드 (DIRECT 등)
                $params['js_preferred_flow_mode'] = method_exists($this->selected_gateway_object, 'getPreferredFlowMode') && !empty($this->selected_gateway_object->getPreferredFlowMode()) ? $this->selected_gateway_object->getPreferredFlowMode() : 'DIRECT';
            }
        }
        // 로그 기록 (클라이언트 키는 [REDACTED]로 마스킹)
        mphb_toss_write_log("Payment params built (client_key redacted for this log entry, full in JS): " . print_r(array_merge($params, ['client_key'=>'[REDACTED]']), true), $log_context);
        return $params;
    }
    
    /**
     * 토스페이먼츠용 고객 키를 생성합니다.
     * MPHB 고객 ID, 세션 ID, 예약 ID 등을 조합하여 생성합니다.
     * @return string 생성된 고객 키 (정규화됨)
     */
    private function generate_customer_key(): string {
        $customer = $this->booking_entity->getCustomer(); $tossCustomerKey = '';
        // MPHB 고객 ID가 있는 경우
        if ( $customer && $customer->getCustomerId() > 0 ) { $tossCustomerKey = 'cust_' . $customer->getCustomerId(); } 
        else { // 고객 ID가 없는 경우
            // MPHB 세션 ID를 사용 시도
            if ( MPHB()->session() && method_exists( MPHB()->session(), 'get_id' ) ) {
                $sessionId = MPHB()->session()->get_id();
                // 세션 ID + 예약 ID 조합 또는 예약 ID + 고유 ID 조합
                $tossCustomerKey = $sessionId ? ('sid_' . $sessionId . '_' . $this->booking_entity->getId()) : ('bkng_' . $this->booking_entity->getId() . '_' . uniqid('tck_', false));
            } else { // 세션도 없는 경우 (드문 경우)
                $tossCustomerKey = 'bkng_' . $this->booking_entity->getId() . '_' . uniqid('tck_', false);
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[MPHB Toss] MPHB session not available for customer key.' );
            }
        }
        // 생성된 키를 정규화 (특수문자 제거, 길이 제한 등)
        return mphbTossSanitizeCustomerKey( $tossCustomerKey );
    }

    /**
     * 주문 이름(상품명)을 생성합니다.
     * 예약된 첫 번째 객실 유형의 제목을 사용하고, 여러 객실일 경우 추가 정보를 표시합니다.
     * @return string 생성된 주문 이름 (최대 100자)
     */
    private function generate_order_name(): string {
        $reservedRooms = $this->booking_entity->getReservedRooms(); $productName = __( 'Reservation', 'mphb-toss-payments' ); // 기본 상품명
        if ( ! empty( $reservedRooms ) ) { // 예약된 객실이 있는 경우
            $firstRoom = $reservedRooms[0]; // 첫 번째 예약된 객실
            if ($firstRoom instanceof \MPHB\Entities\ReservedRoom) {
                $roomType = $firstRoom->getRoomType(); // 객실 유형
                if ( $roomType instanceof \MPHB\Entities\RoomType ) {
                    $firstRoomTypeTitle = $roomType->getTitle(); // 객실 유형 제목
                    if (!empty($firstRoomTypeTitle)) // 제목이 있으면
                        // 여러 객실이면 "첫 객실 외 N개", 아니면 첫 객실 제목만
                        $productName = ( count( $reservedRooms ) > 1 ) ? sprintf( __( '%s and %d other(s)', 'mphb-toss-payments' ), $firstRoomTypeTitle, count( $reservedRooms ) - 1 ) : $firstRoomTypeTitle;
                }
            }
        }
        // 상품명을 살균하고 최대 100자로 자름
        return mb_substr( sanitize_text_field( $productName ), 0, 100 );
    }

    /**
     * 토스페이먼츠용 주문 ID를 생성합니다.
     * 'mphb_예약ID_결제ID' 형식으로 생성하고, 특수문자 제거 및 길이 제한을 적용합니다.
     * @return string 생성된 주문 ID (6~64자)
     */
    private function generate_order_id(): string {
        $orderId = sprintf( 'mphb_%d_%d', $this->booking_entity->getId(), $this->payment_entity->getId() );
        $orderId = preg_replace( '/[^a-zA-Z0-9_-]/', '', $orderId ); // 영문, 숫자, 밑줄, 하이픈 외 제거
        $orderId = substr( $orderId, 0, 64 ); // 최대 64자로 자름
        // 최소 6자리가 되어야 함 (토스페이먼츠 정책)
        if ( strlen( $orderId ) < 6 ) {
            if( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[MPHB Toss] Generated orderId too short: ' . $orderId );
            // 6자 미만이면 오른쪽에 '0'을 채워 6자로 만듦
            $orderId = str_pad($orderId, 6, '0', STR_PAD_RIGHT);
        }
        return $orderId;
    }

    /**
     * 전역 토스페이먼츠 클라이언트 키를 가져옵니다.
     * @return string 클라이언트 키
     * @throws MPHBTossCheckoutException 클라이언트 키가 설정되지 않은 경우
     */
    private function get_global_client_key(): string {
        $clientKey = \MPHBTOSS\TossGlobalSettingsTab::get_global_client_key();
        if( empty($clientKey) ){ // 클라이언트 키가 비어있는 경우
            mphb_toss_write_log("Toss Payments Client Key is empty (Error Code: GCK02)", __CLASS__ . '::get_global_client_key_Error');
            throw new MPHBTossCheckoutException( __( 'Toss Payments Client Key is empty. (Error Code: GCK02)', 'mphb-toss-payments' ) );
        }
        return $clientKey;
    }
}

/**
 * 토스페이먼츠 체크아웃 숏코드('[mphb_toss_checkout]')의 출력을 처리하는 클래스입니다.
 */
class MPHBTossCheckoutShortcodeHandler {
    private array $request_params; // 요청 파라미터

    /**
     * 생성자입니다.
     * @param array $request_params GET 요청 파라미터
     */
    public function __construct( array $request_params ) {
        $this->request_params = $request_params;
    }

    /**
     * 숏코드의 HTML 출력을 생성하고 반환합니다.
     * @return string 생성된 HTML
     */
    public function render(): string {
        $log_context = __CLASS__ . '::render';
        mphb_toss_write_log("ShortcodeHandler render process started.", $log_context);
        ob_start(); // 출력 버퍼링 시작
        try {
            // 데이터 제공자 객체 생성 및 데이터 준비
            $data_provider = new MPHBTossCheckoutDataProvider( $this->request_params );
            $data_provider->prepare_data();
            // 결제 파라미터 빌더 객체 생성 및 파라미터 빌드
            $params_builder = new MPHBTossPaymentParamsBuilder(
                $data_provider->get_booking(), $data_provider->get_payment_entity(),
                $data_provider->get_booking_key(), $data_provider->get_booking_id(),
                $data_provider->get_selected_toss_gateway_object()
            );
            $payment_params_for_js = $params_builder->build(); // JS SDK용 파라미터
            // 뷰 렌더러 객체 생성 및 뷰 렌더링
            $view_renderer = new MPHBTossCheckoutView( $data_provider, $payment_params_for_js );
            echo $view_renderer->render(); // HTML 출력 (보안: 내부적으로 esc_* 사용됨)
        } catch ( MPHBTossCheckoutException $e ) { // 이 플러그인 내 정의된 예외 처리
            mphb_toss_write_log("MPHBTossCheckoutException in ShortcodeHandler: " . $e->getMessage(), $log_context . '_Error');
            $this->render_error_message( $e->getMessage() ); // 오류 메시지 렌더링
        } catch ( \Exception $e ) { // 그 외 일반적인 예외 처리
            mphb_toss_write_log("Generic Exception in ShortcodeHandler: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), $log_context . '_Error');
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[MPHB Toss] Uncaught Exception in ShortcodeHandler: ' . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString() );
            $this->render_error_message( __( 'An unknown error occurred.', 'mphb-toss-payments' ) . ' (Code: GEN01)' ); // 일반 오류 메시지
        }
        $output = ob_get_clean(); // 버퍼 내용 가져오고 버퍼 비우기
        mphb_toss_write_log("ShortcodeHandler render process finished. Output length: " . strlen($output), $log_context);
        return $output; // 최종 HTML 반환
    }
    
    /**
     * 오류 메시지를 HTML 형식으로 출력합니다.
     * @param string $message 표시할 오류 메시지
     */
    private function render_error_message( string $message ): void {
        $error_html  = '<div class="mphb_sc_checkout-form mphb-errors-wrapper">'; // 오류 래퍼 시작
        $error_html .= '<h3>' . esc_html__( 'Payment Error', 'mphb-toss-payments' ) . '</h3>'; // 제목
        $error_html .= '<p class="mphb-error">' . esc_html( $message ) . '</p>'; // 오류 메시지
        // 홈페이지로 돌아가기 버튼
        $error_html .= '<p><a href="' . esc_url( home_url( '/' ) ) . '" class="button mphb-button">' . esc_html__( 'Return to Homepage', 'mphb-toss-payments' ) . '</a></p>';
        // 재시도 버튼 (필수 파라미터가 있는 경우)
        $checkout_page_url = home_url('/toss-checkout/'); $retry_params = [];
        if (isset($this->request_params['booking_id'])) $retry_params['booking_id'] = $this->request_params['booking_id'];
        if (isset($this->request_params['booking_key'])) $retry_params['booking_key'] = $this->request_params['booking_key'];
        if (isset($this->request_params['mphb_gateway_method'])) $retry_params['mphb_gateway_method'] = $this->request_params['mphb_gateway_method'];
        if (isset($this->request_params['mphb_selected_gateway_id'])) $retry_params['mphb_selected_gateway_id'] = $this->request_params['mphb_selected_gateway_id'];
        if (count($retry_params) === 4) { // 필수 파라미터 4개가 모두 있는 경우
            $retry_url = add_query_arg($retry_params, $checkout_page_url); // 재시도 URL 생성
            $error_html .= '<p><a href="' . esc_url( $retry_url ) . '" class="button mphb-button">' . esc_html__( 'Try Again', 'mphb-toss-payments' ) . '</a></p>';
        }
        $error_html .= '</div>'; // 오류 래퍼 끝
        echo $error_html; // HTML 출력 (보안: 내부적으로 esc_* 사용됨)
    }
}

/**
 * 토스페이먼츠 체크아웃 페이지의 뷰(HTML)를 렌더링하는 클래스입니다.
 */
class MPHBTossCheckoutView {
    private MPHBTossCheckoutDataProvider $data_provider; // 데이터 제공자 객체
    private array $payment_params; // JS SDK용 결제 파라미터
    private string $check_in_date_formatted = ''; // 포맷된 체크인 날짜
    private string $check_out_date_formatted = ''; // 포맷된 체크아웃 날짜
    private string $reserved_rooms_details_html = ''; // 예약된 객실 상세 정보 HTML

    /**
     * 생성자입니다.
     * @param MPHBTossCheckoutDataProvider $data_provider 데이터 제공자
     * @param array $payment_params JS SDK용 결제 파라미터
     */
    public function __construct( MPHBTossCheckoutDataProvider $data_provider, array $payment_params ) {
        $this->data_provider  = $data_provider; 
        $this->payment_params = $payment_params;
        $this->prepare_additional_view_data(); // 뷰에 필요한 추가 데이터 준비
        mphb_toss_write_log('CheckoutView constructed. JS Payment Params (client_key redacted for this log, full in JS block): ' . print_r(array_merge($this->payment_params, ['client_key'=>'[REDACTED]']), true), __CLASS__ . '::__construct');
    }
    
    /**
     * 뷰 렌더링에 필요한 추가 데이터(체크인/아웃 날짜, 객실 정보 등)를 준비합니다.
     */
    private function prepare_additional_view_data(): void {
        $booking = $this->data_provider->get_booking(); // 예약 객체
        // 체크인 날짜 포맷팅
        $checkInDateObj = $booking->getCheckInDate();
        if ( $checkInDateObj instanceof \DateTimeInterface ) { // DateTime 객체인 경우
            $this->check_in_date_formatted = date_i18n( get_option( 'date_format' ), $checkInDateObj->getTimestamp() );
        } elseif ( is_string( $checkInDateObj ) && ! empty( $checkInDateObj ) ) { // 문자열인 경우 (DateTime 변환 시도)
            try { 
                $dt = new \DateTime($checkInDateObj, wp_timezone()); 
                $this->check_in_date_formatted = date_i18n(get_option('date_format'), $dt->getTimestamp()); 
            } catch (\Exception $e){ // 변환 실패 시 원본 문자열 사용
                $this->check_in_date_formatted = $checkInDateObj; 
            }
        }
        // 체크아웃 날짜 포맷팅 (체크인과 동일한 로직)
        $checkOutDateObj = $booking->getCheckOutDate();
        if ( $checkOutDateObj instanceof \DateTimeInterface ) {
            $this->check_out_date_formatted = date_i18n( get_option( 'date_format' ), $checkOutDateObj->getTimestamp() );
        } elseif ( is_string( $checkOutDateObj ) && ! empty( $checkOutDateObj ) ) {
             try { 
                $dt = new \DateTime($checkOutDateObj, wp_timezone()); 
                $this->check_out_date_formatted = date_i18n(get_option('date_format'), $dt->getTimestamp()); 
            } catch (\Exception $e){ 
                $this->check_out_date_formatted = $checkOutDateObj; 
            }
        }
        // 예약된 객실 상세 정보 HTML 생성
        if ( method_exists( $booking, 'getReservedRooms' ) ) { // getReservedRooms 메소드가 있는 경우
            $reservedRooms = $booking->getReservedRooms();
            if ( ! empty( $reservedRooms ) ) {
                // 각 예약된 객실의 제목을 리스트 아이템으로 만듦
                $details_list = array_map( function( $reservedRoom ) {
                    if ($reservedRoom instanceof \MPHB\Entities\ReservedRoom) {
                        $roomType = $reservedRoom->getRoomType(); 
                        return $roomType && ($roomType instanceof \MPHB\Entities\RoomType) ? '<li>' . esc_html( $roomType->getTitle() ) . '</li>' : '';
                    } 
                    return '';
                }, $reservedRooms );
                $details_list = array_filter($details_list); // 빈 아이템 제거
                if ( ! empty( $details_list ) ) {
                    $this->reserved_rooms_details_html = '<ul>' . implode( '', $details_list ) . '</ul>';
                }
            }
        }
        // 위에서 HTML 생성이 안됐고, mphb_get_reserved_rooms_details_list 함수가 사용 가능하면 해당 함수 사용 (MPHB 호환성)
        if ( empty( $this->reserved_rooms_details_html ) && function_exists( 'mphb_get_reserved_rooms_details_list' ) ) {
            $this->reserved_rooms_details_html = mphb_get_reserved_rooms_details_list( $booking, [ 'use_links' => false ] );
        }
    }

    /**
     * 체크아웃 페이지의 전체 HTML을 렌더링하여 반환합니다.
     * @return string 렌더링된 HTML
     */
    public function render(): string {
        // 데이터 제공자로부터 필요한 데이터 가져오기
        $booking                        = $this->data_provider->get_booking();
        $payment_entity                 = $this->data_provider->get_payment_entity();
        $selected_toss_gateway_object   = $this->data_provider->get_selected_toss_gateway_object();
        $error_code                     = $this->data_provider->get_error_code();
        $error_message                  = $this->data_provider->get_error_message();
        $payment_params_for_js          = $this->payment_params; // JS SDK용 파라미터

        ob_start(); // 출력 버퍼링 시작
        ?>
        <!-- 체크아웃 페이지 스타일 -->
        <style>
            /* ... (스타일 내용은 원본과 동일하게 유지) ... */
            .page-header .entry-title { display: none !important; } /* 페이지 제목 숨김 */
            .mphb_sc_checkout-form { font-family: "Pretendard", Sans-serif; font-size: 18px; font-weight: 300; line-height: 1.5; color: rgb(134, 142, 150); min-height: 60vh; display: flex; flex-direction: column; justify-content: center; max-width: 900px; margin: 0 auto; }
            .mphb_sc_checkout-form * { color: rgb(134, 142, 150); box-sizing: border-box; }
            .mphb_sc_checkout-form h3 { margin-block-start: 0.5rem; margin-block-end: 1rem; font-weight: 700; line-height: 1.2; font-size: 1.625rem; margin-bottom: .91em; }
            .mphb_sc_checkout-form p { margin-block-start: 0; margin-block-end: 0.9rem; font-weight: normal; margin: 0 0 1em 0; }
            .mphb_sc_checkout-form ul { list-style: none; margin: 0; padding: 0; }
            .mphb_sc_checkout-form li { margin-block-start: 0; margin-block-end: 0; }
            .mphb_sc_checkout-form a { text-decoration: none; }
            .mphb_sc_checkout-form > .mphb-checkout-section:not(:first-of-type),
            .mphb_sc_checkout-form > .mphb-booking-details-section + .mphb-checkout-payment-section { margin-top: 2em; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li { flex: 1 0 100%; padding-left: 0; margin: 0 0 0.5em 0; }
            @media screen and (min-width: 768px) { /* 반응형 스타일 */
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li { flex: 1 0 auto; margin: 0 1.5em 1.5em 0; padding-right: 1.5em; border-right: 1px dashed #d3ced2; }
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.label { display: block; font-size: 0.85em; margin-bottom: 0.2em; }
            }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li:last-of-type { border-right: none; margin-right: 0; padding-right: 0; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.value { font-weight: bold; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations { margin-top: 1em; clear: both; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-title { display: block; font-weight: 500; margin-bottom: 0.3em; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list { display: block; list-style: none; }
             .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list li { list-style: none; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details li { list-style: none; }
            .mphb_sc_checkout-form .mphb-checkout-payment-section { text-align: center; margin-top: 3em; } 
            #mphb-toss-payment-widget { margin-bottom: 1em; } /* 토스 결제 위젯용 (현재 사용 안함) */
            .mphb_sc_checkout-form .mphb-checkout-terms-wrapper { margin-top: 1em; text-align: center; }
            #mphb-toss-pay-btn { cursor: pointer; color: white; display: inline-block !important; }
            #mphb-toss-pay-btn > span { color: white; }
            #toss-payment-message { margin-top: 15px; min-height: 22px; font-size: 1em; }
            #toss-payment-message.mphb-error { color: red; font-weight: bold; }
            #mphb-toss-pay-spinner { display: none; vertical-align: middle; margin-left: 5px; width: 16px; height: 16px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 50%; border-top-color: #fff; animation: spin 1s linear infinite; }
            #mphb-toss-pay-btn.mphb-processing #mphb-toss-pay-spinner { display: inline-block; }
             @keyframes spin { to { transform: rotate(360deg); } }
        </style>

        <!-- 체크아웃 폼 HTML 구조 -->
        <div class="mphb_sc_checkout-form">
            <!-- 예약 상세 정보 섹션 -->
            <div class="mphb-booking-details-section booking">
                <h3 class="mphb-booking-details-title"><?php echo esc_html( '예약 상세 정보' ); ?></h3>
                <ul class="mphb-booking-details">
                    <li class="booking-number">
                        <span class="label"><?php echo esc_html( '예약 번호:' ); ?></span>
                        <span class="value"><?php echo esc_html( $booking->getId() ); ?></span>
                    </li>
                    <li class="booking-check-in">
                        <span class="label"><?php echo esc_html( '체크인:' ); ?></span>
                        <span class="value"><?php echo esc_html( $this->check_in_date_formatted ); ?></span>
                    </li>
                    <li class="booking-check-out">
                        <span class="label"><?php echo esc_html( '체크아웃:' ); ?></span>
                        <span class="value"><?php echo esc_html( $this->check_out_date_formatted ); ?></span>
                    </li>
                    <li class="booking-price">
                        <span class="label"><?php echo esc_html( '총 금액:' ); ?></span>
                        <!-- 가격 포맷팅 함수 사용 (HTML 포함 가능성 있으므로 주의) -->
                        <span class="value"><?php echo mphb_format_price( $payment_entity->getAmount() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    </li>
                    <li class="booking-status">
                        <span class="label"><?php echo esc_html( '예약 상태:' ); ?></span>
                        <span class="value"><?php echo esc_html( mphb_get_status_label( $booking->getStatus() ) ); ?></span>
                    </li>
                    <li class="booking-payment-method">
                        <span class="label"><?php echo esc_html( '결제수단:' ); ?></span>
                        <span class="value"><?php echo esc_html( $selected_toss_gateway_object->getTitleForUser() ); ?></span>
                    </li>
                </ul>
                <!-- 숙소 상세 정보 (객실 목록 등) -->
                <?php if ( ! empty( $this->reserved_rooms_details_html ) ) : ?>
                    <div class="accommodations">
                        <span class="accommodations-title"><?php echo esc_html( '숙소 상세 정보:' ); ?></span>
                        <div class="accommodations-list">
                            <?php echo wp_kses_post( $this->reserved_rooms_details_html ); // HTML 허용 (MPHB 함수 결과) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 결제 진행 섹션 -->
            <div class="mphb-checkout-payment-section">
                <div class="mphb-checkout-terms-wrapper">
                    <!-- 결제 버튼 -->
                    <button type="button" id="mphb-toss-pay-btn" class="button mphb-button mphb-confirm-reservation">
                        <span class="button-text"><?php echo esc_html( '결제 진행하기' ); ?></span>
                        <span id="mphb-toss-pay-spinner" class="mphb-loading-spinner"></span> <!-- 로딩 스피너 -->
                    </button>
                    <!-- 오류/안내 메시지 영역 -->
                    <p id="toss-payment-message" class="<?php if ( $error_code || $error_message ) { echo 'mphb-error'; } ?>">
                        <?php echo $error_message ? esc_html( $error_message ) : ''; // URL에서 전달된 오류 메시지 표시 ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- 토스페이먼츠 JS SDK 로드 -->
        <script src="https://js.tosspayments.com/v2/standard"></script>
        <script>
            jQuery(function ($) { // jQuery 사용
                // PHP에서 전달된 결제 파라미터를 JSON으로 인코딩하여 JS 변수에 할당
                const paymentParamsJS = <?php echo wp_json_encode( $payment_params_for_js ); ?>;
                // 개발자 콘솔에 파라미터 로깅 (디버깅용)
                if (window.console && paymentParamsJS) { 
                    console.log('MPHB 토스 체크아웃 JS 파라미터 (View Render):', JSON.parse(JSON.stringify(paymentParamsJS))); 
                }
                // DOM 요소 선택
                const payButton = $('#mphb-toss-pay-btn'); 
                const payButtonText = payButton.find('.button-text'); 
                const payButtonSpinner = $('#mphb-toss-pay-spinner'); 
                const messageArea = $('#toss-payment-message');
                let isProcessing = false; // 결제 처리 중 플래그
                payButton.prop('disabled', true); // 초기에는 버튼 비활성화

                // 토스 SDK 로드 실패 시 처리
                if (typeof TossPayments !== 'function') {
                    messageArea.text('<?php echo esc_js( 'TossPayments JS SDK 로드 실패.' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide(); 
                    console.error("TossPayments SDK not loaded."); 
                    return;
                }
                // 클라이언트 키 누락 시 처리
                if (!paymentParamsJS || !paymentParamsJS.client_key) {
                    console.error("MPHB 토스 체크아웃: client_key 누락.", paymentParamsJS);
                    messageArea.text('<?php echo esc_js( '결제 초기화 오류 (JSEP01).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide(); 
                    return;
                }
                // 토스 결제 방식 (method) 누락 시 처리
                const tossMethodForSDK = paymentParamsJS.toss_method;
                if (!tossMethodForSDK) {
                    console.error("MPHB 토스 체크아웃: toss_method 누락.");
                    messageArea.text('<?php echo esc_js( '결제 수단 정보 누락 (JSEP02).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide(); 
                    return;
                }
                try {
                    // 토스 SDK 초기화 (클라이언트 키 사용)
                    const toss = TossPayments(paymentParamsJS.client_key);
                    // 결제 위젯 인스턴스 생성 (고객 키 사용)
                    const paymentWidgetInstance = toss.payment(paymentParamsJS.customer_key ? { customerKey: paymentParamsJS.customer_key } : {});
                    console.log("TossPayments SDK initialized. CustomerKey: " . (paymentParamsJS.customer_key || 'N/A'));

                    // 토스 결제 요청 함수
                    function requestTossPayment() {
                        if (isProcessing) return; // 이미 처리 중이면 중복 실행 방지
                        isProcessing = true;
                        // 버튼 상태 변경: 비활성화, 처리 중 클래스 추가, 텍스트 변경, 스피너 표시
                        payButton.prop('disabled', true).addClass('mphb-processing'); 
                        payButtonText.text('<?php echo esc_js( '결제 처리 중...' ); ?>'); 
                        payButtonSpinner.show(); 
                        messageArea.text('').removeClass('mphb-error mphb-success'); // 메시지 영역 초기화
                        console.log('Requesting Toss payment. Method:', tossMethodForSDK);
                        
                        // 토스 SDK에 전달할 결제 데이터 페이로드 구성
                        let paymentDataPayload = {
                            amount: { currency: "KRW", value: parseFloat(paymentParamsJS.amount) }, // 금액
                            orderId: paymentParamsJS.order_id, // 주문 ID
                            orderName: paymentParamsJS.order_name, // 상품명
                            successUrl: paymentParamsJS.success_url, // 성공 콜백 URL
                            failUrl: paymentParamsJS.fail_url,       // 실패 콜백 URL
                            customerEmail: paymentParamsJS.customer_email, // 고객 이메일
                            customerName: paymentParamsJS.customer_name,   // 고객 이름
                            customerMobilePhone: paymentParamsJS.customer_mobile_phone, // 고객 휴대폰 번호
                        };
                        // 결제 방식(tossMethodForSDK)에 따른 추가 파라미터 설정
                        if (tossMethodForSDK === "CARD") { // 카드 결제
                            paymentDataPayload.card = { 
                                useEscrow: false, // 에스크로 사용 안 함
                                flowMode: "DEFAULT", // 기본 플로우 모드
                                useCardPoint: paymentParamsJS.js_flags_use_card_point || false, // 카드 포인트 사용 여부
                                useAppCardOnly: paymentParamsJS.js_flags_use_app_card_only || false, // 앱카드 전용 여부
                                useInternationalCardOnly: paymentParamsJS.js_flags_is_foreign_card_only === true // 해외 카드 전용 여부
                            };
                            // 간편결제 제공사 코드가 있는 경우 (카카오페이, 네이버페이 등)
                            if (paymentParamsJS.js_easy_pay_provider_code) {
                                paymentDataPayload.card.easyPay = paymentParamsJS.js_easy_pay_provider_code;
                                if (paymentParamsJS.js_preferred_flow_mode) { // 선호 플로우 모드가 있으면 설정
                                    paymentDataPayload.card.flowMode = paymentParamsJS.js_preferred_flow_mode;
                                }
                                console.log('EasyPay options for CARD:', paymentDataPayload.card);
                            }
                        } else if (tossMethodForSDK === "TRANSFER") { // 계좌이체
                            paymentDataPayload.transfer = { 
                                useEscrow: paymentParamsJS.js_flags_is_escrow_transfer === true, // 에스크로 사용 여부
                            };
                        } else if (tossMethodForSDK === "VIRTUAL_ACCOUNT") { // 가상계좌
                            paymentDataPayload.virtualAccount = { 
                                cashReceipt: { 
                                    type: paymentParamsJS.js_flags_vbank_cash_receipt_type || '미발행' // 현금영수증 발행 타입
                                }, 
                                useEscrow: false // 에스크로 사용 안 함
                            };
                        }
                        console.log('Final Toss payload:', JSON.parse(JSON.stringify(paymentDataPayload)));
                        
                        // 토스 결제 위젯으로 결제 요청 (method와 나머지 페이로드 전달)
                        paymentWidgetInstance.requestPayment({ method: tossMethodForSDK, ...paymentDataPayload })
                            .then(function(response) { // 성공 시 (보통 리디렉션 발생)
                                console.log("TossPayments success (redirecting):", response); 
                                messageArea.text('<?php echo esc_js( '결제 페이지로 이동합니다...' ); ?>').addClass('mphb-success'); 
                            })
                            .catch(function(error) { // 실패 시 (SDK 내부 오류 또는 사용자 취소 등)
                                console.error("TossPayments SDK error:", error); 
                                messageArea.text(error.message || '<?php echo esc_js( '결제 오류 발생.' ); ?>').addClass('mphb-error'); 
                            })
                            .finally(function() { // 항상 실행 (성공/실패 무관)
                                isProcessing = false; // 처리 중 플래그 해제
                                // 버튼 상태 복원
                                payButton.prop('disabled', false).removeClass('mphb-processing'); 
                                payButtonText.text('<?php echo esc_js( '결제 진행하기' ); ?>'); 
                                console.log("Toss processing finished."); 
                            });
                    }
                    // SDK 초기화 성공 시 버튼 활성화 및 클릭 이벤트 핸들러 연결
                    payButton.prop('disabled', false).show(); 
                    payButton.on('click', requestTossPayment);
                    // 페이지 로드 시 자동으로 결제 요청 실행 (사용자가 버튼 클릭을 다시 하지 않아도 되도록)
                    console.log("Auto-triggering Toss payment."); 
                    requestTossPayment();
                } catch (sdkError) { // SDK 초기화 자체에서 오류 발생 시
                    console.error("TossPayments SDK init error:", sdkError);
                    messageArea.text('<?php echo esc_js( 'SDK 초기화 오류 (JSEI01).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide();
                }
            });
        </script>
        <?php
        return ob_get_clean(); // 버퍼 내용 반환 및 버퍼 비우기
    }
}

/**
 * '[mphb_toss_checkout]' 숏코드 콜백 함수입니다.
 * 숏코드가 페이지에 사용될 때 이 함수가 호출됩니다.
 * @return string 숏코드의 HTML 출력
 */
function mphb_toss_checkout_shortcode_callback(): string {
    // 숏코드 호출 로그 (GET 파라미터 포함)
    mphb_toss_write_log("mphb_toss_checkout_shortcode_callback invoked. GET Params: " . print_r($_GET, true), 'mphb_toss_checkout_shortcode_callback');
    // 숏코드 핸들러 객체 생성 (GET 파라미터 전달)
    $handler = new MPHBTossCheckoutShortcodeHandler( $_GET );
    // 핸들러의 render 메소드를 호출하여 HTML 생성 및 반환
    return $handler->render();
}
// 'mphb_toss_checkout' 숏코드 등록
add_shortcode( 'mphb_toss_checkout', 'mphb_toss_checkout_shortcode_callback' );

