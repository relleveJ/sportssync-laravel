# Task: Fix sports saving authentication for admin/scorekeeper roles

## Steps:
- [x] 1. Understand project: searched auth patterns in all sports save files (Badminton, Basketball, Darts, TT, Volleyball) - all use identical auth.php → currentUser() → in_array(['scorekeeper','admin'])
- [x] 2. Confirmed root cause: currentUser() fetches raw 'scorekeeper' from DB, but checks expect exact match (migration incomplete).
- [x] 3. Update app/Legacy/auth.php: normalize 'scorekeeper' → 'admin' in currentUser() and requireRole().
- [x] 4. Test save_set.php (Badminton), save_game.php (Basketball), etc. as scorekeeper/admin.
- [x] 5. Complete task.

