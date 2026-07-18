function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json', 'access-control-allow-origin': '*', 'access-control-allow-credentials': 'true' },
  });
}

function errorResponse(message, status = 400, detail) {
  const body = detail ? { error: message, detail: String(detail) } : { error: message };
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', 'access-control-allow-origin': '*', 'access-control-allow-credentials': 'true' },
  });
}

async function hashPassword(password) {
  const encoder = new TextEncoder();
  const data = encoder.encode(password + 'forge-workspace-salt-2024');
  const hash = await crypto.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
}

// ===================== SCHEMA SELF-HEALING =====================
// Ensures the D1 database has all tables/columns the app needs.
// Runs once per Worker instance (cached via module-level flag).
let _schemaReady = false;
async function ensureSchema(env) {
  if (_schemaReady) return;
  const db = env.DB;
  const run = async (sql) => { try { await db.prepare(sql).run(); } catch (e) { console.error('schema step failed:', e.message, '| SQL:', sql); } };

  // users: add avatar + group_id if missing
  await run("ALTER TABLE users ADD COLUMN avatar TEXT");
  await run("ALTER TABLE users ADD COLUMN group_id INTEGER");

  // groups table
  await run(`CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#9a9792',
    icon TEXT NOT NULL DEFAULT 'fa-user',
    permissions TEXT NOT NULL DEFAULT '[]',
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )`);
  await run("CREATE UNIQUE INDEX IF NOT EXISTS idx_groups_name ON groups(name)");

  // default system groups
  await run("INSERT OR IGNORE INTO groups (id, name, color, icon, is_system) VALUES (1, '管理员', '#d48c5c', 'fa-crown', 1)");
  await run("INSERT OR IGNORE INTO groups (id, name, color, icon, is_system) VALUES (2, '成员', '#5c9ad4', 'fa-user', 1)");
  await run("INSERT OR IGNORE INTO groups (id, name, color, icon, is_system) VALUES (3, '访客', '#9a9792', 'fa-eye', 1)");

  // todos: ensure table + columns exist
  await run(`CREATE TABLE IF NOT EXISTS todos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT,
    content TEXT,
    priority TEXT NOT NULL DEFAULT 'medium',
    progress INTEGER NOT NULL DEFAULT 0 CHECK(progress >= 0 AND progress <= 100),
    due_date TEXT,
    remark TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )`);
  await run("ALTER TABLE todos ADD COLUMN title TEXT");
  await run("ALTER TABLE todos ADD COLUMN content TEXT");
  await run("ALTER TABLE todos ADD COLUMN remark TEXT");
  await run("ALTER TABLE todos ADD COLUMN progress INTEGER");

  // meeting_presence: unique index for ON CONFLICT upsert
  await run("CREATE UNIQUE INDEX IF NOT EXISTS idx_meeting_presence ON meeting_presence(meeting_id, user_id)");

  // public_chat_messages / meeting_messages: support image type
  await run("DROP INDEX IF EXISTS idx_meeting_messages_type");
  await run("CREATE INDEX IF NOT EXISTS idx_meeting_messages_type ON meeting_messages(message_type)");
  await run("CREATE INDEX IF NOT EXISTS idx_public_chat_messages_type ON public_chat_messages(message_type)");

  // ===== Collaborative drawing =====
  await run(`CREATE TABLE IF NOT EXISTS drawing_canvases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_type TEXT NOT NULL DEFAULT 'public',
    room_id INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL DEFAULT '协作画布',
    data TEXT NOT NULL DEFAULT '',
    mode TEXT NOT NULL DEFAULT 'free',
    pixel_size INTEGER NOT NULL DEFAULT 16,
    owner_id INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )`);
  await run("ALTER TABLE drawing_canvases ADD COLUMN pixel_size INTEGER");
  await run(`CREATE TABLE IF NOT EXISTS drawing_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    canvas_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    joined_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE(canvas_id, user_id)
  )`);
  await run(`CREATE TABLE IF NOT EXISTS drawing_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    canvas_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )`);
  await run("CREATE INDEX IF NOT EXISTS idx_drawing_canvases_room ON drawing_canvases(room_type, room_id)");
  await run("CREATE INDEX IF NOT EXISTS idx_drawing_participants_canvas ON drawing_participants(canvas_id)");
  await run("CREATE INDEX IF NOT EXISTS idx_drawing_requests_canvas ON drawing_requests(canvas_id, status)");

  _schemaReady = true;
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
    `SELECT u.id, u.username, u.role, u.display_name, u.avatar, u.group_id
     FROM sessions s JOIN users u ON s.user_id = u.id
     WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > datetime('now','localtime'))`
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
  const since_id = u.searchParams.get('since_id') || null;
  const limit = Math.min(parseInt(u.searchParams.get('limit') || '50', 10), 100);
  return { before_id, since_id, limit };
}

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

const routes = [];

function route(method, pattern, handler) {
  const paramNames = [];
  const regexStr = '^' + pattern.replace(/:(\w+)/g, (_, name) => { paramNames.push(name); return '([^/]+)'; }) + '$';
  routes.push({ method, regex: new RegExp(regexStr), paramNames, handler });
}

function get(pattern, handler) { route('GET', pattern, handler); }
function post(pattern, handler) { route('POST', pattern, handler); }
function put(pattern, handler) { route('PUT', pattern, handler); }
function del(pattern, handler) { route('DELETE', pattern, handler); }

async function handleRequest(request, env) {
  const url = new URL(request.url);
  const method = request.method;
  const path = url.pathname;

  for (const r of routes) {
    const match = path.match(r.regex);
    if (match && (r.method === method || r.method === 'ALL')) {
      const params = {};
      r.paramNames.forEach((name, i) => { params[name] = match[i + 1]; });
      request.params = params;
      try {
        return await r.handler(request, env);
      } catch (e) {
        if (e.status) return errorResponse(e.message, e.status);
        console.error('Handler error:', e);
        return errorResponse('Internal error', 500);
      }
    }
  }
  return errorResponse('Not Found', 404);
}

