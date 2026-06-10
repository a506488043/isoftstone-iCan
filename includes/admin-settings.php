<?php
/**
 * 后台设置页面
 */

// 加载后台资源
add_action('admin_enqueue_scripts', 'isoftstone_enqueue_admin_assets');

function isoftstone_enqueue_admin_assets($hook) {
    // 只在插件页面加载
    if (strpos($hook, 'isoftstone') !== false) {
        wp_enqueue_style('isoftstone-admin', ISFT_PLUGIN_URL . 'assets/css/admin.css', [], ISFT_VERSION);
        wp_enqueue_script('isoftstone-admin', ISFT_PLUGIN_URL . 'assets/js/admin.js', [], ISFT_VERSION, true);
    }
}

// 添加后台菜单
add_action('admin_menu', 'isoftstone_add_admin_menu');

function isoftstone_add_admin_menu() {
    $page = add_users_page(
        '考勤查询设置',
        '考勤查询',
        'read',
        'isoftstone-attendance-settings',
        'isoftstone_settings_page_html'
    );

    // 移除帮助标签和屏幕选项
    add_action('load-' . $page, 'isoftstone_remove_help_tabs');
}

// 移除帮助标签和屏幕选项
function isoftstone_remove_help_tabs() {
    // 移除屏幕选项
    add_filter('screen_options_show_screen', '__return_false');
    // 移除帮助标签
    add_filter('contextual_help', '__return_empty_array');
}

// 注册设置
add_action('admin_init', 'isoftstone_register_settings');

function isoftstone_register_settings() {
    register_setting('isoftstone_attendance_settings', 'isoftstone_empno', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => false,
    ]);

    register_setting('isoftstone_attendance_settings', 'isoftstone_cookie', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => false,
    ]);

    register_setting('isoftstone_attendance_settings', 'isoftstone_token', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => false,
    ]);
}

