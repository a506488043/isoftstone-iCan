<?php
/**
 * Plugin Name: 软通考勤查询系统
 * Plugin URI: https://www.saiita.com.cn
 * Description: 软通考勤查询系统，支持后台配置认证信息，前端查询考勤数据
 * Version: 1.2.1
 * Author: Saiita
 * Author URI: https://www.saiita.com.cn
 * License: GPL v2 or later
 * Text Domain: isoftstone-attendance
 * Domain Path: /languages
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('ISFT_VERSION', '1.2.1');
define('ISFT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ISFT_PLUGIN_URL', plugin_dir_url(__FILE__));

// 加载加密密钥
if (!defined('ISFT_ENCRYPTION_KEY')) {
    define('ISFT_ENCRYPTION_KEY', 'isft-secret-key-' . AUTH_KEY);
}

/**
 * 加密敏感数据
 */
function isoftstone_encrypt($data) {
    $key = substr(hash('sha256', ISFT_ENCRYPTION_KEY), 0, 32);
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * 解密敏感数据
 */
function isoftstone_decrypt($data) {
    $key = substr(hash('sha256', ISFT_ENCRYPTION_KEY), 0, 32);
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, 0, $iv);
}

/**
 * 获取用户考勤配置信息
 */
function isoftstone_get_user_config($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    $cookie = get_user_meta($user_id, '_isoftstone_cookie', true);
    $token = get_user_meta($user_id, '_isoftstone_token', true);

    return [
        'empno' => get_user_meta($user_id, '_isoftstone_empno', true),
        'cookie' => $cookie ? isoftstone_decrypt($cookie) : '',
        'token' => $token ? isoftstone_decrypt($token) : '',
        'has_config' => !empty($cookie) && !empty($token) && !empty(get_user_meta($user_id, '_isoftstone_empno', true))
    ];
}

/**
 * 保存用户考勤配置信息
 */
function isoftstone_save_user_config($user_id, $empno, $cookie, $token) {
    update_user_meta($user_id, '_isoftstone_empno', sanitize_text_field($empno));
    update_user_meta($user_id, '_isoftstone_cookie', isoftstone_encrypt(sanitize_text_field($cookie)));
    update_user_meta($user_id, '_isoftstone_token', isoftstone_encrypt(sanitize_text_field($token)));
    update_user_meta($user_id, '_isoftstone_config_time', current_time('timestamp'));
}

/**
 * 清除用户考勤配置信息
 */
function isoftstone_clear_user_config($user_id) {
    delete_user_meta($user_id, '_isoftstone_empno');
    delete_user_meta($user_id, '_isoftstone_cookie');
    delete_user_meta($user_id, '_isoftstone_token');
    delete_user_meta($user_id, '_isoftstone_config_time');
}

/**
 * 判断 API 错误是否为凭据过期
 */
function isoftstone_is_auth_expired($status_code, $api_response) {
    // HTTP 层过期状态码
    if (in_array($status_code, [401, 403])) {
        return true;
    }

    // JSON 响应中包含过期关键词
    if (is_array($api_response)) {
        $msg = isset($api_response['message']) ? strtolower($api_response['message']) : '';
        $expire_keywords = ['过期', '无效', '授权', 'expire', 'invalid', 'unauthorized', 'token', 'auth', '登录', '未登录', '登录过期', '会话', 'session'];
        foreach ($expire_keywords as $kw) {
            if (strpos($msg, $kw) !== false) {
                return true;
            }
        }

        // 特定错误码（如 401001, -1 等常见未授权码）
        $code = isset($api_response['code']) ? intval($api_response['code']) : 0;
        if (in_array($code, [-1, 401, 403, 1001, 401001, 403001, 10001, 10002, 10003])) {
            return true;
        }

        // 检测空数据或特定结构（某些 API 在过期时返回空结果）
        if (isset($api_response['code']) && $api_response['code'] !== 200) {
            // 如果 code 不是 200 且 message 中包含认证相关信息
            if (!empty($msg) && (strpos($msg, '登录') !== false || strpos($msg, '认证') !== false)) {
                return true;
            }
        }
    }
    return false;
}

// 包含必要的文件
require_once ISFT_PLUGIN_DIR . 'includes/database-tables.php';
require_once ISFT_PLUGIN_DIR . 'includes/admin-settings.php';
require_once ISFT_PLUGIN_DIR . 'includes/shortcode.php';
require_once ISFT_PLUGIN_DIR . 'includes/ajax-handler.php';

/**
 * 数据库版本检测与自动迁移
 * 插件加载时检查数据库版本，不一致则自动更新表结构
 */
add_action('plugins_loaded', 'isoftstone_check_db_version');

