<?php
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
        // For VBank, you might pass configurable options if you add them to TossGatewayVbank settings
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'vbank') {
            // Example: $params['js_flags_vbank_valid_hours'] = $this->selected_gateway_object->getOption('valid_hours', 24);
            $params['js_flags_vbank_cash_receipt_type'] = $this->selected_gateway_object->getOption('cash_receipt_type', '미발행'); // Default to '미발행' or get from settings
        }
        if ($this->selected_gateway_id === \MPHBTOSS\Gateways\TossGatewayBase::MPHB_GATEWAY_ID_PREFIX . 'applepay') {
            $params['js_flags_is_apple_pay'] = true;
        }

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
     * (Continuing from your provided code)
	 */
	private function generate_order_name(): string {
		$reservedRooms = $this->booking_entity->getReservedRooms();
		$productName   = __( 'Reservation', 'mphb-toss-payments' ); // Default name

		if ( ! empty( $reservedRooms ) ) {
			$firstRoom = $reservedRooms[0];
            if ($firstRoom instanceof \MPHB\Entities\ReservedRoom) {
                $roomType = $firstRoom->getRoomType();
                if ( $roomType instanceof \MPHB\Entities\RoomType ) {
                    $firstRoomTypeTitle = $roomType->getTitle();
                    // Check if title is not empty to avoid issues with sprintf
                    if (!empty($firstRoomTypeTitle)) {
                        $productName = ( count( $reservedRooms ) > 1 ) ? sprintf( __( '%s and %d other(s)', 'mphb-toss-payments' ), $firstRoomTypeTitle, count( $reservedRooms ) - 1 ) : $firstRoomTypeTitle;
                    }
                }
            }
		}
		return mb_substr( sanitize_text_field( $productName ), 0, 100 ); // Max 100 chars for orderName
	}

	/**
	 * 토스페이먼츠 주문 ID를 생성합니다.
     * This MUST match the format expected by TossGatewayBase::handleInstanceTossCallback for validation.
	 */
	private function generate_order_id(): string {
        // Expected format: mphb_BOOKINGID_PAYMENTID
		$orderId = sprintf( 'mphb_%d_%d', $this->booking_entity->getId(), $this->payment_entity->getId() );
        
        // Toss orderId: 6 to 64 characters. String. Allowed: a-z, A-Z, 0-9, -, _
		$orderId = preg_replace( '/[^a-zA-Z0-9_-]/', '', $orderId );
		$orderId = substr( $orderId, 0, 64 );

		if ( strlen( $orderId ) < 6 ) {
            // This should not happen with the new format if booking ID and payment ID are reasonably sized.
            // If it does, it indicates an issue with IDs.
            if( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			    error_log( '[MPHB Toss] PaymentParamsBuilder: Generated orderId is too short: ' . $orderId . ' for Booking ID: ' . $this->booking_entity->getId() . ' Payment ID: ' . $this->payment_entity->getId() );
            }
            // Pad if too short, though this is a workaround for an underlying issue.
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
            ray('Checkout View Initialized. Payment Params for JS:', $this->payment_params)->blue()->label('CheckoutView');
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
                $this->check_in_date_formatted = $checkInDateObj; // fallback
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
                $this->check_out_date_formatted = $checkOutDateObj; // fallback
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
				$details_list = array_filter($details_list); // 빈 항목 제거
				if ( ! empty( $details_list ) ) {
					$this->reserved_rooms_details_html = '<ul>' . implode( '', $details_list ) . '</ul>';
				}
			}
		}

		if ( empty( $this->reserved_rooms_details_html ) && function_exists( 'mphb_get_reserved_rooms_details_list' ) ) {
			// Ensure mphb_get_reserved_rooms_details_list is available and safe to call
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
		// 필요한 변수들을 지역 변수로 할당 (가독성 향상)
		$booking                        = $this->data_provider->get_booking();
		$payment_entity                 = $this->data_provider->get_payment_entity();
		$selected_toss_gateway_object   = $this->data_provider->get_selected_toss_gateway_object();
		$actual_toss_method             = $this->data_provider->get_actual_toss_method(); // Use the method from gateway object
		$error_code                     = $this->data_provider->get_error_code();
		$error_message                  = $this->data_provider->get_error_message();
		$payment_params_for_js          = $this->payment_params; // Already prepared with JS flags

		ob_start();
		?>
		<style>
			/* CSS from your original file - ensure it's complete and correct */
			.page-header .entry-title { display: none !important; }
			.mphb_sc_checkout-form { font-family: "Pretendard", Sans-serif; font-size: 18px; font-weight: 300; line-height: 1.5; color: rgb(134, 142, 150); min-height: 60vh; display: flex; flex-direction: column; justify-content: center; max-width: 900px; margin: 0 auto; padding: 20px; }
			.mphb_sc_checkout-form * { color: rgb(52, 58, 64); /* Adjusted for better readability */ }
			.mphb_sc_checkout-form * { box-sizing: border-box; }
			.mphb_sc_checkout-form h3 { margin-block-start: 0.5rem; margin-block-end: 1rem; font-weight: 700; line-height: 1.2; font-size: 1.625rem; margin-bottom: .91em; color: #343a40; }
			.mphb_sc_checkout-form p { margin-block-start: 0; margin-block-end: 0.9rem; font-weight: normal; margin: 0 0 1em 0; color: #555; }
			.mphb_sc_checkout-form ul { list-style: none; margin: 0; padding: 0; }
			.mphb_sc_checkout-form li { margin-block-start: 0; margin-block-end: 0; }
			.mphb_sc_checkout-form a { text-decoration: none; color: #007bff; }
            .mphb_sc_checkout-form a:hover { text-decoration: underline; }
			.mphb_sc_checkout-form > .mphb-checkout-section:not(:first-of-type),
			.mphb_sc_checkout-form > .mphb-booking-details-section + .mphb-checkout-payment-section { margin-top: 2em; padding-top: 2em; border-top: 1px solid #eee; }
			.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 1em; }
			.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li { flex: 1 1 100%; padding: 0.5em; background-color: #f8f9fa; border-radius: 4px; }
			@media screen and (min-width: 768px) {
				.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li { flex: 1 1 calc(50% - 1em); /* Two columns on larger screens */ }
			}
			.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.label { display: block; font-size: 0.85em; margin-bottom: 0.2em; color: #6c757d; }
			.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details > li span.value { font-weight: bold; color: #343a40; }
			.mphb_sc_checkout-form .mphb-booking-details-section .accommodations { margin-top: 1em; clear: both; }
			.mphb_sc_checkout-form .mphb-booking-details-section .accommodations-title { display: block; font-weight: 500; margin-bottom: 0.3em; color: #495057; }
			.mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list { display: block; padding-left: 1.5em; }
            .mphb_sc_checkout-form .mphb-booking-details-section .accommodations-list ul { list-style: disc; }
			.mphb_sc_checkout-form .mphb-booking-details-section .mphb-booking-details li { list-style: none; } /* Redundant, already set for ul parent */
			.mphb_sc_checkout-form .mphb-checkout-payment-section .mphb-gateway-description { margin-bottom: 1.5em; padding: 1em; background-color: #e9ecef; border-radius: 4px; }
			.mphb_sc_checkout-form .mphb-checkout-terms-wrapper { margin-top: 2em; text-align: center; }
			#mphb-toss-pay-btn { cursor: pointer; color: white; background-color: #007bff; border-color: #007bff; padding: 0.75em 1.5em; font-size: 1em; border-radius: 0.3rem; transition: background-color 0.15s ease-in-out; }
            #mphb-toss-pay-btn:hover { background-color: #0056b3; border-color: #0056b3; }
            #mphb-toss-pay-btn:disabled { background-color: #6c757d; border-color: #6c757d; cursor: not-allowed; }
			#toss-payment-message { margin-top: 15px; min-height: 22px; font-size: 1em; text-align: center; }
			#toss-payment-message.mphb-error { color: #dc3545; font-weight: bold; }
            #toss-payment-message.mphb-success { color: #28a745; font-weight: bold; }
			.mphb-errors-wrapper { padding: 1em; background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 0.25rem; margin: 1em 0; }
			.mphb-errors-wrapper p { margin-bottom: 0.5em; }
			.mphb-errors-wrapper p:last-child { margin-bottom: 0; }
            .mphb-loading-spinner {
                border: 4px solid #f3f3f3; border-radius: 50%; border-top: 4px solid #007bff;
                width: 20px; height: 20px; animation: spin 1s linear infinite;
                display: inline-block; margin-left: 10px; vertical-align: middle;
            }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
		</style>

		<div class="mphb_sc_checkout-form">
			<div class="mphb-booking-details-section booking">
				<h3 class="mphb-booking-details-title"><?php esc_html_e( 'Booking Details', 'mphb-toss-payments' ); ?></h3>
				<ul class="mphb-booking-details">
					<li class="booking-number">
						<span class="label"><?php esc_html_e( 'Booking Number:', 'mphb-toss-payments' ); ?></span>
						<span class="value"><?php echo esc_html( $booking->getId() ); ?></span>
					</li>
					<li class="booking-check-in">
						<span class="label"><?php esc_html_e( 'Check-in:', 'mphb-toss-payments' ); ?></span>
						<span class="value"><?php echo esc_html( $this->check_in_date_formatted ); ?></span>
					</li>
					<li class="booking-check-out">
						<span class="label"><?php esc_html_e( 'Check-out:', 'mphb-toss-payments' ); ?></span>
						<span class="value"><?php echo esc_html( $this->check_out_date_formatted ); ?></span>
					</li>
					<li class="booking-price">
						<span class="label"><?php esc_html_e( 'Total Amount:', 'mphb-toss-payments' ); ?></span>
						<span class="value"><?php echo mphb_format_price( $payment_entity->getAmount() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</li>
					<li class="booking-status">
						<span class="label"><?php esc_html_e( 'Booking Status:', 'mphb-toss-payments' ); ?></span>
						<span class="value"><?php echo esc_html( mphb_get_status_label( $booking->getStatus() ) ); ?></span>
					</li>
				</ul>
				<?php if ( ! empty( $this->reserved_rooms_details_html ) ) : ?>
					<div class="accommodations">
						<span class="accommodations-title"><?php esc_html_e( 'Accommodation Details:', 'mphb-toss-payments' ); ?></span>
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

				<?php /* No specific billing fields needed for Toss Payments Standard SDK on this page */ ?>

				<div class="mphb-checkout-terms-wrapper">
					<button type="button" id="mphb-toss-pay-btn" class="button mphb-button mphb-confirm-reservation">
						<?php echo esc_html__( 'Proceed to Payment', 'mphb-toss-payments' ); ?>
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
					messageArea.text('<?php echo esc_js( __( 'Failed to load TossPayments JS SDK.', 'mphb-toss-payments' ) ); ?>').addClass('mphb-error');
					payButton.prop('disabled', true).hide();
					return;
				}

				const paymentParamsJS = <?php echo wp_json_encode( $payment_params_for_js ); ?>;
                if (window.console && paymentParamsJS) { console.log('MPHB Toss Checkout JS Params:', paymentParamsJS); }


				if (!paymentParamsJS || !paymentParamsJS.client_key) {
					console.error("MPHB Toss Checkout: Invalid paymentParamsJS or client_key missing.", paymentParamsJS);
					messageArea.text('<?php echo esc_js( __( 'Payment initialization error (JSEP01).', 'mphb-toss-payments' ) ); ?>').addClass('mphb-error');
					payButton.prop('disabled', true).hide();
					return;
				}

				const tossMethodForSDK = paymentParamsJS.toss_method; // This comes from PHP: $selected_gateway_object->getTossMethod()
				if (!tossMethodForSDK) {
					console.error("MPHB Toss Checkout: toss_method missing in JS params.");
					messageArea.text('<?php echo esc_js( __( 'Payment method information missing (JSEP02).', 'mphb-toss-payments' ) ); ?>').addClass('mphb-error');
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
						payButton.prop('disabled', true).find('span:not(.mphb-loading-spinner)').text('<?php echo esc_js( __( 'Processing Payment...', 'mphb-toss-payments' ) ); ?>');
						messageArea.text('').removeClass('mphb-error mphb-success');

						let paymentDataPayload = {
							amount: { currency: "KRW", value: parseFloat(paymentParamsJS.amount) },
							orderId: paymentParamsJS.order_id,
							orderName: paymentParamsJS.order_name,
							successUrl: paymentParamsJS.success_url,
							failUrl: paymentParamsJS.fail_url,
							customerEmail: paymentParamsJS.customer_email,
							customerName: paymentParamsJS.customer_name,
                            customerMobilePhone: paymentParamsJS.customer_mobile_phone, // Ensure this is passed if available
                            // appScheme: 'your_app_scheme_here', // If you integrate with a mobile app for redirection
						};

                        // Apply gateway-specific configurations based on JS flags
                        if (tossMethodForSDK === "CARD") {
							paymentDataPayload.card = {
								useEscrow: false, // Default for standard card
								flowMode: "DEFAULT",
								useCardPoint: paymentParamsJS.js_flags_use_card_point || false, // Example: make configurable
								useAppCardOnly: paymentParamsJS.js_flags_use_app_card_only || false, // Example
                                internationalCardOnly: paymentParamsJS.js_flags_is_foreign_card_only === true
							};
						} else if (tossMethodForSDK === "TRANSFER") {
							paymentDataPayload.transfer = {
								useEscrow: paymentParamsJS.js_flags_is_escrow_transfer === true,
                                // cashReceipt: { type: paymentParamsJS.js_flags_transfer_cash_receipt_type || '미발행' } // if configurable
							};
						} else if (tossMethodForSDK === "VIRTUAL_ACCOUNT") {
							paymentDataPayload.virtualAccount = {
								// validHours: paymentParamsJS.js_flags_vbank_valid_hours || 24, // If passed from PHP
								cashReceipt: {
									type: paymentParamsJS.js_flags_vbank_cash_receipt_type || '미발행'
								},
                                customerMobilePhone: paymentParamsJS.customer_mobile_phone, // Often required for VBank notifications
								useEscrow: false // Default for vbank, unless you have a specific escrow vbank gateway type
							};
						} else if (tossMethodForSDK === "APPLEPAY") {
                            // Apple Pay may not require specific sub-objects in paymentDataPayload.
                            // The SDK handles the Apple Pay sheet.
                            // If any specific options from Toss: paymentDataPayload.applePay = { ... };
                        } else if (tossMethodForSDK === "MOBILE_PHONE") {
                            // paymentDataPayload.mobilePhone = { ... }; // If any specific options
                        }
                        // For other easy pays (KAKAOPAY, NAVERPAY, etc.), specific payload options might not be needed
                        // beyond setting the `method` in `requestPayment`. Check Toss docs if specific options arise.

						if (window.console) { console.log('Requesting Toss Payment with method:', tossMethodForSDK, 'Payload:', paymentDataPayload); }

						paymentWidgetInstance.requestPayment({ method: tossMethodForSDK, ...paymentDataPayload })
							.catch(function(error) {
								console.error("TossPayments SDK Error:", error);
								messageArea.text(error.message || '<?php echo esc_js( __( 'Payment was canceled or an error occurred.', 'mphb-toss-payments' ) ); ?>').addClass('mphb-error');
							})
							.finally(function() {
								isProcessing = false;
                                payButtonSpinner.hide();
								payButton.prop('disabled', false).find('span:not(.mphb-loading-spinner)').text('<?php echo esc_js( __( 'Proceed to Payment', 'mphb-toss-payments' ) ); ?>');
							});
					}
					payButton.prop('disabled', false).on('click', requestTossPayment);

				} catch (sdkError) {
					console.error("TossPayments SDK Init Error:", sdkError);
					messageArea.text('<?php echo esc_js( __( 'TossPayments SDK initialization error (JSEI01).', 'mphb-toss-payments' ) ); ?>').addClass('mphb-error');
					payButton.prop('disabled', true).hide();
				}
			});
		</script>
		<?php
		return ob_get_clean();
	}
} // End of MPHBTossCheckoutView


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
			// 1. 데이터 제공자(DataProvider)를 통해 필요한 데이터 로드 및 검증
			$data_provider = new MPHBTossCheckoutDataProvider( $this->request_params );
			$data_provider->prepare_data(); // 실패 시 Exception 발생

			// 2. 결제 파라미터 빌더(PaymentParamsBuilder)를 통해 토스 SDK용 파라미터 생성
			$params_builder = new MPHBTossPaymentParamsBuilder(
				$data_provider->get_booking(),
				$data_provider->get_payment_entity(),
				$data_provider->get_booking_key(),
				$data_provider->get_booking_id(),
				$data_provider->get_selected_toss_gateway_object() // Pass the gateway object
			);
			$payment_params_for_js = $params_builder->build(); // 실패 시 Exception 발생

			// 3. 뷰 렌더러(ViewRenderer)를 통해 HTML 및 JS 생성
			$view_renderer = new MPHBTossCheckoutView( $data_provider, $payment_params_for_js );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View::render() is responsible for escaping.
			echo $view_renderer->render();

		} catch ( MPHBTossCheckoutException $e ) { // 이 플러그인 내에서 정의된 예외
            if (function_exists('ray')) { ray('MPHBTossCheckoutException in ShortcodeHandler:', $e->getMessage())->label('[ShortcodeHandler]')->red(); }
			$this->render_error_message( $e->getMessage() );
		} catch ( \Exception $e ) { // 그 외 일반적인 예외
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
		$error_html  = '<div class="mphb_sc_checkout-form mphb-errors-wrapper">'; // Apply form styling for consistency
		$error_html .= '<h3>' . esc_html__( 'Payment Error', 'mphb-toss-payments' ) . '</h3>';
		$error_html .= '<p class="mphb-error">' . esc_html( $message ) . '</p>';
		$error_html .= '<p><a href="' . esc_url( home_url( '/' ) ) . '" class="button mphb-button">' . esc_html__( 'Return to Homepage', 'mphb-toss-payments' ) . '</a></p>';
		 // Optionally, add a link to retry payment or go to booking page if available
        if (isset($this->request_params['booking_id'], $this->request_params['booking_key'])) {
            $booking_id = absint($this->request_params['booking_id']);
            $booking = MPHB()->getBookingRepository()->findById($booking_id);
            if ($booking && $booking->getCheckoutUrl()) {
                 $error_html .= '<p><a href="' . esc_url( $booking->getCheckoutUrl() ) . '" class="button mphb-button">' . esc_html__( 'Try Again', 'mphb-toss-payments' ) . '</a></p>';
            }
        }
		$error_html .= '</div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $error_html is safe.
		echo $error_html;
	}
} // End of MPHBTossCheckoutShortcodeHandler

/**
 * MPHB Toss Payments 체크아웃 숏코드 콜백 함수.
 * 실제 로직은 MPHBTossCheckoutShortcodeHandler 클래스에 위임합니다.
 *
 * @since x.x.x
 * @return string 렌더링된 체크아웃 페이지 HTML 또는 오류 메시지.
 */
function mphb_toss_checkout_shortcode_callback(): string {
	// 핸들러 클래스의 인스턴스를 생성하고, render 메소드를 호출합니다.
	// $_GET 파라미터를 핸들러에 전달합니다.
	$handler = new MPHBTossCheckoutShortcodeHandler( $_GET );
	return $handler->render();
}

// 기존 숏코드명과 콜백 함수를 연결합니다.
add_shortcode( 'mphb_toss_checkout', 'mphb_toss_checkout_shortcode_callback' );

