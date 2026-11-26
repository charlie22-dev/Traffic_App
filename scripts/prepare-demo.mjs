import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFileSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { join } from 'node:path';
import { root, run } from './process.mjs';

export async function prepareDemo() {
  await run('php', ['artisan', 'config:clear'], 'backend');
  const { stdout } = await promisify(execFile)('php', ['-r',
    "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(['password' => config('commutelive.demo_password'), 'environment' => app()->environment()]);"],
  { cwd: join(root, 'backend'), windowsHide: true });
  const runtimeConfig = JSON.parse(stdout);
  if (!['local', 'testing'].includes(runtimeConfig.environment)) throw new Error('Synthetic demos run only in local/testing environments.');
  let password = runtimeConfig.password;
  if (!password) {
    password = randomBytes(24).toString('base64url');
    const envPath = join(root, 'backend', '.env');
    let contents = readFileSync(envPath, 'utf8');
    contents = /^DEMO_PASSWORD=.*$/m.test(contents)
      ? contents.replace(/^DEMO_PASSWORD=.*$/m, `DEMO_PASSWORD=${password}`)
      : `${contents.trimEnd()}\nDEMO_PASSWORD=${password}\n`;
    writeFileSync(envPath, contents);
    console.log('Generated demo credentials in ignored backend/.env. Credentials and tokens are not printed.');
  }
  if (typeof password !== 'string' || password.length < 12) throw new Error('Set DEMO_PASSWORD to at least 12 characters in backend/.env.');
  await run('php', ['artisan', 'migrate', '--no-interaction'], 'backend');
  await run('php', ['artisan', 'db:seed', '--no-interaction'], 'backend');
  return password;
}
