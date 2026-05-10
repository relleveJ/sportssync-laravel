const http = require('http');

const PORT = process.env.PORT ? parseInt(process.env.PORT, 10) : 3000;
const MATCH_ID = process.argv[2] || 'test123';

const payloadObj = {
  type: 'action',
  sport: 'basketball',
  match_id: String(MATCH_ID),
  payload: {
    gameTimer: { remaining: 295, total: 600, running: true },
    shotClock: { remaining: 10, total: 24, running: true },
    shared: { quarter: 2 }
  }
};

const body = JSON.stringify(payloadObj);
const opts = { method: 'POST', port: PORT, path: '/emit', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } };

const req = http.request(opts, (res) => {
  console.log('emit status', res.statusCode);
  res.setEncoding('utf8');
  res.on('data', d => console.log('emit response', d));
});
req.on('error', (e) => console.error('emit error', e));
req.write(body);
req.end();
