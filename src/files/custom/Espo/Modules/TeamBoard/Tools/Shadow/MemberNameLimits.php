<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

/**
 * Column limits of a board person's name (TeamBoardMember entityDefs): first
 * and last name hold 100 characters each, the joined name holds 150.
 */
final class MemberNameLimits
{
    public const PART = 100;
    public const FULL = 150;

    public static function fullName(string $firstName, string $lastName): string
    {
        return trim($firstName . ' ' . $lastName);
    }

    /** Returns the denial key when the name does not fit its columns. */
    public static function violation(string $firstName, string $lastName): ?string
    {
        if (mb_strlen($firstName) > self::PART ||
            mb_strlen($lastName) > self::PART ||
            mb_strlen(self::fullName($firstName, $lastName)) > self::FULL) {
            return 'nameLength';
        }

        return null;
    }
}
