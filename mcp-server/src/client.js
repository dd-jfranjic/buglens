/**
 * BugLens Bridge HTTP Client
 *
 * Wraps all /buglens/v1/fs/* REST API calls.
 */

export class BugLensClient {
  constructor(siteUrl, apiKey) {
    this.baseUrl = siteUrl.replace(/\/$/, '') + '/wp-json/buglens/v1';
    this.apiKey = apiKey;
    this.token = null;
    this.tokenExpiry = 0;
  }

  async request(endpoint, { method = 'POST', body, query } = {}) {
    const headers = {
      'X-BugLens-Key': this.apiKey,
    };

    if (this.token) {
      headers['X-BugLens-Token'] = this.token;
    }

    let url = this.baseUrl + endpoint;
    if (query) url += '?' + new URLSearchParams(query).toString();

    const opts = { method, headers, signal: AbortSignal.timeout(30000) };

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    const response = await fetch(url, opts);

    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error(`Non-JSON response from ${endpoint} (HTTP ${response.status})`);
    }

    if (!response.ok) {
      const msg = data.error || data.message || `HTTP ${response.status}`;
      throw new Error(msg);
    }

    return data;
  }

  // Token management
  async authenticate() {
    const data = await this.request('/fs/token', { body: {} });
    this.token = data.token;
    this.tokenExpiry = Date.now() + (data.expires_in * 1000) - 60000; // 1 min buffer
    return data;
  }

  async ensureToken() {
    if (this.token && Date.now() < this.tokenExpiry) return;
    try {
      await this.authenticate();
    } catch {
      // Token auth may not be enabled — continue without
      this.token = null;
    }
  }

  // File operations
  async readFile(path, offset, limit) {
    await this.ensureToken();
    const body = { path };
    if (offset !== undefined) body.offset = offset;
    if (limit !== undefined) body.limit = limit;
    return this.request('/fs/read', { body });
  }

  async writeFile(path, content) {
    await this.ensureToken();
    return this.request('/fs/write', { body: { path, content } });
  }

  async createFile(path, content = '', directory = false) {
    await this.ensureToken();
    return this.request('/fs/create', { body: { path, content, directory } });
  }

  async deleteFile(path) {
    await this.ensureToken();
    return this.request('/fs/delete', { body: { path } });
  }

  async renameFile(from, to) {
    await this.ensureToken();
    return this.request('/fs/rename', { body: { from, to } });
  }

  async listDirectory(path = '') {
    await this.ensureToken();
    return this.request('/fs/list', { body: { path } });
  }

  async searchFiles(pattern, path = '', glob = '', regex = false, maxResults = 100, context = 0) {
    await this.ensureToken();
    const body = { pattern, path, max_results: maxResults };
    if (glob) body.glob = glob;
    if (regex) body.regex = true;
    if (context > 0) body.context = context;
    return this.request('/fs/search', { body });
  }

  async fileInfo(path) {
    await this.ensureToken();
    return this.request('/fs/info', { body: { path } });
  }

  async diffFile(path, content) {
    await this.ensureToken();
    return this.request('/fs/diff', { body: { path, content } });
  }

  async bulkRead(paths) {
    await this.ensureToken();
    return this.request('/fs/bulk-read', { body: { paths } });
  }

  async directoryTree(path = '', depth = 3, pattern = '') {
    await this.ensureToken();
    return this.request('/fs/tree', { body: { path, depth, pattern } });
  }

  // Bug reports
  async getReports(status) {
    await this.ensureToken();
    const query = {};
    if (status) query.status = status;
    return this.request('/reports', { method: 'GET', query });
  }

  async getReport(id) {
    await this.ensureToken();
    return this.request('/reports/' + id, { method: 'GET' });
  }

  async updateReportStatus(id, status) {
    await this.ensureToken();
    return this.request('/reports/' + id, { method: 'PATCH', body: { status } });
  }
}
