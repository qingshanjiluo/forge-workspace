const API = {
  async request(method, path, body) {
    const opts = { method, headers: {} };
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const res = await fetch(path, opts);
    if (res.status === 401) {
      window.location.href = '/login.html';
      return;
    }
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      return res.json();
    }
    return { error: '非JSON响应', status: res.status };
  },
  get(path) { return this.request('GET', path); },
  post(path, body) { return this.request('POST', path, body); },
  put(path, body) { return this.request('PUT', path, body); },
  del(path) { return this.request('DELETE', path); }
};
