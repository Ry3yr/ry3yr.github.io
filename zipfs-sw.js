/*  zipfs-sw.js  */
importScripts('https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js');

const ZIP_URL = 'https://ry3yr.github.io/alceawis.de.zip';
const files   = new Map();

/* Promise that resolves when the ZIP has finished loading */
let zipReadyResolve;
const zipReadyPromise = new Promise(res => { zipReadyResolve = res; });

function swLog(...args) {
  const msg = '[ZIPFS SW] ' + args.join(' ');
  console.log(msg);
  self.clients.matchAll().then(clients =>
    clients.forEach(c => c.postMessage({ type: 'SW_LOG', text: msg }))
  );
}

/* ----- install ----------------------------------------------------------- */
self.addEventListener('install', () => {
  swLog('Installing…');
  self.skipWaiting();
});

/* ----- activate: download + unzip --------------------------------------- */
self.addEventListener('activate', event => {
  swLog('Activating → downloading ZIP…');
  event.waitUntil(
    (async () => {
      const r = await fetch(ZIP_URL, { mode: 'cors' });
      swLog('ZIP fetch status:', r.status);
      if (!r.ok) throw new Error('ZIP fetch failed');

      const buf = await r.arrayBuffer();
      swLog('Unzipping…');
      const zip   = await JSZip.loadAsync(buf);
      let   count = 0;

      for (const entry of Object.values(zip.files)) {
        if (entry.dir) continue;
        const path    = '/' + entry.name.split('/').slice(1).join('/');
        const content = await entry.async('uint8array');
        files.set(path, content);
        swLog('→', path, `(${content.length} bytes)`);
        count++;
      }

      swLog('Unzip done –', count, 'files');
      zipReadyResolve();
      await self.clients.claim();
    })().catch(err => swLog('ZIP load failed:', err))
  );
});

/* ----- fetch ------------------------------------------------------------- */
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  /* 1️⃣  Let cross‑origin requests go to the network untouched */
  if (url.origin !== self.location.origin) return;

  /* 2️⃣  Friendly URLs: “/”, “/root”, “/home” → “/index.html” */
  let path = url.pathname;
  if (path === '/' || path === '/root' || path === '/home') path = '/index.html';

  event.respondWith(
    (async () => {
      /* Wait until the ZIP is ready */
      await zipReadyPromise;

      const file = files.get(path);
      if (file) {
        swLog('Serving', path, 'from ZIP');
        return new Response(file, { headers: { 'Content-Type': guessType(path) } });
      }

      /* 3️⃣  Same‑origin file not in ZIP → fall back to network */
      swLog('Not in ZIP, fetching from network:', path);
      try {
        return await fetch(event.request);
      } catch (err) {
        swLog('Network fetch failed:', err);
        return new Response('<h1>Offline & not cached</h1>', {
          headers: { 'Content-Type': 'text/html' },
          status : 503
        });
      }
    })()
  );
});

/* ----- helpers ----------------------------------------------------------- */
function guessType(p) {
  return p.endsWith('.html')             ? 'text/html'            :
         p.endsWith('.js')               ? 'application/javascript':
         p.endsWith('.css')              ? 'text/css'             :
         p.endsWith('.json')             ? 'application/json'     :
         p.endsWith('.png')              ? 'image/png'            :
         p.endsWith('.jpg')||p.endsWith('.jpeg') ? 'image/jpeg'    :
         p.endsWith('.svg')              ? 'image/svg+xml'        :
         'application/octet-stream';
}

/* Optional error logging */
self.addEventListener('error',             e => swLog('Error:', e.message));
self.addEventListener('unhandledrejection',e => swLog('Unhandled rejection:', e.reason));
