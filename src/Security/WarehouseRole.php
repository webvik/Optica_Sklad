<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Úroveň oprávnění: v DB drž obvykle jednu z těchto rolí — role_hierarchy doplní zbytek výchozích tokenů.
 */
final class WarehouseRole
{
    public const VIEW = 'ROLE_SKLAD_VIEW';

    public const EDIT = 'ROLE_SKLAD_EDIT';

    public const ADMIN = 'ROLE_SKLAD_ADMIN';

    public const APP_ADMIN = 'ROLE_APP_ADMIN';

    /** Příznak „ke korekci“ na cívce — zatím jen aplikační admin (později lze self::ADMIN). */
    public const CORRECTION_FLAG_MANAGER = self::APP_ADMIN;

    /**
     * @return array<string, string>
     */
    public static function formChoicesOrdered(): array
    {
        return [
            'Přehled skladu (jen čtení)' => self::VIEW,
            'Provoz (+ příjem)' => self::EDIT,
            'Správa katalogu (+ provoz)' => self::ADMIN,
            'Aplikační administrátor (+ správa uživatelů)' => self::APP_ADMIN,
        ];
    }

    /** @return list<string> */
    public static function assignableRoles(): array
    {
        return [self::VIEW, self::EDIT, self::ADMIN, self::APP_ADMIN];
    }

    /** @param list<string> $stored z getAssignedRoles() */
    public static function primaryFromAssignedRoles(array $stored): string
    {
        foreach ([self::APP_ADMIN, self::ADMIN, self::EDIT] as $r) {
            if (\in_array($r, $stored, true)) {
                return $r;
            }
        }

        return self::VIEW;
    }
}
