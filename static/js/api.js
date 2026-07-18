const API_BASE = '';

function getToken() { return localStorage.getItem('forge_token'); }

const API = {
  async request(method, path, body) {
    const opts = { method, headers: {} };
    const token = getToken();
    if (token) opts.headers['Authorization'] = 'Bearer ' + token;
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(API_BASE + path, opts);
    if (res.status === 401) {
      localStorage.removeItem('forge_token');
      window.location.href = '/login.html';
      return;
    }
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      const data = await res.json();
      if (!res.ok) {
        const err = new Error((data && data.error) ? data.error : ('请求失败 (' + res.status + ')'));
        err.status = res.status;
        throw err;
      }
      return data;
    }
    if (!res.ok) throw new Error('请求失败 (' + res.status + ')');
    return { error: '非JSON响应', status: res.status };
  },
  get(path) { return this.request('GET', path); },
  post(path, body) { return this.request('POST', path, body); },
  put(path, body) { return this.request('PUT', path, body); },
  del(path) { return this.request('DELETE', path); }
};
