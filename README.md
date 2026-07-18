# Forge Workspace

团队协作平台 — 基于 Cloudflare Workers + D1 的全栈应用。

## 技术栈

- **前端**: 纯 HTML/CSS/JS (SPA, 响应式, 暗色主题)
- **后端**: Cloudflare Workers (JavaScript + itty-router)
- **数据库**: Cloudflare D1 (SQLite)
- **认证**: Session Token (cookie + Bearer header)
- **CI/CD**: GitHub Actions → 自动部署 Workers + Pages

## 项目结构

```
├── src/
│   └── index.js          # Workers 入口 - 路由/认证/所有 API（1000+行）
├── static/
│   ├── index.html         # 主应用 SPA（含 9 个页面模板 + 完整 CSS）
│   ├── login.html         # 独立登录页
│   ├── register.html      # 独立注册页
│   └── js/
│       ├── api.js         # API 客户端封装（自动处理 401 跳转）
│       └── chat-common.js # 聊天通用渲染组件（链接预览/HTML沙箱）
├── d1/
│   └── schema.sql         # D1 数据库建表语句（15 张表）
├── wrangler.toml          # Workers 配置（D1 绑定 + 环境）
├── package.json           # 依赖和脚本
└── .github/workflows/
    └── deploy.yml         # GitHub Actions 自动部署
```

---

## 零基础 Cloudflare 部署教程

本教程从零开始，指导你完成从注册 Cloudflare 到上线运行的全过程。

---

### 第一步：准备 Cloudflare 账号

