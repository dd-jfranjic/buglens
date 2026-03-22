import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { BugLensClient } from './client.js';

export async function startServer() {
  const siteUrl = process.env.BUGLENS_URL;
  const apiKey  = process.env.BUGLENS_KEY;

  if (!siteUrl || !apiKey) {
    console.error('Error: BUGLENS_URL and BUGLENS_KEY environment variables are required.');
    console.error('Usage: BUGLENS_URL=https://yoursite.com BUGLENS_KEY=your_key npx buglens-mcp');
    process.exit(1);
  }

  const client = new BugLensClient(siteUrl, apiKey);
  const server = new McpServer({
    name: 'buglens',
    version: '1.0.0',
  });

  // --- File Operations ---

  server.tool('read_file',
    'Read the contents of a file from the WordPress site',
    { path: z.string().describe('Relative path from WordPress root, e.g. wp-content/themes/flavor/style.css'),
      offset: z.number().optional().describe('Start reading from this line number (0-based)'),
      limit: z.number().optional().describe('Maximum number of lines to read') },
    async ({ path, offset, limit }) => {
      const data = await client.readFile(path, offset, limit);
      if (data.binary) {
        return { content: [{ type: 'text', text: `Binary file (${data.type}, ${data.size} bytes). Cannot display content.` }] };
      }
      return { content: [{ type: 'text', text: data.content ?? '' }] };
    }
  );

  server.tool('write_file',
    'Write content to an existing file on the WordPress site',
    { path: z.string().describe('Relative path from WordPress root'),
      content: z.string().describe('New file content') },
    async ({ path, content }) => {
      const data = await client.writeFile(path, content);
      return { content: [{ type: 'text', text: `Written ${data.bytes} bytes to ${data.path}` }] };
    }
  );

  server.tool('create_file',
    'Create a new file or directory on the WordPress site',
    { path: z.string().describe('Relative path from WordPress root'),
      content: z.string().optional().default('').describe('File content (empty for directories)'),
      directory: z.boolean().optional().default(false).describe('Create a directory instead of a file') },
    async ({ path, content, directory }) => {
      const data = await client.createFile(path, content, directory);
      return { content: [{ type: 'text', text: `Created ${data.created}: ${data.path}` }] };
    }
  );

  server.tool('delete_file',
    'Delete a file or empty directory from the WordPress site',
    { path: z.string().describe('Relative path from WordPress root') },
    async ({ path }) => {
      const data = await client.deleteFile(path);
      return { content: [{ type: 'text', text: `Deleted: ${data.path}` }] };
    }
  );

  server.tool('rename_file',
    'Rename or move a file/directory on the WordPress site',
    { from: z.string().describe('Current relative path'),
      to: z.string().describe('New relative path') },
    async ({ from, to }) => {
      const data = await client.renameFile(from, to);
      return { content: [{ type: 'text', text: `Moved ${data.from} → ${data.to}` }] };
    }
  );

  server.tool('list_directory',
    'List contents of a directory on the WordPress site',
    { path: z.string().optional().default('').describe('Relative path (empty for WordPress root)') },
    async ({ path }) => {
      const data = await client.listDirectory(path);
      const lines = (data.items || []).map(i => {
        const prefix = i.is_dir ? '[DIR] ' : '      ';
        const size = i.size !== null ? ` (${formatSize(i.size)})` : '';
        return prefix + i.name + size;
      });
      return { content: [{ type: 'text', text: `${data.path} (${data.count} items):\n\n` + lines.join('\n') }] };
    }
  );

  server.tool('search_files',
    'Search for text/pattern across files on the WordPress site (like grep)',
    { pattern: z.string().describe('Search string or regex pattern'),
      path: z.string().optional().default('').describe('Directory to search in (empty for entire site)'),
      glob: z.string().optional().default('*.php,*.css,*.js,*.html,*.txt,*.json,*.md').describe('File glob patterns, comma-separated'),
      regex: z.boolean().optional().default(false).describe('Treat pattern as regex'),
      max_results: z.number().optional().default(100).describe('Maximum results'),
      context: z.number().optional().default(0).describe('Lines of context around each match') },
    async ({ pattern, path, glob, regex, max_results, context }) => {
      const data = await client.searchFiles(pattern, path, glob, regex, max_results, context);
      const lines = (data.matches || []).map(m => `${m.file}:${m.line}: ${m.text}`);
      const header = `Found ${data.total_matches} matches in ${data.files_searched} files` +
        (data.truncated ? ' (truncated)' : '');
      return { content: [{ type: 'text', text: header + '\n\n' + lines.join('\n') }] };
    }
  );

  server.tool('file_info',
    'Get metadata about a file or directory (size, permissions, modified date)',
    { path: z.string().describe('Relative path from WordPress root') },
    async ({ path }) => {
      const data = await client.fileInfo(path);
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.tool('diff_file',
    'Compare content against the current file on disk',
    { path: z.string().describe('Relative path to the file'),
      content: z.string().describe('Content to compare against the file on disk') },
    async ({ path, content }) => {
      const data = await client.diffFile(path, content);
      if (!data.has_changes) {
        return { content: [{ type: 'text', text: 'No differences found.' }] };
      }
      const lines = data.changes.map(c => {
        let out = `Line ${c.line}:`;
        if (c.old !== undefined) out += `\n  - ${c.old}`;
        if (c.new !== undefined) out += `\n  + ${c.new}`;
        return out;
      });
      return { content: [{ type: 'text', text: `${data.total_changes} changes:\n\n` + lines.join('\n\n') }] };
    }
  );

  server.tool('bulk_read',
    'Read multiple files at once (max 20)',
    { paths: z.array(z.string()).describe('Array of relative file paths') },
    async ({ paths }) => {
      const data = await client.bulkRead(paths);
      const blocks = (data.files || []).map(f => {
        if (f.error) return `--- ${f.path} ---\nERROR: ${f.error}`;
        return `--- ${f.path} (${f.size} bytes) ---\n${f.content}`;
      });
      return { content: [{ type: 'text', text: blocks.join('\n\n') }] };
    }
  );

  server.tool('directory_tree',
    'Get a tree view of the directory structure',
    { path: z.string().optional().default('').describe('Root directory (empty for WordPress root)'),
      depth: z.number().optional().default(3).describe('Maximum depth (1-10)'),
      pattern: z.string().optional().default('').describe('File name pattern filter (e.g. *.php)') },
    async ({ path, depth, pattern }) => {
      const data = await client.directoryTree(path, depth, pattern);
      const text = renderTree(data.tree, '');
      return { content: [{ type: 'text', text: `${data.path}:\n\n` + text }] };
    }
  );

  // --- Bug Reports ---

  server.tool('get_bug_reports',
    'List all BugLens bug reports',
    { status: z.string().optional().describe('Filter by status: open, in_progress, resolved, closed') },
    async ({ status }) => {
      const data = await client.getReports(status);
      const lines = (data.reports || []).map(r =>
        `#${r.id} [${r.status}] ${r.title} — ${r.page_url || 'no URL'}`
      );
      return { content: [{ type: 'text', text: `${data.total} reports:\n\n` + lines.join('\n') }] };
    }
  );

  server.tool('get_bug_report',
    'Get full details of a specific BugLens bug report',
    { id: z.number().describe('Report ID') },
    async ({ id }) => {
      const data = await client.getReport(id);
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.tool('update_bug_status',
    'Update the status of a BugLens bug report',
    { id: z.number().describe('Report ID'),
      status: z.enum(['open', 'in_progress', 'resolved', 'closed']).describe('New status') },
    async ({ id, status }) => {
      const data = await client.updateReportStatus(id, status);
      return { content: [{ type: 'text', text: `Report #${id} status updated to: ${status}` }] };
    }
  );

  // --- Helpers ---

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function renderTree(items, prefix) {
    let out = '';
    items.forEach((item, i) => {
      const isLast = i === items.length - 1;
      const connector = isLast ? '└── ' : '├── ';
      const childPrefix = isLast ? '    ' : '│   ';
      out += prefix + connector + item.name;
      if (!item.is_dir && item.size !== undefined) {
        out += ` (${formatSize(item.size)})`;
      }
      out += '\n';
      if (item.children && item.children.length > 0) {
        out += renderTree(item.children, prefix + childPrefix);
      }
    });
    return out;
  }

  // --- Start ---

  const transport = new StdioServerTransport();
  await server.connect(transport);
}
