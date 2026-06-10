<?php
/**
 * 后台查询历史页面
 */

// 添加后台菜单
add_action('admin_menu', 'isoftstone_add_history_menu');

function isoftstone_add_history_menu() {
    $page = add_users_page(
        '查询历史记录',
        '查询历史',
        'read',
        'isoftstone-attendance-history',
        'isoftstone_history_page_html'
    );

    // 移除帮助标签和屏幕选项
    add_action('load-' . $page, 'isoftstone_remove_help_tabs_history');
}

// 移除帮助标签和屏幕选项
function isoftstone_remove_help_tabs_history() {
    add_filter('screen_options_show_screen', '__return_false');
    add_filter('contextual_help', '__return_empty_array');
}

// 查询历史页面HTML
function isoftstone_history_page_html() {
    if (!current_user_can('read')) {
        return;
    }

    $user_id = get_current_user_id();
    $table = isoftstone_get_history_table();

    // 获取当前页码
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $offset = ($paged - 1) * $per_page;

    // 获取筛选条件
    $where = ["user_id = %d"];
    $where_values = [$user_id];

    $year_filter = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : '';
    if (!empty($year_filter)) {
        $where[] = "query_year = %d";
        $where_values[] = intval($year_filter);
    }

    $source_filter = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';
    if (!empty($source_filter)) {
        $where[] = "data_source = %s";
        $where_values[] = $source_filter;
    }

    $where_clause = implode(' AND ', $where);

    // 获取总数
    global $wpdb;
    $total_items = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE {$where_clause}",
        $where_values
    ));

    // 获取数据
    $records = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `{$table}`
        WHERE {$where_clause}
        ORDER BY created_at DESC
        LIMIT %d OFFSET %d",
        array_merge($where_values, [$per_page, $offset])
    ));

    // 获取统计信息（带缓存）
    $stats_cache_key = "isoftstone_history_stats_{$user_id}";
    $stats = wp_cache_get($stats_cache_key, 'isoftstone');

    if (false === $stats) {
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_queries,
                SUM(result_count) as total_records,
                SUM(total_hours) as total_hours
            FROM `{$table}`
            WHERE user_id = %d",
            $user_id
        ));
        // 缓存 1 小时
        wp_cache_set($stats_cache_key, $stats, 'isoftstone', HOUR_IN_SECONDS);
    }

    // 获取年份列表（带缓存）
    $years_cache_key = "isoftstone_history_years_{$user_id}";
    $years = wp_cache_get($years_cache_key, 'isoftstone');

    if (false === $years) {
        $years = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT query_year
            FROM `{$table}`
            WHERE user_id = %d
            ORDER BY query_year DESC",
            $user_id
        ));
        // 缓存 1 小时
        wp_cache_set($years_cache_key, $years, 'isoftstone', HOUR_IN_SECONDS);
    }

    ?>
    <div class="wrap">
        <h1>查询历史记录</h1>

        <!-- 统计卡片 -->
        <div class="isoftstone-stats-grid">
            <div class="isoftstone-stat-card">
                <div class="isoftstone-stat-label">总查询次数</div>
                <div class="isoftstone-stat-value"><?php echo number_format($stats->total_queries); ?></div>
            </div>
            <div class="isoftstone-stat-card">
                <div class="isoftstone-stat-label">查询记录总数</div>
                <div class="isoftstone-stat-value"><?php echo number_format($stats->total_records); ?></div>
            </div>
            <div class="isoftstone-stat-card">
                <div class="isoftstone-stat-label">累计工时</div>
                <div class="isoftstone-stat-value"><?php echo number_format($stats->total_hours, 1); ?> 小时</div>
            </div>
        </div>

        <!-- 筛选表单 -->
        <div class="isoftstone-filter-box">
            <form method="get">
                <input type="hidden" name="page" value="isoftstone-attendance-history">

                <select name="year">
                    <option value="">全部年份</option>
                    <?php foreach ($years as $y) : ?>
                        <option value="<?php echo esc_attr($y); ?>" <?php selected($year_filter, $y); ?>>
                            <?php echo esc_html($y); ?>年
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="source">
                    <option value="">全部来源</option>
                    <option value="api" <?php selected($source_filter, 'api'); ?>>API查询</option>
                    <option value="database" <?php selected($source_filter, 'database'); ?>>数据库缓存</option>
                </select>

                <button type="submit" class="button">筛选</button>
                <a href="<?php echo esc_url(remove_query_arg(['year', 'source'])); ?>" class="button">重置</a>
            </form>
        </div>

        <!-- 数据表格 -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>查询时间</th>
                    <th>工号</th>
                    <th>查询月份</th>
                    <th>记录数</th>
                    <th>总工时</th>
                    <th>数据来源</th>
                    <th>IP地址</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)) : ?>
                    <tr>
                        <td colspan="7">暂无查询记录</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($records as $record) : ?>
                        <tr>
                            <td><?php echo esc_html($record->created_at); ?></td>
                            <td><?php echo esc_html($record->empno); ?></td>
                            <td><?php echo esc_html($record->query_year); ?>年<?php echo esc_html($record->query_month); ?>月</td>
                            <td><?php echo esc_html($record->result_count); ?> 天</td>
                            <td><?php echo esc_html($record->total_hours); ?> 小时</td>
                            <td>
                                <?php if ($record->data_source === 'api') : ?>
                                    <span class="isoftstone-source-badge isoftstone-source-api">API</span>
                                <?php else : ?>
                                    <span class="isoftstone-source-badge isoftstone-source-database">缓存</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($record->ip_address); ?></td>
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
    <?php
}
