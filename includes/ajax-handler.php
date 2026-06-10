<?php
/**
 * AJAX 处理器
 */

// AJAX 清除缓存
add_action('wp_ajax_isoftstone_clear_cache', 'isoftstone_clear_cache_handler');

function isoftstone_clear_cache_handler() {
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'isoftstone_clear_cache')) {
        wp_send_json_error(['message' => '安全验证失败']);
    }

    // 检查用户登录状态
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => '请先登录']);
    }

    $user_id = get_current_user_id();
    $empno = get_user_meta($user_id, '_isoftstone_empno', true);

    if (empty($empno)) {
        wp_send_json_error(['message' => '未找到工号信息']);
    }

    // 清除该用户的所有缓存
    global $wpdb;
    $cache_key = "_transient_isoftstone_attendance_{$empno}_%";
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $cache_key
        )
    );

    wp_send_json_success(['message' => '缓存已清除']);
}

/**
 * 测试连接 - 验证凭据是否仍然有效
 */
add_action('wp_ajax_isoftstone_test_connection', 'isoftstone_test_connection_handler');

function isoftstone_test_connection_handler() {
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'isoftstone_test_connection')) {
        wp_send_json_error(['message' => '安全验证失败']);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => '请先登录']);
    }

    $user_id = get_current_user_id();
    $config = isoftstone_get_user_config($user_id);

    if (!$config['has_config']) {
        wp_send_json_error(['message' => '未配置考勤信息，请先填写工号、Cookie 和 Token']);
    }

    // 用当前年月发起一次轻量 API 请求来验证凭据
    $year = intval(date('Y'));
    $month = str_pad(date('n'), 2, '0', STR_PAD_LEFT);

    $api_url = add_query_arg([
        'empno'    => $config['empno'],
        'yearmonth' => "{$year}-{$month}"
    ], "https://ipsapro.isoftstone.com/iCan/bk/home/userBaseInfo/loginAtteDayInfo");

    $response = wp_remote_request($api_url, [
        'headers' => [
            'Cookie'        => $config['cookie'],
            'Refreshtoken'  => $config['token'],
            'User-Agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ],
        'timeout' => 15,
        'method'  => 'GET',
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => '网络请求失败: ' . $response->get_error_message(),
            'status'  => 'network_error'
        ]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $api_response = json_decode($body, true);

    // 使用统一的过期检测函数
    if (isoftstone_is_auth_expired($status_code, is_array($api_response) ? $api_response : [])) {
        wp_send_json_error([
            'message' => '❌ 认证已过期，请更新 Cookie 和 RefreshToken',
            'status'  => 'expired'
        ]);
    }

    if (is_array($api_response) && isset($api_response['code']) && $api_response['code'] === 200) {
        $config_time = get_user_meta($user_id, '_isoftstone_config_time', true);
        $time_str = $config_time ? date('Y-m-d H:i:s', $config_time) : '未知';
        wp_send_json_success([
            'message'     => '✅ 连接正常，凭据有效',
            'status'      => 'valid',
            'config_time' => $time_str
        ]);
    }

    // 非 200 的业务错误（非过期）
    if (is_array($api_response) && isset($api_response['code']) && $api_response['code'] !== 200) {
        wp_send_json_error([
            'message' => '⚠️ 接口异常: ' . ($api_response['message'] ?? '未知错误'),
            'status'  => 'api_error'
        ]);
    }

    // 其他情况
    wp_send_json_error([
        'message' => '⚠️ 收到未知响应 (HTTP ' . $status_code . ')，请检查配置',
        'status'  => 'unknown'
    ]);
}

/**
 * 数据库诊断 - AJAX 处理器
 */
add_action('wp_ajax_isoftstone_db_diagnose', 'isoftstone_db_diagnose_handler');

function isoftstone_db_diagnose_handler() {
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'isoftstone_db_diagnose')) {
        wp_send_json_error(['message' => '安全验证失败']);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '权限不足']);
    }

    $diagnosis = isoftstone_diagnose_database();
    wp_send_json_success($diagnosis);
}
