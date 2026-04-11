// Copied from resources/js/badminton_admin.js (trimmed stub)
// Legacy admin JS preserved in public/Badminton Admin UI/; this is a sanitized copy for serving from /assets
let state = { matchType: 'singles', serving: 'A' };
function saveLocalState(){ try{ localStorage.setItem('badmintonAdminState', JSON.stringify(state)); }catch(e){} }

/* (trimmed) Use original file in public/Badminton Admin UI/ for full behavior until migration complete */
