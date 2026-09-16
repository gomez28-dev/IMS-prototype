<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * TEMPORARY STUB — restores site functionality while the real Web Push
 * implementation (VAPID keys, minishlink/web-push, PushSubscription model,
 * actual browser delivery) is finished properly.
 *
 * Both methods below are safe no-ops: they accept the same signatures the
 * calling code already uses (see OrderController.php lines 87, 287, 291,
 * 308, 319) and simply log the intent, so nothing crashes and no data is
 * lost. Replace the bodies of these two methods with real push-sending
 * logic once PushSubscription/migration/VAPID keys are actually in place —
 * do not change the method names or parameter order, since OrderController
 * already calls them exactly as defined here.
 */
class PushNotificationService
{
    /**
     * Intended to notify every user with a given role.
     * Currently a no-op stub — logs only.
     */
    public function sendToRole(string $role, string $title, string $body, array $data = []): void
    {
        try {
            Log::info("[PushNotificationService STUB] Would notify role '{$role}': {$title} — {$body}", $data);
        } catch (\Throwable $e) {
            // Never let a notification failure break the calling action.
        }
    }

    /**
     * Intended to notify a single admin/user by ID.
     * Currently a no-op stub — logs only.
     */
    public function sendToAdmin(?int $adminId, string $title, string $body, array $data = []): void
    {
        try {
            Log::info("[PushNotificationService STUB] Would notify admin_id={$adminId}: {$title} — {$body}", $data);
        } catch (\Throwable $e) {
            // Never let a notification failure break the calling action.
        }
    }
}
