import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

export const root = fileURLToPath(new URL('../', import.meta.url));
export const windows = process.platform === 'win32';

export function launch(command, args, folder = '.', options = {}) {
  const batch = windows && ['npm', 'composer', 'flutter'].includes(command);
  if (batch && args.some(arg => !/^[a-zA-Z0-9_.=\/-]+$/.test(arg))) {
    throw new Error('Batch arguments must be fixed command options without shell characters.');
  }
  return spawn(batch ? `${command} ${args.join(' ')}` : command, batch ? [] : args, {
    cwd: join(root, folder),
    stdio: 'inherit',
    windowsHide: true,
    // Only fixed package-manager commands use the shell for Windows .bat/.cmd.
    shell: batch,
    ...options,
  });
}

export function run(command, args, folder = '.') {
  console.log(`\n[${folder}] ${command} ${args.join(' ')}`);
  return new Promise((resolve, reject) => {
    const child = launch(command, args, folder);
    child.once('error', reject);
    child.once('exit', (code, signal) => code === 0
      ? resolve()
      : reject(new Error(`${command} failed (${signal ?? code}). Check the output above.`)));
  });
}

export function stop(child) {
  if (!child?.pid) return;
  if (windows) {
    spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], {
      stdio: 'ignore', windowsHide: true,
    });
  } else {
    try { process.kill(-child.pid, 'SIGTERM'); } catch { /* Already stopped. */ }
  }
}

export const python = windows
  ? join(root, 'simulator', '.venv', 'Scripts', 'python.exe')
  : join(root, 'simulator', '.venv', 'bin', 'python');
