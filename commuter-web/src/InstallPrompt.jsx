import { useEffect, useState } from 'react';

export default function InstallPrompt() {
  const [prompt, setPrompt] = useState(null);
  const [help, setHelp] = useState(false);
  const [standalone, setStandalone] = useState(window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone);
  useEffect(() => {
    const available = event => { event.preventDefault(); setPrompt(event); };
    const installed = () => { setStandalone(true); setPrompt(null); };
    window.addEventListener('beforeinstallprompt', available);
    window.addEventListener('appinstalled', installed);
    return () => { window.removeEventListener('beforeinstallprompt', available); window.removeEventListener('appinstalled', installed); };
  }, []);
  async function install() {
    if (!prompt) { setHelp(value => !value); return; }
    try { await prompt.prompt(); await prompt.userChoice; }
    catch { setHelp(true); }
    finally { setPrompt(null); }
  }
  if (standalone) return null;
  return <div className="install-control"><button className="quiet-button" onClick={install}>Install app</button>
    {help && <p role="status">Use your browser’s menu to install or add to your home screen. Installation needs HTTPS or localhost. The app keeps a saved route view; live rides need a connection.</p>}</div>;
}
