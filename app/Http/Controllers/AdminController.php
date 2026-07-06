<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        $admins = Admin::orderBy('created_at', 'desc')->paginate(10);
        return view('accounts.index', compact('admins'));
    }

    public function create(): View
    {
        return view('accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64', 'unique:admins,username'],
            'password' => ['required', 'string', 'min:8'],
            'name' => ['required', 'string', 'max:128'],
            'role' => ['required', 'string', 'in:admin,editor,viewer'],
        ]);

        Admin::create([
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'name' => $validated['name'],
            'role' => $validated['role'],
            'is_active' => true,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', 'Account created successfully.');
    }

    public function edit(Admin $admin): View
    {
        return view('accounts.edit', compact('admin'));
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'role' => ['required', 'string', 'in:admin,editor,viewer'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $data = [
            'name' => $validated['name'],
            'role' => $validated['role'],
        ];

        if (!empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $admin->update($data);

        return redirect()->route('accounts.index')
            ->with('success', 'Account updated successfully.');
    }

    public function toggleActive(Admin $admin): RedirectResponse
    {
        if ($admin->id === auth()->id()) {
            return back()->with('danger', 'You cannot deactivate your own account.');
        }

        $admin->update(['is_active' => !$admin->is_active]);

        $status = $admin->is_active ? 'reactivated' : 'deactivated';
        return redirect()->route('accounts.index')
            ->with('success', "Account {$status} successfully.");
    }
}