// 设置页面HTML
function isoftstone_settings_page_html() {
    if (!current_user_can('read')) {
        return;
    }

    $user_id = get_current_user_id();
    $config = isoftstone_get_user_config($user_id);

    // 保存设置
    if (isset($_POST['isoftstone_save_settings'])) {
        check_admin_referer('isoftstone_settings_nonce');

        $empno = isset($_POST['empno']) ? sanitize_text_field($_POST['empno']) : '';
        $cookie = isset($_POST['cookie']) ? sanitize_text_field($_POST['cookie']) : '';
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        if (!empty($empno) && !empty($cookie) && !empty($token)) {
            isoftstone_save_user_config($user_id, $empno, $cookie, $token);
            echo '<div class="notice notice-success"><p>设置已保存！</p></div>';
            $config = isoftstone_get_user_config($user_id);
        } else {
            echo '<div class="notice notice-error"><p>请填写所有必填字段！</p></div>';
        }
    }

    // 测试连接
    if (isset($_POST['isoftstone_test_connection'])) {
        check_admin_referer('isoftstone_settings_nonce');
        // 由 AJAX 处理，这里只是占位
    }

    // 清除设置
    if (isset($_POST['isoftstone_clear_settings'])) {
        check_admin_referer('isoftstone_settings_nonce');
        isoftstone_clear_user_config($user_id);
        echo '<div class="notice notice-success"><p>设置已清除！</p></div>';
        $config = isoftstone_get_user_config($user_id);
    }

    // 修复数据库
    if (isset($_POST['isoftstone_recreate_tables'])) {
        check_admin_referer('isoftstone_settings_nonce');
        isoftstone_create_tables();
        echo '<div class="notice notice-success"><p>数据库表已创建/更新！</p></div>';
    }

    ?>
    <div class="wrap">
        <div class="card" style="width: 100%; margin-top: 20px;">
            <form method="post" action="">
                <?php wp_nonce_field('isoftstone_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="empno">员工工号 <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="empno"
                                   name="empno"
                                   value="<?php echo esc_attr($config['empno']); ?>"
                                   class="regular-text"
                                   pattern="\d{5,}"
                                   required
                                   placeholder="请输入您的员工编号">
                            <p class="description">至少5位数字的工号</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="cookie">认证Cookie <span class="required">*</span></label>
                        </th>
                        <td>
                            <textarea id="cookie"
                                      name="cookie"
                                      class="large-text code"
                                      rows="4"
                                      required
                                      placeholder="从浏览器开发者工具中复制完整的Cookie内容"><?php echo esc_textarea($config['cookie']); ?></textarea>
                            <p class="description">从浏览器开发者工具的请求头中复制Cookie字段</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="token">RefreshToken <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="token"
                                   name="token"
                                   value="<?php echo esc_attr($config['token']); ?>"
                                   class="regular-text code"
                                   required
                                   placeholder="从请求头中获取 Refreshtoken">
                            <p class="description">从请求头中获取的 Refreshtoken 字段值</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">配置状态</th>
                        <td>
                            <?php if ($config['has_config']) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                <strong style="color: green;">已配置</strong>
                                <p class="description">
                                    配置时间：<?php echo get_user_meta($user_id, '_isoftstone_config_time', true) ? date('Y-m-d H:i:s', get_user_meta($user_id, '_isoftstone_config_time', true)) : '未知'; ?>
                                </p>
                            <?php else : ?>
                                <span class="dashicons dashicons-no-alt" style="color: red;"></span>
                                <strong style="color: red;">未配置</strong>
                                <p class="description">请填写上方信息以启用考勤查询功能</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">系统状态</th>
                        <td>
                            <?php
                            $db_status = isoftstone_check_db_tables();
                            $config_time = get_user_meta($user_id, '_isoftstone_config_time', true);
                            $config_time_str = $config_time ? date('Y-m-d H:i:s', $config_time) : '未保存';
                            $config_age = $config_time ? (time() - $config_time) : 0;
                            $config_age_days = floor($config_age / 86400);
                            ?>
                            <table class="widefat" style="max-width: 500px;">
                                <tr>
                                    <td><strong>数据库表</strong></td>
                                    <td>
                                        <?php if ($db_status['attendance_table'] && $db_status['history_table']) : ?>
                                            <span style="color: green;">✅ 正常</span>
                                        <?php else : ?>
                                            <span style="color: red;">❌ 缺失</span>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field('isoftstone_settings_nonce'); ?>
                                                <button type="submit" name="isoftstone_recreate_tables" class="button button-small" style="margin-left: 10px;">修复数据库</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>数据库版本</strong></td>
                                    <td>
                                        <?php echo esc_html($db_status['db_version']); ?>
                                        <?php if ($db_status['needs_migration']) : ?>
                                            <span style="color: orange;"> (需要更新 → <?php echo esc_html($db_status['plugin_version']); ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>凭据保存时间</strong></td>
                                    <td>
                                        <?php echo esc_html($config_time_str); ?>
                                        <?php if ($config_age_days > 30) : ?>
                                            <br><small style="color: orange;">⚠️ 凭据已保存 <?php echo $config_age_days; ?> 天，建议定期更新</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">连接测试</th>
                        <td>
                            <div id="isoftstone-test-result" style="margin-bottom: 10px;"></div>
                            <button type="button" class="button button-secondary" id="isoftstoneTestBtn" onclick="isoftstoneTestConnection()">
                                🔗 测试连接
                            </button>
                            <p class="description">验证当前 Cookie 和 RefreshToken 是否仍然有效</p>
                        </td>
                    </tr>
                </table>

                <div class="isoftstone-submit-buttons">
                    <?php submit_button('保存设置', 'primary', 'isoftstone_save_settings', false); ?>

                    <?php if ($config['has_config']) : ?>
                        <?php submit_button('清除设置', 'secondary', 'isoftstone_clear_settings', false); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <script>
    function isoftstoneTestConnection() {
        var btn = document.getElementById('isoftstoneTestBtn');
        var result = document.getElementById('isoftstone-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ 测试中...';
        result.innerHTML = '<span style="color: #999;">正在验证凭据...</span>';

        var formData = new FormData();
        formData.append('action', 'isoftstone_test_connection');
        formData.append('nonce', '<?php echo wp_create_nonce('isoftstone_test_connection'); ?>');

        fetch(ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                result.innerHTML = '<div class="notice notice-success" style="margin:0;padding:8px 12px;"><p>' + data.data.message + '</p></div>';
            } else {
                var cssClass = data.data.status === 'expired' ? 'notice-error' : 'notice-warning';
                result.innerHTML = '<div class="notice ' + cssClass + '" style="margin:0;padding:8px 12px;"><p>' + data.data.message + '</p></div>';
            }
        })
        .catch(function(err) {
            result.innerHTML = '<div class="notice notice-error" style="margin:0;padding:8px 12px;"><p>请求失败: ' + err.message + '</p></div>';
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '🔗 测试连接';
        });
    }
    </script>
    <?php
}
