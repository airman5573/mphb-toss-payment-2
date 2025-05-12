<?php
// File: includes/toss-checkout-shortcode.php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // WordPress 환경 외부에서 직접 접근하는 것을 방지합니다.
}

/**
 * Class MPHBTossCheckoutException
 * 체크아웃 과정에서 발생하는 특정 예외를 위한 클래스입니다.
 */
class MPHBTossCheckoutException extends \Exception {}

/**
 * Class MPHBTossCallbackUrlGenerator
 * 토스페이먼츠 결제 후 사용될 콜백 URL을 생성하는 역할을 담당합니다.
 */
class MPHBTossCallbackUrlGenerator {

    /**
     * 토스페이먼츠 결제 후 사용될 콜백 URL을 생성합니다.
     *
     * @since x.x.x
     *
     * @param string $callback_type 콜백 유형 ('success' 또는 'fail').
     * @param string $booking_key 예약 고유 키.
     * @param int    $booking_id 예약 ID.
     * @param string $gateway_id 사용된 게이트웨이 ID.
     * @return string 생성된 콜백 URL.
     */
    public static function generate( string $callback_type, string $booking_key, int $booking_id, string $gateway_id ): string {
        return add_query_arg(
            [
                'callback_type'        => $callback_type,
                'mphb_payment_gateway' => $gateway_id,
                'booking_key'          => $booking_key,
                'booking_id'           => $booking_id,
            ],
            home_url( '/' )
        );
    }
}

/**
 * Class MPHBTossCheckoutDataProvider
 * 체크아웃 페이지에 필요한 데이터를 가져오고 유효성을 검사하는 역할을 담당합니다.
 */
class MPHBTossCheckoutDataProvider {

    private array $request_params;
    private \MPHB\Entities\Booking $booking;
    private \MPHB\Entities\Payment $payment_entity;
    private \MPHBTOSS\Gateways\TossGatewayBase $selected_toss_gateway_object;
    private string $mphb_gateway_method;
    private string $error_code = '';
    private string $error_message = '';
    private string $booking_key = '';
    private int $booking_id = 0;
    private string $mphb_selected_gateway_id = '';


    /**
     * 생성자.
     *
     * @param array $request_params HTTP 요청 파라미터 (일반적으로 $_GET).
     */
    public function __construct( array $request_params ) {
        $this->request_params = $request_params;
    }

    /**
     * 체크아웃에 필요한 모든 데이터를 로드하고 유효성을 검사합니다.
     * 성공 시 데이터를 내부 속성에 저장하고 true를 반환, 실패 시 MPHBTossCheckoutException 발생.
     *
     * @since x.x.x
     * @return bool 데이터 준비 성공 시 true.
     * @throws MPHBTossCheckoutException 데이터 로딩 또는 유효성 검사 실패 시.
     */
    public function prepare_data(): bool {
        $this->validate_global_settings();
        $this->extract_and_sanitize_request_params();
        $this->validate_request_params();
        $this->load_and_validate_booking();
        $this->load_and_validate_payment();
        $this->load_and_validate_gateway();

        return true;
    }

