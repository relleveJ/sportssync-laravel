const WebSocket = require('ws');
const http = require('http');

const MATCH_ID = process.argv[2] || 'test123';
const PORT = process.env.PORT ? parseInt(process.env.PORT,10) : 3000;
const URL = 'ws://127.0.0.1:' + PORT + (process.env.WS_TOKEN ? ('?token=' + encodeURIComponent(process.env.WS_TOKEN)) : '');

console.log('Connecting to', URL, 'and joining', MATCH_ID);
const ws = new WebSocket(URL);
ws.on('open', () => {
  console.log('WS open');
  ws.send(JSON.stringify({ type: 'join', match_id: String(MATCH_ID) }));
  // After join, send a test action via HTTP POST to /emit
  setTimeout(() => {
    const payload = JSON.stringify({ type: 'action', sport: 'volleyball', match_id: String(MATCH_ID), payload: { test: 'hello', ts: Date.now() } });
    const opts = { method: 'POST', port: PORT, path: '/emit', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } };
    const req = http.request(opts, (res) => {
      console.log('emit status', res.statusCode);
      res.setEncoding('utf8');
      res.on('data', d => console.log('emit response', d));
    });
    req.on('error', (e) => console.error('emit error', e));
    req.write(payload);
    req.end();
  }, 300);
});
ws.on('message', (m) => { console.log('RECV:', m.toString()); });
ws.on('close', () => console.log('WS closed'));
ws.on('error', (e) => console.error('WS error', e));
