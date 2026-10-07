/** Local HTTPS preview for integrations that require TLS (including Accept.js). */
import https from 'node:https';
import { readFileSync, existsSync, mkdirSync, chmodSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import next from 'next';

const project = fileURLToPath(new URL('../', import.meta.url));
const port = Number(process.env.HTTPS_PREVIEW_PORT || 3443);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid HTTPS_PREVIEW_PORT.');
const certificates = path.join(project, '.local-https');
const cert = path.join(certificates, 'localhost.pem');
const key = path.join(certificates, 'localhost-key.pem');
if (!existsSync(cert) || !existsSync(key)) {
  mkdirSync(certificates, { recursive: true, mode: 0o700 });
  const result = spawnSync('mkcert', ['-cert-file', cert, '-key-file', key, 'localhost', '127.0.0.1', '::1'], { stdio: 'inherit' });
  if (result.status !== 0) throw new Error('Install mkcert to generate the local HTTPS certificate.');
  chmodSync(key, 0o600);
}
const app = next({ dev: false, dir: project, hostname: 'localhost', port });
await app.prepare();
const handle = app.getRequestHandler();
https.createServer({ cert: readFileSync(cert), key: readFileSync(key) }, (req, res) => {
  void handle(req, res).catch(() => {
    if (!res.headersSent) res.writeHead(500);
    res.end('The local preview could not complete this request.');
  });
}).listen(port, '127.0.0.1', () => console.log(`Local HTTPS preview: https://localhost:${port}`));