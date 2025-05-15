<?php

/**
 * 고객 키 문자열을 정규화하고 길이 제한을 적용합니다.
 * 허용되는 문자: 영문 대소문자, 숫자, 하이픈(-), 언더스코어(_), 등호(=), 마침표(.), 골뱅이(@)
 * 길이는 최대 50자로 제한되며, 2자 미만일 경우 '0'으로 채워 2자로 만듭니다.
 *
 * @param string|null $raw 원시 고객 키 문자열입니다.
 * @return string 정규화된 고객 키입니다.
 */
function mphbTossSanitizeCustomerKey($raw) {
    // 허용되지 않는 문자를 제거합니다. $raw가 null일 경우 빈 문자열로 처리합니다.
    $key = preg_replace('/[^a-zA-Z0-9\-\_\=\.\@]/', '', $raw ?: '');
    // 최대 50자로 자릅니다.
    $key = substr($key, 0, 50);
    // 길이가 2자 미만이면 오른쪽에 '0'을 채워 2자로 만듭니다.
    if (strlen($key) < 2) $key = str_pad($key, 2, '0');
    return $key;
}

// 'mphb_toss_write_log' 함수가 이미 정의되지 않았을 경우에만 정의합니다.
if (!function_exists('mphb_toss_write_log')) {
    /**
     * 디버깅이 활성화된 경우 토스페이먼츠 로그 파일에 메시지를 기록합니다.
     *
     * @param mixed $log 기록할 데이터입니다. 문자열, 배열, 객체가 될 수 있습니다.
     * @param string $context 선택 사항. 로그 항목의 컨텍스트입니다 (예: 함수명, 클래스명).
     */
    function mphb_toss_write_log($log, string $context = '') {
        $is_plugin_debug_mode = false;
        // 플러그인 자체 디버그 모드가 활성화되어 있는지 확인합니다.
        if (class_exists('\MPHBTOSS\TossGlobalSettingsTab') && method_exists('\MPHBTOSS\TossGlobalSettingsTab', 'is_debug_mode')) {
            $is_plugin_debug_mode = \MPHBTOSS\TossGlobalSettingsTab::is_debug_mode();
        }

        // 플러그인 디버그 모드가 꺼져 있고, 워드프레스 WP_DEBUG 상수도 정의되지 않았거나 false이면 로그를 기록하지 않습니다.
        if (!$is_plugin_debug_mode && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }

        // 워드프레스 업로드 디렉토리 정보를 가져옵니다.
        $upload_dir = wp_get_upload_dir();
        // 로그 파일 경로를 설정합니다. (예: /wp-content/uploads/toss_payment.log)
        $log_file_path = $upload_dir['basedir'] . '/toss_payment.log';

        // 현재 시간을 워드프레스 지역화된 시간으로 가져옵니다 (MySQL 형식).
        $timestamp = current_time('mysql');

        // 로그 메시지 헤더를 구성합니다.
        $message_header = "[{$timestamp}]";
        if (!empty($context)) {
            $message_header .= " [{$context}]";
        }
        $message_header .= ": ";

        $log_entry = "";
        // 로그 데이터가 배열이거나 객체인 경우
        if (is_array($log) || is_object($log)) {
            // 민감한 데이터를 마스킹 처리합니다.
            $sanitized_log = mphb_toss_sanitize_log_data($log);
            // print_r을 사용하여 사람이 읽기 좋은 형태로 변환합니다.
            $log_entry = print_r($sanitized_log, true);
        } else {
            // 문자열인 경우 그대로 사용합니다.
            $log_entry = $log;
        }

        // 최종 로그 메시지를 만듭니다 (헤더 + 내용 + 줄바꿈).
        $full_message = $message_header . $log_entry . "\n";

        // 로그 파일이 저장될 디렉토리를 가져옵니다.
        $log_dir = dirname($log_file_path);
        // 디렉토리가 존재하지 않으면 생성합니다.
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir); // 워드프레스 방식으로 디렉토리 생성 (재귀적으로)
        }
        
        // 로그 파일이 존재하지만 쓰기 권한이 없는 경우, 권한을 변경하려고 시도합니다.
        if (file_exists($log_file_path) && !is_writable($log_file_path)) {
            @chmod($log_file_path, 0664); // 파일 권한 변경 (오류 발생 시 무시)
        } elseif (!file_exists($log_file_path)) { // 로그 파일이 존재하지 않는 경우
            if (is_writable($log_dir)) { // 디렉토리에 쓰기 권한이 있는 경우
                // file_put_contents 함수가 파일을 생성할 것입니다.
            } else {
                 @chmod($log_dir, 0775); // 디렉토리 권한을 변경하여 쓰기 가능하게 하려고 시도합니다.
            }
        }

        // 파일에 로그를 추가합니다. FILE_APPEND는 이어쓰기, LOCK_EX는 파일 잠금을 의미합니다.
        if (file_put_contents($log_file_path, $full_message, FILE_APPEND | LOCK_EX) === false) {
            // 파일 쓰기에 실패하면 PHP 오류 로그에 기록합니다.
            error_log("Failed to write to Toss Payments log file: {$log_file_path}. Message: {$full_message}");
        }
    }
}

