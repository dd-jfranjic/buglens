/**
 * BugLens MCP Tools — v3.2.0 additions.
 *
 * lego-audit:ignore (function > 30 LoC)
 * Each server.tool() call is ~12 LoC (name, description, zod schema, handler).
 * 5 sequential registrations = 60+ LoC by MCP SDK design. Splitting per-tool
 * would create 5 micro-files for no benefit. This is the same registration
 * pattern Josip uses in index.js startServer() for the original 14 tools.
 *
 * Registers: batch_write, verify_files, health_check, preflight_paths, rescue_call.
 * Extracted from index.js to keep that file under closer-to-200 LoC.
 *
 * @since 3.2.0
 */

import { z } from 'zod';

export function registerV320Tools(server, client) {

  server.tool('batch_write',
    'Atomically write multiple files (all-or-nothing, max 50 files, 10MB total). Prevents partial-deploy disasters where N files succeed but N+1 fails.',
    { files: z.array(z.object({
        path: z.string(),
        content: z.string(),
        sha256: z.string().optional()
      })).describe('Array of files to write atomically') },
    async ({ files }) => {
      const data = await client.batchWrite(files);
      return { content: [{ type: 'text',
        text: `Atomically wrote ${data.written}/${files.length} files.\n\n${JSON.stringify(data.files, null, 2)}` }] };
    }
  );

  server.tool('verify_files',
    'Bulk verify file existence + SHA256 hashes. Use for pre/post-deploy assertions.',
    { files: z.array(z.object({
        path: z.string(),
        sha256: z.string().optional()
      })).describe('Files to verify (sha256 optional)') },
    async ({ files }) => {
      const data = await client.verifyFiles(files);
      return { content: [{ type: 'text', text: JSON.stringify(data.files, null, 2) }] };
    }
  );

  server.tool('health_check',
    'Diagnostic snapshot — WP boot status, PHP/MySQL versions, memory, error log tail, rescue mode status. Use before risky deploys.',
    {},
    async () => {
      const data = await client.healthCheck();
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.tool('preflight_paths',
    'Dry-run validate paths (parent exists? writable? blocked?) without any file changes. Use before /batch_write.',
    { paths: z.array(z.object({
        path: z.string(),
        mode: z.enum(['read', 'write']).optional()
      })).describe('Paths to validate') },
    async ({ paths }) => {
      const data = await client.preflightPaths(paths);
      return { content: [{ type: 'text', text: JSON.stringify(data.paths, null, 2) }] };
    }
  );

  server.tool('rescue_call',
    'EMERGENCY: Call rescue.php directly (bypasses WP REST). Use only when WP is in fatal-error state (REST endpoints return 500). Requires BUGLENS_RESCUE_URL + BUGLENS_RESCUE_KEY env vars.',
    { op: z.enum(['read', 'write', 'delete', 'list', 'info', 'mkdir', 'rename']),
      path: z.string(),
      content: z.string().optional(),
      to: z.string().optional() },
    async ({ op, path, content, to }) => {
      const params = { path };
      if (content !== undefined) params.content = content;
      if (to !== undefined) params.to = to;
      const data = await client.rescueCall(op, params);
      return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
    }
  );
}
