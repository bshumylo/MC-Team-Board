<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Shadow\MemberNameLimits;
use PHPUnit\Framework\TestCase;

/**
 * F1: first name (100) + last name (100) joined with a space can reach 201
 * characters, but the name column holds 150. The limits are checked before
 * any write so the API answers 400 instead of a database 500.
 */
class MemberNameLimitsTest extends TestCase
{
    public function testAcceptsTypicalAndBoundaryNames(): void
    {
        $this->assertNull(MemberNameLimits::violation('Ada', 'Lovelace'));
        $this->assertNull(MemberNameLimits::violation('Ada', ''));
        $this->assertNull(MemberNameLimits::violation(str_repeat('a', 100), str_repeat('b', 49)));
    }

    public function testRejectsHundredPlusHundredThatOverflowsTheNameColumn(): void
    {
        $this->assertSame(
            'nameLength',
            MemberNameLimits::violation(str_repeat('a', 100), str_repeat('b', 100)),
        );
    }

    public function testRejectsFullNameOfOneOverTheColumn(): void
    {
        $this->assertSame('nameLength', MemberNameLimits::violation(str_repeat('a', 100), str_repeat('b', 50)));
    }

    public function testRejectsEachPartOverItsOwnColumn(): void
    {
        $this->assertSame('nameLength', MemberNameLimits::violation(str_repeat('a', 101), ''));
        $this->assertSame('nameLength', MemberNameLimits::violation('Ada', str_repeat('b', 101)));
    }

    public function testCountsCharactersNotBytes(): void
    {
        $this->assertNull(MemberNameLimits::violation(str_repeat('Я', 100), str_repeat('ї', 49)));
        $this->assertSame('nameLength', MemberNameLimits::violation(str_repeat('Я', 100), str_repeat('ї', 50)));
    }

    public function testFullNameIsTheTrimmedJoin(): void
    {
        $this->assertSame('Ada Lovelace', MemberNameLimits::fullName('Ada', 'Lovelace'));
        $this->assertSame('Ada', MemberNameLimits::fullName('Ada', ''));
    }
}
