<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuperadminController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'ensure.role:superadmin']);
    }

    public function index(Request $request)
    {
        // For superadmins prefer the legacy admin landing page
        return redirect(route('legacy.adminlanding'));
    }

    public function users(Request $request)
    {
        $users = \App\Models\User::orderBy('id', 'desc')->limit(200)->get();
        return view('superadmin.users', ['users' => $users]);
    }

    public function promote(Request $request)
    {
        $id = (int) $request->input('user_id');
        if (!$id) return redirect()->back()->with('error', 'Missing user id');
        $u = \App\Models\User::find($id);
        if (!$u) return redirect()->back()->with('error', 'User not found');
        $u->role = 'superadmin';
        $u->save();
        return redirect()->back()->with('success', 'User promoted to superadmin');
    }
}
