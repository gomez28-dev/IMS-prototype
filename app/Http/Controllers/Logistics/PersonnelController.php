<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Personnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Drivers and helpers that can be put on a trip.
 *
 * Same shape as Manage Clients, except people are deactivated rather than
 * deleted, because past trips keep referring to them.
 */
class PersonnelController extends Controller
{
    public function index(): View
    {
        $this->guard();

        $personnel = Personnel::orderBy('name')->paginate(10);

        return view('logistics.personnel.index', compact('personnel'));
    }

    public function create(): View
    {
        $this->guard();

        return view('logistics.personnel.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard();

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:128', $this->uniqueName($request)],
            'role'      => ['required', Rule::in(array_keys(Personnel::ROLES))],
            'phone'     => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $person = Personnel::create([
            'name'      => $validated['name'],
            'role'      => $validated['role'],
            'phone'     => $validated['phone'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::create([
            'admin_id'    => auth()->id(),
            'action'      => 'created',
            'description' => "Added {$person->role_label}: {$person->name}",
        ]);

        return redirect()->route('logistics.personnel.index')
            ->with('success', 'Personnel added successfully.');
    }

    public function edit(Personnel $personnel): View
    {
        $this->guard();

        return view('logistics.personnel.edit', compact('personnel'));
    }

    public function update(Request $request, Personnel $personnel): RedirectResponse
    {
        $this->guard();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:128', $this->uniqueName($request, $personnel)],
            'role'  => ['required', Rule::in(array_keys(Personnel::ROLES))],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $personnel->update($validated);

        AuditLog::create([
            'admin_id'    => auth()->id(),
            'action'      => 'updated',
            'description' => "Updated {$personnel->role_label}: {$personnel->name}",
        ]);

        return redirect()->route('logistics.personnel.index')
            ->with('success', 'Personnel updated successfully.');
    }

    /** People are deactivated, never deleted — trips point at them. */
    public function toggle(Personnel $personnel): RedirectResponse
    {
        $this->guard();

        $personnel->update(['is_active' => ! $personnel->is_active]);

        AuditLog::create([
            'admin_id'    => auth()->id(),
            'action'      => 'updated',
            'description' => ($personnel->is_active ? 'Reactivated ' : 'Deactivated ')
                . "{$personnel->role_label}: {$personnel->name}",
        ]);

        return redirect()->route('logistics.personnel.index')
            ->with('success', $personnel->name . ($personnel->is_active ? ' is active again.' : ' is now inactive.'));
    }

    private function guard(): void
    {
        abort_unless(auth()->user()->canManagePersonnel(), 403,
            'Unauthorized: only an administrator manages personnel.');
    }

    /** A name only has to be unique within its role. */
    private function uniqueName(Request $request, ?Personnel $ignore = null): \Illuminate\Validation\Rules\Unique
    {
        return Rule::unique('personnel', 'name')
            ->where(fn ($query) => $query->where('role', $request->input('role')))
            ->ignore($ignore?->id);
    }
}