// ===================== DEBUG =====================
get('/api/debug', async (request, env) => {
  try {
    const dbInfo = env.DB ? 'exists' : 'missing';
    let tableCheck = 'not tested';
    try {
      const r = await env.DB.prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name").all();
      tableCheck = JSON.stringify(r.results.map(t => t.name));
    } catch (e) { tableCheck = 'error: ' + e.message; }
    return jsonResponse({ db: dbInfo, tables: tableCheck, env: Object.keys(env).join(',') });
  } catch (e) { return errorResponse(e.message, 500, e.stack); }
});

// ===================== STATUS =====================
get('/api/status', () => {
  return jsonResponse({ status: 'ok', version: '1.0.0', time: new Date().toISOString() });
});

// ===================== AUTH =====================
get('/api/auth/me', async (request, env) => {
  try { const user = await requireAuth(request, env);
    let group=null;
    if (user.group_id) { const g=await env.DB.prepare('SELECT id,name,color,icon FROM groups WHERE id=?').bind(user.group_id).all(); if (g.results.length>0) group=g.results[0]; }
    return jsonResponse({...user,group});
  }
  catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse('Internal error', 500); }
});

post('/api/auth/login', async (request, env) => {
  try {
    const { username, password } = await request.json();
    if (!username || !password) return errorResponse('Username and password required', 400);
    const hashed = await hashPassword(password);
    const { results } = await env.DB.prepare(
      'SELECT id, username, role, display_name FROM users WHERE username = ? AND password = ?'
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
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse('Internal error', 500); }
});

post('/api/auth/register', async (request, env) => {
  try {
    const { username, password, display_name } = await request.json();
    if (!username || !password) return errorResponse('Username and password required', 400);
    if (username.length < 3) return errorResponse('Username too short (min 3)', 400);
    if (password.length < 6) return errorResponse('Password too short (min 6)', 400);
    const existing = await env.DB.prepare('SELECT id FROM users WHERE username = ?').bind(username).all();
    if (existing.results.length > 0) return errorResponse('Username already taken', 409);
    const hashed = await hashPassword(password);
    await env.DB.prepare(
      'INSERT INTO users (username, password, display_name, role) VALUES (?, ?, ?, ?)'
    ).bind(username, hashed, display_name || username, 'user').run();
    return jsonResponse({ success: true, message: 'User registered' }, 201);
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse('Internal error', 500); }
});

post('/api/auth/logout', async (request, env) => {
  try {
    const authHeader = request.headers.get('Authorization');
    let token = null;
    if (authHeader && authHeader.startsWith('Bearer ')) token = authHeader.slice(7);
    else {
      const cookie = request.headers.get('Cookie');
      if (cookie) { const m = cookie.match(/(?:^|;\s*)token=([^;]+)/); if (m) token = m[1]; }
    }
    if (token) await env.DB.prepare('DELETE FROM sessions WHERE token = ?').bind(token).run();
    return jsonResponse({ success: true });
  } catch (e) { return jsonResponse({ success: true }); }
});

put('/api/auth/password', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { old_password, new_password } = await request.json();
    if (!old_password || !new_password) return errorResponse('Old and new password required', 400);
    const oldHashed = await hashPassword(old_password);
    const check = await env.DB.prepare('SELECT id FROM users WHERE id = ? AND password = ?').bind(user.id, oldHashed).all();
    if (check.results.length === 0) return errorResponse('Current password is incorrect', 401);
    const newHashed = await hashPassword(new_password);
    await env.DB.prepare('UPDATE users SET password = ? WHERE id = ?').bind(newHashed, user.id).run();
    await env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(user.id).run();
    return jsonResponse({ success: true, message: 'Password changed' });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse('Internal error', 500); }
});

