<?php
/**
 * 数据库表管理
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 创建数据表
 */
function isoftstone_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // 考勤数据表
    $table_attendance = $wpdb->prefix . 'isoftstone_attendance';
    $sql_attendance = "CREATE TABLE IF NOT EXISTS `{$table_attendance}` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `empno` varchar(20) NOT NULL,
        `attendance_date` date NOT NULL,
        `work_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
        `status` varchar(50) NOT NULL DEFAULT '正常',
        `datetype` varchar(50) DEFAULT NULL,
        `data_source` varchar(20) NOT NULL DEFAULT 'api',
        `original_data` text DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_record` (`user_id`, `empno`, `attendance_date`),
        KEY `idx_empno` (`empno`),
        KEY `idx_date` (`attendance_date`),
        KEY `idx_user` (`user_id`),
        KEY `idx_datetype` (`datetype`)
    ) $charset_collate;";

    // 查询历史表
    $table_history = $wpdb->prefix . 'isoftstone_query_history';
    $sql_history = "CREATE TABLE IF NOT EXISTS `{$table_history}` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) UNSIGNED NOT NULL,
        `empno` varchar(20) NOT NULL,
        `query_year` int(4) NOT NULL,
        `query_month` int(2) NOT NULL,
        `result_count` int(11) NOT NULL DEFAULT 0,
        `total_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
        `data_source` varchar(20) NOT NULL DEFAULT 'api',
        `ip_address` varchar(45) DEFAULT NULL,
        `user_agent` varchar(255) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_empno` (`empno`),
        KEY `idx_date` (`query_year`, `query_month`),
        KEY `idx_created` (`created_at`)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta($sql_attendance);
    dbDelta($sql_history);

    // 保存当前数据库版本
    update_option('isoftstone_db_version', ISFT_VERSION);
}

/**
 * 获取考勤数据表名
 */
function isoftstone_get_attendance_table() {
    global $wpdb;
    return $wpdb->prefix . 'isoftstone_attendance';
}

/**
 * 获取查询历史表名
 */
function isoftstone_get_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'isoftstone_query_history';
}

/**
 * 保存考勤数据到数据库
 */
function isoftstone_save_attendance_data($user_id, $empno, $attendance_data) {
    global $wpdb;
    $table = isoftstone_get_attendance_table();

    // 确保表存在
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("isoftstone: Table {$table} not found, creating tables...");
        }
        isoftstone_create_tables();
    }

    $saved_count = 0;
    $failed_records = [];

    foreach ($attendance_data as $record) {
        $date = isset($record['date']) ? $record['date'] : '';
        $work_hours = isset($record['workHour']) ? floatval($record['workHour']) : 0;
        $status = isset($record['status']) ? $record['status'] : '正常';
        $datetype = isset($record['datetype']) ? $record['datetype'] : null;
        $original_data = wp_json_encode($record);

        if (empty($date)) {
            continue;
        }

        // 检查表结构是否完整（检查必需字段）
        $check_column = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = %s
            AND table_name = %s
            AND column_name = 'datetype'",
            $wpdb->dbname, $table
        ));

        // 如果表结构不完整，重建表
        if ($check_column === '0') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("isoftstone: Table structure incomplete, recreating tables...");
            }
            isoftstone_create_tables();
        }

        $result = $wpdb->replace(
            $table,
            [
                'user_id'        => intval($user_id),
                'empno'          => sanitize_text_field($empno),
                'attendance_date'=> sanitize_text_field($date),
                'work_hours'     => $work_hours,
                'status'         => sanitize_text_field($status),
                'datetype'       => $datetype ? sanitize_text_field($datetype) : null,
                'data_source'    => 'api',
                'original_data'  => $original_data
            ],
            ['%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s']
        );

        if ($result !== false) {
            $saved_count++;
        } else {
            $failed_records[] = $date;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("isoftstone Save Error for {$date}: " . $wpdb->last_error);
            }
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("isoftstone Save: {$saved_count}/" . count($attendance_data) . " records saved");
        if (!empty($failed_records)) {
            error_log("isoftstone Failed records: " . implode(', ', $failed_records));
        }
    }

    return $saved_count > 0 ? $saved_count : false;
}

/**
 * 从数据库读取考勤数据
 */
function isoftstone_get_attendance_from_db($user_id, $empno, $year, $month) {
    // 尝试从对象缓存获取
    $cache_key = "isoftstone_attendance_db_{$user_id}_{$empno}_{$year}_{$month}";
    $cached = wp_cache_get($cache_key, 'isoftstone');

    if (false !== $cached) {
        return $cached;
    }

    global $wpdb;
    $table = isoftstone_get_attendance_table();

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `{$table}`
        WHERE `user_id` = %d
        AND `empno` = %s
        AND YEAR(`attendance_date`) = %d
        AND MONTH(`attendance_date`) = %d
        ORDER BY `attendance_date` ASC",
        $user_id,
        $empno,
        $year,
        $month
    ), ARRAY_A);

    if (empty($results)) {
        return null;
    }

    // 转换为前端需要的格式
    $formatted_data = [];
    foreach ($results as $row) {
        $formatted_data[] = [
            'date' => $row['attendance_date'],
            'workHour' => floatval($row['work_hours']),
            'status' => $row['status'],
            'datetype' => isset($row['datetype']) ? $row['datetype'] : null
        ];
    }

    // 缓存结果 1 小时
    wp_cache_set($cache_key, $formatted_data, 'isoftstone', HOUR_IN_SECONDS);

    return $formatted_data;
}

/**
 * 保存查询历史记录
 */
function isoftstone_save_query_history($user_id, $empno, $year, $month, $result_count, $total_hours, $data_source = 'api') {
    global $wpdb;
    $table = isoftstone_get_history_table();

    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    return $wpdb->insert(
        $table,
        [
            'user_id' => $user_id,
            'empno' => $empno,
            'query_year' => $year,
            'query_month' => $month,
            'result_count' => $result_count,
            'total_hours' => $total_hours,
            'data_source' => $data_source,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ],
        ['%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
    );
}

/**
 * 获取查询历史记录
 */
function isoftstone_get_query_history($user_id, $limit = 20) {
    // 尝试从对象缓存获取
    $cache_key = "isoftstone_history_{$user_id}_{$limit}";
    $cached = wp_cache_get($cache_key, 'isoftstone');

    if (false !== $cached) {
        return $cached;
    }

    global $wpdb;
    $table = isoftstone_get_history_table();

    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `{$table}`
        WHERE `user_id` = %d
        ORDER BY `created_at` DESC
        LIMIT %d",
        $user_id,
        $limit
    ), ARRAY_A);

    // 缓存结果 30 分钟
    wp_cache_set($cache_key, $results, 'isoftstone', 30 * MINUTE_IN_SECONDS);

    return $results;
}

/**
 * 删除考勤数据表（插件卸载时使用）
 */
function isoftstone_drop_tables() {
    global $wpdb;

    $table_attendance = isoftstone_get_attendance_table();
    $table_history = isoftstone_get_history_table();

    $wpdb->query("DROP TABLE IF EXISTS `{$table_attendance}`");
    $wpdb->query("DROP TABLE IF EXISTS `{$table_history}`");

    delete_option('isoftstone_db_version');
}

// 插件激活时创建数据表
register_activation_hook(ISFT_PLUGIN_DIR . 'isoftstone-attendance.php', 'isoftstone_create_tables');
