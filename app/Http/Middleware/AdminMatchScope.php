<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMatchScope
{
    /**
     * Ensure admin actions are scoped to a single match_id per session.
     */
    public function handle(Request $request, Closure $next)
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $matchId = $request->route('match_id') ?? $request->input('match_id') ?? $request->query('match_id') ?? null;
        if ($matchId !== null) {
            $matchId = (int)$matchId;
            if (!empty($_SESSION['admin_match_id']) && $_SESSION['admin_match_id'] !== $matchId) {
                $msg = urlencode('Admin session already scoped to a different match');
                return redirect('/admin/tabletennis/viewer?error=' . $msg);
            }
            // bind match id to session for this admin
            $_SESSION['admin_match_id'] = $matchId;
            // also bind admin_user_id to ensure isolation for multiple admins
            if (!empty($_SESSION['user_id'])) {
                $_SESSION['admin_user_id'] = (int)$_SESSION['user_id'];
            }
        }

        return $next($request);
    }
}
