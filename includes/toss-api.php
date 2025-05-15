<?php
namespace MPHBTOSS;

use WP_Error;
use MPHBTOSS\TossException;

if (!defined('ABSPATH')) {
    exit;
}

class TossAPI {

    const API_BASE_URL = 'https://api.tosspayments.com/v1/';

    private string $secretKey;
    private bool $isDebug; 

    public function __construct(string $secretKey, bool $isDebug = false) {
        $this->secretKey = $secretKey;
        $this->isDebug = $isDebug;
        // mphb_toss_write_log("TossAPI constructed. Debug mode for this instance: " . ($this->isDebug ? 'Enabled' : 'Disabled'), __CLASS__ . '::__construct'); // Reduced verbosity
    }

    private function request(string $endpoint, array $body = [], string $method = 'POST'): ?object {
        $log_context = __CLASS__ . '::request';
        $url = self::API_BASE_URL . $endpoint;
        $credentials = base64_encode($this->secretKey . ':'); // Secret key is part of credentials
        $headers = [
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/json',
        ];

        if (strtoupper($method) === 'POST' && (strpos($endpoint, 'confirm') !== false || strpos($endpoint, 'cancel') !== false)) {
            $idempotencyKey = uniqid('mphb-toss-', true);
            $headers['Idempotency-Key'] = $idempotencyKey;
            // mphb_toss_write_log("Generated Idempotency-Key: " . $idempotencyKey, $log_context); // Reduced verbosity
        }

        $args = [
            'method'    => strtoupper($method),
            'headers'   => $headers,
            'timeout'   => 60,
            'sslverify' => true, 
            'body'      => ($method === 'POST' || $method === 'PUT') ? wp_json_encode($body) : null,
        ];
        
        $global_debug_enabled = (class_exists('\MPHBTOSS\TossGlobalSettingsTab') && TossGlobalSettingsTab::is_debug_mode()) || (defined('WP_DEBUG') && WP_DEBUG);

        if ($global_debug_enabled) {
            $log_args_display = $args;
            // Authorization header already sanitized by mphb_toss_sanitize_log_data if passed as array
            if(isset($log_args_display['body'])) {
                $decoded_body_for_log = json_decode($log_args_display['body'], true);
                $log_args_display['body'] = mphb_toss_sanitize_log_data($decoded_body_for_log);
            }
            mphb_toss_write_log("API Request. URL: {$url}, Method: {$method}, Args (sanitized): " . print_r($log_args_display, true), $log_context);
        }
        
        if ($this->isDebug) {
            function_exists('ray') && ray('[TossAPI::request] API Request URL', $url);
            $ray_args = $args;
            if(isset($ray_args['headers']['Authorization'])) $ray_args['headers']['Authorization'] = 'Basic ***';
            if(isset($ray_args['body'])) $ray_args['body'] = mphb_toss_sanitize_log_data(json_decode($ray_args['body'] ?? '', true));
            function_exists('ray') && ray('[TossAPI::request] API Request Args (sanitized for ray)', $ray_args);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            mphb_toss_write_log("API WP_Error: " . $error_message, $log_context . '_Error');
            if (function_exists('ray')) { ray('[TossAPI::request] API WP_Error', $error_message); }
            throw new TossException("API Request Failed: " . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_response_for_log = json_decode($response_body, true);


        if ($global_debug_enabled) {
            $log_response_body_display = mb_strlen($response_body) > 1000 ? mb_substr($response_body, 0, 1000) . '... [TRUNCATED]' : $response_body;
            $decoded_for_log_display = json_decode($log_response_body_display, true); // Try to decode truncated for logging
            if (json_last_error() !== JSON_ERROR_NONE && $decoded_response_for_log !== null) { // If truncated is invalid json, use full decoded
                 $decoded_for_log_display = $decoded_response_for_log;
            }

            mphb_toss_write_log("API Response. Code: {$response_code}, Body (sanitized, possibly truncated): " . print_r(mphb_toss_sanitize_log_data($decoded_for_log_display), true), $log_context);
        }
        if (function_exists('ray')) {
            ray('[TossAPI::request] API Response Code', $response_code);
            ray('[TossAPI::request] API Response Body (sanitized for ray)', mphb_toss_sanitize_log_data($decoded_response_for_log));
        }

        $decoded = json_decode($response_body); // Use the full body for actual processing

        if (json_last_error() !== JSON_ERROR_NONE) {
            $json_error = json_last_error_msg();
            mphb_toss_write_log("API JSON Decode Error: {$json_error}. Raw Response (first 500 chars): " . substr($response_body, 0, 500), $log_context . '_Error');
            if (function_exists('ray')) {
                ray('[TossAPI::request] API JSON Decode Error', $json_error);
                ray('[TossAPI::request] API Raw Response', $response_body);
            }
            throw new TossException("Failed to decode API response. Error: {$json_error}");
        }

        if (isset($decoded->code)) { 
            $error_code = $decoded->code;
            $error_message = $decoded->message ?? 'An unknown API error occurred.';
            mphb_toss_write_log("API Error Response from Toss. Code: {$error_code}, Message: {$error_message}", $log_context . '_Error');
            if (function_exists('ray')) { ray('[TossAPI::request] API Error Response (from decoded)', ['Code' => $error_code, 'Message' => $error_message]); }
            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code);
        }
        
        if ($response_code >= 400) {
            $error_code = 'HTTP_' . $response_code;
            $error_message = $decoded->message ?? 'HTTP error with no specific Toss error code.';
            mphb_toss_write_log("API HTTP Error. Code: {$error_code}, Message: {$error_message}, Full Decoded: ". print_r($decoded, true), $log_context . '_Error');
            if (function_exists('ray')) { ray('[TossAPI::request] API HTTP Error Response', ['Code' => $error_code, 'Message' => $error_message, 'FullDecoded' => $decoded]); }
            throw new TossException("API Error [{$error_code}]: {$error_message}", $error_code);
        }

        return $decoded;
    }

    public function confirmPayment(string $paymentKey, string $tossOrderId, float $amount): ?object {
        $log_context = __CLASS__ . '::confirmPayment';
        mphb_toss_write_log("Confirming payment. PaymentKey: {$paymentKey}, OrderID: {$tossOrderId}, Amount: {$amount}", $log_context);
        $endpoint = 'payments/confirm';
        $body = [
            'paymentKey' => $paymentKey,
            'orderId'    => $tossOrderId,
            'amount'     => round($amount),
        ];
        return $this->request($endpoint, $body, 'POST');
    }

    public function cancelPayment(string $paymentKey, string $reason, ?float $amount = null): ?object {
        $log_context = __CLASS__ . '::cancelPayment';
        $log_message = "Canceling payment. PaymentKey: {$paymentKey}, Reason: {$reason}";
        if ($amount !== null) $log_message .= ", Amount: {$amount}";
        mphb_toss_write_log($log_message, $log_context);
        
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
