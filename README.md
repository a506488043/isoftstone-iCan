# 软通考勤查询系统

## 插件信息

- **插件名称**: 软通考勤查询系统
- **版本**: 1.1.2
- **作者**: Saiita
- **网站**: https://www.saiita.com.cn
- **WordPress 要求**: 5.0+
- **PHP 要求**: 7.4+

## 功能特点

### 🎯 核心功能

1. **考勤数据查询**
   - 支持按年月查询考勤记录
   - 美观的日历视图展示
   - 实时统计数据（累计工时、出勤天数、日均工时）
   - 工时颜色标识（正常/轻度加班/重度加班）

2. **数据库存储**
   - 自动保存考勤数据到数据库
   - 智能表结构检测与自动修复
   - 支持数据持久化和历史追溯

3. **认证管理**
   - Cookie 和 RefreshToken 加密存储
   - 连接测试功能
   - 认证过期自动检测
   - 友好的过期提示

4. **数据管理**
   - 后台考勤数据管理
   - 查询历史记录
   - 数据库诊断工具（WP_DEBUG 模式）

### 🔒 安全特性

- ✅ 敏感数据 AES-256 加密存储
- ✅ WordPress Nonce 验证防护
- ✅ SQL 注入防护（prepare 语句）
- ✅ 用户权限检查
- ✅ 输入数据验证和清理
- ✅ 错误日志记录（WP_DEBUG 模式）

### 🛠️ 技术特性

- **智能数据库修复**: 表结构不完整时自动重建
- **双重保存机制**: 保存失败自动重试
- **详细错误提示**: HTTP 状态码 + API Code
- **认证过期检测**: 多关键词、多错误码识别
- **数据库诊断**: 自动检测表结构和数据完整性

## 安装说明

### 自动安装

1. 登录 WordPress 后台
2. 进入"插件 → 安装插件"
3. 上传 `isoftstone-attendance.zip`
4. 点击"启用插件"
5. 数据表会自动创建

### 手动安装

1. 解压缩插件到 `wp-content/plugins/isoftstone-attendance/`
2. 在 WordPress 后台启用插件
3. 数据表会自动创建

## 使用指南

### 前端查询

1. **创建页面**
   - 新建 WordPress 页面
   - 添加短代码: `[isoftstone_attendance]`
   - 发布页面

2. **配置认证信息**
   - 登录 WordPress
   - 进入"用户 → 考勤查询"
   - 填写员工工号、Cookie 和 RefreshToken
   - 点击"测试连接"验证

3. **查询考勤**
   - 在页面选择年月
   - 点击"查询考勤"按钮
   - 查看日历视图和统计数据

### 后台管理

1. **考勤查询设置** (`用户 → 考勤查询`)
   - 配置员工工号
   - 配置认证 Cookie
   - 配置 RefreshToken
   - 测试连接
   - 查看系统状态

2. **考勤数据管理** (`用户 → 考勤管理`)
   - 查看所有考勤记录
   - 添加/编辑/删除记录
   - 按月份筛选

3. **查询历史** (`用户 → 查询历史`)
   - 查看所有查询记录
   - 统计总查询次数
   - 按年份/数据来源筛选

## 获取认证信息

### 方法一：浏览器开发者工具

1. 登录软通考勤系统
2. 按 F12 打开开发者工具
3. 切换到"Network"（网络）标签
4. 刷新页面或执行考勤查询
5. 找到考勤接口请求
6. 查看请求头:
   - 复制完整的 `Cookie` 字段
   - 复制 `Refreshtoken` 字段值

### 方法二：使用说明

推荐使用 Chrome 或 Edge 浏览器的开发者工具。

## 常见问题

### Q: 认证已过期怎么办？

**A**: 出现"认证已过期"提示时：

1. 重新登录软通考勤系统
2. 使用开发者工具获取新的 Cookie 和 Token
3. 进入"用户 → 考勤查询"更新配置
4. 点击"测试连接"验证

