# Multi-Admin Real-Time Sync Guide

## 🚀 What Changed

All admins (regardless of browser/device/session) can now sync the same match state in real-time WITHOUT page refresh.

### Key Fixes
✅ **Removed origin restrictions** - Any admin can connect  
✅ **Removed per-session auth gates** - All admins accepted on state.php  
✅ **Removed sender filters** - ALL clients receive broadcasts (no exclusions)  
✅ **Match room routing** - All admins load same match_id join same room  
✅ **Server-side broadcasts** - state.php immediately notifies all connected admins  

---

## 📖 How It Works

```
Admin A (Browser 1)          Admin B (Browser 2)          Admin C (Mobile)
        |                           |                          |
        └─────── Load same match URL with ?match_id=5 ────────┘
               ↓                     ↓                          ↓
        Join WebSocket      Join WebSocket            Join WebSocket
        match_id="5"         match_id="5"             match_id="5"
               |                     |                          |
               └──────────── All in same room ─────────────────┘
                                     ↓
        Admin B enters "120" ──→ POST /state.php
                                     ↓
                          Save to DB + curl /emit
                                     ↓
                    WebSocket server broadcasts to room
                                     ↓
        Admin A                   Admin B                   Admin C
        Updates to "120"    Confirms update              Updates to "120"
        (no page refresh)   (no page refresh)            (no page refresh)
```

---

## 🧪 Testing Multi-Admin Sync

### Option 1: Same PC, Different Browsers
```
Browser 1 (Chrome):   http://localhost/sportssync-laravel/public/DARTS%20ADMIN%20UI/index.php?match_id=5
Browser 2 (Firefox):  http://localhost/sportssync-laravel/public/DARTS%20ADMIN%20UI/index.php?match_id=5
Browser 3 (Edge):     http://localhost/sportssync-laravel/public/DARTS%20ADMIN%20UI/index.php?match_id=5
```

### Option 2: Different PCs on Same Network
```
PC 1:  http://192.168.X.X:8000/sportssync-laravel/public/DARTS%20ADMIN%20UI/index.php?match_id=5
PC 2:  http://192.168.X.X:8000/sportssync-laravel/public/DARTS%20ADMIN%20UI/index.php?match_id=5
```

### Option 3: Incognito/Private Windows (Different Sessions)
```
Chrome Normal:    http://localhost/...?match_id=5 (logged in as Admin A)
Chrome Incognito: http://localhost/...?match_id=5 (logged in as Admin B)
```

---

## ✅ Test Checklist

### Test 1: Input Panel Sync
- [ ] **Admin A** enters digits in input panel (e.g., "120")
- [ ] **Admin B** should see "120" appear instantly
- [ ] **Admin C** should see "120" appear instantly
- **MUST NOT require page refresh**

### Test 2: Player Selection Sync
- [ ] **Admin A** clicks Player 2 card
- [ ] Player 2 becomes active (highlighted)
- [ ] **Admin B's** screen shows Player 2 active instantly
- [ ] **Admin C's** screen shows Player 2 active instantly
- **MUST persist across reload**

### Test 3: Simultaneous Updates
- [ ] **Admin A** enters "180" while **Admin B** enters "100"
- [ ] Last write wins (server receives "100", all see "100")
- [ ] No conflicts or corruption

### Test 4: New Admin Joins
- [ ] **Admin A** & **Admin B** already playing
- [ ] **Admin C** opens page mid-game with same match_id
- [ ] **Admin C** instantly sees current state (last saved state)
- [ ] **Admin C** can make updates that sync to A & B

### Test 5: Reload Persistence
- [ ] **Admin A** sets Player 2 + enters "120"
- [ ] **Admin B** reloads browser
- [ ] **Admin B** still sees Player 2 active + "120" in input
- [ ] No desync

---

## 🔍 Debugging: Check Browser Console

Open DevTools (F12 → Console) and look for:

```javascript
// When page loads:
[INIT] Match ID from URL: 5

// When admin joins WebSocket:
[admin] sent join message: match_id=5

// When input is updated:
[publishLiveState] Sending to state.php: match_id=5 inputStr=120
[publishLiveState] Response status: 200
[publishLiveState] Success - broadcasting state

// When state is received from server:
[SYNC RECEIVED] type=state match_id=5 inputStr=120 currentPlayer=2
```

---

## 🔍 Debugging: Check Server Logs

Look for these entries in PHP error log or `storage/logs/laravel.log`:

```
[state.php POST] Update from AdminA (role=admin): match_id=5 inputStr=120
[state.php POST] Pending file written: YES
[state.php POST] DB update executed: SUCCESS for match_id=5
[state.php POST] Broadcast sent to /emit: match_id=5 http_code=200
```

Look for these in WebSocket server console (if running locally):

```
[admin connection] client connected from 127.0.0.1
[/emit] Received broadcast: type=state match_id=5 timestamp=...
[BROADCAST] type=state match_id=5 clients=3 timestamp=...
```

---

## ⚠️ Common Issues & Fixes

### Issue: Only Admin A sees updates, not Admin B
**Cause**: Admin B on different match_id room  
**Fix**: Ensure URL includes `?match_id=5` (same number for both admins)

### Issue: Page refresh clears state
**Cause**: Not reloading from database  
**Fix**: Server should send cached state on join. Check `[last_state]` in console

### Issue: Updates appear 1-2 seconds delayed
**Cause**: Debounce delay on HTTP publish (intentional for battery/network)  
**Fix**: WebSocket should be instant. If not, check WebSocket connection status in console

### Issue: Broadcast doesn't reach anyone
**Cause**: WebSocket server not running on :3000  
**Fix**: Verify `server.js` is running: `node public/ws-server/server.js`

### Issue: 403 Forbidden error in state.php
**Cause**: Still has authentication gate  
**Fix**: Verify auth gate is removed from state.php POST handler

---

## 🛠️ Key Configuration

### URL Format (CRITICAL)
All admins MUST load with same match_id in URL:
```
?match_id=5    ← All admins load this way to sync
```

### WebSocket Server
Must be running on port 3000:
```bash
cd public/ws-server
node server.js
```

### Database Column
Table `darts_matches` must have `live_state` JSON column:
```sql
ALTER TABLE darts_matches ADD COLUMN live_state LONGTEXT;
```

---

## 📊 Components

| Component | Port | Purpose |
|-----------|------|---------|
| Darts Admin UI | 8000 (or 80) | Frontend for admins |
| WebSocket Server | 3000 | Real-time relay for cross-device sync |
| state.php | 8000 (or 80) | HTTP persistence + broadcast trigger |
| Database | 3306 | Stores live_state for persistence |

---

## 🚨 Critical Requirements

1. ✅ **No origin validation** - Any browser can connect
2. ✅ **No per-session auth gates** - state.php accepts all POSTs
3. ✅ **No sender filters** - Broadcasts go to ALL clients
4. ✅ **Consistent match_id** - URL param ensures all admins in same room
5. ✅ **Server-side broadcast** - state.php triggers curl /emit immediately

---

## ✨ Expected Behavior

### Before: ❌ Only Works for One Admin
- Admin A's input updates ✓
- Admin B's input doesn't broadcast ✗
- Only Admin A sees changes ✗
- Requires page refresh ✗

### After: ✅ Works for ALL Admins
- Admin A's input updates ✓
- Admin B's input broadcasts immediately ✓
- ALL admins see changes instantly ✓
- No page refresh needed ✓
- Persists across reload ✓

---

**Ready to test! Open 3 browsers with ?match_id=5 and start updating! 🎯**
