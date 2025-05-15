<?php
namespace MPHBTOSS; // 네임스페이스 선언

use WP_Error; // 워드프레스 오류 객체 (여기서는 직접 사용되지 않지만, wp_remote_request 반환 타입으로 가능)
use MPHBTOSS\TossException; // 이 플러그인의 토스 예외 클래스 사용

// 워드프레스 환경 외부에서 직접 접근하는 것을 방지
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 API와 통신하는 클래스입니다.
 */
class TossAPI {

    // 토스페이먼츠 API 기본 URL 상수
    const API_BASE_URL = 'https://api.tosspayments.com/v1/';

    // API 시크릿 키를 저장할 private 속성
    private string $secretKey;
    // 디버그 모드 여부를 저장할 private 속성 (이 인스턴스에 대한)
    private bool $isDebug; 

    /**
     * TossAPI 생성자입니다.
     * @param string $secretKey 토스페이먼츠 시크릿 키
     * @param bool $isDebug 디버그 모드 활성화 여부 (기본값: false)
     */
    public function __construct(string $secretKey, bool $isDebug = false) {
        $this->secretKey = $secretKey;
        $this->isDebug = $isDebug;
        // mphb_toss_write_log("TossAPI constructed. Debug mode for this instance: " . ($this->isDebug ? 'Enabled' : 'Disabled'), __CLASS__ . '::__construct'); // 로그 상세도 줄임
    }

    /**
     * 토스페이먼츠 API에 요청을 보냅니다.
     * @param string $endpoint API 엔드포인트 (예: 'payments/confirm')
     * @param array $body 요청 본문 데이터 배열 (POST, PUT 요청 시)
     * @param string $method HTTP 메소드 (기본값: 'POST')
     * @return object|null API 응답 객체 또는 실패 시 null (TossException 발생으로 실제 null 반환은 드묾)
     * @throws TossException API 통신 오류 또는 토스 API 자체 오류 발생 시
     */
    private function request(string $endpoint, array $body = [], string $method = 'POST'): ?object {
        $log_context = __CLASS__ . '::request'; // 로그 컨텍스트
        // 전체 API URL 구성
        $url = self::API_BASE_URL . $endpoint;
        // 시크릿 키를 사용하여 Basic 인증 헤더 값 생성 (시크릿키 + ':')
        $credentials = base64_encode($this->secretKey . ':');
        // 요청 헤더 배열
        $headers = [
            'Authorization' => "Basic {$credentials}", // 인증 헤더
            'Content-Type'  => 'application/json',      // 컨텐츠 타입 JSON
        ];

        // POST 메소드이고, 엔드포인트가 'confirm' 또는 'cancel'을 포함하는 경우 (멱등성 키 필요)
        if (strtoupper($method) === 'POST' && (strpos($endpoint, 'confirm') !== false || strpos($endpoint, 'cancel') !== false)) {
            $idempotencyKey = uniqid('mphb-toss-', true); // 고유한 멱등성 키 생성
            $headers['Idempotency-Key'] = $idempotencyKey; // 멱등성 키 헤더 추가
            // mphb_toss_write_log("Generated Idempotency-Key: " . $idempotencyKey, $log_context); // 로그 상세도 줄임
        }

        // wp_remote_request 함수에 전달할 인수 배열
        $args = [
            'method'    => strtoupper($method), // HTTP 메소드 (대문자)
            'headers'   => $headers,             // 요청 헤더
            'timeout'   => 60,                  // 타임아웃 시간 (초)
            'sslverify' => true,                // SSL 인증서 검증 사용 (보안상 중요)
            // POST 또는 PUT 요청일 경우 본문 데이터를 JSON 문자열로 인코딩하여 전달
            'body'      => ($method === 'POST' || $method === 'PUT') ? wp_json_encode($body) : null,
        ];
        
        // 전역 디버그 모드 (플러그인 설정 또는 WP_DEBUG) 활성화 여부 확인
        $global_debug_enabled = (class_exists('\MPHBTOSS\TossGlobalSettingsTab') && TossGlobalSettingsTab::is_debug_mode()) || (defined('WP_DEBUG') && WP_DEBUG);

        // 전역 디버그 모드가 활성화된 경우 요청 정보 로그 기록
        if ($global_debug_enabled) {
            $log_args_display = $args;
            // Authorization 헤더는 mphb_toss_sanitize_log_data 함수가 배열로 전달 시 이미 처리함
            if(isset($log_args_display['body'])) {
                // 본문 데이터 디코딩 후 민감 정보 살균하여 로그용으로 준비
                $decoded_body_for_log = json_decode($log_args_display['body'], true);
                $log_args_display['body'] = mphb_toss_sanitize_log_data($decoded_body_for_log);
            }
            mphb_toss_write_log("API Request. URL: {$url}, Method: {$method}, Args (sanitized): " . print_r($log_args_display, true), $log_context);
        }
        
        // 이 인스턴스의 isDebug가 true일 경우 (Ray 디버깅 도구 등에 사용될 수 있음)
        if ($this->isDebug) {
            $ray_args = $args;
            if(isset($ray_args['headers']['Authorization'])) $ray_args['headers']['Authorization'] = 'Basic ***'; // Authorization 헤더 마스킹
            if(isset($ray_args['body'])) $ray_args['body'] = mphb_toss_sanitize_log_data(json_decode($ray_args['body'] ?? '', true)); // 본문 살균
        }

        // 워드프레스 HTTP API를 사용하여 요청 전송
        $response = wp_remote_request($url, $args);

        // 워드프레스 자체 오류 발생 시 (네트워크 문제 등)
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            mphb_toss_write_log("API WP_Error: " . $error_message, $log_context . '_Error');
            throw new TossException("API Request Failed: " . $error_message); // 예외 발생
        }