// 'mphb_toss_sanitize_log_data' 함수가 이미 정의되지 않았을 경우에만 정의합니다.
if (!function_exists('mphb_toss_sanitize_log_data')) {
    /**
     * 로그 배열/객체에서 잠재적으로 민감한 데이터를 살균(마스킹)합니다.
     * 'key', 'secret', 'token', 'password'를 포함하는 키의 문자열 값은 부분적으로 마스킹됩니다.
     *
     * @param mixed $data 살균할 데이터입니다.
     * @return mixed 살균된 데이터입니다.
     */
    function mphb_toss_sanitize_log_data($data) {
        if (is_array($data)) { // 데이터가 배열인 경우
            $sanitized_array = [];
            foreach ($data as $key => $value) {
                // 키가 문자열이고 특정 민감한 단어를 포함하는 경우
                if (is_string($key) && (stripos($key, 'key') !== false || stripos($key, 'secret') !== false || stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
                    if (is_string($value) && !empty($value)) { // 값이 비어있지 않은 문자열인 경우
                        // 길이가 8자 초과면 앞 4자 **** 뒤 4자, 아니면 '********'로 마스킹
                        $sanitized_array[$key] = strlen($value) > 8 ? substr($value, 0, 4) . '****' . substr($value, -4) : '********';
                    } else {
                        $sanitized_array[$key] = $value; // 문자열이 아니거나 비어있으면 그대로 유지
                    }
                } elseif (is_array($value) || is_object($value)) { // 값이 배열이거나 객체인 경우
                    $sanitized_array[$key] = mphb_toss_sanitize_log_data($value); // 재귀 호출로 내부 데이터도 살균
                } else {
                    $sanitized_array[$key] = $value; // 그 외의 경우 그대로 유지
                }
            }
            return $sanitized_array;
        } elseif (is_object($data)) { // 데이터가 객체인 경우
            // 원본 객체를 수정하지 않기 위해 복제합니다.
            $cloned_data = clone $data;
            $vars = get_object_vars($cloned_data); // 객체의 속성을 배열로 가져옵니다.
            foreach ($vars as $key => $value) {
                 // 키가 문자열이고 특정 민감한 단어를 포함하는 경우
                 if (is_string($key) && (stripos($key, 'key') !== false || stripos($key, 'secret') !== false || stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
                    if (is_string($value) && !empty($value)) { // 값이 비어있지 않은 문자열인 경우
                        $cloned_data->{$key} = strlen($value) > 8 ? substr($value, 0, 4) . '****' . substr($value, -4) : '********';
                    }
                } elseif (is_array($value) || is_object($value)) { // 값이 배열이거나 객체인 경우
                    $cloned_data->{$key} = mphb_toss_sanitize_log_data($value); // 재귀 호출
                }
            }
            return $cloned_data;
        }
        return $data; // 배열이나 객체가 아니면 그대로 반환
    }
}

