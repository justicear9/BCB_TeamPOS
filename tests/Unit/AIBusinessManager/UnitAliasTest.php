<?php

namespace Tests\Unit\AIBusinessManager;

use Modules\AIBusinessManager\Support\UnitAlias;
use PHPUnit\Framework\TestCase;

class UnitAliasTest extends TestCase
{
    public function test_loaf_and_pc_are_same_family(): void
    {
        $this->assertSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('loaves'));
        $this->assertSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('Loaf'));
        $this->assertSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('Pc'));
        $this->assertSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('Pc(s)'));
        $this->assertSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('Pcs'));
        $this->assertTrue(UnitAlias::unitsAreCompatible(['loaves', 'Pc', 'Pcs', 'piece']));
    }

    public function test_kg_not_compatible_with_loaf(): void
    {
        $this->assertFalse(UnitAlias::unitsAreCompatible(['loaves', 'kg']));
        $this->assertNotSame(UnitAlias::FAMILY_PIECE, UnitAlias::family('kg'));
    }

    public function test_looks_like_unit_query(): void
    {
        $this->assertTrue(UnitAlias::looksLikeUnitQuery('loaves'));
        $this->assertTrue(UnitAlias::looksLikeUnitQuery('Pc'));
        $this->assertFalse(UnitAlias::looksLikeUnitQuery('Butter loaf'));
        $this->assertFalse(UnitAlias::looksLikeUnitQuery('Airport'));
    }

    public function test_display_label_for_loaves(): void
    {
        $this->assertSame('loaves', UnitAlias::displayLabel('loaf'));
        $this->assertSame('Pc(s)', UnitAlias::displayLabel('Pc'));
    }
}
