import { useEffect, useState } from 'react';

export default function PwaUpdate() {
  const [update, setUpdate] = useState(null);
  useEffect(() => {
    const ready = event => setUpdate(() => event.detail.update);
    window.addEventListener('commutelive:update', ready);
    return () => window.removeEventListener('commutelive:update', ready);
  }, []);
  return update ? <div className="connection-notice" role="status"><p>A new version is ready.</p><button className="quiet-button" onClick={() => update()}>Update app</button></div> : null;
}
