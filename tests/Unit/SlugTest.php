<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Media\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testCreatesSafeFileSlugs(): void
    {
        self::assertSame('pruefung-fuer-strasse', ImageVariantGenerator::slug('Prüfung für Straße'));
        self::assertSame('a-b', ImageVariantGenerator::slug('../a/../b'));
        self::assertSame('bild', ImageVariantGenerator::slug('…'));
    }
}