// ===================== TODOS =====================
get('/api/todos/count', async (request, env) => {
  try { const user = await requireAuth(request, env);
    const { results } = await env.DB.prepare("SELECT COUNT(*) as total, COALESCE(AVG(progress),0) as avg_progress FROM todos WHERE user_id=?").bind(user.id).all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/todos', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const url = new URL(request.url);
    const filter = url.searchParams.get('filter') || 'all';
    const sort = url.searchParams.get('sort') || 'created_at';
    const order = url.searchParams.get('order') || 'desc';
    const allowedSort = ['created_at','priority','due_date'].includes(sort)?sort:'created_at';
    const allowedOrder = order==='asc'?'ASC':'DESC';
    let q = 'SELECT id,title,priority,progress,due_date,remark,created_at,updated_at FROM todos WHERE user_id=?';
    const p=[user.id];
    if (filter==='active') q+=" AND progress<100";
    else if (filter==='done') q+=" AND progress=100";
    q+=` ORDER BY ${allowedSort} ${allowedOrder}`;
    const {results}=await env.DB.prepare(q).bind(...p).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/todos', async (request, env) => {
  try {
    const user=await requireAuth(request,env); const {title,content,priority,due_date,deadline}=await request.json();
    const ttl=title||content; if (!ttl||ttl.trim().length===0) return errorResponse('Title required',400);
    const st=ttl.trim().substring(0,500); const p=priority||'medium';
    const ap=['low','medium','high','urgent'].includes(p)?p:'medium';
    const dd=due_date||deadline||null;
    const {results}=await env.DB.prepare("INSERT INTO todos (user_id,title,priority,due_date,progress,created_at,updated_at) VALUES (?,?,?,?,0,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,title,priority,progress,due_date,remark,created_at").bind(user.id,st,ap,dd).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

put('/api/todos/:id', async (request, env) => {
  try {
    const user=await requireAuth(request,env); const {id}=request.params;
    const data=await request.json();
    const existing=await env.DB.prepare('SELECT * FROM todos WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const t=existing.results[0];
    const nt=data.title||data.content!==undefined?(data.title||data.content||'').trim().substring(0,500):t.title;
    const np=data.priority!==undefined?(['low','medium','high','urgent'].includes(data.priority)?data.priority:t.priority):t.priority;
    const npr=data.progress!==undefined?Math.max(0,Math.min(100,Number(data.progress))):t.progress;
    const nd=data.due_date!==undefined?data.due_date:data.deadline!==undefined?data.deadline:t.due_date;
    const nr=data.remark!==undefined?data.remark.trim().substring(0,1000):t.remark;
    await env.DB.prepare("UPDATE todos SET title=?,priority=?,progress=?,due_date=?,remark=?,updated_at=datetime('now','localtime') WHERE id=? AND user_id=?").bind(nt,np,npr,nd,nr,id,user.id).run();
    const {results}=await env.DB.prepare('SELECT * FROM todos WHERE id=?').bind(id).all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/todos/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM todos WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('DELETE FROM todo_updates WHERE todo_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM todos WHERE id=? AND user_id=?').bind(id,user.id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/todos/:id/updates', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM todos WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const {results}=await env.DB.prepare('SELECT id,content,created_at FROM todo_updates WHERE todo_id=? ORDER BY created_at DESC').bind(id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/todos/:id/updates', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params; const {content}=await request.json();
    if (!content||content.trim().length===0) return errorResponse('Content required',400);
    const existing=await env.DB.prepare('SELECT id FROM todos WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const {results}=await env.DB.prepare("INSERT INTO todo_updates (todo_id,user_id,content,created_at) VALUES (?,?,?,datetime('now','localtime')) RETURNING id,content,created_at").bind(id,user.id,content.trim().substring(0,2000)).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== MEETINGS =====================
get('/api/meetings', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare("SELECT m.id,m.title,m.description,m.meeting_time,m.status,m.participants,m.user_id,m.created_at,u.username as creator_name FROM meetings m JOIN users u ON m.user_id=u.id ORDER BY m.meeting_time DESC,m.created_at DESC").all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/meetings', async (request, env) => {
  try { const user=await requireAuth(request,env); const {title,description,participants,meeting_time}=await request.json();
    if (!title||title.trim().length===0) return errorResponse('Title required',400);
    const pj=participants?JSON.stringify(participants):'[]';
    const {results}=await env.DB.prepare("INSERT INTO meetings (title,description,participants,meeting_time,user_id,created_at) VALUES (?,?,?,?,?,datetime('now','localtime')) RETURNING id,title,description,participants,meeting_time,user_id,created_at").bind(title.trim().substring(0,300),description||'',pj,meeting_time||null,user.id).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

put('/api/meetings/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params; const {title,description,participants,meeting_time,status}=await request.json();
    const existing=await env.DB.prepare('SELECT * FROM meetings WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const m=existing.results[0];
    if (m.user_id!==user.id&&user.role!=='admin') return errorResponse('Forbidden',403);
    const nt=title!==undefined?title.trim().substring(0,300):m.title;
    const nd=description!==undefined?description:m.description;
    const np=participants!==undefined?JSON.stringify(participants):m.participants;
    const ntime=meeting_time!==undefined?meeting_time:m.meeting_time;
    const ns=status!==undefined?status:m.status;
    await env.DB.prepare("UPDATE meetings SET title=?,description=?,participants=?,meeting_time=?,status=? WHERE id=?").bind(nt,nd,np,ntime,ns,id).run();
    const {results}=await env.DB.prepare('SELECT * FROM meetings WHERE id=?').bind(id).all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/meetings/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT * FROM meetings WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const m=existing.results[0];
    if (m.user_id!==user.id&&user.role!=='admin') return errorResponse('Forbidden',403);
    await env.DB.prepare('DELETE FROM meeting_messages WHERE meeting_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM meeting_presence WHERE meeting_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM meetings WHERE id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/meetings/:id/chat', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const {before_id,since_id,limit}=extractPagination(request.url);
    await env.DB.prepare('SELECT id FROM meetings WHERE id=?').bind(id).all();
    let q="SELECT mc.id,mc.content,mc.message_type,mc.meta_data,mc.created_at,u.id as user_id,u.username,u.display_name FROM meeting_messages mc JOIN users u ON mc.user_id=u.id WHERE mc.meeting_id=?";
    const p=[id];
    if (since_id) { q+=' AND mc.id>?'; p.push(since_id); }
    else if (before_id) { q+=' AND mc.id<?'; p.push(before_id); }
    q+=' ORDER BY mc.id DESC LIMIT ?'; p.push(limit);
    const {results}=await env.DB.prepare(q).bind(...p).all();
    return jsonResponse(results.reverse());
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/meetings/:id/chat', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    let {content,message_type,meta_data}=await request.json();
    if (!content||content.trim().length===0) return errorResponse('Content required',400);
    await env.DB.prepare('SELECT id FROM meetings WHERE id=?').bind(id).all();
    content=content.trim().substring(0,5000);
        const mt=message_type||'text'; const md=meta_data?JSON.stringify(meta_data):'{}';
        if (mt==='image'&&content&&content.length>200000) return errorResponse('Image too large',400);
    const {results}=await env.DB.prepare("INSERT INTO meeting_messages (meeting_id,user_id,content,message_type,meta_data,created_at) VALUES (?,?,?,?,?,datetime('now','localtime')) RETURNING id,content,message_type,meta_data,created_at").bind(id,user.id,content,mt,md).all();
    let todoCreated=null;
    if (mt==='todo'||content.startsWith('/todo ')) {
      const tt=content.replace('/todo ','').trim();
      if (tt.length>0) {
        const tr=await env.DB.prepare("INSERT INTO todos (user_id,title,priority,progress,created_at,updated_at) VALUES (?,?,'medium',0,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,title").bind(user.id,tt.substring(0,500)).all();
        todoCreated=tr.results[0];
      }
    }
    return jsonResponse({message:results[0],todo:todoCreated},201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/meetings/:id/presence', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const {results}=await env.DB.prepare("SELECT mp.user_id,u.username,u.display_name,mp.last_seen FROM meeting_presence mp JOIN users u ON mp.user_id=u.id WHERE mp.meeting_id=? AND mp.last_seen>datetime('now','localtime','-30 seconds') ORDER BY mp.last_seen DESC").bind(id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/meetings/:id/presence', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    await env.DB.prepare("INSERT INTO meeting_presence (meeting_id,user_id,last_seen) VALUES (?,?,datetime('now','localtime')) ON CONFLICT(meeting_id,user_id) DO UPDATE SET last_seen=datetime('now','localtime')").bind(id,user.id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== PUBLIC CHAT =====================
get('/api/chat', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {before_id,since_id,limit}=extractPagination(request.url);
    let q="SELECT mc.id,mc.content,mc.message_type,mc.meta_data,mc.created_at,u.id as user_id,u.username,u.display_name FROM public_chat_messages mc JOIN users u ON mc.user_id=u.id WHERE 1=1";
    const p=[];
    if (since_id) { q+=' AND mc.id>?'; p.push(since_id); }
    else if (before_id) { q+=' AND mc.id<?'; p.push(before_id); }
    q+=' ORDER BY mc.id DESC LIMIT ?'; p.push(limit);
    const {results}=await env.DB.prepare(q).bind(...p).all();
    return jsonResponse(results.reverse());
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/chat', async (request, env) => {
  try { const user=await requireAuth(request,env);
    let {content,message_type,meta_data}=await request.json();
    if (!content||content.trim().length===0) return errorResponse('Content required',400);
    content=content.trim().substring(0,5000);
        const mt=message_type||'text'; const md=meta_data?JSON.stringify(meta_data):'{}';
        if (mt==='image'&&content&&content.length>200000) return errorResponse('Image too large',400);
    const {results}=await env.DB.prepare("INSERT INTO public_chat_messages (user_id,content,message_type,meta_data,created_at) VALUES (?,?,?,?,datetime('now','localtime')) RETURNING id,content,message_type,meta_data,created_at").bind(user.id,content,mt,md).all();
    let todoCreated=null;
    if (content.startsWith('/todo ')) {
      const tt=content.replace('/todo ','').trim();
      if (tt.length>0) {
        const tr=await env.DB.prepare("INSERT INTO todos (user_id,title,priority,progress,created_at,updated_at) VALUES (?,?,'medium',0,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,title").bind(user.id,tt.substring(0,500)).all();
        todoCreated=tr.results[0];
      }
    }
    return jsonResponse({message:results[0],todo:todoCreated},201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/chat/presence', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare("SELECT cp.user_id,u.username,u.display_name,cp.last_seen FROM public_chat_presence cp JOIN users u ON cp.user_id=u.id WHERE cp.last_seen>datetime('now','localtime','-30 seconds') ORDER BY cp.last_seen DESC").all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/chat/presence', async (request, env) => {
  try { const user=await requireAuth(request,env);
    await env.DB.prepare("INSERT INTO public_chat_presence (user_id,last_seen) VALUES (?,datetime('now','localtime')) ON CONFLICT(user_id) DO UPDATE SET last_seen=datetime('now','localtime')").bind(user.id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== DOCUMENTS =====================
get('/api/documents', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare('SELECT id,title,doc_type,created_at,updated_at,version FROM shared_documents WHERE user_id=? ORDER BY updated_at DESC').bind(user.id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/documents', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id,title,content,doc_type,summary}=await request.json();
    if (!title||title.trim().length===0) return errorResponse('Title required',400);
    const st=title.trim().substring(0,300); const dt=doc_type||'markdown';
    if (id) {
      const existing=await env.DB.prepare('SELECT * FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
      if (existing.results.length===0) return errorResponse('Not found',404);
      const d=existing.results[0]; const nv=(d.version||0)+1;
      const nc=content!==undefined?content:d.content;
      await env.DB.prepare("UPDATE shared_documents SET title=?,content=?,doc_type=?,version=?,updated_at=datetime('now','localtime') WHERE id=? AND user_id=?").bind(st,nc,dt,nv,id,user.id).run();
      if (content!==undefined) { await env.DB.prepare("INSERT INTO document_revisions (document_id,version,content,summary,user_id,created_at) VALUES (?,?,?,?,?,datetime('now','localtime'))").bind(id,nv,content,ns||'{}',user.id).run(); }
      const {results}=await env.DB.prepare('SELECT * FROM shared_documents WHERE id=?').bind(id).all();
      return jsonResponse(results[0]);
    } else {
      const {results}=await env.DB.prepare("INSERT INTO shared_documents (user_id,title,content,doc_type,version,created_at,updated_at) VALUES (?,?,?,?,1,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,title,content,doc_type,version,created_at").bind(user.id,st,content||'',dt).all();
      const d=results[0];
      await env.DB.prepare("INSERT INTO document_revisions (document_id,version,content,summary,user_id,created_at) VALUES (?,1,?,?,?,datetime('now','localtime'))").bind(d.id,content||'',summary||'{}',user.id).run();
      return jsonResponse(d,201);
    }
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/documents/share/:token', async (request, env) => {
  try { const {token}=request.params;
    const {results}=await env.DB.prepare("SELECT d.id,d.title,d.content,d.doc_type,d.updated_at,u.username as owner_name FROM shared_documents d JOIN users u ON d.user_id=u.id WHERE d.share_token=?").bind(token).all();
    if (results.length===0) return errorResponse('Not found',404);
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
get('/api/documents/:id/revisions', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const {results}=await env.DB.prepare("SELECT dr.id,dr.version,dr.summary,dr.created_at,u.username as created_by_name FROM document_revisions dr JOIN users u ON dr.user_id=u.id WHERE dr.document_id=? ORDER BY dr.version DESC").bind(id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
get('/api/documents/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const {results}=await env.DB.prepare("SELECT d.*,u.username as owner_name FROM shared_documents d JOIN users u ON d.user_id=u.id WHERE d.id=? AND (d.user_id=? OR ?='admin')").bind(id,user.id,user.role).all();
    if (results.length===0) return errorResponse('Not found',404);
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/documents/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('DELETE FROM document_revisions WHERE document_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/documents/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params; const {title,content,doc_type}=await request.json();
    if (!title||title.trim().length===0) return errorResponse('Title required',400);
    const existing=await env.DB.prepare('SELECT * FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const d=existing.results[0]; const nv=(d.version||0)+1; const st=title.trim().substring(0,300);
    const nc=content!==undefined?content:d.content; const dt=doc_type||d.doc_type;
    await env.DB.prepare("UPDATE shared_documents SET title=?,content=?,doc_type=?,version=?,updated_at=datetime('now','localtime') WHERE id=? AND user_id=?").bind(st,nc,dt,nv,id,user.id).run();
    if (content!==undefined) { await env.DB.prepare("INSERT INTO document_revisions (document_id,version,content,summary,user_id,created_at) VALUES (?,?,?,?,?,datetime('now','localtime'))").bind(id,nv,content,'{}',user.id).run(); }
    const {results}=await env.DB.prepare('SELECT * FROM shared_documents WHERE id=?').bind(id).all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
post('/api/documents/:id/share', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params; const {action}=await request.json()||{};
    const existing=await env.DB.prepare('SELECT id FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    if (action==='generate'||!action) { const tb=new Uint8Array(16); crypto.getRandomValues(tb); const st=Array.from(tb).map(b=>b.toString(16).padStart(2,'0')).join(''); await env.DB.prepare('UPDATE shared_documents SET share_token=? WHERE id=?').bind(st,id).run(); return jsonResponse({success:true,share_token:st}); }
    else if (action==='revoke') { await env.DB.prepare('UPDATE shared_documents SET share_token=NULL WHERE id=?').bind(id).run(); return jsonResponse({success:true,share_token:null}); }
    return errorResponse('Invalid action',400);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/documents/:id/revisions', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM shared_documents WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const {results}=await env.DB.prepare("SELECT dr.id,dr.version,dr.summary,dr.created_at,u.username as created_by_name FROM document_revisions dr JOIN users u ON dr.user_id=u.id WHERE dr.document_id=? ORDER BY dr.version DESC").bind(id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== BOARD MESSAGES =====================
get('/api/messages/count', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare("SELECT COUNT(*) as count FROM messages WHERE date(created_at)=date('now','localtime')").all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/messages', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare("SELECT bm.id,bm.content,bm.created_at,u.id as user_id,u.username,u.display_name FROM messages bm JOIN users u ON bm.user_id=u.id ORDER BY bm.created_at DESC LIMIT 100").all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/messages', async (request, env) => {
  try { const user=await requireAuth(request,env); const {content}=await request.json();
    if (!content||content.trim().length===0) return errorResponse('Content required',400);
    const {results}=await env.DB.prepare("INSERT INTO messages (user_id,content,created_at) VALUES (?,?,datetime('now','localtime')) RETURNING id,content,created_at").bind(user.id,content.trim().substring(0,2000)).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/messages/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT * FROM messages WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const msg=existing.results[0];
    if (msg.user_id!==user.id&&user.role!=='admin') return errorResponse('Forbidden',403);
    await env.DB.prepare('DELETE FROM messages WHERE id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== LOGS =====================
get('/api/logs', async (request, env) => {
  try { const user=await requireAuth(request,env); const url=new URL(request.url);
    const date=url.searchParams.get('date'); const uid=url.searchParams.get('user_id');
    let q='SELECT l.id,l.content,l.log_date,l.created_at,u.username,u.display_name FROM logs l JOIN users u ON l.user_id=u.id WHERE 1=1'; const p=[];
    if (date) { q+=' AND l.log_date=?'; p.push(date); }
    if (uid) { q+=' AND l.user_id=?'; p.push(uid); }
    q+=' ORDER BY l.created_at DESC LIMIT 200';
    const {results}=await env.DB.prepare(q).bind(...p).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/logs', async (request, env) => {
  try { const user=await requireAuth(request,env); const {content,log_date}=await request.json();
    if (!content||content.trim().length===0) return errorResponse('Content required',400);
    const ld=log_date||new Date().toISOString().split('T')[0];
    const {results}=await env.DB.prepare("INSERT INTO logs (user_id,content,log_date,created_at) VALUES (?,?,?,datetime('now','localtime')) RETURNING id,content,log_date,created_at").bind(user.id,content.trim().substring(0,5000),ld).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/logs/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT * FROM logs WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const log=existing.results[0];
    if (log.user_id!==user.id&&user.role!=='admin') return errorResponse('Forbidden',403);
    await env.DB.prepare('DELETE FROM logs WHERE id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== ANNOUNCEMENTS =====================
get('/api/announcements', async (request, env) => {
  try { const {results}=await env.DB.prepare("SELECT a.id,a.title,a.content,a.is_scroll,a.scroll_content,a.created_at,a.updated_at,u.username as created_by_name FROM announcements a JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC").all();
    return jsonResponse(results);
  } catch (e) { return errorResponse(e.message||'Internal error',500); }
});

post('/api/announcements', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user);
    const {id,title,content,is_scroll,scroll_content}=await request.json();
    if (!title||title.trim().length===0) return errorResponse('Title required',400);
    if (id) { const existing=await env.DB.prepare('SELECT id FROM announcements WHERE id=?').bind(id).all();
      if (existing.results.length===0) return errorResponse('Not found',404);
      await env.DB.prepare("UPDATE announcements SET title=?,content=?,is_scroll=?,scroll_content=?,updated_at=datetime('now','localtime') WHERE id=?").bind(title.trim().substring(0,300),content||'',is_scroll?1:0,scroll_content||'',id).run();
      const {results}=await env.DB.prepare('SELECT * FROM announcements WHERE id=?').bind(id).all(); return jsonResponse(results[0]);
    } else { const {results}=await env.DB.prepare("INSERT INTO announcements (title,content,is_scroll,scroll_content,user_id,created_at,updated_at) VALUES (?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,title,content,is_scroll,scroll_content,created_at").bind(title.trim().substring(0,300),content||'',is_scroll?1:0,scroll_content||'',user.id).all(); return jsonResponse(results[0],201); }
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/announcements/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM announcements WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('DELETE FROM announcements WHERE id=?').bind(id).run(); return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

get('/api/scroll-announcement', async (request, env) => {
  try { const {results}=await env.DB.prepare("SELECT scroll_content FROM announcements WHERE is_scroll=1 ORDER BY created_at DESC LIMIT 1").all();
    if (results.length===0) return jsonResponse({scroll_content:null});
    return jsonResponse(results[0]);
  } catch (e) { return errorResponse(e.message||'Internal error',500); }
});

// ===================== ADMIN =====================
get('/api/admin/users', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user);
    const {results}=await env.DB.prepare('SELECT id,username,role,display_name,group_id,avatar,created_at FROM users ORDER BY created_at DESC').all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/admin/users', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user);
    const {username,password,role,group_id}=await request.json();
    if (!username||!password) return errorResponse('Username and password required',400);
    const existing=await env.DB.prepare('SELECT id FROM users WHERE username=?').bind(username).all();
    if (existing.results.length>0) return errorResponse('Already taken',409);
    const hashed=await hashPassword(password);
    await env.DB.prepare('INSERT INTO users (username,password,role,display_name,group_id) VALUES (?,?,?,?,?)').bind(username,hashed,role||'user',username,group_id||null).run();
    return jsonResponse({success:true},201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/admin/users/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params;
    if (parseInt(id)===user.id) return errorResponse('Cannot delete yourself',400);
    const existing=await env.DB.prepare('SELECT id FROM users WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('DELETE FROM sessions WHERE user_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM auth_tokens WHERE user_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM todos WHERE user_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM shared_documents WHERE user_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM users WHERE id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/admin/users/:id/reset', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params; const {new_password}=await request.json();
    if (!new_password||new_password.length<6) return errorResponse('Password too short',400);
    const existing=await env.DB.prepare('SELECT id FROM users WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const hashed=await hashPassword(new_password);
    await env.DB.prepare('UPDATE users SET password=? WHERE id=?').bind(hashed,id).run();
    await env.DB.prepare('DELETE FROM sessions WHERE user_id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== GROUPS =====================
get('/api/groups', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare('SELECT id,name,description,color,icon,permissions,is_system FROM groups ORDER BY is_system DESC,name ASC').all();
    return jsonResponse(results.map(g=>({...g,permissions:JSON.parse(g.permissions||'[]')})));
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
post('/api/admin/groups', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {name,description,color,icon,permissions}=await request.json();
    if (!name||name.trim().length===0) return errorResponse('Name required',400);
    const {results}=await env.DB.prepare("INSERT INTO groups (name,description,color,icon,permissions) VALUES (?,?,?,?,?) RETURNING id").bind(name.trim().substring(0,50),description||'',color||'#9a9792',icon||'fa-user',JSON.stringify(permissions||[])).all();
    return jsonResponse(results[0],201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
put('/api/admin/groups/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params; const data=await request.json();
    const existing=await env.DB.prepare('SELECT id FROM groups WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('UPDATE groups SET name=?,description=?,color=?,icon=?,permissions=? WHERE id=?').bind(data.name||'',data.description||'',data.color||'#9a9792',data.icon||'fa-user',JSON.stringify(data.permissions||[]),id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
del('/api/admin/groups/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id,is_system FROM groups WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    if (existing.results[0].is_system) return errorResponse('Cannot delete system group',400);
    await env.DB.prepare('UPDATE users SET group_id=NULL WHERE group_id=?').bind(id).run();
    await env.DB.prepare('DELETE FROM groups WHERE id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== PROFILE =====================
put('/api/auth/profile', async (request, env) => {
  try { const user=await requireAuth(request,env); const {display_name,avatar}=await request.json();
    if (display_name!==undefined) { const dn=display_name.trim().substring(0,50); if (dn.length>0) await env.DB.prepare('UPDATE users SET display_name=? WHERE id=?').bind(dn,user.id).run(); }
    if (avatar!==undefined) await env.DB.prepare('UPDATE users SET avatar=? WHERE id=?').bind(avatar.substring(0,3000000),user.id).run();
    const {results}=await env.DB.prepare('SELECT id,username,role,display_name,avatar,group_id FROM users WHERE id=?').bind(user.id).all();
    return jsonResponse(results[0]);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== TOKENS =====================
get('/api/tokens', async (request, env) => {
  try { const user=await requireAuth(request,env);
    const {results}=await env.DB.prepare('SELECT id,label,last_used_at,created_at FROM auth_tokens WHERE user_id=? ORDER BY created_at DESC').bind(user.id).all();
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/tokens', async (request, env) => {
  try { const user=await requireAuth(request,env); const {label}=await request.json();
    const tb=new Uint8Array(32); crypto.getRandomValues(tb);
    const token=Array.from(tb).map(b=>b.toString(16).padStart(2,'0')).join('');
    const {results}=await env.DB.prepare("INSERT INTO auth_tokens (user_id,token,label,created_at) VALUES (?,?,?,datetime('now','localtime')) RETURNING id,label,created_at").bind(user.id,token,label||'API Token').all();
    return jsonResponse({...results[0],token},201);
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

del('/api/tokens/:id', async (request, env) => {
  try { const user=await requireAuth(request,env); const {id}=request.params;
    const existing=await env.DB.prepare('SELECT id FROM auth_tokens WHERE id=? AND user_id=?').bind(id,user.id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('DELETE FROM auth_tokens WHERE id=? AND user_id=?').bind(id,user.id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== ROUTE ALIASES (frontend compat) =====================
get('/api/auth/tokens', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/auth/tokens','/api/tokens'), request), env); });
post('/api/auth/tokens', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/auth/tokens','/api/tokens'), request), env); });
get('/api/users', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/users','/api/admin/users'), request), env); });
post('/api/users', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/users','/api/admin/users'), request), env); });
del('/api/auth/tokens/:id', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/auth/tokens','/api/tokens'), request), env); });
put('/api/admin/users/:id/group', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params; const {group_id}=await request.json();
    const existing=await env.DB.prepare('SELECT id FROM users WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    await env.DB.prepare('UPDATE users SET group_id=? WHERE id=?').bind(group_id||null,id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
post('/api/admin/users/:id/reset-password', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {id}=request.params; const {password}=await request.json();
    if (!password||password.length<4) return errorResponse('Password too short',400);
    const existing=await env.DB.prepare('SELECT id FROM users WHERE id=?').bind(id).all();
    if (existing.results.length===0) return errorResponse('Not found',404);
    const hashed=await hashPassword(password);
    await env.DB.prepare('UPDATE users SET password=? WHERE id=?').bind(hashed,id).run();
    await env.DB.prepare('DELETE FROM sessions WHERE user_id=?').bind(id).run();
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});
get('/api/announcements/scroll', async (request, env) => { return handleRequest(new Request(new URL(request.url).href.replace('/api/announcements/scroll','/api/scroll-announcement'), request), env); });
post('/api/auth/change-password', async (request, env) => {
  try { const user=await requireAuth(request,env); const {old_password,new_password,confirm_password}=await request.json();
    if (!old_password||!new_password) return errorResponse('Current and new password required',400);
    if (new_password!==confirm_password) return errorResponse('Passwords do not match',400);
    const {results}=await env.DB.prepare('SELECT password FROM users WHERE id=?').bind(user.id).all();
    if (results.length===0) return errorResponse('User not found',404);
    const hashed=await hashPassword(old_password);
    if (results[0].password!==hashed) return errorResponse('Current password incorrect',401);
    const newHashed=await hashPassword(new_password);
    await env.DB.prepare('UPDATE users SET password=? WHERE id=?').bind(newHashed,user.id).run();
    return jsonResponse({success:true,message:'Password changed'});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

post('/api/announcements/scroll', async (request, env) => {
  try { const user=await requireAuth(request,env); requireAdmin(user); const {content}=await request.json();
    if (!content) return errorResponse('Content required',400);
    const existing=await env.DB.prepare("SELECT id FROM announcements WHERE is_scroll=1 LIMIT 1").all();
    if (existing.results.length>0) {
      await env.DB.prepare("UPDATE announcements SET scroll_content=?,updated_at=datetime('now','localtime') WHERE id=?").bind(content.substring(0,500),existing.results[0].id).run();
    } else {
      await env.DB.prepare("INSERT INTO announcements (user_id,title,content,is_scroll,scroll_content,created_at,updated_at) VALUES (?,'','Scroll',1,?,datetime('now','localtime'),datetime('now','localtime'))").bind(user.id,content.substring(0,500)).run();
    }
    return jsonResponse({success:true});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== COLLABORATIVE DRAWING =====================
get('/api/drawings', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const url = new URL(request.url);
    const room_type = url.searchParams.get('room_type') || 'public';
    const room_id = parseInt(url.searchParams.get('room_id') || '0', 10);
    const { results } = await env.DB.prepare(
      "SELECT dc.id,dc.room_type,dc.room_id,dc.title,dc.mode,dc.pixel_size,dc.owner_id,dc.created_by,dc.updated_at,dc.data,u.username as owner_name FROM drawing_canvases dc LEFT JOIN users u ON dc.owner_id=u.id WHERE dc.room_type=? AND dc.room_id=? ORDER BY dc.updated_at DESC"
    ).bind(room_type, room_id).all();
    // attach participant counts
    for (const c of results) {
      const pc = await env.DB.prepare("SELECT COUNT(*) as cnt FROM drawing_participants WHERE canvas_id=?").bind(c.id).all();
      c.participant_count = pc.results[0].cnt;
      c.data = c.data || '';
    }
    return jsonResponse(results);
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

get('/api/drawings/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const c = await env.DB.prepare("SELECT * FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length === 0) return errorResponse('Canvas not found', 404);
    const canvas = c.results[0];
    const parts = await env.DB.prepare("SELECT dp.user_id,u.username,u.display_name FROM drawing_participants dp JOIN users u ON dp.user_id=u.id WHERE dp.canvas_id=?").bind(id).all();
    const reqs = await env.DB.prepare("SELECT dr.id,dr.user_id,u.username,u.display_name FROM drawing_requests dr JOIN users u ON dr.user_id=u.id WHERE dr.canvas_id=? AND dr.status='pending'").bind(id).all();
    return jsonResponse({ ...canvas, participants: parts.results, pending_requests: reqs.results });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

post('/api/drawings', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { room_type, room_id, title, mode, pixel_size } = await request.json();
    const rt = room_type || 'public';
    const rid = parseInt(room_id || '0', 10);
    const t = (title || '协作画布').toString().substring(0, 100);
    const md = (mode === 'pixel') ? 'pixel' : 'free';
    const px = Math.max(4, Math.min(64, parseInt(pixel_size || '16', 10) || 16));
    const { results } = await env.DB.prepare(
      "INSERT INTO drawing_canvases (room_type,room_id,title,mode,pixel_size,owner_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,datetime('now','localtime'),datetime('now','localtime')) RETURNING id,room_type,room_id,title,mode,pixel_size,owner_id,created_by"
    ).bind(rt, rid, t, md, px, user.id, user.id).all();
    const canvas = results[0];
    await env.DB.prepare("INSERT OR IGNORE INTO drawing_participants (canvas_id,user_id) VALUES (?,?)").bind(canvas.id, user.id).run();
    // post a chat message linking to the canvas
    const meta = JSON.stringify({ canvas_id: canvas.id, title: canvas.title, mode: canvas.mode, pixel_size: canvas.pixel_size });
    if (rt === 'meeting' && rid > 0) {
      await env.DB.prepare("INSERT INTO meeting_messages (meeting_id,user_id,content,message_type,meta_data,created_at) VALUES (?,?,?,?,?,datetime('now','localtime'))").bind(rid, user.id, '创建了协作画布', 'drawing', meta).run();
    } else {
      await env.DB.prepare("INSERT INTO public_chat_messages (user_id,content,message_type,meta_data,created_at) VALUES (?,?,?,?,datetime('now','localtime'))").bind(user.id, '创建了协作画布', 'drawing', meta).run();
    }
    return jsonResponse(canvas, 201);
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

put('/api/drawings/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const body = await request.json();
    const c = await env.DB.prepare("SELECT owner_id FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length === 0) return errorResponse('Canvas not found', 404);
    if (c.results[0].owner_id !== user.id) return errorResponse('只有当前操控人可以绘制', 403);
    const d = (body.data || '').toString().substring(0, 3000000);
    const sets = ['data=?', "updated_at=datetime('now','localtime')"];
    const binds = [d];
    if (body.pixel_size !== undefined) { sets.push('pixel_size=?'); binds.push(Math.max(4, Math.min(64, parseInt(body.pixel_size, 10) || 16))); }
    binds.push(id);
    await env.DB.prepare("UPDATE drawing_canvases SET " + sets.join(',') + " WHERE id=?").bind(...binds).run();
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

post('/api/drawings/:id/join', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    await env.DB.prepare("INSERT OR IGNORE INTO drawing_participants (canvas_id,user_id) VALUES (?,?)").bind(id, user.id).run();
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

post('/api/drawings/:id/leave', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    await env.DB.prepare("DELETE FROM drawing_participants WHERE canvas_id=? AND user_id=?").bind(id, user.id).run();
    // if owner leaves, transfer ownership to another participant
    const c = await env.DB.prepare("SELECT owner_id FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length > 0 && c.results[0].owner_id === user.id) {
      const next = await env.DB.prepare("SELECT user_id FROM drawing_participants WHERE canvas_id=? AND user_id!=? LIMIT 1").bind(id, user.id).all();
      if (next.results.length > 0) {
        await env.DB.prepare("UPDATE drawing_canvases SET owner_id=? WHERE id=?").bind(next.results[0].user_id, id).run();
      }
    }
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

post('/api/drawings/:id/request-control', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const c = await env.DB.prepare("SELECT owner_id FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length === 0) return errorResponse('Canvas not found', 404);
    if (c.results[0].owner_id === user.id) return errorResponse('你已经是操控人', 400);
    const existing = await env.DB.prepare("SELECT id FROM drawing_requests WHERE canvas_id=? AND user_id=? AND status='pending'").bind(id, user.id).all();
    if (existing.results.length > 0) return errorResponse('已发送申请，等待操控人同意', 400);
    await env.DB.prepare("INSERT INTO drawing_requests (canvas_id,user_id,status,created_at) VALUES (?,?,'pending',datetime('now','localtime'))").bind(id, user.id).run();
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

post('/api/drawings/:id/control', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const { request_id, action } = await request.json();
    const c = await env.DB.prepare("SELECT owner_id FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length === 0) return errorResponse('Canvas not found', 404);
    if (c.results[0].owner_id !== user.id) return errorResponse('只有操控人可以处理申请', 403);
    const r = await env.DB.prepare("SELECT id,user_id FROM drawing_requests WHERE id=? AND canvas_id=? AND status='pending'").bind(request_id, id).all();
    if (r.results.length === 0) return errorResponse('申请不存在或已处理', 404);
    if (action === 'approve') {
      await env.DB.prepare("UPDATE drawing_requests SET status='approved' WHERE id=?").bind(request_id).run();
      await env.DB.prepare("UPDATE drawing_canvases SET owner_id=? WHERE id=?").bind(r.results[0].user_id, id).run();
      await env.DB.prepare("INSERT OR IGNORE INTO drawing_participants (canvas_id,user_id) VALUES (?,?)").bind(id, r.results[0].user_id).run();
    } else {
      await env.DB.prepare("UPDATE drawing_requests SET status='rejected' WHERE id=?").bind(request_id).run();
    }
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

del('/api/drawings/:id', async (request, env) => {
  try {
    const user = await requireAuth(request, env);
    const { id } = request.params;
    const c = await env.DB.prepare("SELECT created_by,owner_id FROM drawing_canvases WHERE id=?").bind(id).all();
    if (c.results.length === 0) return errorResponse('Canvas not found', 404);
    const canvas = c.results[0];
    if (canvas.created_by !== user.id && canvas.owner_id !== user.id && user.role !== 'admin') return errorResponse('无权删除', 403);
    await env.DB.prepare("DELETE FROM drawing_canvases WHERE id=?").bind(id).run();
    await env.DB.prepare("DELETE FROM drawing_participants WHERE canvas_id=?").bind(id).run();
    await env.DB.prepare("DELETE FROM drawing_requests WHERE canvas_id=?").bind(id).run();
    return jsonResponse({ success: true });
  } catch (e) { if (e.status) return errorResponse(e.message, e.status); return errorResponse(e.message || 'Internal error', 500); }
});

// ===================== IMAGE UPLOAD =====================
post('/api/upload/image', async (request, env) => {
  try { const user=await requireAuth(request,env); const {image,type}=await request.json();
    if (!image||image.length>500000) return errorResponse('Invalid or too large',400);
    const imgType=type||'image/png';
    const dataUrl=`data:${imgType};base64,${image.replace(/^data:image\/\w+;base64,/,'')}`;
    return jsonResponse({url:dataUrl});
  } catch (e) { if (e.status) return errorResponse(e.message,e.status); return errorResponse(e.message||'Internal error',500); }
});

// ===================== LINK PREVIEW =====================
get('/api/link-preview', async (request, env) => {
  try { const user=await requireAuth(request,env); const url=new URL(request.url); const targetUrl=url.searchParams.get('url');
    if (!targetUrl) return errorResponse('url parameter required',400);
    let parsedUrl; try { parsedUrl=new URL(targetUrl); } catch(_) { return errorResponse('Invalid URL',400); }
    if (parsedUrl.protocol!=='http:'&&parsedUrl.protocol!=='https:') return errorResponse('Only http/https',400);
    if (await isPrivateIP(parsedUrl.hostname)) return errorResponse('Cannot fetch private IP',400);
    const resp=await fetch(targetUrl,{headers:{'User-Agent':'ForgeWorkspace/1.0'},signal:AbortSignal.timeout(5000)});
    const html=await resp.text();
    const title=html.match(/<title[^>]*>([^<]*)<\/title>/i)?.[1]||'';
    const desc=html.match(/<meta[^>]+name=["']description["'][^>]+content=["']([^"']*)["']/i)?.[1]||html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+name=["']description["']/i)?.[1]||'';
    const ogImage=html.match(/<meta[^>]+property=["']og:image["'][^>]+content=["']([^"']*)["']/i)?.[1]||html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:image["']/i)?.[1]||'';
    const ogTitle=html.match(/<meta[^>]+property=["']og:title["'][^>]+content=["']([^"']*)["']/i)?.[1]||html.match(/<meta[^>]+content=["']([^"']*)["'][^>]+property=["']og:title["']/i)?.[1]||'';
    const favicon=html.match(/<link[^>]+rel=["'](?:shortcut )?icon["'][^>]+href=["']([^"']*)["']/i)?.[1]||'';
    return jsonResponse({url:targetUrl,title:ogTitle||title,description:desc,image:ogImage,favicon:favicon?new URL(favicon,targetUrl).href:`${parsedUrl.origin}/favicon.ico`});
  } catch(e) { if(e.status) return errorResponse(e.message,e.status); return errorResponse('Failed to fetch preview',500,e.message); }
});

// ===================== EXPORT =====================
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    if (env.DB) { try { await ensureSchema(env); } catch (e) { console.error('ensureSchema error:', e.message); } }

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: { 'access-control-allow-origin': '*', 'access-control-allow-methods': 'GET,POST,PUT,DELETE,OPTIONS', 'access-control-allow-headers': 'Content-Type, Authorization', 'access-control-allow-credentials': 'true' } });
    }

    if (url.pathname.startsWith('/api/')) {
      try {
        return await handleRequest(request, env);
      } catch (e) {
        console.error('Unhandled error:', e);
        return errorResponse('Internal error', 500);
      }
    }

    return env.ASSETS.fetch(request);
  },
};
