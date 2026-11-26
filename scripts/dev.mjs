import net from 'node:net';
import { launch, stop, windows } from './process.mjs';

async function checkPort(port) {
  await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', () => reject(new Error(`Port ${port} is already in use. Stop the existing server before running dev.`)));
    server.listen(port, '127.0.0.1', () => server.close(resolve));
  });
}

export async function startDev() {
  await checkPort(8000);
  await checkPort(5173);
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
  for (const [command, args, folder] of [
    ['php', ['artisan', 'serve', '--host=127.0.0.1', '--port=8000', '--tries=1'], 'backend'],
    [process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5173', '--strictPort'], 'commuter-web'],
  ]) {
    const child = launch(command, args, folder, { detached: !windows });
    children.push(child);
    child.once('error', error => { close(); rejectDone(error); });
    child.once('exit', code => {
      if (!stopping) { close(); rejectDone(new Error(`${folder} exited unexpectedly (${code}).`)); }
    });
  }
  const onSignal = () => { close(); resolveDone(); };
  process.once('SIGINT', onSignal);
  process.once('SIGTERM', onSignal);
  console.log('\nCommuteLive: http://127.0.0.1:5173 | API: http://127.0.0.1:8000/api/health\nPress Ctrl+C to stop both servers.');
  return { done, close: () => {
    close();
    process.removeListener('SIGINT', onSignal);
    process.removeListener('SIGTERM', onSignal);
    resolveDone();
  } };
}
