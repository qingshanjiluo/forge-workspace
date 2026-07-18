-- Forge Workspace - D1 Database Schema
-- D1 uses SQLite under the hood

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member' CHECK(role IN ('member','admin')),
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT 'medium' CHECK(priority IN ('high','medium','low')),
    progress INTEGER NOT NULL DEFAULT 0 CHECK(progress >= 0 AND progress <= 100),
    due_date TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TRIGGER IF NOT EXISTS todos_updated_at
    AFTER UPDATE ON todos
    FOR EACH ROW
BEGIN
    UPDATE todos SET updated_at = datetime('now','localtime') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS todo_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    todo_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (todo_id) REFERENCES todos(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS meetings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    participants TEXT NOT NULL DEFAULT '[]',
    meeting_time TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','ended')),
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS meeting_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    message_type TEXT NOT NULL DEFAULT 'text' CHECK(message_type IN ('text','todo','html')),
    meta_data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS meeting_presence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    last_seen TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public_chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    message_type TEXT NOT NULL DEFAULT 'text' CHECK(message_type IN ('text','todo','html')),
    meta_data TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public_chat_presence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    last_seen TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shared_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    doc_type TEXT NOT NULL DEFAULT 'text' CHECK(doc_type IN ('text','markdown','html')),
    user_id INTEGER NOT NULL,
    last_editor_id INTEGER,
    version INTEGER NOT NULL DEFAULT 1,
    share_token TEXT UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_editor_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TRIGGER IF NOT EXISTS shared_documents_updated_at
    AFTER UPDATE ON shared_documents
    FOR EACH ROW
BEGIN
    UPDATE shared_documents SET updated_at = datetime('now','localtime') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS document_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    version INTEGER NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (document_id) REFERENCES shared_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    label TEXT,
    last_used_at TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    log_date TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    is_scroll INTEGER NOT NULL DEFAULT 0 CHECK(is_scroll IN (0,1)),
    scroll_content TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TRIGGER IF NOT EXISTS announcements_updated_at
    AFTER UPDATE ON announcements
    FOR EACH ROW
BEGIN
    UPDATE announcements SET updated_at = datetime('now','localtime') WHERE id = OLD.id;
END;

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Indexes for foreign keys and commonly queried columns

CREATE INDEX IF NOT EXISTS idx_todos_user_id ON todos(user_id);
CREATE INDEX IF NOT EXISTS idx_todos_priority ON todos(priority);
CREATE INDEX IF NOT EXISTS idx_todos_due_date ON todos(due_date);
CREATE INDEX IF NOT EXISTS idx_todos_created_at ON todos(created_at);

CREATE INDEX IF NOT EXISTS idx_todo_updates_todo_id ON todo_updates(todo_id);
CREATE INDEX IF NOT EXISTS idx_todo_updates_user_id ON todo_updates(user_id);

CREATE INDEX IF NOT EXISTS idx_meetings_user_id ON meetings(user_id);
CREATE INDEX IF NOT EXISTS idx_meetings_status ON meetings(status);
CREATE INDEX IF NOT EXISTS idx_meetings_meeting_time ON meetings(meeting_time);

CREATE INDEX IF NOT EXISTS idx_meeting_messages_meeting_id ON meeting_messages(meeting_id);
CREATE INDEX IF NOT EXISTS idx_meeting_messages_user_id ON meeting_messages(user_id);
CREATE INDEX IF NOT EXISTS idx_meeting_messages_created_at ON meeting_messages(created_at);

CREATE INDEX IF NOT EXISTS idx_meeting_presence_meeting_id ON meeting_presence(meeting_id);
CREATE INDEX IF NOT EXISTS idx_meeting_presence_user_id ON meeting_presence(user_id);

CREATE INDEX IF NOT EXISTS idx_public_chat_messages_user_id ON public_chat_messages(user_id);
CREATE INDEX IF NOT EXISTS idx_public_chat_messages_created_at ON public_chat_messages(created_at);

CREATE INDEX IF NOT EXISTS idx_shared_documents_user_id ON shared_documents(user_id);
CREATE INDEX IF NOT EXISTS idx_shared_documents_last_editor_id ON shared_documents(last_editor_id);
CREATE INDEX IF NOT EXISTS idx_shared_documents_share_token ON shared_documents(share_token);
CREATE INDEX IF NOT EXISTS idx_shared_documents_updated_at ON shared_documents(updated_at);

CREATE INDEX IF NOT EXISTS idx_document_revisions_document_id ON document_revisions(document_id);
CREATE INDEX IF NOT EXISTS idx_document_revisions_user_id ON document_revisions(user_id);
CREATE INDEX IF NOT EXISTS idx_document_revisions_version ON document_revisions(version);

CREATE INDEX IF NOT EXISTS idx_auth_tokens_user_id ON auth_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_tokens_token ON auth_tokens(token);
CREATE INDEX IF NOT EXISTS idx_auth_tokens_expires_at ON auth_tokens(expires_at);

CREATE INDEX IF NOT EXISTS idx_messages_user_id ON messages(user_id);
CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at);

CREATE INDEX IF NOT EXISTS idx_logs_user_id ON logs(user_id);
CREATE INDEX IF NOT EXISTS idx_logs_log_date ON logs(log_date);

CREATE INDEX IF NOT EXISTS idx_announcements_user_id ON announcements(user_id);
CREATE INDEX IF NOT EXISTS idx_announcements_created_at ON announcements(created_at);

CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);
