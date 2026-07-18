import { Router, cors, error, json } from 'itty-router';

const { preflight, corsify } = cors({
  origins: ['*'],
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  credentials: true,
});

function jsonResponse(data, status = 200) {
  return json(data, status);
}

function errorResponse(message, status = 400) {
  return error(status, message);
}

async function hashPassword(password) {
  const encoder = new TextEncoder();
  const data = encoder.encode(password + 'forge-workspace-salt-2024');
  const hash = await crypto.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function getSessionUser(request, env) {
  const authHeader = request.headers.get('Authorization');
  let token = null;
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.slice(7);
  } else {
    const cookie = request.headers.get('Cookie');
    if (cookie) {
      const match = cookie.match(/(?:^|;\s*)token=([^;]+)/);
      if (match) token = match[1];
    }
  }
  if (!token) return null;
  const { results } = await env.DB.prepare(
    `SELECT u.id, u.username, u.role, u.display_name, u.email, u.avatar_url
     FROM sessions s JOIN users u ON s.user_id = u.id
     WHERE s.token = ?
     AND (s.expires_at IS NULL OR s.expires_at > datetime('now','localtime'))`
  ).bind(token).all();
  return results.length > 0 ? results[0] : null;
}

async function requireAuth(request, env) {
  const user = await getSessionUser(request, env);
  if (!user) throw { status: 401, message: 'Unauthorized' };
  return user;
}

function requireAdmin(user) {
  if (!user || user.role !== 'admin') throw { status: 403, message: 'Forbidden: Admin only' };
}

function extractPagination(url) {
  const u = new URL(url);
  const before_id = u.searchParams.get('before_id') || null;
  const limit = Math.min(parseInt(u.searchParams.get('limit') || '50', 10), 100);
  return { before_id, limit };
}

const router = Router();

router.all('*', preflight);

router.get('/api/status', () => {
  return jsonResponse({ status: 'ok', version: '1.0.0', time: new Date().toISOString() });
});

