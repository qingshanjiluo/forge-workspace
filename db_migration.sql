-- ============================================================
-- 数据库迁移（纯增量·幂等）
-- ============================================================

-- 会议聊天消息（扩展消息类型支持）
CREATE TABLE IF NOT EXISTS `meeting_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `meeting_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `content` TEXT NOT NULL,
  `message_type` VARCHAR(20) DEFAULT 'text',
  `meta_data` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_meeting` (`meeting_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `meeting_presence` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `meeting_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `last_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_meeting_user` (`meeting_id`, `user_id`),
  INDEX `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 尝试为现有 meeting_messages 表补充新列（如已存在则忽略错误）
ALTER TABLE `meeting_messages` ADD COLUMN `message_type` VARCHAR(20) DEFAULT 'text' AFTER `content`;
ALTER TABLE `meeting_messages` ADD COLUMN `meta_data` TEXT AFTER `message_type`;

-- 公共聊天室消息（独立于会议）
CREATE TABLE IF NOT EXISTS `public_chat_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `content` TEXT NOT NULL,
  `message_type` VARCHAR(20) DEFAULT 'text',
  `meta_data` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 共享文档
CREATE TABLE IF NOT EXISTS `shared_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `doc_type` VARCHAR(10) DEFAULT 'markdown',
  `user_id` INT NOT NULL,
  `last_editor_id` INT DEFAULT NULL,
  `version` INT DEFAULT 1,
  `share_token` VARCHAR(64) UNIQUE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_share` (`share_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 文档修订历史
CREATE TABLE IF NOT EXISTS `document_revisions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `content` LONGTEXT,
  `version` INT NOT NULL,
  `summary` VARCHAR(500) DEFAULT '',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_doc_version` (`document_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 公共聊天室在线状态
CREATE TABLE IF NOT EXISTS `public_chat_presence` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `last_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_pc_user` (`user_id`),
  INDEX `idx_pc_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API 认证令牌
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `label` VARCHAR(100) DEFAULT '',
  `last_used_at` TIMESTAMP NULL,
  `expires_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
