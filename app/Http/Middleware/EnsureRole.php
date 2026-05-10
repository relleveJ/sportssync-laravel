<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class EnsureRole
{
    /**
     * Handle an incoming request.
     * Usage: EnsureRole:admin or EnsureRole:viewer
     */
    public function handle($request, $next, $requiredRole)
    {
        // Normalize role and status from Laravel auth user if present
        $role = null;
        $status = null;
        try {
            if (Auth::guard('web')->check()) {
                $user = Auth::guard('web')->user();
                /** @var User|null $user */
                if ($user && isset($user->id)) {
                    try {
                        $row = DB::table('users')->where('id', $user->id)->first(['role','status','is_active']);
                        if ($row) {
                            $role = $row->role ?? ($user->role ?? null);
                            $status = $row->status ?? null;
                        } else {
                            $role = $user->role ?? null;
                            $status = null;
                        }
                    } catch (\Throwable $_) {
                        $role = $user->role ?? null;
                        $status = null;
                    }
                } elseif ($user && isset($user->role)) {
                    $role = $user->role;
                    $status = null;
                }
            }
        } catch (\Throwable $e) {
            // fallback to legacy session
            $role = null;
            $status = null;
        }

        // Fallback to legacy session role
        if (!$role && session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (!$role && !empty($_SESSION['user_id'])) {
            $uid = (int)($_SESSION['user_id']);
            try {
                $row = DB::table('users')->where('id', $uid)->first(['role','status','is_active']);
                if ($row) {
                    $role = $row->role ?? (!empty($_SESSION['role']) ? $_SESSION['role'] : null);
                    $status = $row->status ?? null;
                } elseif (!empty($_SESSION['role'])) {
                    $role = $_SESSION['role'];
                }
            } catch (\Throwable $e) {
                if (!empty($_SESSION['role'])) {
                    $role = $_SESSION['role'];
                }
            }
        } elseif (!$role && !empty($_SESSION['role'])) {
            $role = $_SESSION['role'];
        }

        // Normalize role string (handle JSON/array values and case-insensitivity)
        $roleVal = '';
        if (is_array($role)) {
            $roleVal = (string)($role[0] ?? $role['role'] ?? '');
        } elseif (is_string($role)) {
            $decoded = json_decode($role, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded) {
                if (is_array($decoded)) {
                    $roleVal = (string)($decoded[0] ?? $decoded['role'] ?? '');
                } elseif (is_string($decoded)) {
                    $roleVal = $decoded;
                }
            } else {
                $roleVal = $role;
            }
        } else {
            $roleVal = (string)$role;
        }
        $role = strtolower(trim($roleVal));

        // Map legacy 'scorekeeper' to 'admin'
        if ($role === 'scorekeeper') {
            $role = 'admin';
        }
        // Superadmin bypasses all checks
        if ($role === 'superadmin') {
            return $next($request);
        }

        // Block users whose account status is pending or rejected from role-protected pages
        if (!empty($status) && in_array(strtolower($status), ['pending','rejected'], true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Account not approved'], 403);
            }
            abort(403, 'Account not approved');
        }

        // Normalize required role values
        if ($requiredRole === 'scorekeeper') {
            $requiredRole = 'admin';
        }

        // If required role is 'viewer', allow both viewer and admin; otherwise exact match
        if ($requiredRole === 'viewer') {
            $allowed = ['viewer', 'admin'];
        } else {
            $allowed = [$requiredRole];
        }

        if (!in_array($role, $allowed, true)) {
            // Return 403 for API/Laravel routes; legacy pages redirect elsewhere
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Access denied: insufficient privileges'], 403);
            }
            abort(403, 'Access denied: insufficient privileges');
        }

        return $next($request);
    }
}
