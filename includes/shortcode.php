<?php
/**
 * 短代码处理
 */

// 加载前端资源
add_action('wp_enqueue_scripts', 'isoftstone_enqueue_frontend_assets');

function isoftstone_enqueue_frontend_assets() {
    // 只在包含短代码的页面加载
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'isoftstone_attendance')) {
        wp_enqueue_style('isoftstone-frontend', ISFT_PLUGIN_URL . 'assets/css/frontend.css', [], ISFT_VERSION);
        wp_enqueue_script('isoftstone-frontend', ISFT_PLUGIN_URL . 'assets/js/frontend.js', [], ISFT_VERSION, true);
    }
}

// 注册短代码
add_shortcode('isoftstone_attendance', 'isoftstone_attendance_shortcode');

function isoftstone_attendance_shortcode($atts) {
    // 检查用户登录状态
    if (!is_user_logged_in()) {
        return '<div class="isoftstone-notice isoftstone-error">
            <p>请先<a href="' . wp_login_url(get_permalink()) . '">登录</a>后使用考勤查询功能</p>
        </div>';
    }

    $user_id = get_current_user_id();
    $config = isoftstone_get_user_config($user_id);

    // 检查是否已配置
    if (!$config['has_config']) {
        return '<div class="isoftstone-notice isoftstone-warning">
            <h3>⚠️ 未配置考勤信息</h3>
            <p>请先在<a href="' . admin_url('users.php?page=isoftstone-attendance-settings') . '">后台设置页面</a>配置您的考勤认证信息。</p>
        </div>';
    }

    // 处理查询
    $api_response = null;
    $error_message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['isoftstone_query'])) {
        try {
            // 验证nonce
            if (!wp_verify_nonce($_POST['query_nonce'], 'isoftstone_query_action')) {
                throw new Exception("安全验证失败，请刷新页面重试");
            }

            // 权限检查
            if (!current_user_can('read')) {
                throw new Exception("权限不足");
            }

            $year = isset($_POST['select_year']) ? absint($_POST['select_year']) : date('Y');
            $month = isset($_POST['select_month']) ? absint($_POST['select_month']) : date('n');
            $formatted_month = str_pad($month, 2, '0', STR_PAD_LEFT);

            // 优先从 API 获取最新数据，然后保存到数据库
            $data_source = 'api';

            // 构建API请求
            $api_url = "https://ipsapro.isoftstone.com/iCan/bk/home/userBaseInfo/loginAtteDayInfo";
            $api_params = [
                'empno' => $config['empno'],
                'yearmonth' => "{$year}-{$formatted_month}"
            ];
            $api_url = add_query_arg($api_params, $api_url);

            $request_args = [
                'headers' => [
                    'Cookie' => $config['cookie'],
                    'Refreshtoken' => $config['token'],
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ],
                'timeout' => apply_filters('isoftstone_api_timeout', 30),
                'method' => 'GET',
                'sslverify' => true
            ];

            // 发送请求
            $response = wp_remote_request($api_url, $request_args);

            if (is_wp_error($response)) {
                throw new Exception("网络请求失败，请检查网络连接后重试");
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $api_response = json_decode($body, true);

            // 记录响应信息到调试日志（仅 WP_DEBUG 开启时）
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("isoftstone API Response - Status: {$status_code}, Body: " . substr($body, 0, 500));
            }

            // 🔑 统一凭据过期检测（HTTP 层 + API 层）
            // 先用 HTTP 状态码做初步检测，即使 JSON 解析失败也能判断
            $auth_expired = isoftstone_is_auth_expired($status_code, is_array($api_response) ? $api_response : []);

            if ($auth_expired) {
                // 认证过期，尝试从数据库获取历史数据
                $db_data = isoftstone_get_attendance_from_db($user_id, $config['empno'], $year, $month);
                if ($db_data && !empty($db_data)) {
                    // 使用数据库历史数据
                    $api_response = [
                        'result' => $db_data,
                        'from_cache' => true,
                        'auth_expired' => true
                    ];
                    $data_source = 'database';

                    // 计算统计数据（只计算工作日，排除节假日）
                    $work_days = 0;
                    $total_hours = 0;
                    foreach ($db_data as $record) {
                        $total_hours += $record['workHour'];
                        if ($record['workHour'] > 0 && (!isset($record['datetype']) || $record['datetype'] != '节假日')) {
                            $work_days++;
                        }
                    }

                    // 保存查询历史
                    isoftstone_save_query_history($user_id, $config['empno'], $year, $month, $work_days, $total_hours, $data_source);

                    // 将统计信息保存到响应中
                    $api_response['stats'] = [
                        'work_days' => $work_days,
                        'total_hours' => $total_hours
                    ];

                    // 跳过后续处理，直接显示结果
                    goto display_results;
                } else {
                    throw new Exception("🔑 认证已过期且无历史数据，请前往<a href='" . admin_url('users.php?page=isoftstone-attendance-settings') . "'>设置页面</a>更新 Cookie 和 RefreshToken");
                }
            }

            // HTTP 错误处理
            if ($status_code >= 500) {
                throw new Exception("服务器错误 (HTTP {$status_code})，请稍后重试");
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                // JSON 解析失败，可能也是认证问题（如返回 HTML 登录页）
                if ($status_code == 200 && strpos($body, '<') !== false) {
                    throw new Exception("🔑 认证已过期，API 返回了非 JSON 数据（可能是登录页面）。请前往<a href='" . admin_url('users.php?page=isoftstone-attendance-settings') . "'>设置页面</a>更新 Cookie 和 RefreshToken");
                }
                throw new Exception("数据解析失败，请稍后重试");
            }

            // API 业务层错误处理
            if (!isset($api_response['code']) || $api_response['code'] !== 200) {
                // 优先检测：API 返回的错误码/消息可能指示凭据过期
                if (isoftstone_is_auth_expired(0, $api_response)) {
                    throw new Exception("🔑 认证已过期或无效，请前往<a href='" . esc_url(admin_url('users.php?page=isoftstone-attendance-settings')) . "'>设置页面</a>更新配置");
                }

                $msg = isset($api_response['message']) ? esc_html($api_response['message']) : '未知错误';
                $code = isset($api_response['code']) ? intval($api_response['code']) : 0;

                // 对于其他非 200 错误，提供更详细的信息
                throw new Exception("查询失败 (HTTP {$status_code}, API Code {$code}): " . $msg);
            }

            $attendance_data = null;
            if (isset($api_response['result']) && is_array($api_response['result'])) {
                $attendance_data = $api_response['result'];
            } elseif (isset($api_response['data']) && is_array($api_response['data'])) {
                $attendance_data = $api_response['data'];
            } else {
                throw new Exception("数据格式错误");
            }

            // 转换数据格式以匹配前端期望
            $transformed_data = [];
            foreach ($attendance_data as $record) {
                // 提取日期部分（去掉时间部分）
                $date = isset($record['date']) ? substr($record['date'], 0, 10) : '';

                $transformed_data[] = [
                    'date' => $date,
                    'workHour' => isset($record['attendanceHours']) ? floatval($record['attendanceHours']) : 0,
                    'status' => isset($record['statusdesc']) ? $record['statusdesc'] : '正常',
                    'datetype' => isset($record['datetype']) ? $record['datetype'] : null
                ];
            }

            $api_response['result'] = $transformed_data;

            // 保存到数据库（如果表不存在则自动创建后重试）
            $save_result = isoftstone_save_attendance_data($user_id, $config['empno'], $transformed_data);

            // 如果保存失败，尝试重建表后重试
            if ($save_result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("isoftstone: First save attempt failed, trying to recreate tables...");
                }
                // 强制重建表（会检查版本并执行 dbDelta）
                isoftstone_create_tables();

                // 再次尝试保存
                $save_result = isoftstone_save_attendance_data($user_id, $config['empno'], $transformed_data);

                // 如果仍然失败，记录但不阻止用户查看数据
                if ($save_result === false) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("isoftstone: Second save attempt also failed. API data retrieved but not saved to database.");
                    }
                    // 在响应中添加警告标志
                    $api_response['db_save_warning'] = true;
                }
            }

            // 计算统计数据（只计算工作日，排除节假日）
            $work_days = 0;
            $total_hours = 0;
            foreach ($transformed_data as $record) {
                $total_hours += $record['workHour'];
                // 只统计有工时且不是节假日的工作日
                if ($record['workHour'] > 0 && (!isset($record['datetype']) || $record['datetype'] != '节假日')) {
                    $work_days++;
                }
            }
            $result_count = $work_days;

            // 保存查询历史
            isoftstone_save_query_history($user_id, $config['empno'], $year, $month, $result_count, $total_hours, $data_source);

            // 将统计信息保存到响应中
            $api_response['stats'] = [
                'work_days' => $work_days,
                'total_hours' => $total_hours
            ];

            // 同时也使用 transient 缓存1小时作为二级缓存
            $cache_key = "isoftstone_attendance_{$config['empno']}_{$year}_{$formatted_month}";
            set_transient($cache_key, $api_response, HOUR_IN_SECONDS);

            display_results:

        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

    $current_year = intval(date('Y'));
    $current_month = intval(date('n'));

    // 获取用户上次选择的年份和月份，如果没有则使用当前年月
    $selected_year = isset($_POST['select_year']) ? intval($_POST['select_year']) : $current_year;
    $selected_month = isset($_POST['select_month']) ? intval($_POST['select_month']) : $current_month;

    ob_start();
    ?>
    <div class="isoftstone-attendance-container">
        <!-- 查询表单 -->
        <div class="isoftstone-query-card">
            <form method="post" id="isoftstoneForm">
                <?php wp_nonce_field('isoftstone_query_action', 'query_nonce'); ?>
                <input type="hidden" name="isoftstone_query" value="1">

                <div class="isoftstone-form-row">
                    <div class="isoftstone-form-group">
                        <label for="select_year">📆 选择年份</label>
                        <select id="select_year" name="select_year" required>
                            <?php
                            for ($y = 2020; $y <= $current_year + 1; $y++) {
                                $sel = ($y === $selected_year) ? ' selected="selected"' : '';
                                echo '<option value="' . $y . '"' . $sel . '>' . $y . '年</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="isoftstone-form-group">
                        <label for="select_month">📊 选择月份</label>
                        <select id="select_month" name="select_month" required>
                            <?php
                            for ($m = 1; $m <= 12; $m++) {
                                $sel = ($m === $selected_month) ? ' selected="selected"' : '';
                                $dis = ($m > $current_month && $selected_year == $current_year) ? ' disabled="disabled"' : '';
                                echo '<option value="' . $m . '"' . $sel . $dis . '>' . $m . '月</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="isoftstone-btn-wrapper">
                        <button type="submit" class="isoftstone-btn" id="isoftstoneSubmitBtn">
                            <span class="btn-text">🔍 查询考勤</span>
                            <div class="loader" aria-hidden="true">
                                <div class="spinner"></div>
                            </div>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($error_message) :
            // 检测是否为凭据过期错误
            $is_auth_error = (strpos($error_message, '认证已过期') !== false || strpos($error_message, '认证可能已过期') !== false);
            $alert_class = $is_auth_error ? 'isoftstone-alert-auth-expired' : '';
        ?>
            <div class="isoftstone-alert isoftstone-alert-error <?php echo esc_attr($alert_class); ?>" data-auth-expired="<?php echo $is_auth_error ? 'true' : 'false'; ?>">
                <div class="alert-icon">!</div>
                <div class="alert-content">
                    <h3>查询失败</h3>
                    <p><?php echo wp_kses($error_message, ['a' => ['href' => []]]); ?></p>
                    <?php if ($is_auth_error) : ?>
                        <p><a href="<?php echo esc_url(admin_url('users.php?page=isoftstone-attendance-settings')); ?>" class="button button-primary" style="margin-top: 10px;">前往更新配置 →</a></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // 显示数据库保存警告（如果有）
        if (isset($api_response['db_save_warning']) && $api_response['db_save_warning']):
        ?>
            <div class="isoftstone-alert isoftstone-alert-warning">
                <div class="alert-icon">⚠</div>
                <div class="alert-content">
                    <h3>数据已查询但未保存到数据库</h3>
                    <p>考勤数据已从 API 成功获取并显示，但由于数据库表问题未能保存。下次查询相同月份时需要重新请求 API。请联系管理员检查数据库表结构。</p>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // 显示历史数据提示（认证过期时使用数据库数据）
        if (isset($api_response['auth_expired']) && $api_response['auth_expired']):
        ?>
            <div class="isoftstone-alert isoftstone-alert-info">
                <div class="alert-icon">ℹ</div>
                <div class="alert-content">
                    <h3>📋 历史数据</h3>
                    <p>您的认证信息已过期，以下是从数据库读取的历史考勤数据。如需获取最新数据，请前往<a href="<?php echo esc_url(admin_url('users.php?page=isoftstone-attendance-settings')); ?>">设置页面</a>更新 Cookie 和 RefreshToken。</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- 结果面板 -->
        <?php if ($api_response && isset($api_response['result'])) : ?>
            <div class="isoftstone-result-panel">
                <?php
                $total_hours = isset($api_response['stats']['total_hours'])
                    ? $api_response['stats']['total_hours']
                    : array_sum(array_column($api_response['result'], 'workHour'));
                $work_days = isset($api_response['stats']['work_days'])
                    ? $api_response['stats']['work_days']
                    : count($api_response['result']);
                $avg_hours = $work_days > 0 ? $total_hours / $work_days : 0;

                // 根据平均工时确定颜色类
                $color_class = 'metric-green';
                if ($avg_hours > 9) {
                    $color_class = 'metric-red';
                } elseif ($avg_hours > 8) {
                    $color_class = 'metric-yellow';
                }
                ?>
                <div class="isoftstone-summary">
                    <div class="isoftstone-summary-card">
                        <div class="isoftstone-metric">
                            <span class="metric-label">⏰ 累计工时</span>
                            <span class="metric-value">
                                <?php echo number_format($total_hours, 2); ?> 小时
                            </span>
                        </div>
                        <div class="isoftstone-metric">
                            <span class="metric-label">📅 出勤天数</span>
                            <span class="metric-value">
                                <?php echo $work_days; ?> 天
                            </span>
                        </div>
                        <div class="isoftstone-metric <?php echo esc_attr($color_class); ?>">
                            <span class="metric-label">📊 日均工时</span>
                            <span class="metric-value">
                                <?php echo number_format($avg_hours, 2); ?> 小时
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($api_response && isset($api_response['result'])) : ?>

            <div class="isoftstone-calendar-wrapper">
                <div class="isoftstone-calendar-grid">
                    <div class="isoftstone-weekdays-row">
                        <?php foreach (['一', '二', '三', '四', '五', '六', '日'] as $day) : ?>
                            <div class="isoftstone-weekday-cell"><?php echo $day; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="isoftstone-calendar-body" id="calendarBody"></div>
                </div>
            </div>
        </div>

        <!-- 空状态提示（未查询时显示） -->
        <?php elseif (!$api_response) : ?>
            <div class="isoftstone-empty-state">
                <div class="isoftstone-empty-state-icon">📅</div>
                <h3>暂无考勤数据</h3>
                <p>请选择年月并点击"查询考勤"按钮查看您的考勤记录</p>
            </div>
        <?php endif; ?>

    </div>

    <!-- 加载遮罩层 -->
    <div class="isoftstone-loading-overlay" id="isoftstoneLoadingOverlay" style="display: none;">
        <div class="isoftstone-loading-content">
            <div class="isoftstone-loading-spinner"></div>
            <div class="isoftstone-loading-text">正在查询考勤数据...</div>
        </div>
    </div>

    <script>
    // 传递考勤数据给前端 JS
    var isoftstoneData = <?php
        if (isset($api_response) && isset($api_response['result']) && is_array($api_response['result'])) {
            echo json_encode(array_column($api_response['result'], null, 'date'));
        } else {
            echo 'null';
        }
        ?>;
    </script>
    <?php
    return ob_get_clean();
}