function isoftstone_check_db_version() {
    $db_version = get_option('isoftstone_db_version', '0.0.0');
    $table_status = isoftstone_check_db_tables();

    // 版本不一致 或 表不存在 → 触发迁移
    if (version_compare($db_version, ISFT_VERSION, '<') || !$table_status['attendance_table'] || !$table_status['history_table']) {
        isoftstone_create_tables();
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("isoftstone DB migrated: {$db_version} -> " . ISFT_VERSION);
        }
    }
}

/**
 * 检查数据库表是否存在
 * 用于后台状态面板显示
 */
function isoftstone_check_db_tables() {
    global $wpdb;
    $table_attendance = $wpdb->prefix . 'isoftstone_attendance';
    $table_history = $wpdb->prefix . 'isoftstone_query_history';

    $attendance_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        $wpdb->dbname, $table_attendance
    ));

    $history_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
        $wpdb->dbname, $table_history
    ));

    return [
        'attendance_table' => (bool) $attendance_exists,
        'history_table'    => (bool) $history_exists,
        'db_version'       => get_option('isoftstone_db_version', '0.0.0'),
        'plugin_version'   => ISFT_VERSION,
        'needs_migration'  => version_compare(get_option('isoftstone_db_version', '0.0.0'), ISFT_VERSION, '<'),
    ];
}

/**
 * 数据库诊断工具 - 返回详细的数据库状态信息
 */
function isoftstone_diagnose_database() {
    global $wpdb;
    $table_attendance = isoftstone_get_attendance_table();
    $table_history = isoftstone_get_history_table();

    $diagnosis = [
        'tables_exist' => false,
        'table_structure_correct' => false,
        'errors' => [],
        'info' => []
    ];

    // 检查表是否存在
    $attendance_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_attendance}'");
    $history_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_history}'");

    $diagnosis['tables_exist'] = ($attendance_exists && $history_exists);

    if (!$attendance_exists) {
        $diagnosis['errors'][] = "考勤数据表不存在: {$table_attendance}";
    }
    if (!$history_exists) {
        $diagnosis['errors'][] = "查询历史表不存在: {$table_history}";
    }

    // 如果表存在，检查表结构
    if ($attendance_exists) {
        $required_columns = ['id', 'user_id', 'empno', 'attendance_date', 'work_hours', 'status', 'datetype', 'data_source', 'original_data', 'created_at', 'updated_at'];
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_attendance}");
        $missing_columns = array_diff($required_columns, $existing_columns);

        if (!empty($missing_columns)) {
            $diagnosis['errors'][] = "考勤数据表缺少字段: " . implode(', ', $missing_columns);
            $diagnosis['table_structure_correct'] = false;
        } else {
            $diagnosis['table_structure_correct'] = true;
            $diagnosis['info']['attendance_columns'] = count($existing_columns) . " columns";
        }

        // 检查记录数
        $record_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_attendance}");
        $diagnosis['info']['attendance_records'] = $record_count;
    }

    if ($history_exists) {
        $record_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_history}");
        $diagnosis['info']['history_records'] = $record_count;
    }

    $diagnosis['db_version'] = get_option('isoftstone_db_version', 'not set');
    $diagnosis['plugin_version'] = ISFT_VERSION;

    return $diagnosis;
}

/**
 * 管理员通知：数据库表缺失
 */
add_action('admin_notices', 'isoftstone_admin_notices');

function isoftstone_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $status = isoftstone_check_db_tables();

    if (!$status['attendance_table'] || !$status['history_table']) {
        echo '<div class="notice notice-error"><p><strong>软通考勤插件</strong>：数据库表缺失！请前往<a href="' . esc_url(admin_url('plugins.php')) . '">插件页面</a>停用并重新激活本插件以创建数据表。</p></div>';
    } elseif ($status['needs_migration']) {
        echo '<div class="notice notice-warning"><p><strong>软通考勤插件</strong>：数据库需要更新 (当前: ' . esc_html($status['db_version']) . ' → 目标: ' . esc_html($status['plugin_version']) . ')。<a href="' . esc_url(admin_url('plugins.php')) . '">停用并重新激活插件</a>以执行迁移。</p></div>';
    }

    // 如果 WP_DEBUG 开启，显示数据库诊断信息
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $diagnosis = isoftstone_diagnose_database();
        if (!empty($diagnosis['errors'])) {
            echo '<div class="notice notice-error"><p><strong>软通考勤插件 - 数据库诊断</strong>：</p><ul>';
            foreach ($diagnosis['errors'] as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul><p>当前版本: ' . esc_html($diagnosis['plugin_version']) . ' | 数据库版本: ' . esc_html($diagnosis['db_version']) . '</p></div>';
        }
    }
}
