<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        $admins = Admin::orderBy('created_at', 'desc')->paginate(15);
        return view('accounts.index', compact('admins'));
    }

    public function create(): View
    {
        return view('accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (!auth()->user()->canManageAccounts()) {
            abort(403);
        }

        $rolesList = implode(',', array_keys(Admin::ROLES));

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64', 'unique:admins,username'],
            'password' => ['required', 'string', 'min:8'],
            'name' => ['required', 'string', 'max:128'],
            'role' => ['required', 'string', "in:{$rolesList}"],
        ]);

        $admin = Admin::create([
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'name' => $validated['name'],
            'role' => $validated['role'],
            'is_active' => true,
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'CREATED',
            'description' => "Created account {$admin->username} with role {$admin->role_label}",
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Account created successfully.');
    }

    public function edit(Admin $admin): View
    {
        if (!auth()->user()->canManageAccounts()) {
            abort(403);
        }

        return view('accounts.edit', compact('admin'));
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        if (!auth()->user()->canManageAccounts()) {
            abort(403);
        }

        $rolesList = implode(',', array_keys(Admin::ROLES));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:64', 'unique:admins,username,' . $admin->id],
            'role' => ['required', 'string', "in:{$rolesList}"],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $oldRole = $admin->role_label;
        $oldUsername = $admin->username;
        $admin->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'role' => $validated['role'],
        ]);

        if (!empty($validated['password'])) {
            $admin->update([
                'password' => Hash::make($validated['password']),
            ]);
        }

        $desc = "Updated account {$oldUsername}";
        if ($oldUsername !== $admin->username) {
            $desc .= " → {$admin->username}";
        }
        $desc .= " (Role: {$admin->role_label})";
        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'UPDATED',
            'description' => $desc,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Account updated successfully.');
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        if (!auth()->user()->canManageAccounts()) {
            abort(403);
        }
        if ($admin->id === auth()->id()) {
            return back()->with('danger', 'You cannot delete your own account.');
        }
        if (Admin::where('role', 'admin')->count() <= 1 && $admin->role === 'admin') {
            return back()->with('danger', 'Cannot delete the last Portal Administrator account.');
        }

        $username = $admin->username;
        $admin->delete();

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'DELETED',
            'description' => "Deleted account {$username}",
        ]);

        return redirect()->route('accounts.index')->with('success', "Account {$username} deleted successfully.");
    }

    public function toggleActive(Admin $admin): RedirectResponse
    {
        if (!auth()->user()->canManageAccounts()) {
            abort(403);
        }
        if ($admin->id === auth()->id()) {
            return back()->with('danger', 'You cannot deactivate your own account.');
        }

        $admin->update(['is_active' => !$admin->is_active]);

        $status = $admin->is_active ? 'reactivated' : 'deactivated';

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'UPDATED',
            'description' => "{$status} account {$admin->username}",
        ]);

        return redirect()->route('accounts.index')
            ->with('success', "Account {$status} successfully.");
    }
}
