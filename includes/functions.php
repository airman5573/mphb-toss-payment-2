<?php

function mphbTossSanitizeCustomerKey($raw) {
    $key = preg_replace('/[^a-zA-Z0-9\-\_\=\.\@]/', '', $raw ?: '');
    $key = substr($key, 0, 50);
    if (strlen($key) < 2) $key = str_pad($key, 2, '0');
    return $key;
}
