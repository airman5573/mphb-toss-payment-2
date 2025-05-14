<?php

function mphbTossSanitizeCustomerKey($raw) {
    $key = preg_replace('/[^a-zA-Z0-9\-\_\=\.\@]/', '', $raw ?: '');
    $key = substr($key, 0, 50);
    if (strlen($key) < 2) $key = str_pad($key, 2, '0');
    return $key;
}

if (!function_exists('mphb_toss_write_log')) {
    /**
     * Writes a message to the Toss Payments log file if debugging is enabled.
     *
     * @param mixed $log The data to log. Can be a string, array, or object.
     * @param string $context Optional context for the log entry (e.g., function name, class name).
     */
    function mphb_toss_write_log($log, string $context = '') {
        $is_plugin_debug_mode = false;
        if (class_exists('\MPHBTOSS\TossGlobalSettingsTab') && method_exists('\MPHBTOSS\TossGlobalSettingsTab', 'is_debug_mode')) {
            $is_plugin_debug_mode = \MPHBTOSS\TossGlobalSettingsTab::is_debug_mode();
        }

        if (!$is_plugin_debug_mode && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }

        $upload_dir = wp_get_upload_dir();
        $log_file_path = $upload_dir['basedir'] . '/toss_payment.log';

        $timestamp = current_time('mysql'); // WordPress's localized time

        $message_header = "[{$timestamp}]";
        if (!empty($context)) {
            $message_header .= " [{$context}]";
        }
        $message_header .= ": ";

        $log_entry = "";
        if (is_array($log) || is_object($log)) {
            // Sanitize sensitive data before logging.
            $sanitized_log = mphb_toss_sanitize_log_data($log);
            $log_entry = print_r($sanitized_log, true);
        } else {
            $log_entry = $log;
        }

        $full_message = $message_header . $log_entry . "\n";

        $log_dir = dirname($log_file_path);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        if (file_exists($log_file_path) && !is_writable($log_file_path)) {
            @chmod($log_file_path, 0664);
        } elseif (!file_exists($log_file_path)) {
            if (is_writable($log_dir)) {
                // File will be created by file_put_contents
            } else {
                 @chmod($log_dir, 0775); // Try to make dir writable
            }
        }

        if (file_put_contents($log_file_path, $full_message, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to Toss Payments log file: {$log_file_path}. Message: {$full_message}");
        }
    }
}

if (!function_exists('mphb_toss_sanitize_log_data')) {
    /**
     * Sanitizes potentially sensitive data from log arrays/objects.
     * Keys containing 'key', 'secret', 'token' will have their string values partially masked.
     *
     * @param mixed $data The data to sanitize.
     * @return mixed Sanitized data.
     */
    function mphb_toss_sanitize_log_data($data) {
        if (is_array($data)) {
            $sanitized_array = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && (stripos($key, 'key') !== false || stripos($key, 'secret') !== false || stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
                    if (is_string($value) && !empty($value)) {
                        $sanitized_array[$key] = strlen($value) > 8 ? substr($value, 0, 4) . '****' . substr($value, -4) : '********';
                    } else {
                        $sanitized_array[$key] = $value;
                    }
                } elseif (is_array($value) || is_object($value)) {
                    $sanitized_array[$key] = mphb_toss_sanitize_log_data($value); // Recursive call
                } else {
                    $sanitized_array[$key] = $value;
                }
            }
            return $sanitized_array;
        } elseif (is_object($data)) {
            // Clone the object to avoid modifying the original
            $cloned_data = clone $data;
            $vars = get_object_vars($cloned_data);
            foreach ($vars as $key => $value) {
                 if (is_string($key) && (stripos($key, 'key') !== false || stripos($key, 'secret') !== false || stripos($key, 'token') !== false || stripos($key, 'password') !== false)) {
                    if (is_string($value) && !empty($value)) {
                        $cloned_data->{$key} = strlen($value) > 8 ? substr($value, 0, 4) . '****' . substr($value, -4) : '********';
                    }
                } elseif (is_array($value) || is_object($value)) {
                    $cloned_data->{$key} = mphb_toss_sanitize_log_data($value); // Recursive call
                }
            }
            return $cloned_data;
        }
        return $data;
    }
}

