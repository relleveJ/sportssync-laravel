from pathlib import Path
path = Path(r'c:/Users/Administrator/Desktop/XAMPP MAIN FILE/htdocs/sportssync-laravel/public/Basketball Admin UI/app.js')
text = path.read_text(encoding='utf-8')
old = "      try { scRenderFrame(); } catch(_){ }\n      // Persist reset to timer endpoint and broadcast via WS (best-effort)\n      try {\n        const mid = getMatchId();\n        if (mid && String(mid) !== '0') {\n          try { persistAndBroadcastTimerUpdate(mid, { total: gtTotalSecs, remaining: gtTotalSecs, running: false, ts: null }, { total: scTotal, remaining: scTotal, running: false, ts: null }, { control: 'reset', clientId: CLIENT_ID }); } catch(_){ }\n          try { immediatePersistControl('reset','game').catch(()=>{}); } catch(_){ }\n          try { immediatePersistControl('reset','shot').catch(()=>{}); } catch(_){ }\n        }\n      } catch(_) {}\n    } catch (e) {}\n"
new = "      try { scRenderFrame(); } catch(_){ }\n    } catch (e) {}\n"
if old not in text:
    raise SystemExit('OLD block not found')
text = text.replace(old, new, 1)
path.write_text(text, encoding='utf-8')
print('patched')