        // 응답 코드 및 본문 가져오기
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        // 로그용으로 응답 본문 디코딩
        $decoded_response_for_log = json_decode($response_body, true);


        // 전역 디버그 모드가 활성화된 경우 응답 정보 로그 기록
        if ($global_debug_enabled) {
            // 응답 본문이 너무 길면 잘라서 로그 기록
            $log_response_body_display = mb_strlen($response_body) > 1000 ? mb_substr($response_body, 0, 1000) . '... [TRUNCATED]' : $response_body;
            // 잘린 본문을 디코딩 시도 (JSON이 깨질 수 있음)
            $decoded_for_log_display = json_decode($log_response_body_display, true); 
            // 잘린 본문 디코딩 실패 시, 전체 디코딩된 응답 사용 (민감 정보는 살균됨)
            if (json_last_error() !== JSON_ERROR_NONE && $decoded_response_for_log !== null) {
                 $decoded_for_log_display = $decoded_response_for_log;
            }
            mphb_toss_write_log("API Response. Code: {$response_code}, Body (sanitized, possibly truncated): " . print_r(mphb_toss_sanitize_log_data($decoded_for_log_display), true), $log_context);
        }

        // 실제 처리를 위해 전체 응답 본문 디코딩
        $decoded = json_decode($response_body);

        // JSON 디코딩 오류 발생 시
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            mphb_toss_write_log("API JSON Decode Error: {$json_error}. Raw Response (first 500 chars): " . substr($response_body, 0, 500), $log_context . '_Error');
            throw new TossException("Failed to decode API response. Error: {$json_error}"); // 예외 발생
        }

        // 응답 객체에 'code' 필드가 있는 경우 (토스 API 자체 오류)
        if (isset($decoded->code)) { 
            $error_code = $decoded->code;
            $error_message = $decoded->message ?? 'An unknown API error occurred.';
            mphb_toss_write_log("API Error Response from Toss. Code: {$error_code}, Message: {$error_message}", $log_context . '_Error');
            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code); // 예외 발생
        }
        
        // HTTP 응답 코드가 400 이상인 경우 (일반적인 HTTP 오류)
        if ($response_code >= 400) {
            $error_code = 'HTTP_' . $response_code;
            $error_message = $decoded->message ?? 'HTTP error with no specific Toss error code.';
            mphb_toss_write_log("API HTTP Error. Code: {$error_code}, Message: {$error_message}, Full Decoded: ". print_r($decoded, true), $log_context . '_Error');
            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code); // 예외 발생
        }

        // 성공적인 응답 객체 반환
        return $decoded;
    }

    /**
     * 토스페이먼츠 결제를 승인합니다.
     * @param string $paymentKey 토스 결제 키
     * @param string $tossOrderId 토스 주문 ID
     * @param float $amount 결제 금액
     * @return object|null API 응답 객체
     * @throws TossException
     */
    public function confirmPayment(string $paymentKey, string $tossOrderId, float $amount): ?object {
        $log_context = __CLASS__ . '::confirmPayment';
        mphb_toss_write_log("Confirming payment. PaymentKey: {$paymentKey}, OrderID: {$tossOrderId}, Amount: {$amount}", $log_context);
        $endpoint = 'payments/confirm'; // 결제 승인 엔드포인트
        $body = [
            'paymentKey' => $paymentKey,    // 결제 키
            'orderId'    => $tossOrderId,   // 주문 ID
            'amount'     => round($amount),  // 금액 (정수로 반올림)
        ];
        return $this->request($endpoint, $body, 'POST'); // API 요청
    }

    /**
     * 토스페이먼츠 결제를 취소(환불)합니다.
     * @param string $paymentKey 토스 결제 키
     * @param string $reason 취소 사유
     * @param float|null $amount 취소(환불)할 금액 (null이면 전체 환불)
     * @return object|null API 응답 객체
     * @throws TossException
     */
    public function cancelPayment(string $paymentKey, string $reason, ?float $amount = null): ?object {
        $log_context = __CLASS__ . '::cancelPayment';
        $log_message = "Canceling payment. PaymentKey: {$paymentKey}, Reason: {$reason}";
        if ($amount !== null) $log_message .= ", Amount: {$amount}";
        mphb_toss_write_log($log_message, $log_context);
        
        // 결제 취소 엔드포인트 (결제 키 포함)
        $endpoint = "payments/{$paymentKey}/cancel";
        $body = [
            // 취소 사유 (최대 200자)
            'cancelReason' => mb_substr($reason, 0, 200),
        ];

        // 부분 환불 금액이 지정된 경우
        if ($amount !== null && $amount > 0) {
            $body['cancelAmount'] = round($amount); // 환불 금액 (정수로 반올림)
        }
        return $this->request($endpoint, $body, 'POST'); // API 요청
    }
    
    /**
     * 현재 인스턴스의 시크릿 키를 반환합니다.
     * @return string 시크릿 키
     */
    public function getSecretKey(): string {
        return $this->secretKey;
    }

    /**
     * 이 API 인스턴스의 디버그 모드를 설정합니다.
     * @param bool $isDebug 디버그 모드 활성화 여부
     */
    public function setDebugMode(bool $isDebug): void {
        $this->isDebug = $isDebug;
    }
}

