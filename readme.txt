=== 中软考勤查询系统 ===
Contributors: saiita
Tags: attendance, isoftstone, 考勤, 查询
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

中软国际员工考勤查询系统，支持后台配置认证信息，前端查询考勤数据。

== 描述 ==

本插件为中软国际员工提供便捷的考勤查询功能。

主要特性：
* 后台配置员工工号、Cookie、Token等认证信息
* 前端通过短代码显示考勤查询表单
* 日历视图展示每日考勤数据
* 自动计算累计工时和出勤天数
* AES-256加密存储敏感信息
* 支持数据缓存，提升查询速度

== 安装 ==

1. 上传插件文件到 `/wp-content/plugins/isoftstone-attendance` 目录
2. 在 WordPress 后台"插件"菜单激活插件
3. 进入"用户 > 考勤查询设置"配置认证信息
4. 在任意页面使用短代码 `[isoftstone_attendance]` 显示查询表单

== 常见问题 ==

= 如何获取认证信息？ =

1. 打开中软国际iCan系统并登录
2. 按F12打开浏览器开发者工具
3. 切换到 Network（网络）标签
4. 刷新页面，找到任意请求
5. 在请求头中复制Cookie和Refreshtoken字段

= 查询提示认证已过期怎么办？ =

需要重新登录iCan系统并更新Cookie和Token信息。

= 数据会保存多长时间？ =

考勤数据缓存时间为1小时，过期后自动重新获取。

== 升级日志 ==

= 1.0.0 =
* 首次发布
