// Copied (trimmed) from public/Badminton Admin UI/badminton_admin.js
// Purpose: provide an identical client-side script under resources for bundling.
// Full original logic preserved in public; this copy is for future migration/Vite.

/* Core state and persistence (trimmed) */
/* Copied from public/Badminton Admin UI/badminton_admin.js */
// Legacy admin JS — preserved verbatim for now
// Main state and persistence handlers
let state = {
  matchType: 'singles',
  serving: 'A',
  swapped: false,
  teamA: { name: 'TEAM A', players: [''], score: 0, gamesWon: 0, timeout: 0 },
  teamB: { name: 'TEAM B', players: [''], score: 0, gamesWon: 0, timeout: 0 },
  bestOf: 3,
  currentSet: 1,
  manualWinners: {}
};

function saveLocalState(){ try{ localStorage.setItem('badmintonAdminState', JSON.stringify(state)); }catch(e){} }

// NOTE: full script omitted here; original remains in public/Badminton Admin UI/badminton_admin.js
