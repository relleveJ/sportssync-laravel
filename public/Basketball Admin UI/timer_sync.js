// timer_sync.js — small helper for computing remaining time from canonical payload
(function(global){
  function computeRemainingSecs(timer) {
    // timer: supports legacy { remaining (s), running, ts } and canonical
    // { remaining_ms, total_ms, running, start_timestamp }
    if (!timer) return 0;
    var remSecs = 0;
    if (typeof timer.remaining_ms === 'number') {
      remSecs = Number(timer.remaining_ms) / 1000.0;
    } else if (typeof timer.remaining === 'number') {
      // detect whether remaining is in ms (large number) or seconds
      var maybe = Number(timer.remaining);
      if (maybe > 10000) remSecs = maybe / 1000.0; else remSecs = maybe;
    } else {
      remSecs = 0;
    }
    var running = (typeof timer.is_running !== 'undefined') ? !!timer.is_running : !!timer.running;
    var ts = timer.start_timestamp || timer.last_started_at || timer.ts || null;
    if (running && ts) {
      var now = Date.now();
      var elapsed = (now - Number(ts)) / 1000.0;
      return Math.max(0, remSecs - elapsed);
    }
    return Math.max(0, remSecs);
  }

  function payloadForControl(matchId, gameTimer, shotClock, control, clientId) {
    return JSON.stringify({
      match_id: matchId,
      payload: { gameTimer: gameTimer, shotClock: shotClock },
      meta: { control: control, clientId: clientId || null }
    });
  }

  global.TimerSync = { computeRemainingSecs: computeRemainingSecs, payloadForControl: payloadForControl };
})(window);