router.get('/api/auth/me', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    return jsonResponse({ success: true, user });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/auth/login', async (request, env) => {
  try {
    const { username, password } = await request.json();
    if (!username || !password) return errorResponse('Username and password required', 400);
    const hashed = await hashPassword(password);
    const { results } = await env.DB.prepare(
      'SELECT id, username, role, display_name, email, avatar_url FROM users WHERE username = ? AND password_hash = ?'
    ).bind(username, hashed).all();
    if (results.length === 0) return errorResponse('Invalid username or password', 401);
    const user = results[0];
    const tokenBytes = new Uint8Array(32);
    crypto.getRandomValues(tokenBytes);
    const token = Array.from(tokenBytes).map(b => b.toString(16).padStart(2, '0')).join('');
    const expiresAt = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString();
    await env.DB.prepare(
      'INSERT INTO sessions (token, user_id, expires_at, created_at) VALUES (?, ?, ?, datetime(\'now\',\'localtime\'))'
    ).bind(token, user.id, expiresAt).run();
    return jsonResponse({ success: true, user, token });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/auth/register', async (request, env) => {
  try {
    const { username, password, display_name } = await request.json();
    if (!username || !password) return errorResponse('Username and password required', 400);
    if (username.length < 3) return errorResponse('Username too short (min 3)', 400);
    if (password.length < 6) return errorResponse('Password too short (min 6)', 400);
    const existing = await env.DB.prepare('SELECT id FROM users WHERE username = ?').bind(username).all();
    if (existing.results.length > 0) return errorResponse('Username already taken', 409);
    const hashed = await hashPassword(password);
    await env.DB.prepare(
      'INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)'
    ).bind(username, hashed, display_name || username, 'user').run();
    return jsonResponse({ success: true, message: 'User registered' }, 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/auth/logout', async (request, env) => {
  try {
    const authHeader = request.headers.get('Authorization');
    let token = null;
    if (authHeader && authHeader.startsWith('Bearer ')) {
      token = authHeader.slice(7);
    } else {
      const cookie = request.headers.get('Cookie');
      if (cookie) {
        const match = cookie.match(/(?:^|;\s*)token=([^;]+)/);
        if (match) token = match[1];
      }
    }
    if (token) {
      await env.DB.prepare('DELETE FROM sessions WHERE token = ?').bind(token).run();
    }
    return jsonResponse({ success: true });
  } catch (e) {
    return jsonResponse({ success: true });
  }
});

router.put('/api/auth/password', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { old_password, new_password } = await request.json();
    if (!old_password || !new_password) return errorResponse('Old and new password required', 400);
    if (new_password.length < 6) return errorResponse('New password too short (min 6)', 400);
    const oldHashed = await hashPassword(old_password);
    const check = await env.DB.prepare('SELECT id FROM users WHERE id = ? AND password_hash = ?').bind(user.id, oldHashed).all();
    if (check.results.length === 0) return errorResponse('Current password is incorrect', 401);
    const newHashed = await hashPassword(new_password);
    await env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(newHashed, user.id).run();
    await env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(user.id).run();
    return jsonResponse({ success: true, message: 'Password changed' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/todos/count', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      `SELECT COUNT(*) as total, COALESCE(AVG(progress), 0) as avg_progress FROM todos WHERE user_id = ?`
    ).bind(user.id).all();
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/todos', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const url = new URL(request.url);
    const filter = url.searchParams.get('filter') || 'all';
    const sort = url.searchParams.get('sort') || 'created_at';
    const order = url.searchParams.get('order') || 'desc';
    const allowedSort = ['created_at', 'priority', 'due_date'].includes(sort) ? sort : 'created_at';
    const allowedOrder = order === 'asc' ? 'ASC' : 'DESC';
    let query = 'SELECT id, title, priority, progress, status, due_date, created_at, updated_at FROM todos WHERE user_id = ?';
    const params = [user.id];
    if (filter === 'active') {
      query += ' AND status = \'active\'';
    } else if (filter === 'done') {
      query += ' AND status = \'done\'';
    }
    query += ` ORDER BY ${allowedSort} ${allowedOrder}`;
    const { results } = await env.DB.prepare(query).bind(...params).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/todos', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { title, priority, due_date } = await request.json();
    if (!title || title.trim().length === 0) return errorResponse('Title required', 400);
    const sanitizedTitle = title.trim().substring(0, 500);
    const p = priority || 'medium';
    const allowedP = ['low', 'medium', 'high', 'urgent'].includes(p) ? p : 'medium';
    const { results } = await env.DB.prepare(
      `INSERT INTO todos (user_id, title, priority, due_date, status, progress, created_at, updated_at)
       VALUES (?, ?, ?, ?, 'active', 0, datetime('now','localtime'), datetime('now','localtime'))
       RETURNING id, title, priority, progress, status, due_date, created_at`
    ).bind(user.id, sanitizedTitle, allowedP, due_date || null).all();
    return jsonResponse(results[0], 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.put('/api/todos/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { title, priority, progress, due_date, status } = await request.json();
    const existing = await env.DB.prepare('SELECT * FROM todos WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Todo not found', 404);
    const todo = existing.results[0];
    const newTitle = title !== undefined ? title.trim().substring(0, 500) : todo.title;
    const newPriority = priority !== undefined ? (['low', 'medium', 'high', 'urgent'].includes(priority) ? priority : todo.priority) : todo.priority;
    const newProgress = progress !== undefined ? Math.max(0, Math.min(100, Number(progress))) : todo.progress;
    const newDueDate = due_date !== undefined ? due_date : todo.due_date;
    const newStatus = status !== undefined ? (['active', 'done', 'archived'].includes(status) ? status : todo.status) : todo.status;
    await env.DB.prepare(
      `UPDATE todos SET title = ?, priority = ?, progress = ?, due_date = ?, status = ?, updated_at = datetime('now','localtime')
       WHERE id = ? AND user_id = ?`
    ).bind(newTitle, newPriority, newProgress, newDueDate, newStatus, id, user.id).run();
    const { results } = await env.DB.prepare('SELECT * FROM todos WHERE id = ?').bind(id).all();
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/todos/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM todos WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Todo not found', 404);
    await env.DB.prepare('DELETE FROM todo_updates WHERE todo_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM todos WHERE id = ? AND user_id = ?').bind(id, user.id).run();
    return jsonResponse({ success: true, message: 'Todo deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/todos/:id/updates', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM todos WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Todo not found', 404);
    const { results } = await env.DB.prepare(
      'SELECT id, content, created_at FROM todo_updates WHERE todo_id = ? ORDER BY created_at DESC'
    ).bind(id).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/todos/:id/updates', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { content } = await request.json();
    if (!content || content.trim().length === 0) return errorResponse('Content required', 400);
    const existing = await env.DB.prepare('SELECT id FROM todos WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Todo not found', 404);
    const { results } = await env.DB.prepare(
      `INSERT INTO todo_updates (todo_id, user_id, content, created_at)
       VALUES (?, ?, ?, datetime('now','localtime'))
       RETURNING id, content, created_at`
    ).bind(id, user.id, content.trim().substring(0, 2000)).all();
    return jsonResponse(results[0], 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/meetings', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      `SELECT m.id, m.title, m.description, m.meeting_time, m.is_finished, m.participants,
              m.created_by, m.created_at, u.username as creator_name
       FROM meetings m JOIN users u ON m.created_by = u.id
       ORDER BY m.meeting_time DESC, m.created_at DESC`
    ).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/meetings', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { title, description, participants, meeting_time } = await request.json();
    if (!title || title.trim().length === 0) return errorResponse('Title required', 400);
    const participantsJson = participants ? JSON.stringify(participants) : '[]';
    const { results } = await env.DB.prepare(
      `INSERT INTO meetings (title, description, participants, meeting_time, created_by, created_at)
       VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))
       RETURNING id, title, description, participants, meeting_time, created_by, created_at`
    ).bind(title.trim().substring(0, 300), description || null, participantsJson, meeting_time || null, user.id).all();
    return jsonResponse(results[0], 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.put('/api/meetings/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { title, description, participants, meeting_time, is_finished } = await request.json();
    const existing = await env.DB.prepare('SELECT * FROM meetings WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Meeting not found', 404);
    const meet = existing.results[0];
    if (meet.created_by !== user.id && user.role !== 'admin') return errorResponse('Forbidden', 403);
    const newTitle = title !== undefined ? title.trim().substring(0, 300) : meet.title;
    const newDesc = description !== undefined ? description : meet.description;
    const newParts = participants !== undefined ? JSON.stringify(participants) : meet.participants;
    const newTime = meeting_time !== undefined ? meeting_time : meet.meeting_time;
    const newFinished = is_finished !== undefined ? (is_finished ? 1 : 0) : meet.is_finished;
    await env.DB.prepare(
      `UPDATE meetings SET title = ?, description = ?, participants = ?, meeting_time = ?, is_finished = ?
       WHERE id = ?`
    ).bind(newTitle, newDesc, newParts, newTime, newFinished, id).run();
    const { results } = await env.DB.prepare('SELECT * FROM meetings WHERE id = ?').bind(id).all();
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/meetings/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT * FROM meetings WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Meeting not found', 404);
    const meet = existing.results[0];
    if (meet.created_by !== user.id && user.role !== 'admin') return errorResponse('Forbidden', 403);
    await env.DB.prepare('DELETE FROM meeting_chat WHERE meeting_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM meeting_presence WHERE meeting_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM meetings WHERE id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'Meeting deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/meetings/:id/chat', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { before_id, limit } = extractPagination(request.url);
    const existing = await env.DB.prepare('SELECT id FROM meetings WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Meeting not found', 404);
    let query = `SELECT mc.id, mc.content, mc.message_type, mc.meta_data, mc.created_at,
                        u.id as user_id, u.username, u.display_name, u.avatar_url
                 FROM meeting_chat mc JOIN users u ON mc.user_id = u.id
                 WHERE mc.meeting_id = ?`;
    const params = [id];
    if (before_id) {
      query += ' AND mc.id < ?';
      params.push(before_id);
    }
    query += ' ORDER BY mc.id DESC LIMIT ?';
    params.push(limit);
    const { results } = await env.DB.prepare(query).bind(...params).all();
    return jsonResponse(results.reverse());
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/meetings/:id/chat', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    let { content, message_type, meta_data } = await request.json();
    if (!content || content.trim().length === 0) return errorResponse('Content required', 400);
    const existing = await env.DB.prepare('SELECT id FROM meetings WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Meeting not found', 404);
    content = content.trim().substring(0, 5000);
    const mt = message_type || 'text';
    const md = meta_data ? JSON.stringify(meta_data) : null;
    const { results } = await env.DB.prepare(
      `INSERT INTO meeting_chat (meeting_id, user_id, content, message_type, meta_data, created_at)
       VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))
       RETURNING id, content, message_type, meta_data, created_at`
    ).bind(id, user.id, content, mt, md).all();
    const msg = results[0];
    let todoCreated = null;
    if (mt === 'todo' || content.startsWith('/todo ')) {
      const todoTitle = content.replace('/todo ', '').trim();
      if (todoTitle.length > 0) {
        const todoResult = await env.DB.prepare(
          `INSERT INTO todos (user_id, title, priority, status, progress, created_at, updated_at)
           VALUES (?, ?, 'medium', 'active', 0, datetime('now','localtime'), datetime('now','localtime'))
           RETURNING id, title`
        ).bind(user.id, todoTitle.substring(0, 500)).all();
        todoCreated = todoResult.results[0];
      }
    }
    return jsonResponse({ message: msg, todo: todoCreated }, 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/meetings/:id/presence', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { results } = await env.DB.prepare(
      `SELECT mp.user_id, u.username, u.display_name, u.avatar_url, mp.last_seen
       FROM meeting_presence mp JOIN users u ON mp.user_id = u.id
       WHERE mp.meeting_id = ? AND mp.last_seen > datetime('now','localtime', '-30 seconds')
       ORDER BY mp.last_seen DESC`
    ).bind(id).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/meetings/:id/presence', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    await env.DB.prepare(
      `INSERT INTO meeting_presence (meeting_id, user_id, last_seen)
       VALUES (?, ?, datetime('now','localtime'))
       ON CONFLICT(meeting_id, user_id) DO UPDATE SET last_seen = datetime('now','localtime')`
    ).bind(id, user.id).run();
    return jsonResponse({ success: true });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/chat', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { before_id, limit } = extractPagination(request.url);
    let query = `SELECT mc.id, mc.content, mc.message_type, mc.meta_data, mc.created_at,
                        u.id as user_id, u.username, u.display_name, u.avatar_url
                 FROM chat_messages mc JOIN users u ON mc.user_id = u.id
                 WHERE 1=1`;
    const params = [];
    if (before_id) {
      query += ' AND mc.id < ?';
      params.push(before_id);
    }
    query += ' ORDER BY mc.id DESC LIMIT ?';
    params.push(limit);
    const { results } = await env.DB.prepare(query).bind(...params).all();
    return jsonResponse(results.reverse());
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/chat', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    let { content, message_type, meta_data } = await request.json();
    if (!content || content.trim().length === 0) return errorResponse('Content required', 400);
    content = content.trim().substring(0, 5000);
    const mt = message_type || 'text';
    const md = meta_data ? JSON.stringify(meta_data) : null;
    const { results } = await env.DB.prepare(
      `INSERT INTO chat_messages (user_id, content, message_type, meta_data, created_at)
       VALUES (?, ?, ?, ?, datetime('now','localtime'))
       RETURNING id, content, message_type, meta_data, created_at`
    ).bind(user.id, content, mt, md).all();
    const msg = results[0];
    let todoCreated = null;
    if (content.startsWith('/todo ')) {
      const todoTitle = content.replace('/todo ', '').trim();
      if (todoTitle.length > 0) {
        const todoResult = await env.DB.prepare(
          `INSERT INTO todos (user_id, title, priority, status, progress, created_at, updated_at)
           VALUES (?, ?, 'medium', 'active', 0, datetime('now','localtime'), datetime('now','localtime'))
           RETURNING id, title`
        ).bind(user.id, todoTitle.substring(0, 500)).all();
        todoCreated = todoResult.results[0];
      }
    }
    return jsonResponse({ message: msg, todo: todoCreated }, 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/chat/presence', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      `SELECT cp.user_id, u.username, u.display_name, u.avatar_url, cp.last_seen
       FROM chat_presence cp JOIN users u ON cp.user_id = u.id
       WHERE cp.last_seen > datetime('now','localtime', '-30 seconds')
       ORDER BY cp.last_seen DESC`
    ).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/chat/presence', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    await env.DB.prepare(
      `INSERT INTO chat_presence (user_id, last_seen)
       VALUES (?, datetime('now','localtime'))
       ON CONFLICT(user_id) DO UPDATE SET last_seen = datetime('now','localtime')`
    ).bind(user.id).run();
    return jsonResponse({ success: true });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/documents', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      `SELECT id, title, doc_type, summary, created_at, updated_at, version
       FROM documents WHERE user_id = ? ORDER BY updated_at DESC`
    ).bind(user.id).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/documents', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id, title, content, doc_type, summary } = await request.json();
    if (!title || title.trim().length === 0) return errorResponse('Title required', 400);
    const sanitizedTitle = title.trim().substring(0, 300);
    const dt = doc_type || 'markdown';
    if (id) {
      const existing = await env.DB.prepare('SELECT * FROM documents WHERE id = ? AND user_id = ?').bind(id, user.id).all();
      if (existing.results.length === 0) return errorResponse('Document not found', 404);
      const doc = existing.results[0];
      const newVersion = (doc.version || 0) + 1;
      const newSummary = summary !== undefined ? summary : doc.summary;
      const newContent = content !== undefined ? content : doc.content;
      await env.DB.prepare(
        `UPDATE documents SET title = ?, content = ?, doc_type = ?, summary = ?, version = ?, updated_at = datetime('now','localtime')
         WHERE id = ? AND user_id = ?`
      ).bind(sanitizedTitle, newContent, dt, newSummary, newVersion, id, user.id).run();
      if (content !== undefined) {
        await env.DB.prepare(
          `INSERT INTO document_revisions (document_id, version, content, summary, created_by, created_at)
           VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))`
        ).bind(id, newVersion, content, newSummary || null, user.id).run();
      }
      const { results } = await env.DB.prepare('SELECT * FROM documents WHERE id = ?').bind(id).all();
      return jsonResponse(results[0]);
    } else {
      const { results } = await env.DB.prepare(
        `INSERT INTO documents (user_id, title, content, doc_type, summary, version, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, datetime('now','localtime'), datetime('now','localtime'))
         RETURNING id, title, content, doc_type, summary, version, created_at`
      ).bind(user.id, sanitizedTitle, content || '', dt, summary || null).all();
      const doc = results[0];
      await env.DB.prepare(
        `INSERT INTO document_revisions (document_id, version, content, summary, created_by, created_at)
         VALUES (?, 1, ?, ?, ?, datetime('now','localtime'))`
      ).bind(doc.id, content || '', summary || null, user.id).run();
      return jsonResponse(doc, 201);
    }
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/documents/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { results } = await env.DB.prepare(
      `SELECT d.*, u.username as owner_name
       FROM documents d JOIN users u ON d.user_id = u.id
       WHERE d.id = ? AND (d.user_id = ? OR ? = 'admin')`
    ).bind(id, user.id, user.role).all();
    if (results.length === 0) return errorResponse('Document not found', 404);
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/documents/share/:token', async (request, env) => {
  try {
    const { token } = request.params;
    const { results } = await env.DB.prepare(
      `SELECT d.id, d.title, d.content, d.doc_type, d.summary, d.updated_at, u.username as owner_name
       FROM documents d JOIN users u ON d.user_id = u.id
       WHERE d.share_token = ?`
    ).bind(token).all();
    if (results.length === 0) return errorResponse('Document not found or share token invalid', 404);
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/documents/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM documents WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Document not found', 404);
    await env.DB.prepare('DELETE FROM document_revisions WHERE document_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM documents WHERE id = ? AND user_id = ?').bind(id, user.id).run();
    return jsonResponse({ success: true, message: 'Document deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/documents/:id/share', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { action } = await request.json();
    const existing = await env.DB.prepare('SELECT id FROM documents WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Document not found', 404);
    if (action === 'generate') {
      const tokenBytes = new Uint8Array(16);
      crypto.getRandomValues(tokenBytes);
      const shareToken = Array.from(tokenBytes).map(b => b.toString(16).padStart(2, '0')).join('');
      await env.DB.prepare('UPDATE documents SET share_token = ? WHERE id = ?').bind(shareToken, id).run();
      return jsonResponse({ success: true, share_token: shareToken });
    } else if (action === 'revoke') {
      await env.DB.prepare('UPDATE documents SET share_token = NULL WHERE id = ?').bind(id).run();
      return jsonResponse({ success: true, share_token: null });
    }
    return errorResponse('Invalid action. Use "generate" or "revoke"', 400);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/documents/:id/revisions', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM documents WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Document not found', 404);
    const { results } = await env.DB.prepare(
      `SELECT dr.id, dr.version, dr.summary, dr.created_at, u.username as created_by_name
       FROM document_revisions dr JOIN users u ON dr.created_by = u.id
       WHERE dr.document_id = ? ORDER BY dr.version DESC`
    ).bind(id).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/messages/count', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      "SELECT COUNT(*) as count FROM board_messages WHERE date(created_at) = date('now','localtime')"
    ).all();
    return jsonResponse(results[0]);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/messages', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      `SELECT bm.id, bm.content, bm.created_at, u.id as user_id, u.username, u.display_name, u.avatar_url
       FROM board_messages bm JOIN users u ON bm.user_id = u.id
       ORDER BY bm.created_at DESC LIMIT 100`
    ).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/messages', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { content } = await request.json();
    if (!content || content.trim().length === 0) return errorResponse('Content required', 400);
    const { results } = await env.DB.prepare(
      `INSERT INTO board_messages (user_id, content, created_at)
       VALUES (?, ?, datetime('now','localtime'))
       RETURNING id, content, created_at`
    ).bind(user.id, content.trim().substring(0, 2000)).all();
    return jsonResponse(results[0], 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/messages/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT * FROM board_messages WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Message not found', 404);
    const msg = existing.results[0];
    if (msg.user_id !== user.id && user.role !== 'admin') return errorResponse('Forbidden', 403);
    await env.DB.prepare('DELETE FROM board_messages WHERE id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'Message deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/logs', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const url = new URL(request.url);
    const date = url.searchParams.get('date');
    const userId = url.searchParams.get('user_id');
    let query = `SELECT l.id, l.content, l.log_date, l.created_at, u.username, u.display_name
                 FROM logs l JOIN users u ON l.user_id = u.id WHERE 1=1`;
    const params = [];
    if (date) {
      query += ' AND l.log_date = ?';
      params.push(date);
    }
    if (userId) {
      query += ' AND l.user_id = ?';
      params.push(userId);
    }
    query += ' ORDER BY l.created_at DESC LIMIT 200';
    const { results } = await env.DB.prepare(query).bind(...params).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/logs', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { content, log_date } = await request.json();
    if (!content || content.trim().length === 0) return errorResponse('Content required', 400);
    const logDate = log_date || new Date().toISOString().split('T')[0];
    const { results } = await env.DB.prepare(
      `INSERT INTO logs (user_id, content, log_date, created_at)
       VALUES (?, ?, ?, datetime('now','localtime'))
       RETURNING id, content, log_date, created_at`
    ).bind(user.id, content.trim().substring(0, 5000), logDate).all();
    return jsonResponse(results[0], 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/logs/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT * FROM logs WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Log not found', 404);
    const log = existing.results[0];
    if (log.user_id !== user.id && user.role !== 'admin') return errorResponse('Forbidden', 403);
    await env.DB.prepare('DELETE FROM logs WHERE id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'Log deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/announcements', async (request, env) => {
  try {
    const { results } = await env.DB.prepare(
      `SELECT a.id, a.title, a.content, a.is_scroll, a.scroll_content, a.created_at, a.updated_at,
              u.username as created_by_name
       FROM announcements a JOIN users u ON a.created_by = u.id
       ORDER BY a.created_at DESC`
    ).all();
    return jsonResponse(results);
  } catch (e) {
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/announcements', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { id, title, content, is_scroll, scroll_content } = await request.json();
    if (!title || title.trim().length === 0) return errorResponse('Title required', 400);
    if (id) {
      const existing = await env.DB.prepare('SELECT id FROM announcements WHERE id = ?').bind(id).all();
      if (existing.results.length === 0) return errorResponse('Announcement not found', 404);
      await env.DB.prepare(
        `UPDATE announcements SET title = ?, content = ?, is_scroll = ?, scroll_content = ?, updated_at = datetime('now','localtime')
         WHERE id = ?`
      ).bind(title.trim().substring(0, 300), content || '', is_scroll ? 1 : 0, scroll_content || null, id).run();
      const { results } = await env.DB.prepare('SELECT * FROM announcements WHERE id = ?').bind(id).all();
      return jsonResponse(results[0]);
    } else {
      const { results } = await env.DB.prepare(
        `INSERT INTO announcements (title, content, is_scroll, scroll_content, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))
         RETURNING id, title, content, is_scroll, scroll_content, created_at`
      ).bind(title.trim().substring(0, 300), content || '', is_scroll ? 1 : 0, scroll_content || null, user.id).all();
      return jsonResponse(results[0], 201);
    }
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/announcements/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM announcements WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('Announcement not found', 404);
    await env.DB.prepare('DELETE FROM announcements WHERE id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'Announcement deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/scroll-announcement', async (request, env) => {
  try {
    const { results } = await env.DB.prepare(
      "SELECT scroll_content FROM announcements WHERE is_scroll = 1 ORDER BY created_at DESC LIMIT 1"
    ).all();
    if (results.length === 0) return jsonResponse({ scroll_content: null });
    return jsonResponse(results[0]);
  } catch (e) {
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/admin/users', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { results } = await env.DB.prepare(
      'SELECT id, username, role, display_name, email, avatar_url, created_at FROM users ORDER BY created_at DESC'
    ).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/admin/users', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { username, password, role } = await request.json();
    if (!username || !password) return errorResponse('Username and password required', 400);
    const existing = await env.DB.prepare('SELECT id FROM users WHERE username = ?').bind(username).all();
    if (existing.results.length > 0) return errorResponse('Username already taken', 409);
    const hashed = await hashPassword(password);
    const newRole = (role === 'admin' || role === 'user') ? role : 'user';
    await env.DB.prepare(
      'INSERT INTO users (username, password_hash, role, display_name) VALUES (?, ?, ?, ?)'
    ).bind(username, hashed, newRole, username).run();
    return jsonResponse({ success: true, message: 'User created' }, 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/admin/users/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { id } = request.params;
    if (parseInt(id) === user.id) return errorResponse('Cannot delete yourself', 400);
    const existing = await env.DB.prepare('SELECT id FROM users WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('User not found', 404);
    await env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM tokens WHERE user_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM todos WHERE user_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM documents WHERE user_id = ?').bind(id).run();
    await env.DB.prepare('DELETE FROM users WHERE id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'User deleted' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/admin/users/:id/reset', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    requireAdmin(user);
    const { id } = request.params;
    const { new_password } = await request.json();
    if (!new_password || new_password.length < 6) return errorResponse('New password too short (min 6)', 400);
    const existing = await env.DB.prepare('SELECT id FROM users WHERE id = ?').bind(id).all();
    if (existing.results.length === 0) return errorResponse('User not found', 404);
    const hashed = await hashPassword(new_password);
    await env.DB.prepare('UPDATE users SET password_hash = ? WHERE id = ?').bind(hashed, id).run();
    await env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(id).run();
    return jsonResponse({ success: true, message: 'Password reset' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.get('/api/tokens', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare(
      'SELECT id, label, last_used_at, created_at FROM tokens WHERE user_id = ? AND revoked = 0 ORDER BY created_at DESC'
    ).bind(user.id).all();
    return jsonResponse(results);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.post('/api/tokens', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { label } = await request.json();
    const tokenBytes = new Uint8Array(32);
    crypto.getRandomValues(tokenBytes);
    const token = Array.from(tokenBytes).map(b => b.toString(16).padStart(2, '0')).join('');
    const { results } = await env.DB.prepare(
      `INSERT INTO tokens (user_id, token, label, revoked, created_at)
       VALUES (?, ?, ?, 0, datetime('now','localtime'))
       RETURNING id, label, created_at`
    ).bind(user.id, token, label || 'API Token').all();
    return jsonResponse({ ...results[0], token }, 201);
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

router.delete('/api/tokens/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const existing = await env.DB.prepare('SELECT id FROM tokens WHERE id = ? AND user_id = ?').bind(id, user.id).all();
    if (existing.results.length === 0) return errorResponse('Token not found', 404);
    await env.DB.prepare('UPDATE tokens SET revoked = 1 WHERE id = ? AND user_id = ?').bind(id, user.id).run();
    return jsonResponse({ success: true, message: 'Token revoked' });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Internal error', 500);
  }
});

async function isPrivateIP(hostname) {
  if (hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1') return true;
  if (hostname.startsWith('10.') || hostname.startsWith('172.16.') || hostname.startsWith('192.168.')) return true;
  if (hostname === '0.0.0.0') return true;
  if (hostname.startsWith('169.254.')) return true;
  if (hostname.startsWith('fc') || hostname.startsWith('fd')) return true;
  try {
    const parts = hostname.split('.').map(Number);
    if (parts.length === 4 && parts.every(p => !isNaN(p) && p >= 0 && p <= 255)) {
      if (parts[0] === 127) return true;
    }
  } catch (_) {}
  return false;
}

router.get('/api/link-preview', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const url = new URL(request.url);
    const targetUrl = url.searchParams.get('url');
    if (!targetUrl) return errorResponse('url parameter required', 400);
    let parsedUrl;
    try {
      parsedUrl = new URL(targetUrl);
    } catch (_) {
      return errorResponse('Invalid URL', 400);
    }
    if (parsedUrl.protocol !== 'http:' && parsedUrl.protocol !== 'https:') {
      return errorResponse('Only http and https URLs allowed', 400);
    }
    if (await isPrivateIP(parsedUrl.hostname)) {
      return errorResponse('Cannot fetch private IP addresses', 400);
    }
    const resp = await fetch(targetUrl, {
      method: 'GET',
      headers: { 'User-Agent': 'ForgeWorkspace/1.0' },
      signal: AbortSignal.timeout(5000),
    });
    const html = await resp.text();
    const title = html.match(/<title[^>]*>([^<]*)<\/title>/i)?.[1] || '';
    const desc = html.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i)?.[1]
      || html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+name=["']description["']/i)?.[1] || '';
    const ogImage = html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']*)["']/i)?.[1]
      || html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:image["']/i)?.[1] || '';
    const ogTitle = html.match(/<meta[^>]+property=["']og:title["'][^>]+content=["']([^"']*)["']/i)?.[1]
      || html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:title["']/i)?.[1] || '';
    const favicon = html.match(/<link[^>]+rel=["'](?:shortcut )?icon["'][^>]+href=["']([^"']*)["']/i)?.[1] || '';
    return jsonResponse({
      url: targetUrl,
      title: ogTitle || title,
      description: desc,
      image: ogImage,
      favicon: favicon ? new URL(favicon, targetUrl).href : `${parsedUrl.origin}/favicon.ico`,
    });
  } catch (e) {
    if (e.status) return errorResponse(e.message, e.status);
    return errorResponse('Failed to fetch preview', 500);
  }
});

router.all('*', () => error(404, 'Not Found'));

export default {
  async fetch(request, env, ctx) {
    return router.handle(request, env, ctx).then(corsify);
  },
};
