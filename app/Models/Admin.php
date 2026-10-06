<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use Notifiable;

    protected $table = 'admins';

    protected $fillable = [
        'username',
        'password',
        'name',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    public const ROLES = [
        'admin' => 'Portal Administrator',
        'viewer' => 'President (Viewer)',
        'audit' => 'Auditor',
        'sales' => 'Sales Executive',
        'ops_admin' => 'Operations Admin',
        'ops_mgr' => 'Operations Manager',
        'ops_wh' => 'Operations Warehouse',
        'ops_log' => 'Operations Logistics',
        'accounting' => 'Accounting',
        'purchasing' => 'Purchasing (Rica Esaga)',
        'hod' => 'Head of Department',
        'vp' => 'Vice President',
    ];

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles);
    }

    // Role Inquiries
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isViewer(): bool { return $this->role === 'viewer'; }
    public function isAudit(): bool { return $this->role === 'audit'; }
    public function isSales(): bool { return $this->role === 'sales'; }
    public function isPurchasing(): bool { return $this->role === 'purchasing'; }
    public function isOpsAdmin(): bool { return $this->role === 'ops_admin'; }
    public function isOpsMgr(): bool { return $this->role === 'ops_mgr'; }
    public function isOpsWh(): bool { return $this->role === 'ops_wh'; }
    public function isOpsLog(): bool { return $this->role === 'ops_log'; }
    public function isAccounting(): bool { return $this->role === 'accounting'; }
    public function isHod(): bool { return $this->role === 'hod'; }
    public function isVp(): bool { return $this->role === 'vp'; }

    public function canAccessStockOrders(): bool
    {
        return in_array($this->role, ['admin', 'purchasing', 'vp', 'audit', 'viewer']);
    }

    public function canEditStockOrders(): bool
    {
        return in_array($this->role, ['admin', 'purchasing']);
    }

    public function canApproveStockOrders(): bool
    {
        return in_array($this->role, ['admin', 'vp']);
    }

    /**
     * Manage the supplier list that Purchase Orders draw from.
     */
    public function canManageSuppliers(): bool
    {
        return in_array($this->role, ['admin', 'purchasing']);
    }

    /**
     * Mark an ATL as Lifted.
     *
     * Explicit allowlist. Lifting is the only way Purchasing can move a Module 1
     * order to Fulfilled, so it must not widen to any other Module 2 editor.
     */
    public function canMarkAtlLifted(): bool
    {
        return in_array($this->role, ['purchasing', 'admin']);
    }

    /**
     * Receive a Doyen Stocks delivery into a depot tank.
     *
     * Explicit allowlist rather than the broader canEditModule2(), which also
     * admits ops_log, hod and vp. Those roles may edit wet stock generally but
     * must not confirm that fuel physically arrived at a depot.
     */
    public function canReceiveStockIntoDepot(): bool
    {
        return in_array($this->role, ['ops_admin', 'ops_wh', 'ops_mgr', 'admin']);
    }

    // Permission Helpers
    public function canMarkFulfilled(): bool
    {
        return in_array($this->role, ['admin', 'ops_admin', 'ops_mgr']);
    }

    public function canClearOrders(): bool
    {
        return in_array($this->role, ['admin', 'accounting']);
    }

    public function canViewAuditLog(): bool
    {
        return in_array($this->role, ['admin', 'accounting', 'audit']);
    }

    public function canManageAccounts(): bool
    {
        return $this->role === 'admin';
    }

    public function canEditModule1(): bool
    {
        return in_array($this->role, ['admin', 'sales', 'hod', 'vp']);
    }

    public function canEditModule2(): bool
    {
        return in_array($this->role, ['admin', 'ops_admin', 'ops_mgr', 'ops_wh', 'ops_log', 'hod', 'vp']);
    }

    // Approval Permissions
    public function canApproveModule1Modification(): bool
    {
        return in_array($this->role, ['admin', 'hod']);
    }

    public function canApproveModule2Modification(): bool
    {
        return in_array($this->role, ['admin', 'ops_mgr', 'ops_admin']);
    }

    public function canViewApprovals(): bool
    {
        return $this->canApproveModule1Modification() || $this->canApproveModule2Modification();
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? ucfirst($this->role);
    }

    public function getRoleBadgeClassAttribute(): string
    {
        return match ($this->role) {
            'admin' => 'badge-role-admin',
            'viewer' => 'badge-role-viewer',
            'audit' => 'badge-role-audit',
            'sales' => 'badge-role-sales',
            'ops_admin' => 'badge-role-ops-admin',
            'ops_mgr' => 'badge-role-ops-mgr',
            'ops_wh' => 'badge-role-ops-wh',
            'ops_log' => 'badge-role-ops-log',
            'accounting' => 'badge-role-accounting',
            'purchasing' => 'badge-role-purchasing',
            'hod' => 'badge-role-hod',
            'vp' => 'badge-role-vp',
            default => 'badge-role-viewer',
        };
    }
}
