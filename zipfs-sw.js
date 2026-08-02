/* eslint-env serviceworker */
importScripts('jszip.min.js');

const ZIP_URL = 'alceawis.de.zip';
const files   = new Map();                // <virtual-path, Uint8Array>

let zipReadyResolve;
const zipReady = new Promise(r => (zipReadyResolve = r));

function swLog(...a) {
  const msg = '[ZIPFS SW] ' + a.join(' ');
  console.log(msg);
  self.clients.matchAll().then(clients =>
    clients.forEach(c => c.postMessage({ type: 'SW_LOG', text: msg }))
  );
}

/* ---------- install ---------- */
self.addEventListener('install', () => {
  swLog('Installing…');
  self.skipWaiting();
});

/* ---------- activate --------- */
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    swLog('Activating; claiming clients…');
    await self.clients.claim();

    try {
      swLog('Fetching ZIP:', ZIP_URL);
      const res = await fetch(ZIP_URL, { mode: 'cors' });
      if (!res.ok) throw new Error(`ZIP fetch failed (${res.status})`);

      const buf = await res.arrayBuffer();
      const zip = await JSZip.loadAsync(buf);

      /* 1 . find the first index.html and mark its folder as ROOT -------- */
      let rootDir = '';                                 // e.g. "alceawis.de"
      for (const entry of Object.values(zip.files)) {
        if (entry.dir) continue;
        const name = entry.name.replace(/\\/g, '/');    // normalise
        if (name.endsWith('/index.html')) {
          rootDir = name.slice(0, -'/index.html'.length);
          swLog('Root directory detected:', rootDir);
          break;                                        // FIRST match only
        }
      }

      /* 2 . cache every file, stripping the rootDir prefix --------------- */
      let count = 0;
      for (const entry of Object.values(zip.files)) {
        if (entry.dir) continue;

        const raw      = entry.name.replace(/\\/g, '/');
        const stripped = rootDir && raw.startsWith(rootDir + '/')
                       ? raw.slice(rootDir.length + 1)
                       : raw;

        const vPath   = '/' + stripped;                 // e.g. "/css/style.css"
        const content = await entry.async('uint8array');
        files.set(vPath, content);
        swLog('→', vPath, `(${content.length} bytes)`);
        count++;
      }
      swLog(`Unzipped ${count} files`);
    } catch (err) {
      swLog('ZIP load failed:', err);
    } finally {
      zipReadyResolve();          // always resolve so fetches don’t hang
    }
  })());
});

/* ---------- fetch ------------ */
const SHELL_PAGE = '/zipfs-sw-worker.html';  // never routed into the ZIP fs

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;   // let CORS requests pass

  let path = url.pathname;

  /* Never intercept the demo shell page — always go to network for it */
  if (path === SHELL_PAGE) return;

  /* Friendly aliases → /index.html */
  if (path === '/' || path === '/root' || path === '/home' || path === '/index')
    path = '/index.html';

  /* Directory paths → index.html */
  if (path.endsWith('/')) path += 'index.html';

  event.respondWith((async () => {
    await zipReady;

    const hit = files.get(path);
    if (hit) {
      swLog('Serving', path, 'from ZIP');
      return new Response(hit, { headers: { 'Content-Type': mime(path) } });
    }

    /* Not in ZIP – try network */
    swLog('Not cached, fetching network:', path);
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

/* ---------- helpers ---------- */
function mime(p) {
  return p.endsWith('.html')             ? 'text/html'            :
         p.endsWith('.js')               ? 'application/javascript':
         p.endsWith('.css')              ? 'text/css'             :
         p.endsWith('.json')             ? 'application/json'     :
         p.endsWith('.png')              ? 'image/png'            :
         p.endsWith('.jpg')||p.endsWith('.jpeg') ? 'image/jpeg'    :
         p.endsWith('.svg')              ? 'image/svg+xml'        :
         'application/octet-stream';
}

self.addEventListener('error',              e => swLog('Error:', e.message));
self.addEventListener('unhandledrejection', e => swLog('Unhandled rejection:', e.reason));