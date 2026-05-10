const http = require('http');

const PORT = process.env.PORT ? parseInt(process.env.PORT, 10) : 3000;
const MATCH_ID = process.argv[2] || 'test123';

const payloadObj = {
  type: 'basketball_state',
  match_id: String(MATCH_ID),
  payload: {
    teamA: { name: 'Team A - Test', players: [{ id: 'pA1', no: '7', name: 'Alice', pts: 4 }], foul: 1, timeout: 2, manualScore: 0 },
    teamB: { name: 'Team B - Test', players: [{ id: 'pB1', no: '12', name: 'Bob', pts: 6 }], foul: 0, timeout: 1, manualScore: 0 },
    shared: { quarter: 2, foul: 0, timeout: 0 },
    committee: 'Ref Test',
    gameTimer: { remaining: 300, total: 600, running: true },
    shotClock: { remaining: 12, total: 24, running: true }
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
