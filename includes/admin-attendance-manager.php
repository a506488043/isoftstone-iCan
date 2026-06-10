<?php
/**
 * 后台考勤管理页面
 */

// 添加后台菜单
add_action('admin_menu', 'isoftstone_add_attendance_manager_menu');

function isoftstone_add_attendance_manager_menu() {
    $page = add_users_page(
        '考勤数据管理',
        '考勤管理',
        'read',
        'isoftstone-attendance-manager',
        'isoftstone_attendance_manager_page_html'
    );

    // 移除帮助标签和屏幕选项
    add_action('load-' . $page, 'isoftstone_remove_help_tabs_manager');
}

// 移除帮助标签和屏幕选项
function isoftstone_remove_help_tabs_manager() {
    add_filter('screen_options_show_screen', '__return_false');
    add_filter('contextual_help', '__return_empty_array');
}

// 考勤管理页面HTML
function isoftstone_attendance_manager_page_html() {
    if (!current_user_can('read')) {
        return;
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table = isoftstone_get_attendance_table();

    // 获取用户工号
    $empno = get_user_meta($user_id, '_isoftstone_empno', true);

    if (empty($empno)) {
        echo '<div class="notice notice-warning"><p>请先配置考勤信息！</p></div>';
        return;
    }

    // 处理删除操作
    if (isset($_POST['isoftstone_delete_attendance']) && isset($_POST['attendance_id'])) {
        check_admin_referer('isoftstone_attendance_manager_nonce');

        $id = intval($_POST['attendance_id']);
        $wpdb->delete(
            $table,
            ['id' => $id, 'user_id' => $user_id],
            ['%d', '%d']
        );

        echo '<div class="notice notice-success"><p>考勤记录已删除！</p></div>';
    }

    // 处理编辑/添加操作
    if (isset($_POST['isoftstone_save_attendance'])) {
        check_admin_referer('isoftstone_attendance_manager_nonce');

        $attendance_date = isset($_POST['attendance_date']) ? sanitize_text_field($_POST['attendance_date']) : '';
        $work_hours = isset($_POST['work_hours']) ? floatval($_POST['work_hours']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '正常';

        if (!empty($attendance_date)) {
            $wpdb->replace(
                $table,
                [
                    'user_id' => $user_id,
                    'empno' => $empno,
                    'attendance_date' => $attendance_date,
                    'work_hours' => $work_hours,
                    'status' => $status,
                    'data_source' => 'manual',
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%f', '%s', '%s', '%s']
            );

            echo '<div class="notice notice-success"><p>考勤记录已保存！</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>请填写日期！</p></div>';
        }
    }

    // 获取当前页码
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;

    // 获取筛选条件
    $where = ["user_id = %d", "empno = %s"];
    $where_values = [$user_id, $empno];

    $month_filter = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : '';
    if (!empty($month_filter)) {
        $where[] = "DATE_FORMAT(attendance_date, '%%Y-%%m') = %s";
        $where_values[] = $month_filter;
    }

    $where_clause = implode(' AND ', $where);

    // 获取总数
    $total_items = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}",
        $where_values
    ));

    // 获取数据
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `{$table}`
        WHERE {$where_clause}
        ORDER BY attendance_date DESC
        LIMIT %d OFFSET %d",
        array_merge($where_values, [$per_page, $offset])
    ));

    // 获取有数据的月份列表（用于筛选）
    $months = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE_FORMAT(attendance_date, '%%Y-%%m')
        FROM `{$table}`
        WHERE user_id = %d AND empno = %s
        ORDER BY attendance_date DESC",
        $user_id,
        $empno
    ));

    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">考勤数据管理</h1>
        <button type="button" class="page-title-action" onclick="openEditModal('', '', '', '')">添加记录</button>
        <hr class="wp-header-end">

        <!-- 筛选表单 -->
        <div class="isoftstone-filter-box">
            <form method="get">
                <input type="hidden" name="page" value="isoftstone-attendance-manager">
                <select name="month">
                    <option value="">全部月份</option>
                    <?php foreach ($months as $m) : ?>
                        <option value="<?php echo esc_attr($m); ?>" <?php selected($month_filter, $m); ?>>
                            <?php echo esc_html($m); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">筛选</button>
            </form>
        </div>

        <!-- 数据表格 -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>日期</th>
                    <th>工时</th>
                    <th>状态</th>
                    <th>数据来源</th>
                    <th>更新时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)) : ?>
                    <tr>
                        <td colspan="6">暂无考勤数据</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($records as $record) : ?>
                        <tr>
                            <td><?php echo esc_html($record->attendance_date); ?></td>
                            <td><?php echo esc_html($record->work_hours); ?> 小时</td>
                            <td>
                                <span class="isoftstone-status-badge isoftstone-status-<?php echo esc_attr($record->status); ?>">
                                    <?php echo esc_html($record->status); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($record->data_source === 'manual') : ?>
                                    <span class="dashicons dashicons-edit" style="color: #0073aa;" title="手动添加"></span> 手动
                                <?php else : ?>
                                    <span class="dashicons dashicons-cloud" style="color: #667eea;" title="API同步"></span> API
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($record->updated_at); ?></td>
                            <td>
                                <button type="button" class="button button-small" onclick="openEditModal('<?php echo esc_js($record->attendance_date); ?>', '<?php echo esc_js($record->work_hours); ?>', '<?php echo esc_js($record->status); ?>', '<?php echo esc_js($record->id); ?>')">编辑</button>
                                <button type="button" class="button button-small" onclick="deleteRecord('<?php echo esc_js($record->id); ?>', '<?php echo esc_js($record->attendance_date); ?>')">删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 分页 -->
        <?php if ($total_items > $per_page) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $total_pages = ceil($total_items / $per_page);
                    $current_url = remove_query_arg('paged');

                    echo '<span class="displaying-num">' . $total_items . ' 个项目</span>';
                    echo '<span class="pagination-links">';

                    if ($paged > 1) {
                        echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', '1', $current_url)) . '"><span>&laquo;</span></a>';
                        echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $paged - 1, $current_url)) . '"><span>&lsaquo;</span></a>';
                    }

                    echo '<span class="paging-input">' . $paged . ' / ' . $total_pages . '</span>';

                    if ($paged < $total_pages) {
                        echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $paged + 1, $current_url)) . '"><span>&rsaquo;</span></a>';
                        echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $current_url)) . '"><span>&raquo;</span></a>';
                    }

                    echo '</span>';
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 编辑/添加模态框 -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; min-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <h2 style="margin-top: 0;">编辑考勤记录</h2>
            <form method="post">
                <?php wp_nonce_field('isoftstone_attendance_manager_nonce'); ?>
                <input type="hidden" name="isoftstone_save_attendance" value="1">
                <input type="hidden" id="edit_attendance_id" name="attendance_id" value="">

                <table class="form-table">
                    <tr>
                        <th><label for="edit_attendance_date">日期 *</label></th>
                        <td>
                            <input type="date" id="edit_attendance_date" name="attendance_date" required class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_work_hours">工时 *</label></th>
                        <td>
                            <input type="number" id="edit_work_hours" name="work_hours" step="0.01" min="0" max="24" required class="regular-text" placeholder="例如：8">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_status">状态 *</label></th>
                        <td>
                            <select id="edit_status" name="status" class="regular-text">
                                <option value="正常">正常</option>
                                <option value="迟到">迟到</option>
                                <option value="早退">早退</option>
                                <option value="请假">请假</option>
                                <option value="缺勤">缺勤</option>
                                <option value="加班">加班</option>
                                <option value="节假日">节假日</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">保存</button>
                    <button type="button" class="button" onclick="closeEditModal()">取消</button>
                </p>
            </form>
        </div>
    </div>

    <!-- 删除表单 -->
    <form method="post" id="deleteForm">
        <?php wp_nonce_field('isoftstone_attendance_manager_nonce'); ?>
        <input type="hidden" name="isoftstone_delete_attendance" value="1">
        <input type="hidden" id="delete_attendance_id" name="attendance_id" value="">
    </form>
    <?php
}
