importScripts('jszip.min.js');

const ZIP_URL = 'https://ry3yr.github.io/alceawis.de.zip';
const files = new Map();

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

/* ----- activate ---------------------------------------------------------- */
self.addEventListener('activate', event => {
  swLog('Activating and claiming clients immediately');
  event.waitUntil(
    (async () => {
      await self.clients.claim();
      swLog('Clients claimed, now downloading ZIP');
      try {
        const r = await fetch(ZIP_URL, { mode: 'cors' });
        swLog('ZIP fetch status:', r.status);
        if (!r.ok) throw new Error('ZIP fetch failed');

        const buf = await r.arrayBuffer();
        swLog('Unzipping…');
        const zip = await JSZip.loadAsync(buf);
        let count = 0;

        for (const entry of Object.values(zip.files)) {
          if (entry.dir) continue;
          const path = '/' + entry.name; // Use full name, no slice
          const content = await entry.async('uint8array');
          files.set(path, content);
          swLog('→', path, `(${content.length} bytes)`);
          count++;
        }

        swLog('Unzip done –', count, 'files');
        zipReadyResolve();
      } catch (err) {
        swLog('ZIP load failed:', err);
        zipReadyResolve(); // Resolve anyway so fetch handlers don't hang
      }
    })()
  );
});

/* ----- fetch ------------------------------------------------------------- */
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  if (url.origin !== self.location.origin) return;

  let path = url.pathname;
  if (path === '/' || path === '/root' || path === '/home') path = '/index.html';

  event.respondWith((async () => {
    await zipReadyPromise;

    const file = files.get(path);
    if (file) {
      swLog('Serving', path, 'from ZIP');
      return new Response(file, { headers: { 'Content-Type': guessType(path) } });
    }

    if (path === '/index.html') {
      swLog('ERROR: /index.html not found in ZIP, returning error page');
      return new Response('<h1>Error: index.html not found in ZIP and cannot load from network.</h1>', {
        status: 503,
        headers: { 'Content-Type': 'text/html' }
      });
    }

    swLog('Not in ZIP, fetching from network:', path);
    try {
      return await fetch(event.request);
    } catch (err) {
      swLog('Network fetch failed:', err);
      return new Response('<h1>Offline & not cached</h1>', {
        status: 503,
        headers: { 'Content-Type': 'text/html' }
      });
    }
  })());
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
