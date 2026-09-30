<?php

namespace App\Services;

class RoleNormalizerService
{
    /** Canonical mapping of legacy/alias role strings to project roles. */
    private const ALIASES = [
        'admin_maintenance'     => 'maintenance_admin',
        'eelab_staff'           => 'maintenance_staff',
        'maintenance_personnel' => 'maintenance_staff',
    ];

    /**
     * Normalize a raw role string through the alias table.
     * An empty/unmapped role is returned unchanged (lowercased, trimmed).
     */
    public static function normalize(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));

        return self::ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Same as normalize(), but an empty role defaults to 'maintenance_staff'.
     * Matches the default several call sites use for a missing/blank role.
     */
    public static function normalizeWithStaffDefault(?string $role): string
    {
        $normalized = self::normalize($role);

        return $normalized === '' ? 'maintenance_staff' : $normalized;
    }

    /**
     * All raw role/alias strings (as stored in the users table) that resolve
     * to any of the given canonical roles. Used for whereIn()-style queries
     * against the un-normalized stored role column.
     *
     * @param string[] $canonicalRoles
     * @return string[]
     */
    public static function rawValuesFor(array $canonicalRoles): array
    {
        $values = $canonicalRoles;

        foreach (self::ALIASES as $alias => $canonical) {
            if (in_array($canonical, $canonicalRoles, true)) {
                $values[] = $alias;
            }
        }

        return array_values(array_unique($values));
    }
}