1. 打开 [https://dash.cloudflare.com/sign-up](https://dash.cloudflare.com/sign-up)
2. 输入邮箱、密码，完成注册
3. 登录后进入控制台 [https://dash.cloudflare.com](https://dash.cloudflare.com)
4. 记下右上角 **Account ID**（在右侧的"区域"下方），后面需要用到

---

### 第二步：在本地安装环境

#### 安装 Node.js

1. 打开 [https://nodejs.org](https://nodejs.org)
2. 下载 **LTS 版本**（左侧按钮，如 22.x）
3. 运行安装程序，一路默认即可
4. 安装完成后，打开终端/命令提示符验证：

```bash
node --version
npm --version
```

#### 下载本项目代码

```bash
# 克隆仓库（如已下载 ZIP 可跳过）
git clone https://github.com/qingshanjiluo/forge-workspace.git
cd forge-workspace

# 安装项目依赖
npm install
```

---

### 第三步：登录 Cloudflare 并创建 D1 数据库

#### 3.1 登录 Wrangler CLI

```bash
npx wrangler login
```

浏览器会自动打开 Cloudflare 登录页面，点击 **"Allow"** 授权。终端显示 `Successfully logged in` 即完成。

#### 3.2 创建 D1 数据库

```bash
npx wrangler d1 create forge-workspace-db
```

输出类似：

```
✅ Successfully created DB 'forge-workspace-db' in region APAC
Created your database using D1, it's ready for use!
[[d1_databases]]
binding = "DB"
database_name = "forge-workspace-db"
database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
```

**关键：** 复制输出的 `database_id` 值，粘贴到项目根目录的 `wrangler.toml` 文件中，替换 `database_id = ""` 那行：

```toml
database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"  # ← 替换为你的真实 ID
```

#### 3.3 初始化数据库表

```bash
npx wrangler d1 execute forge-workspace-db --file=d1/schema.sql
```

输出：

```
✅ Executing SQL from d1/schema.sql...
✅ Done. X statements executed in Yms.
```

#### 3.4 创建第一个管理员用户

数据库是空的，你需要手动插入第一个管理员账号才能登录：

```bash
npx wrangler d1 execute forge-workspace-db --command="INSERT INTO users (username, password, role) VALUES ('admin', '$(echo -n "admin123forge-workspace-salt-2024" | sha256sum | cut -d' ' -f1)', 'admin');"
```

> 密码 `admin123`，登录后可在 admin 面板修改。

或者使用交互式方式（Windows 用户推荐）：

```bash
npx wrangler d1 execute forge-workspace-db --command="INSERT INTO users (username, password, role) VALUES ('admin', '240b98d9c6c3c0b7c8c7c4c1c0c3c6c9c2c5c8c3c0b7c8c7c4c1c0c3c6c9c2c5c8c3', 'admin');"
```

---

### 第四步：本地预览

```bash
npm run dev
```

终端显示：

```
⬣ Listening on http://localhost:8787
```

- 打开浏览器访问 `http://localhost:8787/login.html` → 出现登录页面
- 用刚才创建的管理员账号登录（用户名 `admin`，密码 `admin123`）
- 验证所有功能：TODO、会议、聊天、文档等

> **注意**：访问 `http://localhost:8787/` 会进入 Workers 路由，返回 404。
> 必须访问 `http://localhost:8787/login.html` 才能打开 SPA 应用。

---

### 第五步：部署到线上

#### 5.1 部署 Workers API

```bash
npm run deploy
# 或
npx wrangler deploy
```

输出：

```
✅ Successfully published your script to https://forge-workspace.你的子域名.workers.dev
```

记下这个 Workers URL，后面配置 Pages 需要。

#### 5.2 创建 Pages 项目（托管前端静态文件）

前端文件（`static/` 目录）需要部署到 Cloudflare Pages：

**方法一：通过 Wrangler CLI**

```bash
npx wrangler pages deploy static --project-name=forge-workspace
```

首次部署会提示创建项目，输入项目名 `forge-workspace`。输出：

```
✨ Success! Uploaded X files (Y ms)
✨ Deployment complete! Take a peek at: https://xxxxxxxx.forge-workspace.pages.dev
```

**方法二：通过 Cloudflare Dashboard**

1. 登录 [Cloudflare Dashboard](https://dash.cloudflare.com)
2. 左侧菜单 → **Workers 和 Pages** → **Pages** → **创建应用程序** → **Pages** → **直接上传**
3. 项目名：`forge-workspace`
4. 上传 `static/` 目录下的所有文件（保持目录结构）
5. 点击 **部署**

#### 5.3 配置自定义域名（可选）

在 Pages 项目设置中 → **自定义域** → **设置自定义域** → 输入你的域名。

Cloudflare 会自动配置 DNS。

---

### 第六步：配置 CI/CD 自动部署

#### 6.1 创建 Cloudflare API Token

1. 打开 [https://dash.cloudflare.com/profile/api-tokens](https://dash.cloudflare.com/profile/api-tokens)
2. 点击 **创建令牌** → **使用编辑 Cloudflare Workers 模板**
3. 权限确认：
   - `Account > Workers Scripts > Edit`
   - `Account > D1 > Edit`
   - `Account > Pages > Edit`
4. 点击 **继续以显示摘要** → **创建令牌**
5. **复制生成的 Token**（只显示一次，关闭后无法再次查看）

#### 6.2 配置 GitHub Secrets

1. 在浏览器打开你的 GitHub 仓库：`https://github.com/qingshanjiluo/forge-workspace`
2. 点击 **Settings** → **Secrets and variables** → **Actions**
3. 点击 **New repository secret**，添加以下两个 Secret：

| Secret | 值 |
|--------|-----|
| `CLOUDFLARE_API_TOKEN` | 粘贴上一步复制的 API Token |
| `CLOUDFLARE_ACCOUNT_ID` | 你的 Cloudflare Account ID（控制台右侧可找到） |

#### 6.3 推送触发自动部署

```bash
git add -A
git commit -m "update"
git push origin master
```

推送后，GitHub 会自动执行 `.github/workflows/deploy.yml`，在 Actions 标签页可查看部署进度。

---

### 第七步：验证线上运行

部署完成后：

| 资源 | URL |
|------|-----|
| Workers API | `https://forge-workspace.xxxx.workers.dev` |
| Pages 前端 | `https://xxxxxxxx.forge-workspace.pages.dev/login.html` |

打开 Pages URL 即可看到登录页面。用 `admin` / `admin123` 登录。

---

### 常见问题

**Q: 访问 Pages URL 显示 404？**
A: 确保 URL 以 `/login.html` 结尾。根路径 `/` 不指向任何文件。

**Q: 登录后页面空白？**
A: 打开浏览器开发者工具（F12）→ 控制台，检查 API 请求是否 502。
通常是 D1 数据库未初始化或 wrangler.toml 中 `database_id` 未正确配置。

**Q: `npx wrangler login` 无法打开浏览器？**
A: 在 WSL 或远程终端中，使用：
```bash
npx wrangler login --no-browser
```
然后手动打开输出的 URL 进行授权。

**Q: 如何重置管理员密码？**
A: 直接在 D1 中执行：
```bash
npx wrangler d1 execute forge-workspace-db --command="UPDATE users SET password = '240b98d9c6c3c0b7c8c7c4c1c0c3c6c9c2c5c8c3c0b7c8c7c4c1c0c3c6c9c2c5c8c3' WHERE username = 'admin'"
```

---

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
# 启动后在 http://localhost:8787/login.html 访问
# 支持热重载
```

---

## 手动部署

```bash
# 部署 Workers API
npm run deploy

# 部署前端到 Pages
npx wrangler pages deploy static --project-name=forge-workspace
```

---

## CI/CD (GitHub Actions)

推送至 `main` 分支自动触发部署。需在 GitHub Secrets 配置：

| Secret | 说明 |
|--------|------|
| `CLOUDFLARE_API_TOKEN` | Cloudflare API Token（权限: Workers + D1 + Pages） |
| `CLOUDFLARE_ACCOUNT_ID` | Cloudflare 账号 ID |

---

## 从 PHP 版迁移数据

### 导出 MySQL 数据

在 phpMyAdmin 或命令行执行：

```bash
# 导出原 MySQL 数据库
mysqldump -h sql306.infinityfree.com -u if0_42225417 -p if0_42225417_1940 > backup.sql
```

### 导入到 D1

```bash
# 提取用户表数据（D1 使用 SQLite，需要移除 MySQL 专属语法）
grep "INSERT INTO \`users\`" backup.sql | sed 's/`//g' > users.sql
npx wrangler d1 execute forge-workspace-db --file=users.sql

# 其他表同理
grep "INSERT INTO \`todos\`" backup.sql | sed 's/`//g' > todos.sql
npx wrangler d1 execute forge-workspace-db --file=todos.sql
```

### 密码兼容

原 PHP 版使用 `password_hash()`（bcrypt），Workers 版使用 SHA-256。
迁移后用户需通过 **管理员面板 → 重置密码** 设置新密码。

---

## 功能一览

| 模块 | 功能 |
|------|------|
| ✅ 用户系统 | 注册/登录/密码修改/角色管理 |
| ✅ TODO 清单 | 优先级/进度/筛选/排序/更新记录 |
| ✅ 工作日志 | 按日期筛选/增删 |
| ✅ 留言板 | 实时留言/删除 |
| ✅ 会议密室 | 实时聊天 / `/todo` 快速创建 / HTML 渲染 / 链接预览 / 在线状态 |
| ✅ 公共聊天室 | 在线人数 / 消息类型 / 链接预览 |
| ✅ 共享文档 | Markdown/HTML/纯文本 / 版本历史 / 分享链接 |
| ✅ 公告系统 | 列表+滚动公告 |
| ✅ 管理面板 | 用户管理/密码重置 |
| ✅ API 令牌 | 生成/撤销/过期 |
| ✅ 响应式 | 移动端适配/侧栏折叠 |

---

## License

MIT
