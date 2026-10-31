<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Design\DesignCss;
use Mea\Module\Design\DesignSchema;
use Mea\Module\Design\DesignValidator;
use Mea\Module\Design\FontLibrary;
use PHPUnit\Framework\TestCase;

/** Designsystem ohne Datenbank: Standardwerte, Prüfregeln, erzeugtes CSS, stufenlose Größen, Kontrast. */
final class DesignSystemUnitTest extends TestCase
{
    private function validator(): DesignValidator
    {
        return new DesignValidator(new FontLibrary());
    }

    public function testEveryDefaultIsValidAndHasLabelTabAndRange(): void
    {
        $keys = [];
        foreach (DesignSchema::fields() as $f) {
            self::assertArrayHasKey($f['tab'], DesignSchema::TABS, $f['key']);
            self::assertNotSame('', $f['label']);
            self::assertArrayNotHasKey($f['key'], $keys, 'doppelter Schlüssel ' . $f['key']);
            $keys[$f['key']] = true;
            if (in_array($f['type'], ['px', 'number', 'em'], true)) {
                self::assertLessThanOrEqual($f['max'], $f['default'], $f['key']);
                self::assertGreaterThanOrEqual($f['min'], $f['default'], $f['key']);
            }
            if (isset($f['fluid'])) {
                self::assertArrayHasKey($f['fluid'], DesignSchema::byKey(), 'Handy-Wert fehlt: ' . $f['key']);
            }
        }
        [$values, $errors] = $this->validator()->validate(DesignSchema::defaults());
        self::assertSame([], $errors);
        self::assertEquals(DesignSchema::defaults(), $values);
        self::assertSame([], DesignValidator::warnings($values), 'Standardfarben erfüllen den Kontrast');
        // Alle fünf Bereiche haben Felder
        self::assertSame(array_keys(DesignSchema::TABS), array_values(array_unique(array_column(DesignSchema::fields(), 'tab'))));
    }

    public function testNormalisesInput(): void
    {
        [$v, $e] = $this->validator()->validate(['color_primary' => '#ABC', 'color_text' => '22394A', 'lh_body' => '1,7', 'size_h2' => ' 30 ', 'ls_heading' => '-0.02']);
        self::assertSame([], $e);
        self::assertSame('#aabbcc', $v['color_primary']);
        self::assertSame('#22394a', $v['color_text']);
        self::assertSame(1.7, $v['lh_body']);
        self::assertEquals(30, $v['size_h2']);
        self::assertSame(-0.02, $v['ls_heading']);
    }

    public function testRejectsAnythingThatIsNotAPlainValue(): void
    {
        $bad = [
            'color_primary' => ['red', '#12345g', 'rgb(0,0,0)', '#fff;}', 'var(--x)', '#ffffff /*'],
            'size_body' => ['16px', '1e2', '0x10', '', 'NaN', '9999999', '17;'],
            'font_heading' => ['Arial', '"Plus Jakarta Sans"', 'custom-999'],
            'tt_heading' => ['UPPERCASE', 'none;color:red'],
            'shadow_card' => ['0 0 4px #000'],
            'weight_heading' => ['bold', '750'],
        ];
        foreach ($bad as $key => $values) {
            foreach ($values as $raw) {
                [$v, $e] = $this->validator()->validate([$key => $raw]);
                self::assertArrayHasKey($key, $e, "$key = $raw");
                self::assertSame(DesignSchema::defaults()[$key], $v[$key], 'bei Fehler Standardwert');
            }
        }
        [, $e] = $this->validator()->validate(['color_primary' => ['#ffffff'], 'size_body' => (object) []]);
        self::assertCount(2, $e);
    }

    public function testGeneratedCssContainsOnlySafeValues(): void
    {
        $css = new DesignCss(new FontLibrary());
        $tokens = $css->tokens(DesignSchema::defaults());
        foreach ($tokens as $name => $value) {
            self::assertMatchesRegularExpression('/^--[a-z0-9-]+$/', $name);
            self::assertDoesNotMatchRegularExpression('/[;{}<>\\\\]|\/\*|url\(|expression|javascript/i', $value, $name);
        }
        foreach (['--font-family-heading', '--font-size-h1', '--font-size-h6', '--color-primary', '--color-button-hover', '--color-button-active',
            '--color-focus', '--color-success', '--color-warning', '--color-error', '--content-max-width', '--section-spacing',
            '--radius-button', '--radius-card', '--radius-input', '--border-width', '--shadow-card', '--line-height-body', '--letter-spacing-heading',
            '--text-transform-heading', '--font-style-body', '--font-weight-heading'] as $required) {
            self::assertArrayHasKey($required, $tokens);
        }
        $sheet = $css->stylesheet(DesignSchema::defaults());
        self::assertStringStartsWith('/* MEA Designsystem', $sheet);
        self::assertSame(1, substr_count($sheet, ':root {'));
    }

    public function testFluidSizesHitMobileAndDesktopValues(): void
    {
        $clamp = DesignCss::fluid(32, 42, 375, 1280, true);
        self::assertSame('clamp(2rem, 1.741rem + 1.105vw, 2.625rem)', $clamp);
        // Wert am Handy- und Computerrand nachrechnen: rem + vw
        preg_match('/clamp\(([\d.]+)rem, ([\d.]+)rem \+ ([\d.]+)vw, ([\d.]+)rem\)/', $clamp, $m);
        self::assertEqualsWithDelta(32, (float) $m[2] * 16 + (float) $m[3] * 3.75, 0.05);
        self::assertEqualsWithDelta(42, (float) $m[2] * 16 + (float) $m[3] * 12.8, 0.05);
        self::assertSame('1.0625rem', DesignCss::fluid(17, 17, 375, 1280, true));
        self::assertSame('clamp(16px, 12.6851px + 0.884vw, 24px)', DesignCss::fluid(16, 24, 375, 1280, false));
        // Handy größer als Computer: Grenzen bleiben in richtiger Reihenfolge
        self::assertStringStartsWith('clamp(1.5rem, ', DesignCss::fluid(30, 24, 375, 1280, true));
        self::assertStringContainsString('vw - ', DesignCss::fluid(10, 60, 375, 1280, true));
    }

    public function testContrastFollowsWcag(): void
    {
        self::assertEqualsWithDelta(21.0, DesignValidator::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, DesignValidator::contrast('#317566', '#317566'), 0.001);
        $v = DesignSchema::defaults();
        $v['color_button_text'] = '#ffffff';
        $v['color_button'] = '#8fd3c1';
        self::assertNotEmpty(DesignValidator::warnings($v));
    }

    public function testCustomFontNamesCannotBreakOutOfCss(): void
    {
        self::assertSame('"Hausschrift"', FontLibrary::quote('Hausschrift'));
        self::assertSame('"  body  x"', FontLibrary::quote('"; } body { x'));
        $lib = new FontLibrary([['id' => 3, 'family' => 'Haus"}', 'file' => 'abc.woff2', 'weight_min' => 400, 'weight_max' => 700, 'style' => 'normal', 'fallback' => 'serif']]);
        self::assertTrue($lib->has('custom-3'));
        self::assertStringStartsWith('"Haus", Georgia', $lib->stack('custom-3'));
        self::assertStringNotContainsString('}"', $lib->fontFaceCss());
    }
}
