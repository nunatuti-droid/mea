<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Exams\PriceCalculator;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PriceCalculator();
    }

    public function testUsesBasePriceWhenSessionHasNoOwnPrice(): void
    {
        $r = $this->calc->calculate(['base_price_cents' => 18000, 'currency' => 'EUR'], ['price_cents' => null]);
        self::assertSame(18000, $r['total_cents']);
    }

    public function testSessionPriceOverridesBasePrice(): void
    {
        $r = $this->calc->calculate(['base_price_cents' => 18000, 'currency' => 'EUR'], ['price_cents' => 15000]);
        self::assertSame(15000, $r['base_cents']);
        self::assertSame(15000, $r['total_cents']);
    }

    public function testAddsMandatoryAndChosenFeesOnly(): void
    {
        $fees = [
            ['id' => 1, 'label' => 'Prüfungsgebühr', 'amount_cents' => 1000, 'is_mandatory' => 1],
            ['id' => 2, 'label' => 'Express-Zertifikat', 'amount_cents' => 2500, 'is_mandatory' => 0],
            ['id' => 3, 'label' => 'Zweitschrift', 'amount_cents' => 900, 'is_mandatory' => 0],
        ];
        $r = $this->calc->calculate(['base_price_cents' => 18000, 'currency' => 'EUR'], null, $fees, [3]);
        self::assertSame(18000 + 1000 + 900, $r['total_cents']);
        self::assertCount(2, $r['fees']);
    }

    public function testReturnsNullWithoutAnyPrice(): void
    {
        self::assertNull($this->calc->calculate(['base_price_cents' => null], null));
    }
}
