<?php
declare(strict_types=1);

final class OcrTestingAccess
{
    public static function allowedRole(mixed $role): bool
    {
        return in_array($role, ['admin', 'manager'], true);
    }

    public static function allowed(?array $user): bool
    {
        if (!$user || empty($user['id'])) return false;
        try {
            // Check active status and role in the database, not cached session claims.
            $query = db()->prepare('SELECT role FROM portal_users WHERE id = ? AND is_active = true');
            $query->execute([$user['id']]);
            return self::allowedRole($query->fetchColumn());
        } catch (Throwable $error) {
            return false;
        }
    }
}
