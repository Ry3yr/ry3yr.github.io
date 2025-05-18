importScripts('https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js');

const ZIP_URL = 'https://ry3yr.github.io/alceawis.de.zip';
const files = new Map();
let zipReady = false;

function swLog(...args) {
  const message = '[ZIPFS SW] ' + args.join(' ');
  console.log(message);
  self.clients.matchAll().then(clients => {
    for (const client of clients) {
      client.postMessage({ type: 'SW_LOG', text: message });
    }
  });
}

self.addEventListener('install', event => {
  swLog('installing...');
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  swLog('activate → downloading ZIP...');
  event.waitUntil(
    fetch(ZIP_URL, { mode: 'cors' }).then(async r => {
      swLog('ZIP fetch status:', r.status);
      if (!r.ok) throw new Error('ZIP fetch failed');
      const buf = await r.arrayBuffer();

      swLog('unzip starting...');
      const zip = await JSZip.loadAsync(buf);
      let count = 0;

      const entries = Object.values(zip.files);
      for (const entry of entries) {
        if (entry.dir) continue;
        // Strip first directory component if present:
        const path = '/' + entry.name.split('/').slice(1).join('/');
        const content = await entry.async('uint8array');
        files.set(path, content);
        swLog('→', path, `(${content.length} bytes)`);
        count++;
      }

      zipReady = true;
      swLog('unzip done –', count, 'files');
      return self.clients.claim();
    }).catch(err => {
      swLog('ZIP load failed:', err);
    })
  );
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  let path = url.pathname;

  // Map friendly URLs to /index.html
  if (path === '/' || path === '/root' || path === '/home') {
    path = '/index.html';
  }

  if (!zipReady) {
    event.respondWith(new Response(
      '<h1>Service Worker ZIP still loading...</h1><p>Please reload after a moment.</p>',
      { headers: { 'Content-Type': 'text/html' }, status: 503 }
    ));
    return;
  }

  const file = files.get(path);
  if (file) {
    const type = guessType(path);
    swLog('Serving', path, 'as', type);
    event.respondWith(new Response(file, {
      headers: { 'Content-Type': type }
    }));
  } else {
    swLog('File not found in ZIP:', path);
    event.respondWith(new Response('<h1>404 Not Found</h1>', {
      headers: { 'Content-Type': 'text/html' },
      status: 404
    }));
  }
});

function guessType(path) {
  return path.endsWith('.html') ? 'text/html'
    : path.endsWith('.js') ? 'application/javascript'
    : path.endsWith('.css') ? 'text/css'
    : path.endsWith('.json') ? 'application/json'
    : path.endsWith('.png') ? 'image/png'
    : path.endsWith('.jpg') || path.endsWith('.jpeg') ? 'image/jpeg'
    : path.endsWith('.svg') ? 'image/svg+xml'
    : 'application/octet-stream';
}

// Error catching
self.addEventListener('error', e => {
  swLog('error:', e.message, e.filename, e.lineno, e.colno);
});
self.addEventListener('unhandledrejection', e => {
  swLog('unhandled promise rejection:', e.reason);
});
