WS Relay — Basketball SSOT

This small Node.js process provides a WebSocket relay and server-authoritative
timer broadcasting for the Basketball Admin UI. PHP endpoints notify this
service after persisting authoritative state; the relay then broadcasts to all
connected clients in the match room.

Quick start (developer):

1. Install dependencies

   cd "public/Basketball Admin UI/ws-server" || cd public/ws-server
   npm install

2. Start the relay

   npm start

Environment variables:

- `PORT` — TCP port for the relay (default 3000)
- `WS_RELAY_SECRET` — secret shared with PHP helper (default: dev_secret_change_me)
- `PHP_BASE_URL` — base URL used by the relay to fetch state.php when hydrating (default http://127.0.0.1)

PHP helper: include `public/ws-server/ws_relay.php` and call
`ss_ws_relay_notify_state($matchId, $payload)` or
`ss_ws_relay_notify_timer($matchId, $gameTimer)` after persisting state.
WS Relay (minimal)

This lightweight WebSocket relay accepts HTTP POSTs at /emit and broadcasts the payload to connected WebSocket clients.

Usage:
  - Install Node.js (16+)
  - cd public/ws-server
  - npm install
  - node server.js

Environment variables:
  - PORT (default 3000)
  - WS_TOKEN (optional) — if set, POSTs must include header `X-WS-Token: <token>` to be accepted

HTTP endpoint:
  POST /emit  Content-Type: application/json
  Body: { type: 'room_state', match_id: <id>, payload: {...} }

Clients should connect to ws://<host>:<port> and may send JSON messages to join rooms:
  { type: 'join', match_id: '<id>' }

Relay behavior:
  - on POST /emit, the server broadcasts to all clients subscribed to the specified match_id
  - if no match_id provided, broadcasts to all connected clients

Security:
  - Intended for LAN / trusted use. Use reverse-proxy and firewall for production.
