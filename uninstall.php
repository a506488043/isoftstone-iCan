<?php
/**
 * 插件卸载脚本
 *
 * 当插件被删除时，此脚本会删除所有数据表和数据
 */

// 如果不是通过 WordPress 卸载，则退出
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 删除数据表
isoftstone_drop_tables();

// 删除所有用户的配置信息
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta}
    WHERE meta_key LIKE '_isoftstone_%'"
);

// 删除所有 transient 缓存
$wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_isoftstone_%'"
);

$wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_timeout_isoftstone_%'"
);
