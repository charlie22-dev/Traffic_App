import net from 'node:net';
import { launch, stop, windows } from './process.mjs';

async function checkPort(port) {
  return await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', () => reject(new Error(`Port ${port} is already in use. Stop the existing server before running dev.`)));
    server.listen(port, '127.0.0.1', () => {
      const allocatedPort = server.address().port;
      server.close(() => resolve(allocatedPort));
    });
  });
}

export async function startDev({ apiPort = 8000, webPort = 5173, handleSignals = true, quiet = false, scheduler = false } = {}) {
  apiPort = await checkPort(apiPort);
  webPort = await checkPort(webPort);
  if (apiPort === webPort) webPort = await checkPort(0);
  const apiUrl = `http://127.0.0.1:${apiPort}`;
  const webUrl = `http://127.0.0.1:${webPort}`;
  const children = [];
  let stopping = false;
  const close = () => {
    if (stopping) return;
    stopping = true;
    for (const child of children) stop(child);
  };
  let resolveDone;
  let rejectDone;
  const done = new Promise((resolve, reject) => { resolveDone = resolve; rejectDone = reject; });
  const processes = [
    ['php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${apiPort}`, '--tries=1'], 'backend'],
    [process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', String(webPort), '--strictPort'], 'commuter-web'],
  ];
  if (scheduler) processes.push(['php', ['artisan', 'schedule:work'], 'backend']);
  for (const [command, args, folder] of processes) {
    const child = launch(command, args, folder, {
      detached: !windows,
      stdio: quiet ? 'ignore' : 'inherit',
      env: { ...process.env, COMMUTELIVE_API_PROXY_TARGET: apiUrl },
    });
    children.push(child);
    child.once('error', error => { close(); rejectDone(error); });
    child.once('exit', code => {
      if (!stopping) { close(); rejectDone(new Error(`${folder} exited unexpectedly (${code}).`)); }
    });
  }
  const onSignal = () => { close(); resolveDone(); };
  if (handleSignals) {
    process.once('SIGINT', onSignal);
    process.once('SIGTERM', onSignal);
  }
  console.log(`\nCommuteLive: ${webUrl} | API: ${apiUrl}/api/health${scheduler ? ' | Scheduler ON' : ''}\nPress Ctrl+C to stop local processes.`);
  return { done, apiUrl, webUrl, close: () => {
    close();
    process.removeListener('SIGINT', onSignal);
    process.removeListener('SIGTERM', onSignal);
    resolveDone();
  } };
}
