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
    public function __construct(string $secretKey, bool $isDebug = true) {
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
            $headers['Idempotency-Key'] = uniqid('mphb-toss-', true);
        }

        $args = [
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => 60,
            'sslverify' => true,
            'body'      => ($method === 'POST' || $method === 'PUT') ? wp_json_encode($body) : null,
        ];

        if ($this->isDebug) {
            function_exists('ray') && ray('[TossAPI::request] API Request URL', $url);
            function_exists('ray') && ray('[TossAPI::request] API Request Args', array_merge(
                $args,
                ['headers' => ['Authorization' => 'Basic ***']])
            );
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();

            if ($this->isDebug) {
                function_exists('ray') && ray('[TossAPI::request] API WP_Error', $error_message);
            }

            throw new TossException("API Request Failed: " . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($this->isDebug) {
            function_exists('ray') && ray('[TossAPI::request] API Response Code', $response_code);
            function_exists('ray') && ray('[TossAPI::request] API Response Body', $response_body);
        }

        $decoded = json_decode($response_body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();

            if ($this->isDebug) {
                function_exists('ray') && ray('[TossAPI::request] API JSON Decode Error', $json_error);
                function_exists('ray') && ray('[TossAPI::request] API Raw Response', $response_body);
            }

            throw new TossException("Failed to decode API response.");
        }

        if ($response_code >= 400 || isset($decoded->code)) {
            $error_code = $decoded->code ?? 'UNKNOWN_ERROR';
            $error_message = $decoded->message ?? 'An unknown API error occurred.';

            if ($this->isDebug) {
                function_exists('ray') && ray('[TossAPI::request] API Error Response', [
                    'Code'    => $error_code,
                    'Message' => $error_message
                ]);
            }

            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code);
        }

        return $decoded;
    }

    public function confirmPayment(string $paymentKey, string $tossOrderId, float $amount): ?object {
        $endpoint = 'payments/confirm';
        $body = [
            'paymentKey' => $paymentKey,
            'orderId'    => $tossOrderId,
            'amount'     => round($amount),
        ];

        return $this->request($endpoint, $body, 'POST');
    }

    public function cancelPayment(string $paymentKey, string $reason, ?float $amount = null): ?object {
        $endpoint = "payments/{$paymentKey}/cancel";
        $body = [
            'cancelReason' => mb_substr($reason, 0, 200),
        ];

        if ($amount !== null && $amount > 0) {
            $body['cancelAmount'] = round($amount);
        }

        return $this->request($endpoint, $body, 'POST');
    }

    public function getSecretKey(): string {
        return $this->secretKey;
    }

    public function setDebugMode(bool $isDebug): void {
        $this->isDebug = $isDebug;
    }
}