### Q: 数据无法保存到数据库？

**A**: 可能的原因和解决方法：

1. **表结构问题**: 插件会自动检测并修复
2. **数据库权限**: 检查数据库用户是否有 CREATE/ALTER 权限
3. **查看详细错误**: 启用 WP_DEBUG 模式查看错误日志

### Q: 如何查看详细错误信息？

**A**: 在 `wp-config.php` 中启用调试模式：

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

错误日志会保存到 `wp-content/debug.log`

### Q: 如何备份和恢复数据？

**A**:

- **备份**: 定期备份 WordPress 数据库
- **恢复**: 插件会自动重新创建表并从 API 获取数据

## 系统要求

- **WordPress**: 5.0 或更高版本
- **PHP**: 7.4 或更高版本
- **MySQL**: 5.6 或更高版本
- **PHP 扩展**:
  - OpenSSL (用于加密)
  - mbstring (用于字符串处理)

## 数据库表结构

### 考勤数据表 (`wp_isoftstone_attendance`)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint(20) | 主键ID |
| user_id | bigint(20) | 用户ID |
| empno | varchar(20) | 员工工号 |
| attendance_date | date | 考勤日期 |
| work_hours | decimal(5,2) | 工作时长 |
| status | varchar(50) | 考勤状态 |
| datetype | varchar(50) | 日期类型（工作日/节假日） |
| data_source | varchar(20) | 数据来源 |
| original_data | text | 原始数据JSON |
| created_at | datetime | 创建时间 |
| updated_at | datetime | 更新时间 |

**索引**:
- PRIMARY: `id`
- UNIQUE: `user_id` + `empno` + `attendance_date`
- INDEX: `empno`, `attendance_date`, `user_id`, `datetype`

### 查询历史表 (`wp_isoftstone_query_history`)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint(20) | 主键ID |
| user_id | bigint(20) | 用户ID |
| empno | varchar(20) | 员工工号 |
| query_year | int(4) | 查询年份 |
| query_month | int(2) | 查询月份 |
| result_count | int(11) | 结果数量 |
| total_hours | decimal(8,2) | 总工时 |
| data_source | varchar(20) | 数据来源 |
| ip_address | varchar(45) | IP地址 |
| user_agent | varchar(255) | 用户代理 |
| created_at | datetime | 查询时间 |

## 更新日志

### v1.1.2 (2026-03-27)

**Bug 修复**:
- 🐛 修复 Cookie/Token 过期检测不准确的问题
- 🐛 修复考勤数据无法存入数据库的问题
- 🐛 修复优先从数据库读取导致不同步的问题

**改进**:
- ✨ 扩展过期检测关键词（未登录、会话、session 等）
- ✨ 新增数据库诊断功能
- ✨ 增强表结构完整性检查
- ✨ 添加自动表重建机制
- ✨ 改进错误提示信息（显示 HTTP 状态码和 API Code）
- ✨ 添加数据库保存警告提示

**技术细节**:
- 优先从 API 获取最新数据
- 双重保存机制（保存失败自动重建表并重试）
- 详细的调试日志记录
- 友好的用户错误提示

### v1.1.1 (2026-01-30)

- ✨ 新增 MySQL 数据库存储功能
- ✨ 新增考勤数据管理页面
- ✨ 新增查询历史记录页面
- 🐛 修复数据格式转换问题

### v1.0.0

- 🎉 初始版本发布
- ✨ 基础考勤查询功能
- ✨ 后台配置认证信息

## 卸载说明

删除插件时会自动：
- ✅ 删除所有数据表
- ✅ 删除所有用户配置
- ✅ 删除所有缓存数据

**⚠️ 警告**: 删除操作无法撤销，请提前备份数据！

## 技术支持

- **作者**: Saiita
- **网站**: https://www.saiita.com.cn
- **Bug 反馈**: 请在 GitHub Issues 提交

## 许可证

GPL v2 或更高版本

## 致谢

感谢所有测试用户提供的反馈和建议！
