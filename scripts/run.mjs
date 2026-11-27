import { existsSync, copyFileSync, readFileSync, openSync, closeSync } from 'node:fs';
import { join } from 'node:path';
import { root, run, python, windows } from './process.mjs';
import { startDev } from './dev.mjs';

async function setup() {
  // Verify tools before installing; don't install or replace system runtimes.
  await run('php', ['-v']);
  await run('composer', ['--version']);
  await run('flutter', ['--version']);
  const systemPython = windows ? 'python' : 'python3';
  await run(systemPython, ['-c', 'import sys; assert (3, 11) <= sys.version_info < (3, 14), "Use Python 3.11, 3.12 or 3.13"']);
  await run('composer', ['install', '--no-interaction', '--prefer-dist'], 'backend');
  const envPath = join(root, 'backend', '.env');
  if (!existsSync(envPath)) copyFileSync(join(root, 'backend', '.env.example'), envPath);
  if (!/^APP_KEY=.+/m.test(readFileSync(envPath, 'utf8'))) {
    await run('php', ['artisan', 'key:generate', '--no-interaction'], 'backend');
  }
  const dbPath = join(root, 'backend', 'database', 'database.sqlite');
  if (!existsSync(dbPath)) closeSync(openSync(dbPath, 'a'));
  await run('php', ['artisan', 'config:clear'], 'backend');
  await run('php', ['artisan', 'migrate', '--no-interaction'], 'backend');
  await run('npm', ['ci', '--no-fund', '--no-audit'], 'commuter-web');
  await run('flutter', ['pub', 'get'], 'driver-app');
  if (!existsSync(python)) await run(systemPython, ['-m', 'venv', '.venv'], 'simulator');
  await run(python, ['-m', 'pip', 'install', '-r', 'requirements.txt'], 'simulator');
  await run(python, ['-m', 'pip', 'install', '-r', 'analysis/requirements.txt']);
  console.log('\nSetup complete. Run npm run dev, or npm test for all checks.');
}

async function test() {
  await run('php', ['artisan', 'config:clear'], 'backend');
  await run('php', ['artisan', 'test'], 'backend');
  await run('npm', ['test'], 'commuter-web');
  await run('npm', ['run', 'build'], 'commuter-web');
  await run('flutter', ['analyze'], 'driver-app');
  await run('flutter', ['test'], 'driver-app');
  await run(python, ['-m', 'unittest', 'discover', '-s', 'tests', '-v'], 'simulator');
  await run(python, ['-m', 'unittest', 'discover', '-s', 'data/scripts/tests', '-v']);
  await run(python, ['-m', 'unittest', 'discover', '-s', 'analysis/tests', '-v']);
  await run(python, ['analysis/run_notebook.py']);
  await run(process.execPath, ['scripts/smoke.mjs']);
  console.log('\nAll automated checks passed. See docs/PROGRESS.md for phase acceptance gates.');
}

try {
  switch (process.argv[2]) {
    case 'setup': await setup(); break;
    case 'dev': { const servers = await startDev({ scheduler: true }); await servers.done; break; }
    case 'test': await test(); break;
    case 'evaluate-eta': await run(python, ['analysis/run_notebook.py']); break;
    default: throw new Error('Use: node scripts/run.mjs setup|dev|test|evaluate-eta');
  }
} catch (error) {
  console.error(`\n${error.message}`);
  process.exitCode = 1;
}
