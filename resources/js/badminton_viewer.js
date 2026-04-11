// Copied from public/Badminton Admin UI/badminton_viewer.js (read-only viewer)
// The full script remains in public; this resources copy is for bundling/reference.
const STORAGE_KEY  = 'badmintonMatchState';
// lightweight init stub — production viewers will prefer the legacy public JS
document.addEventListener('DOMContentLoaded', function(){
  // If legacy MATCH_DATA object exists, attempt a gentle render fallback
  try { if (window.MATCH_DATA) console.log('MATCH_DATA present for viewer'); } catch(e){}
});
