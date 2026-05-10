const WebSocket = require('ws');

const url = 'ws://localhost:3000';
const ws = new WebSocket(url);

ws.on('open', function() {
  console.log('connected to', url);
  // join a test room
  ws.send(JSON.stringify({ type: 'join', match_id: 'test-room' }));
  // send a test state
  ws.send(JSON.stringify({ type: 'state', match_id: 'test-room', payload: { ts: Date.now(), msg: 'hello from test client' } }));
});

ws.on('message', function(m) {
  console.log('received:', m.toString());
});

ws.on('close', function(code, reason) {
  console.log('closed', code, reason && reason.toString ? reason.toString() : reason);
  process.exit(0);
});

ws.on('error', function(err) {
  console.error('error', err && err.message ? err.message : err);
  process.exit(1);
});

// terminate after 6s
setTimeout(() => { console.log('terminating test client'); ws.terminate(); }, 6000);
