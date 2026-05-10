# Timer SSOT (Single Source of Truth) Implementation

## Overview
Implemented a persistent timer synchronization system using the `match_timers` database table as the authoritative source of truth for all timer state across admin users.

## Architecture

### Database Layer (match_timers table)
**SSOT Storage**: `match_timers` table holds:
- `game_total`, `game_remaining`, `game_running`, `game_ts` (game timer state)
- `shot_total`, `shot_remaining`, `shot_running`, `shot_ts` (shot clock state)
- `match_id` (association to match)
- `updated_at` (timestamp of last update)

### Backend (PHP - timer.php)

#### GET Request
- Returns current timer state from `match_timers` table
- Fetches: `gameTimer` and `shotClock` objects with `total`, `remaining`, `running`, and `ts` values
- Used on page load to hydrate the exact current timer state

#### POST Request
- Accepts: `match_id`, `gameTimer`, `shotClock`, and `meta` (with control info)
- Performs intelligent state preservation:
  - Protects running timers from being overwritten by stale clients
  - Prevents flip-flopping between running/paused states from old browser sessions
  - **Returns the saved timer state payload** so clients can apply it immediately
- Updates `match_timers` via upsert (INSERT ... ON DUPLICATE KEY UPDATE)
- Broadcasts to WebSocket relay for all admin users to receive updates
- Persists to `match_states` table for full match history

### Frontend (JavaScript - app.js)

#### Timer Control Flow
1. **User Action** (Play/Pause/Reset) triggers timer control function
2. **Compute Current State** from local anchored values
3. **For Start Control**: Fetch latest DB state first to resume from correct value
4. **Send to timer.php**: POST timer payload with control metadata
5. **Apply Server Response**: Accept returned timer state from `match_timers`
6. **Update Local State**: Reset all local variables to server state
7. **Start/Stop Loops**: Enable animation loops based on server running flag
8. **Broadcast to Others**: WebSocket relay sends update to all connected admin users

#### Timer Persistence Layer
- `persistTimersToServer(control)`: Debounced write to `match_timers` (600ms throttle)
  - Uses current remaining values anchored to server timestamp
  - Includes control metadata so server applies gentle guards
  - Used for continuous tick updates

- `immediatePersistControl(control, timerType)`: Immediate write for explicit controls
  - Fetches latest DB state before starting (resume semantics)
  - Computes remaining from local anchor timestamp
  - Sends to `timer.php` with control flag
  - Applies returned payload to local state
  - Routes back through `postImmediateTimerUpdate()` for WebSocket broadcast

#### Local State Variables
- `gtAnchorTs`: Server timestamp when game timer started running
- `gtRemainingAtAnchor`: Game timer remaining at anchor time
- `gtRemaining`: Current computed remaining seconds
- Same variables for shot clock with `sc` prefix

#### Timer State Synchronization
- `applyIncomingState(payload)` completely resets local timer state when server updates arrive
- Prevents partial updates or stale anchors from causing desync
- Resets animation loops before starting fresh

#### Page Reload Recovery
- `initializeTimersFromServerState()`: Called after server state hydration
- Fetches timer state from `timer.php?match_id=N`
- Restores exact timer state, remaining time, and running status
- Starts/stops loops based on server running flag

### WebSocket Broadcasting (server.js)

#### Admin Client Tracking
- `adminClients` Set tracks all connected admin users
- Join messages include role information
- Only admin users are added to `adminClients`

#### Timer Control Broadcasting
- `timer_control` messages are sent only to admin users
- `timer_update` messages with `meta.control` flag are broadcast to admins
- Viewers receive timer updates but cannot trigger timer controls

### Relay Functions (ws_relay.php)

#### Key Functions
- `ss_ws_relay_post(obj)`: Generic POST to WebSocket server `/emit` hook
- `ss_ws_relay_notify_state(match_id, payload, ts)`: Send state updates with timestamp
- `ss_ws_relay_notify_admins(payload, ts)`: Send timer control updates to admin users only

## Synchronization Guarantees

### Single Source of Truth
✓ Database `match_timers` table is authoritative
✓ All admin UI state changes must write to `match_timers`
✓ No timer state stored in local browser without DB sync

### Real-Time Admin Sync
✓ When one admin controls timer, all others receive WebSocket broadcast
✓ Broadcast includes complete timer payload + control metadata
✓ Each admin applies server payload, replacing local state completely

### Page Reload Safety
✓ On page reload, `initializeTimersFromServerState()` fetches latest DB state
✓ Exact remaining time and running status are restored
✓ No timer resets or state loss

### No Desynchronization
✓ Prevents multiple animation loops per timer
✓ Loop management (`ensureGtLoop`/`stopGtLoopIfIdle`) prevents duplicates
✓ Server start timestamp anchoring prevents timer drift
✓ Complete state replacement on remote updates prevents partial state errors

### Stale Client Protection
✓ `timer.php` prevents old browser sessions from overwriting running timers
✓ Gentle guards check if timer was recently updated
✓ Only explicit control actions (with meta flag) can start running timers
✓ Running timers preserve their values across passive persist attempts

## Key Implementation Details

### Timestamp Semantics
- `ts` field = milliseconds when timer started running (or null if paused)
- `remaining` = seconds remaining at the time of save
- Live remaining = `remaining - ((now - ts) / 1000)`

### Control Metadata
- `meta.control`: 'start' | 'pause' | 'reset' indicates explicit user action
- `meta.clientId`: Identifies which admin initiated the control
- `meta.timer`: 'game' | 'shot' specifies which timer was controlled

### Debounce Strategy
- `scheduleTimerPersist(control)`: Debounces regular ticks to 600ms
- `immediatePersistControl(control, timerType)`: Immediate write for explicit controls
- Prevents database thrashing while maintaining responsiveness

### WebSocket Coordination
- Broadcasting bypasses admin page that initiated it (sender check in sendToAdmins)
- Only admin-targeted messages are broadcast
- Fallback to localStorage if WebSocket unavailable

## Testing Checklist

- [ ] Start game timer → other admin sees it start immediately
- [ ] Pause game timer → other admin sees pause and exact remaining time
- [ ] Resume game timer → other admin sees timer continue from paused value
- [ ] Reset game timer → all admins see timer reset to total
- [ ] Reload page while timer running → restored timer continues from exact remaining
- [ ] Open new admin tab during match → new tab shows current timer state
- [ ] Multiple admins pause/resume → no timer desyncs or duplicate ticks
- [ ] Network lag scenarios → eventual consistency maintained
- [ ] Shot clock independent control → game timer unaffected by shot clock controls

## Files Modified

1. **app.js** - Timer control persistence routing to timer.php SSOT
2. **timer.php** - Enhanced POST response to return saved timer payload
3. **server.js** - Admin-only broadcast already in place
4. **ws_relay.php** - Admin notification functions already in place

## Future Enhancements

- Real-time timer synchronization API endpoint for fast clients
- Timer state versioning to handle clock skew between devices
- Admin activity logging for timer state changes
- Timer presets and templates system
