<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Design\DesignCss;
use Mea\Module\Design\DesignSchema;
use Mea\Module\Design\FontLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Die Komponenten (public/assets/css/app.css) verwenden nur die zentralen Designvariablen:
 * keine Farbwerte, Schriftnamen, festen Schriftgrößen oder -gewichte, keine Eckradien ohne Variable.
 * Und jede verwendete Variable wird vom Designsystem (oder in app.css als Gerüstmaß) definiert.
 */
final class DesignCssUsageTest extends TestCase
{
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/app.css');
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /** @return list<string> Deklarationen „eigenschaft: wert“ */
    private static function declarations(string $property): array
    {
        preg_match_all('/(?<![\w-])' . preg_quote($property, '/') . '\s*:\s*([^;}]+)/', self::css(), $m);
        return array_map('trim', $m[1]);
    }

    public function testNoHardCodedColours(): void
    {
        $css = self::css();
        self::assertSame(0, preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $m), 'HEX-Farben: ' . implode(', ', $m[0] ?? []));
        self::assertSame(0, preg_match_all('/\b(rgba?|hsla?|hwb|lab|lch|oklch)\(/i', $css, $m), 'Farbfunktionen: ' . implode(', ', $m[0] ?? []));
        self::assertSame(0, preg_match_all('/:\s*[^;{}]*\b(white|black|red|green|blue|gray|grey)\b/i', $css, $m), 'Farbnamen: ' . implode(' | ', $m[0] ?? []));
    }

    public function testTypographyComesFromVariables(): void
    {
        foreach (self::declarations('font-family') as $v) {
            self::assertMatchesRegularExpression('/^(var\(--font-family-[a-z]+\)|inherit)$/', $v, 'font-family: ' . $v);
        }
        foreach (self::declarations('font-size') as $v) {
            self::assertMatchesRegularExpression('/^(var\(--font-size-[a-z0-9-]+\)|calc\(var\(--font-size-[a-z0-9-]+\) \* [\d.]+\)|inherit)$/', $v, 'font-size: ' . $v);
        }
        foreach (self::declarations('font-weight') as $v) {
            self::assertMatchesRegularExpression('/^(var\(--font-weight-[a-z]+\)|inherit)$/', $v, 'font-weight: ' . $v);
        }
        foreach (self::declarations('font') as $v) {
            if ($v === 'inherit') {
                continue;
            }
            self::assertMatchesRegularExpression('/^var\(--font-weight-[a-z]+\) var\(--font-size-[a-z-]+\)\/(var\(--line-height-[a-z-]+\)|[\d.]+) var\(--font-family-[a-z]+\)$/', $v, 'font: ' . $v);
        }
        foreach (['letter-spacing', 'text-transform'] as $p) {
            foreach (self::declarations($p) as $v) {
                self::assertStringStartsWith('var(--', $v, "$p: $v");
            }
        }
    }

    public function testRadiiAndShadowsComeFromVariables(): void
    {
        foreach (self::declarations('border-radius') as $v) {
            self::assertMatchesRegularExpression('/^(.*var\(--radius-[a-z-]+\).*|50%|0|4px|2px|\d+px)$/', $v, 'border-radius: ' . $v);
            if (preg_match('/^\d+px$/', $v)) {
                self::assertContains($v, ['0', '2px', '4px', '40px', '48px', '56px'], 'nur Fokusrahmen, Rauten-Marker und Deko-Rauten haben feste Radien: ' . $v);
            }
        }
        foreach (self::declarations('box-shadow') as $v) {
            self::assertMatchesRegularExpression('/var\(--(shadow-[a-z]+|color-[a-z-]+)\)|^none$/', $v, 'box-shadow: ' . $v);
        }
    }

    public function testEveryUsedVariableIsDefined(): void
    {
        $tokens = array_keys((new DesignCss(new FontLibrary()))->tokens(DesignSchema::defaults()));
        preg_match_all('/(--[a-z0-9-]+)\s*:/', self::css(), $local);
        $defined = array_merge($tokens, $local[1]);
        preg_match_all('/var\((--[a-z0-9-]+)/', self::css(), $used);
        $missing = array_values(array_diff(array_unique($used[1]), $defined));
        self::assertSame([], $missing, 'Nicht definierte Variablen in app.css');
        // Die Komponenten nutzen die wichtigsten Gruppen tatsächlich
        foreach (['--font-family-heading', '--font-family-body', '--font-family-ui', '--color-primary', '--color-secondary', '--color-accent',
            '--color-button-active', '--color-focus', '--color-link-hover', '--color-error', '--content-max-width', '--section-spacing',
            '--radius-button', '--radius-card', '--radius-input', '--border-width', '--shadow-card', '--card-padding', '--space-heading',
            '--text-transform-button', '--font-size-h6', '--font-size-price', '--input-height', '--button-height'] as $v) {
            self::assertContains($v, $used[1], "$v wird von keiner Komponente verwendet");
        }
    }
}
