<?php
namespace MPHBTOSS;

use WP_Error;
use MPHBTOSS\TossException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 토스페이먼츠 API 통신 서비스
 */
class TossAPI {

    const API_BASE_URL = 'https://api.tosspayments.com/v1/';

    private string $secretKey;
    private bool $isDebug;

    /**
     * 생성자
     *
     * @param string $secretKey 토스페이먼츠 시크릿 키
     * @param bool $isDebug 디버그 모드 여부
     */
    public function __construct(string $secretKey, bool $isDebug = false) {
        $this->secretKey = $secretKey;
        $this->isDebug = $isDebug;
    }

    /**
     * 토스페이먼츠 API 요청 전송
     *
     * @param string $endpoint API 엔드포인트 (예: 'payments/confirm')
     * @param array $body 요청 바디 데이터
     * @param string $method HTTP 메서드 (POST, GET 등)
     * @return object|null API 응답 객체 또는 실패 시 null
     * @throws TossException API 통신 오류 또는 JSON이 아닌 응답 시
     */
    private function request(string $endpoint, array $body = [], string $method = 'POST'): ?object {
        $url = self::API_BASE_URL . $endpoint;
        $credentials = base64_encode($this->secretKey . ':');
        $headers = [
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/json',
        ];

        // Add Idempotency-Key for POST requests to confirm/cancel endpoints
        if (strtoupper($method) === 'POST' && (strpos($endpoint, 'confirm') !== false || strpos($endpoint, 'cancel') !== false)) {
            // Using uniqid for simplicity. A more robust key generation might involve payment/order IDs.
            $headers['Idempotency-Key'] = uniqid('mphb-toss-', true);
        }

        $args = [
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => 60,
            'sslverify' => true, // 프로덕션에서는 true여야 함
            'body'      => ($method === 'POST' || $method === 'PUT') ? wp_json_encode($body) : null,
        ];

        if ($this->isDebug && function_exists('MPHB')) {
            // MPHB 로그에도 기록 (Ray와 별개)
            MPHB()->log()->debug(sprintf('[%s] API Request URL: %s', __CLASS__, $url));
            MPHB()->log()->debug(sprintf('[%s] API Request Args: %s', __CLASS__, print_r(array_merge($args, ['headers' => ['Authorization' => 'Basic ***']]), true))); // 로그에서 키 마스킹
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();

            if ($this->isDebug && function_exists('MPHB')) {
                MPHB()->log()->error(sprintf('[%s] API WP_Error: %s', __CLASS__, $error_message));
            }

            throw new TossException("API Request Failed: " . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($this->isDebug && function_exists('MPHB')) {
            MPHB()->log()->debug(sprintf('[%s] API Response Code: %s', __CLASS__, $response_code));
            MPHB()->log()->debug(sprintf('[%s] API Response Body: %s', __CLASS__, $response_body));
        }

        $decoded = json_decode($response_body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();

            if ($this->isDebug && function_exists('MPHB')) {
                MPHB()->log()->error(sprintf('[%s] API JSON Decode Error: %s', __CLASS__, $json_error));
                MPHB()->log()->error(sprintf('[%s] API Raw Response: %s', __CLASS__, $response_body));
            }

            throw new TossException("Failed to decode API response.");
        }


        // 응답 본문에서 토스페이먼츠 특정 오류 구조 확인 및 예외 발생
        if ($response_code >= 400 || isset($decoded->code)) {
            $error_code = $decoded->code ?? 'UNKNOWN_ERROR';
            $error_message = $decoded->message ?? 'An unknown API error occurred.';

            if ($this->isDebug && function_exists('MPHB')) {
                MPHB()->log()->error(sprintf('[%s] API Error Response: Code=%s, Message=%s', __CLASS__, $error_code, $error_message));
            }

            // Throw an exception for consistent error handling
            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code);
        }

        // Only return decoded object on success (HTTP 2xx)
        return $decoded;
    }

    /**
     * 결제 승인
     *
     * @param string $paymentKey 토스 프론트엔드에서 받은 결제 키
     * @param string $tossOrderId 토스에 전송된 고유 주문 ID
     * @param float $amount 승인할 금액
     * @return object|null 승인 응답 객체
     * @throws TossException
     */
    public function confirmPayment(string $paymentKey, string $tossOrderId, float $amount): ?object {
        $endpoint = 'payments/confirm';
        $body = [
            'paymentKey' => $paymentKey,
            'orderId'    => $tossOrderId,
            'amount'     => round($amount), // KRW의 경우 정수 보장
        ];

        return $this->request($endpoint, $body, 'POST');
    }

    /**
     * 결제 취소 (전체 또는 부분)
     *
     * @param string $paymentKey 원래 결제 키
     * @param string $reason 취소 사유
     * @param float|null $amount 취소 금액 (null은 전체 취소)
     * @return object|null 취소 응답 객체
     * @throws TossException
     */
    public function cancelPayment(string $paymentKey, string $reason, ?float $amount = null): ?object {
        $endpoint = "payments/{$paymentKey}/cancel";
        $body = [
            'cancelReason' => mb_substr($reason, 0, 200), // 사유는 최대 200자
        ];

        if ($amount !== null && $amount > 0) {
            $body['cancelAmount'] = round($amount); // 부분 취소
        }
        // amount가 null이거나 0이면 토스 API는 암시적으로 전체 취소로 처리

        return $this->request($endpoint, $body, 'POST');
    }

    /**
     * 현재 시크릿 키 가져오기
     *
     * @return string 시크릿 키
     */
    public function getSecretKey(): string {
        return $this->secretKey;
    }

    /**
     * 디버그 모드 설정
     *
     * @param bool $isDebug 디버그 모드 여부
     */
    public function setDebugMode(bool $isDebug): void {
        $this->isDebug = $isDebug;
    }
}
