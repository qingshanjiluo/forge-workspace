# Forge Workspace

团队协作平台 — 基于 Cloudflare Workers + D1 的全栈应用。

## 技术栈

- **前端**: 纯 HTML/CSS/JS (SPA, 响应式, 暗色主题)
- **后端**: Cloudflare Workers (JavaScript + itty-router)
- **数据库**: Cloudflare D1 (SQLite)
- **认证**: JWT Token (cookie + Bearer header)

## 项目结构

```
├── src/
│   └── index.js          # Workers 入口 - 路由/认证/所有 API
├── static/
│   ├── index.html         # 主应用 SPA (含所有页面模板)
│   ├── login.html         # 登录页
│   ├── register.html      # 注册页
│   └── js/
│       ├── api.js         # API 客户端封装
│       └── chat-common.js  # 聊天通用渲染组件
├── d1/
│   └── schema.sql         # D1 数据库建表语句
├── wrangler.toml          # Cloudflare Workers 配置
├── package.json           # 依赖和脚本
└── .github/workflows/
    └── deploy.yml         # CI/CD 自动部署
```

## 本地开发

### 前置要求

- Node.js 18+
- npm 或 yarn
- Cloudflare 账号
- Wrangler CLI

### 安装

```bash
# 安装依赖
npm install

# 登录 Cloudflare
npx wrangler login

# 创建 D1 数据库
npx wrangler d1 create forge-workspace-db
# 记录输出的 database_id，更新到 wrangler.toml

# 初始化数据库表
npx wrangler d1 execute forge-workspace-db --file=d1/schema.sql

# 本地开发
npm run dev
```

### 开发模式

```bash
npm run dev
# 启动后在 http://localhost:8787 访问
# 支持热重载
```

## 部署

### 手动部署

```bash
# 部署 Workers API
npm run deploy

# 创建预览数据库（可选）
npx wrangler d1 create forge-workspace-db-preview
npx wrangler d1 execute forge-workspace-db-preview --file=d1/schema.sql
```

### CI/CD (GitHub Actions)

推送至 `main` 分支自动触发部署。需在 GitHub Secrets 配置：

| Secret | 说明 |
|--------|------|
| `CLOUDFLARE_API_TOKEN` | Cloudflare API Token (权限: Workers + D1) |
| `CLOUDFLARE_ACCOUNT_ID` | Cloudflare 账号 ID |

## 从 PHP 版迁移数据

### 导出 MySQL 数据

在 phpMyAdmin 或命令行执行：

```bash
# 导出原 MySQL 数据库
mysqldump -h sql306.infinityfree.com -u if0_42225417 -p if0_42225417_1940 > backup.sql
```

### 导入到 D1

```bash
# 转换并导入（D1 使用 SQLite，需要调整语法）
# 推荐分批导入核心表：

# 1. 导入用户
cat backup.sql | grep "INSERT INTO \`users\`" | sed 's/`//g' > users.sql
npx wrangler d1 execute forge-workspace-db --file=users.sql

# 2. 导入其他表（同样方式）
# 注意：D1 不支持 ENGINE=InnoDB/CHARSET/ON UPDATE CURRENT_TIMESTAMP，
# 这些已在 schema.sql 中处理。导出数据时自动忽略表结构。
```

### 手动迁移核心数据

1. 用户 (`users`): 必需迁移，否则无法登录
2. TODO 清单 (`todos`, `todo_updates`): 可选
3. 会议 (`meetings`, `meeting_messages`, `meeting_presence`): 可选
4. 共享文档 (`shared_documents`, `document_revisions`): 可选
5. 留言 (`messages`): 可选
6. 日志 (`logs`): 可选
7. 公告 (`announcements`): 可选
8. 公共聊天 (`public_chat_messages`, `public_chat_presence`): 可选
9. API 令牌 (`auth_tokens`): 可选

### 密码兼容

原 PHP 版使用 `password_hash()` (bcrypt)，Workers 版使用 SHA-256。
迁移后用户需通过「重置密码」功能设置新密码，或管理员在 admin 面板重置。

## 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `JWT_SECRET` | Token 签名密钥 | 自动生成 |
| `SESSION_EXPIRY` | Session 过期时间(小时) | 72 |

## 功能列表

- [x] 用户注册/登录/密码修改
- [x] TODO 清单 (优先级/进度/筛选/排序)
- [x] 工作日志 (按日期筛选)
- [x] 留言板
- [x] 会议密室 (实时聊天 + /todo 快速创建)
- [x] 公共聊天室 (在线状态/链接预览)
- [x] 共享文档 (Markdown/HTML/纯文本/版本历史)
- [x] 公告 (滚动公告栏)
- [x] 管理员面板 (用户管理)
- [x] API 认证令牌
- [x] 响应式移动端适配

## License

MIT