    /**
     * 전역 토스페이먼츠 설정을 검증합니다 (클라이언트 키).
     * @throws MPHBTossCheckoutException 설정 오류 시.
     */
    private function validate_global_settings(): void {
        if ( ! class_exists( '\MPHBTOSS\TossGlobalSettingsTab' ) ||
            ! method_exists( '\MPHBTOSS\TossGlobalSettingsTab', 'get_global_client_key' ) ||
            empty( \MPHBTOSS\TossGlobalSettingsTab::get_global_client_key() ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MPHB Toss] DataProvider Error: TossGlobalSettingsTab not available or Global Client Key is empty.' );
            }
            throw new MPHBTossCheckoutException( __( 'Toss Payments 클라이언트 키가 설정되지 않았습니다. 사이트 관리자에게 문의하여 주십시오. (오류 코드: GCK01)', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 요청 파라미터를 추출하고 정제하여 내부 속성에 저장합니다.
     */
    private function extract_and_sanitize_request_params(): void {
        $this->error_code                 = isset( $this->request_params['code'] ) ? sanitize_text_field( $this->request_params['code'] ) : '';
        $this->error_message              = isset( $this->request_params['message'] ) ? sanitize_text_field( urldecode( $this->request_params['message'] ) ) : '';
        $this->booking_id                 = isset( $this->request_params['booking_id'] ) ? absint( $this->request_params['booking_id'] ) : 0;
        $this->booking_key                = isset( $this->request_params['booking_key'] ) ? sanitize_text_field( $this->request_params['booking_key'] ) : '';
        $this->mphb_gateway_method        = isset( $this->request_params['mphb_gateway_method'] ) ? sanitize_text_field( strtoupper( $this->request_params['mphb_gateway_method'] ) ) : '';
        $this->mphb_selected_gateway_id   = isset( $this->request_params['mphb_selected_gateway_id'] ) ? sanitize_text_field( $this->request_params['mphb_selected_gateway_id'] ) : '';
    }

    /**
     * 필수 요청 파라미터의 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 파라미터 누락 시.
     */
    private function validate_request_params(): void {
        if ( ! $this->booking_id || ! $this->booking_key ) {
            throw new MPHBTossCheckoutException( __( '잘못된 접근입니다. 예약 정보를 확인할 수 없습니다. (ID/Key 누락)', 'mphb-toss-payments' ) );
        }
        if ( empty( $this->mphb_gateway_method ) || empty( $this->mphb_selected_gateway_id ) ) {
            throw new MPHBTossCheckoutException( __( '잘못된 접근입니다. 결제 수단 정보를 확인할 수 없습니다. (Method/Gateway ID 누락)', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 예약 정보를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 예약 정보 오류 시.
     */
    private function load_and_validate_booking(): void {
        $booking_repo  = \MPHB()->getBookingRepository();
        $this->booking = $booking_repo->findById( $this->booking_id );

        if ( ! $this->booking || ! ( $this->booking instanceof \MPHB\Entities\Booking ) || $this->booking->getKey() !== $this->booking_key ) {
            throw new MPHBTossCheckoutException( __( '예약 정보를 찾을 수 없거나 접근 권한이 없습니다.', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 예약에 연결된 결제 정보를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 결제 정보 오류 시.
     */
    private function load_and_validate_payment(): void {
        $expected_payment_id = $this->booking->getExpectPaymentId();
        if ( ! $expected_payment_id || $expected_payment_id <= 0 ) {
            throw new MPHBTossCheckoutException( __( '결제 대기 중인 예약이 아닙니다. (Expected Payment ID 없음)', 'mphb-toss-payments' ) );
        }

        $payment_repository = \MPHB()->getPaymentRepository();
        $this->payment_entity = $payment_repository->findById( $expected_payment_id );

        if ( ! $this->payment_entity || $this->payment_entity->getBookingId() != $this->booking->getId() ) {
            throw new MPHBTossCheckoutException( __( '예약에 연결된 결제 정보를 찾을 수 없습니다.', 'mphb-toss-payments' ) );
        }

        if ( (float) $this->payment_entity->getAmount() <= 0 ) {
            throw new MPHBTossCheckoutException( __( '결제할 금액이 없습니다. 예약 내용을 다시 확인해 주십시오.', 'mphb-toss-payments' ) );
        }
    }

    /**
     * 선택된 토스 게이트웨이 객체를 로드하고 유효성을 검사합니다.
     * @throws MPHBTossCheckoutException 게이트웨이 정보 오류 시.
     */
    private function load_and_validate_gateway(): void {
        $this->selected_toss_gateway_object = \MPHB()->gatewayManager()->getGateway( $this->mphb_selected_gateway_id );

        if ( ! $this->selected_toss_gateway_object || ! ( $this->selected_toss_gateway_object instanceof \MPHBTOSS\Gateways\TossGatewayBase ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[MPHB Toss] DataProvider Error: Could not load Toss Gateway object for ID: %s. Booking ID: %d', $this->mphb_selected_gateway_id, $this->booking_id ) );
            }
            throw new MPHBTossCheckoutException( sprintf( __( '선택하신 결제 수단(%s)을 현재 사용할 수 없습니다. 사이트 관리자에게 문의해 주십시오.', 'mphb-toss-payments' ), esc_html( $this->mphb_selected_gateway_id ) ) );
        }

        if ( ! $this->selected_toss_gateway_object->isEnabled() || ! $this->selected_toss_gateway_object->isActive() ) {
            throw new MPHBTossCheckoutException( sprintf( __( '%s 결제 수단이 현재 비활성화되어 있습니다.', 'mphb-toss-payments' ), $this->selected_toss_gateway_object->getTitleForUser() ) );
        }
    }

    // 게터 메소드들
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

// Start of MPHBTossPaymentParamsBuilder (modified)
class MPHBTossPaymentParamsBuilder {

    private \MPHB\Entities\Booking $booking_entity;
    private \MPHB\Entities\Payment $payment_entity;
    private string $booking_key;
    private int $booking_id;
    private \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object; // Store the gateway object
    private string $selected_gateway_id;


    /**
     * 생성자.
     *
     * @param \MPHB\Entities\Booking $booking_entity 예약 엔티티.
     * @param \MPHB\Entities\Payment $payment_entity 결제 엔티티.
     * @param string                 $booking_key 예약 고유 키.
     * @param int                    $booking_id 예약 ID.
     * @param \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object 선택된 MPHB 게이트웨이 객체.
     */
    public function __construct(
        \MPHB\Entities\Booking $booking_entity,
        \MPHB\Entities\Payment $payment_entity,
        string $booking_key,
        int $booking_id,
        \MPHBTOSS\Gateways\TossGatewayBase $selected_gateway_object
    ) {
        $this->booking_entity          = $booking_entity;
        $this->payment_entity          = $payment_entity;
        $this->booking_key             = $booking_key;
        $this->booking_id              = $booking_id;
        $this->selected_gateway_object = $selected_gateway_object;
        $this->selected_gateway_id     = $selected_gateway_object->getId();
    }

    /**
     * 결제 파라미터 배열을 빌드합니다.
     *
     * @since x.x.x
     * @return array 생성된 결제 파라미터.
     * @throws MPHBTossCheckoutException 클라이언트 키 조회 실패 시.
     */
    public function build(): array {
        $customer      = $this->booking_entity->getCustomer();
        $customerEmail = $customer && $customer->getEmail() ? sanitize_email( $customer->getEmail() ) : '';
        $customerName  = $customer && ($customer->getFirstName() || $customer->getLastName()) ? sanitize_text_field( trim( $customer->getFirstName() . ' ' . $customer->getLastName() ) ) : '';
        if (empty($customerName) && $customer && $customer->getDisplayName()){
            $customerName = sanitize_text_field($customer->getDisplayName());
        }
        if (empty($customerName)) { // Fallback if no name parts are available
            $customerName = __('Customer', 'mphb-toss-payments');
        }

        $customerPhone = $customer && $customer->getPhone() ? sanitize_text_field( $customer->getPhone() ) : '';
        // Remove non-digits from phone for Toss, as it might prefer a cleaner format for some methods like VBank notifications
        $customerPhoneClean = preg_replace('/\D/', '', $customerPhone);


        $tossCustomerKey = $this->generate_customer_key();
        $productName     = $this->generate_order_name();
        $orderId         = $this->generate_order_id(); // Corrected order ID generation
        $clientKey       = $this->get_global_client_key();

        $params = [
            'client_key'           => $clientKey,
            'customer_key'         => $tossCustomerKey,
            'amount'               => (float) $this->payment_entity->getAmount(),
            'order_id'             => $orderId,
            'order_name'           => $productName,
            'customer_email'       => $customerEmail,
            'customer_name'        => $customerName,
            'customer_mobile_phone'=> $customerPhoneClean, // Use cleaned phone number
            'success_url'          => MPHBTossCallbackUrlGenerator::generate( 'success', $this->booking_key, $this->booking_id, $this->selected_gateway_id ),
            'fail_url'             => MPHBTossCallbackUrlGenerator::generate( 'fail', $this->booking_key, $this->booking_id, $this->selected_gateway_id ),
            'selected_gateway_id'  => $this->selected_gateway_id,
            'toss_method'          => $this->selected_gateway_object->getTossMethod(), // Pass the actual Toss method for JS
        ];
        
        // Add specific flags based on the selected gateway type for JS
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'foreign_card') {
            $params['js_flags_is_foreign_card_only'] = true;
        }
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'escrow_bank') {
            $params['js_flags_is_escrow_transfer'] = true;
        }
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'vbank') {
            function_exists('ray') && ray('$this->selected_gateway_object', $this->selected_gateway_object)->blue();
            
            $params['js_flags_vbank_cash_receipt_type'] = $this->selected_gateway_object->get_gateway_option('cash_receipt_type', '미발행'); 
        }
        // Apple Pay is now handled by the generic easy pay logic below if it uses CARD method
        // if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'applepay') {
        //    $params['js_flags_is_apple_pay'] = true; // This specific flag might not be needed if generic easyPay handling is sufficient
        // }

        // === Generalized EasyPay (like NaverPay, KakaoPay, ApplePay etc.) parameter handling ===
        // This section adds parameters if the gateway uses the 'CARD' method but is an EasyPay type.
        if ($this->selected_gateway_object->getTossMethod() === 'CARD') {
            if (method_exists($this->selected_gateway_object, 'getEasyPayProviderCode') &&
                !empty($this->selected_gateway_object->getEasyPayProviderCode())) {
                
                $params['js_easy_pay_provider_code'] = $this->selected_gateway_object->getEasyPayProviderCode();

                if (method_exists($this->selected_gateway_object, 'getPreferredFlowMode') &&
                    !empty($this->selected_gateway_object->getPreferredFlowMode())) {
                    $params['js_preferred_flow_mode'] = $this->selected_gateway_object->getPreferredFlowMode();
                } else {
                    // Default flow mode for EasyPay if not specified by gateway (though 'DIRECT' is typical for this setup)
                    $params['js_preferred_flow_mode'] = 'DIRECT'; 
                }
            }
        }
        // === End of Generalized EasyPay parameter handling ===

        return $params;
    }

    /**
     * 토스페이먼츠 고객 키를 생성합니다.
     */
    private function generate_customer_key(): string {
        $customer        = $this->booking_entity->getCustomer();
        $tossCustomerKey = '';

        if ( $customer && $customer->getCustomerId() > 0 ) {
            $tossCustomerKey = 'cust_' . $customer->getCustomerId();
        } else {
            if ( MPHB()->session() && method_exists( MPHB()->session(), 'get_id' ) ) {
                $sessionId = MPHB()->session()->get_id();
                $tossCustomerKey = $sessionId ? ('sid_' . $sessionId . '_' . $this->booking_entity->getId()) : ('bkng_' . $this->booking_entity->getId() . '_' . uniqid('tck_', false));
            } else {
                $tossCustomerKey = 'bkng_' . $this->booking_entity->getId() . '_' . uniqid('tck_', false);
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[MPHB Toss] PaymentParamsBuilder: MPHB session or get_id method not available for customer key generation.' );
                }
            }
        }

        if ( function_exists( 'mphbTossSanitizeCustomerKey' ) ) {
            return mphbTossSanitizeCustomerKey( $tossCustomerKey );
        } else {
            $tossCustomerKey = preg_replace( '/[^a-zA-Z0-9\-\_\=\.\@]/', '', $tossCustomerKey );
            $tossCustomerKey = substr( $tossCustomerKey, 0, 50 );
            if (strlen($tossCustomerKey) < 2) $tossCustomerKey = str_pad($tossCustomerKey, 2, '0');
            return $tossCustomerKey;
        }
    }

    /**
     * 토스페이먼츠 주문명을 생성합니다.
     */
    private function generate_order_name(): string {
        $reservedRooms = $this->booking_entity->getReservedRooms();
        $productName   = __( 'Reservation', 'mphb-toss-payments' ); 

        if ( ! empty( $reservedRooms ) ) {
            $firstRoom = $reservedRooms[0];
            if ($firstRoom instanceof \MPHB\Entities\ReservedRoom) {
                $roomType = $firstRoom->getRoomType();
                if ( $roomType instanceof \MPHB\Entities\RoomType ) {
                    $firstRoomTypeTitle = $roomType->getTitle();
                    if (!empty($firstRoomTypeTitle)) {
                        $productName = ( count( $reservedRooms ) > 1 ) ? sprintf( __( '%s and %d other(s)', 'mphb-toss-payments' ), $firstRoomTypeTitle, count( $reservedRooms ) - 1 ) : $firstRoomTypeTitle;
                    }
                }
            }
        }
        return mb_substr( sanitize_text_field( $productName ), 0, 100 ); 
    }

    /**
     * 토스페이먼츠 주문 ID를 생성합니다.
     */
    private function generate_order_id(): string {
        $orderId = sprintf( 'mphb_%d_%d', $this->booking_entity->getId(), $this->payment_entity->getId() );
        $orderId = preg_replace( '/[^a-zA-Z0-9_-]/', '', $orderId );
        $orderId = substr( $orderId, 0, 64 );

        if ( strlen( $orderId ) < 6 ) {
            if( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MPHB Toss] PaymentParamsBuilder: Generated orderId is too short: ' . $orderId . ' for Booking ID: ' . $this->booking_entity->getId() . ' Payment ID: ' . $this->payment_entity->getId() );
            }
            $orderId = str_pad($orderId, 6, '0', STR_PAD_RIGHT);
        }
        return $orderId;
    }

    /**
     * 전역 설정에서 클라이언트 키를 가져옵니다.
     * @throws MPHBTossCheckoutException 클라이언트 키 설정 오류 시.
     */
    private function get_global_client_key(): string {
        if ( class_exists( '\MPHBTOSS\TossGlobalSettingsTab' ) && method_exists( '\MPHBTOSS\TossGlobalSettingsTab', 'get_global_client_key' ) ) {
            $clientKey = \MPHBTOSS\TossGlobalSettingsTab::get_global_client_key();
            if( empty($clientKey) ){
                throw new MPHBTossCheckoutException( __( 'Toss Payments Client Key is empty. (Error Code: GCK02)', 'mphb-toss-payments' ) );
            }
            return $clientKey;
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MPHB Toss] PaymentParamsBuilder: TossGlobalSettingsTab class or get_global_client_key method not found.' );
        }
        throw new MPHBTossCheckoutException( __( 'Toss Payments Client Key configuration not found. (Error Code: GCK03)', 'mphb-toss-payments' ) );
    }
} // End of MPHBTossPaymentParamsBuilder

/**
 * Class MPHBTossCheckoutView
 * 체크아웃 페이지의 HTML, CSS, JavaScript를 렌더링하는 역할을 담당합니다.
 */
class MPHBTossCheckoutView {

    private MPHBTossCheckoutDataProvider $data_provider;
    private array $payment_params;

    private string $check_in_date_formatted = '';
    private string $check_out_date_formatted = '';
    private string $reserved_rooms_details_html = '';

    /**
     * 생성자.
     *
     * @param MPHBTossCheckoutDataProvider $data_provider 데이터 제공 객체.
     * @param array                       $payment_params 토스 결제 파라미터.
     */
    public function __construct( MPHBTossCheckoutDataProvider $data_provider, array $payment_params ) {
        $this->data_provider  = $data_provider;
        $this->payment_params = $payment_params;
        $this->prepare_additional_view_data();
        if (function_exists('ray')) {
            // 체크아웃 뷰 초기화됨. JS용 결제 파라미터:
            ray('체크아웃 뷰 초기화됨. JS용 결제 파라미터:', $this->payment_params)->blue()->label('CheckoutView');
        }
    }

    /**
     * 뷰 렌더링에 필요한 추가 데이터(날짜 포맷팅, 객실 정보 HTML 등)를 준비합니다.
     */
    private function prepare_additional_view_data(): void {
        $booking = $this->data_provider->get_booking();

        $checkInDateObj = $booking->getCheckInDate();
        if ( $checkInDateObj instanceof \DateTimeInterface ) {
            $this->check_in_date_formatted = date_i18n( get_option( 'date_format' ), $checkInDateObj->getTimestamp() );
        } elseif ( is_string( $checkInDateObj ) && ! empty( $checkInDateObj ) ) {
            try {
                $dt = new \DateTime($checkInDateObj, wp_timezone());
                $this->check_in_date_formatted = date_i18n(get_option('date_format'), $dt->getTimestamp());
            } catch (\Exception $e){
                $this->check_in_date_formatted = $checkInDateObj;
            }
        }

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

        if ( method_exists( $booking, 'getReservedRooms' ) ) {
            $reservedRooms = $booking->getReservedRooms();
            if ( ! empty( $reservedRooms ) ) {
                $details_list = array_map( function( $reservedRoom ) {
                    if ($reservedRoom instanceof \MPHB\Entities\ReservedRoom) {
                        $roomType = $reservedRoom->getRoomType();
                        return $roomType && ($roomType instanceof \MPHB\Entities\RoomType) ? '<li>' . esc_html( $roomType->getTitle() ) . '</li>' : '';
                    }
                    return '';
                }, $reservedRooms );
                $details_list = array_filter($details_list);
                if ( ! empty( $details_list ) ) {
                    $this->reserved_rooms_details_html = '<ul>' . implode( '', $details_list ) . '</ul>';
                }
            }
        }

        if ( empty( $this->reserved_rooms_details_html ) && function_exists( 'mphb_get_reserved_rooms_details_list' ) ) {
            $this->reserved_rooms_details_html = mphb_get_reserved_rooms_details_list( $booking, [ 'use_links' => false ] );
        }
    }

    /**
     * 체크아웃 HTML 및 JavaScript를 렌더링합니다.
     *
     * @since x.x.x
     * @return string 렌더링된 HTML 및 JavaScript.
     */
    public function render(): string {
        $booking                        = $this->data_provider->get_booking();
        $payment_entity                 = $this->data_provider->get_payment_entity();
        $selected_toss_gateway_object   = $this->data_provider->get_selected_toss_gateway_object();
        $error_code                     = $this->data_provider->get_error_code();
        $error_message                  = $this->data_provider->get_error_message();
        $payment_params_for_js          = $this->payment_params;

        ob_start();
        ?>
        <style>
            /* 스타일은 유지합니다. 필요시 클래스명 등을 조정하세요. */
            .page-header .entry-title { display: none !important; }
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
            @media screen and (min-width: 768px) {
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li { flex: 1 0 auto; margin: 0 1.5em 1.5em 0; padding-right: 1.5em; border-right: 1px dashed #d3ced2; }
                .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.label { display: block; font-size: 0.85em; margin-bottom: 0.2em; }
            }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li:last-of-type { border-right: none; margin-right: 0; padding-right: 0; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.value { font-weight: bold; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations { margin-top: 1em; clear: both; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-title { display: block; font-weight: 500; margin-bottom: 0.3em; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list { display: block; }
            .mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details li { list-style: none; }
            .mphb_sc_checkout-form .mphb-checkout-payment-section .mphb-gateway-description { margin-bottom: 1.5em; }
            #mphb-toss-payment-widget { margin-bottom: 1em; }
            .mphb_sc_checkout-form .mphb-checkout-terms-wrapper { margin-top: 2em; text-align: center; }
            #mphb-toss-pay-btn { cursor: pointer; color: white; }
            #toss-payment-message { margin-top: 15px; min-height: 22px; font-size: 1em; }
            #toss-payment-message.mphb-error { color: red; font-weight: bold; }
        </style>

        <div class="mphb_sc_checkout-form">
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
                        <span class="value"><?php echo mphb_format_price( $payment_entity->getAmount() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                    </li>
                    <li class="booking-status">
                        <span class="label"><?php echo esc_html( '예약 상태:' ); ?></span>
                        <span class="value"><?php echo esc_html( mphb_get_status_label( $booking->getStatus() ) ); ?></span>
                    </li>
                </ul>
                <?php if ( ! empty( $this->reserved_rooms_details_html ) ) : ?>
                    <div class="accommodations">
                        <span class="accommodations-title"><?php echo esc_html( '숙소 상세 정보:' ); ?></span>
                        <div class="accommodations-list">
                            <?php echo wp_kses_post( $this->reserved_rooms_details_html ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mphb-checkout-payment-section">
                <h3 class="mphb-gateway-chooser-title"><?php echo esc_html( $selected_toss_gateway_object->getTitleForUser() ); ?></h3>
                <div class="mphb-gateway-description">
                    <?php echo wp_kses_post( wpautop( $selected_toss_gateway_object->getDescriptionForUser() ) ); ?>
                </div>

                <div class="mphb-checkout-terms-wrapper">
                    <button type="button" id="mphb-toss-pay-btn" class="button mphb-button mphb-confirm-reservation">
                        <?php echo esc_html( '결제 진행하기' ); ?>
                        <span id="mphb-toss-pay-spinner" class="mphb-loading-spinner" style="display:none;"></span>
                    </button>
                    <p id="toss-payment-message" class="<?php if ( $error_code || $error_message ) { echo 'mphb-error'; } ?>">
                        <?php echo $error_message ? esc_html( $error_message ) : ''; ?>
                    </p>
                </div>
            </div>
        </div>

        <script src="https://js.tosspayments.com/v2/standard"></script>
        <script>
            jQuery(function ($) {
                const payButton = $('#mphb-toss-pay-btn');
                const payButtonSpinner = $('#mphb-toss-pay-spinner');
                const messageArea = $('#toss-payment-message');
                let isProcessing = false;

                if (typeof TossPayments !== 'function') {
                    messageArea.text('<?php echo esc_js( 'TossPayments JS SDK 로드 실패.' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide();
                    return;
                }

                const paymentParamsJS = <?php echo wp_json_encode( $payment_params_for_js ); ?>;
                if (window.console && paymentParamsJS) { console.log('MPHB 토스 체크아웃 JS 파라미터:', paymentParamsJS); }


                if (!paymentParamsJS || !paymentParamsJS.client_key) {
                    console.error("MPHB 토스 체크아웃: 잘못된 paymentParamsJS 또는 client_key 누락.", paymentParamsJS);
                    messageArea.text('<?php echo esc_js( '결제 초기화 오류 (JSEP01).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide();
                    return;
                }

                const tossMethodForSDK = paymentParamsJS.toss_method;
                if (!tossMethodForSDK) {
                    console.error("MPHB 토스 체크아웃: JS 파라미터에 toss_method 누락.");
                    messageArea.text('<?php echo esc_js( '결제 수단 정보 누락 (JSEP02).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide();
                    return;
                }

                try {
                    const toss = TossPayments(paymentParamsJS.client_key);
                    const paymentWidgetInstance = toss.payment(paymentParamsJS.customer_key ? { customerKey: paymentParamsJS.customer_key } : {});

                    function requestTossPayment() {
                        if (isProcessing) return;
                        isProcessing = true;
                        payButtonSpinner.show();
                        payButton.prop('disabled', true).find('span:not(.mphb-loading-spinner)').text('<?php echo esc_js( '결제 처리 중...' ); ?>');
                        messageArea.text('').removeClass('mphb-error mphb-success');

                        console.log('tossMethodForSDK (PHP 게이트웨이 객체로부터):', tossMethodForSDK);

                        let paymentDataPayload = {
                            amount: { currency: "KRW", value: parseFloat(paymentParamsJS.amount) },
                            orderId: paymentParamsJS.order_id,
                            orderName: paymentParamsJS.order_name,
                            successUrl: paymentParamsJS.success_url,
                            failUrl: paymentParamsJS.fail_url,
                            customerEmail: paymentParamsJS.customer_email,
                            customerName: paymentParamsJS.customer_name,
                            customerMobilePhone: paymentParamsJS.customer_mobile_phone,
                        };

                        if (tossMethodForSDK === "CARD") {
                            paymentDataPayload.card = {
                                useEscrow: false,
                                flowMode: "DEFAULT",
                                useCardPoint: paymentParamsJS.js_flags_use_card_point || false,
                                useAppCardOnly: paymentParamsJS.js_flags_use_app_card_only || false,
                                useInternationalCardOnly: paymentParamsJS.js_flags_is_foreign_card_only === true
                            };

                            if (paymentParamsJS.js_easy_pay_provider_code) {
                                paymentDataPayload.card.easyPay = paymentParamsJS.js_easy_pay_provider_code;
                                if (paymentParamsJS.js_preferred_flow_mode) {
                                    paymentDataPayload.card.flowMode = paymentParamsJS.js_preferred_flow_mode;
                                }
                                console.log('간편결제 옵션이 CARD 방식에 추가됨. Payload.card:', paymentDataPayload.card);
                            }
                        } else if (tossMethodForSDK === "TRANSFER") {
                            paymentDataPayload.transfer = {
                                useEscrow: paymentParamsJS.js_flags_is_escrow_transfer === true,
                            };
                        } else if (tossMethodForSDK === "VIRTUAL_ACCOUNT") {
                            paymentDataPayload.virtualAccount = {
                                cashReceipt: {
                                    type: paymentParamsJS.js_flags_vbank_cash_receipt_type || '미발행'
                                },
                                useEscrow: false
                            };
                        }
                        // 'MOBILE_PHONE', 'PAYPAL'과 같은 다른 직접 방식 (CARD+easyPay를 통하지 않는 경우)
                        // SDK는 `tossMethodForSDK`를 직접 사용합니다. 현재 변경 사항은 대부분의 간편결제가
                        // 네이버페이 예시처럼 CARD 방식을 통해 라우팅된다고 가정합니다.

                        if (window.console) { console.log('토스 결제 요청 중, 방식:', tossMethodForSDK, '최종 페이로드:', JSON.parse(JSON.stringify(paymentDataPayload)) ); }


                        paymentWidgetInstance.requestPayment({ method: tossMethodForSDK, ...paymentDataPayload })
                            .catch(function(error) {
                                console.error("TossPayments SDK 오류:", error);
                                messageArea.text(error.message || '<?php echo esc_js( '결제가 취소되었거나 오류가 발생했습니다.' ); ?>').addClass('mphb-error');
                            })
                            .finally(function() {
                                isProcessing = false;
                                payButtonSpinner.hide();
                                payButton.prop('disabled', false).find('span:not(.mphb-loading-spinner)').text('<?php echo esc_js( '결제 진행하기' ); ?>');
                            });
                    }
                    payButton.prop('disabled', false).on('click', requestTossPayment);

                } catch (sdkError) {
                    console.error("TossPayments SDK 초기화 오류:", sdkError);
                    messageArea.text('<?php echo esc_js( 'TossPayments SDK 초기화 오류 (JSEI01).' ); ?>').addClass('mphb-error');
                    payButton.prop('disabled', true).hide();
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }
} // MPHBTossCheckoutView 클래스 끝


/**
 * Class MPHBTossCheckoutShortcodeHandler
 * MPHB Toss Payments 체크아웃 숏코드를 처리하는 메인 핸들러 클래스입니다.
 */
class MPHBTossCheckoutShortcodeHandler {

    private array $request_params;

    /**
     * 생성자.
     *
     * @param array $request_params HTTP GET 요청 파라미터.
     */
    public function __construct( array $request_params ) {
        $this->request_params = $request_params;
    }

    /**
     * 숏코드를 렌더링합니다.
     *
     * @since x.x.x
     * @return string 렌더링된 HTML 또는 오류 메시지.
     */
    public function render(): string {
        if (function_exists('ray')) {
            ray('MPHBTossCheckoutShortcodeHandler->render() called. Request Params:', $this->request_params)->label('[ShortcodeHandler]')->blue();
        }
        ob_start();
        try {
            $data_provider = new MPHBTossCheckoutDataProvider( $this->request_params );
            $data_provider->prepare_data(); 

            $params_builder = new MPHBTossPaymentParamsBuilder(
                $data_provider->get_booking(),
                $data_provider->get_payment_entity(),
                $data_provider->get_booking_key(),
                $data_provider->get_booking_id(),
                $data_provider->get_selected_toss_gateway_object() 
            );
            $payment_params_for_js = $params_builder->build(); 

            $view_renderer = new MPHBTossCheckoutView( $data_provider, $payment_params_for_js );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $view_renderer->render();

        } catch ( MPHBTossCheckoutException $e ) { 
            if (function_exists('ray')) { ray('MPHBTossCheckoutException in ShortcodeHandler:', $e->getMessage())->label('[ShortcodeHandler]')->red(); }
            $this->render_error_message( $e->getMessage() );
        } catch ( \Exception $e ) { 
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[MPHB Toss] Uncaught Exception in ShortcodeHandler: ' . $e->getMessage() . "\nStack Trace:\n" . $e->getTraceAsString() );
            }
            if (function_exists('ray')) { ray('Generic Exception in ShortcodeHandler:', $e->getMessage(), $e->getTraceAsString())->label('[ShortcodeHandler]')->red(); }
            $this->render_error_message( __( 'An unknown error occurred. Please try again shortly.', 'mphb-toss-payments' ) . ' (Code: GEN01)' );
        }
        return ob_get_clean();
    }

    /**
     * 오류 메시지를 HTML로 렌더링합니다.
     *
     * @param string $message 표시할 오류 메시지.
     */
    private function render_error_message( string $message ): void {
        $error_html  = '<div class="mphb_sc_checkout-form mphb-errors-wrapper">';
        $error_html .= '<h3>' . esc_html__( 'Payment Error', 'mphb-toss-payments' ) . '</h3>';
        $error_html .= '<p class="mphb-error">' . esc_html( $message ) . '</p>';
        $error_html .= '<p><a href="' . esc_url( home_url( '/' ) ) . '" class="button mphb-button">' . esc_html__( 'Return to Homepage', 'mphb-toss-payments' ) . '</a></p>';
        
        $checkout_page_url = home_url('/toss-checkout/');
        $retry_params = [];

        if (isset($this->request_params['booking_id'])) {
            $retry_params['booking_id'] = $this->request_params['booking_id'];
        }
        if (isset($this->request_params['booking_key'])) {
            $retry_params['booking_key'] = $this->request_params['booking_key'];
        }
        if (isset($this->request_params['mphb_gateway_method'])) {
            $retry_params['mphb_gateway_method'] = $this->request_params['mphb_gateway_method'];
        }
        if (isset($this->request_params['mphb_selected_gateway_id'])) {
            $retry_params['mphb_selected_gateway_id'] = $this->request_params['mphb_selected_gateway_id'];
        }

        if (count($retry_params) === 4) { 
            $retry_url = add_query_arg($retry_params, $checkout_page_url);
            $error_html .= '<p><a href="' . esc_url( $retry_url ) . '" class="button mphb-button">' . esc_html__( 'Try Again', 'mphb-toss-payments' ) . '</a></p>';
        }

        $error_html .= '</div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $error_html;
    }
} // End of MPHBTossCheckoutShortcodeHandler

/**
 * MPHB Toss Payments 체크아웃 숏코드 콜백 함수.
 */
function mphb_toss_checkout_shortcode_callback(): string {
    // Ensure $_GET is used as the source of truth for request parameters.
    $handler = new MPHBTossCheckoutShortcodeHandler( $_GET );
    return $handler->render();
}

// 기존 숏코드명과 콜백 함수를 연결합니다.
add_shortcode( 'mphb_toss_checkout', 'mphb_toss_checkout_shortcode_callback' );

