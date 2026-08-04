<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    /**
     * Cached scope query (30 minutes).
     */
    public function index()
    {
        return view('users.index', ['users' => User::active()->getFromCache()]);
    }

    /**
     * Query with a custom TTL (2 hours).
     */
    public function all()
    {
        return view('users.all', ['users' => User::remember(120)->getFromCache()]);
    }

    /**
     * First result, cached.
     */
    public function findByEmail(Request $request)
    {
        $user = User::where('email', $request->input('email'))->firstFromCache();

        if (! $user) {
            return redirect()->back()->with('error', 'User not found.');
        }

        return view('users.show', ['user' => $user]);
    }

    /**
     * Updating a user flushes the cache automatically.
     */
    public function update(Request $request, User $user)
    {
        $user->update($request->only('name', 'email'));

        return redirect()->route('users.show', $user)->with('success', 'User updated.');
    }
}
