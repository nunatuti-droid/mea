<?php
declare(strict_types=1);

namespace Mea\Core;

use Mea\Module\Media\MediaRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Twig mit automatischem Escaping und den Helfern der Website. */
final class View
{
    private Environment $twig;

    public function __construct(
        string $root,
        bool $debug,
        private MediaRepository $media,
        private string $baseUrl,
    ) {
        $loader = new FilesystemLoader($root . '/templates');
        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : $root . '/storage/cache/twig',
            // Geänderte Vorlagen sofort übernehmen (z. B. nach Hochladen einzelner Dateien)
            'auto_reload' => true,
            'autoescape' => 'html',
            'strict_variables' => $debug,
        ]);
        $this->twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $this->twig->addExtension(new \Twig\Extra\String\StringExtension());
        // Ausgabe immer in deutscher Ortszeit, gespeichert wird in UTC
        $this->twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone('Europe/Berlin');
        $publicDir = $root . '/public';

        $this->twig->addFunction(new TwigFunction('asset', function (string $p) use ($publicDir): string {
            $file = $publicDir . '/assets/' . ltrim($p, '/');
            $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
            return '/assets/' . ltrim($p, '/') . '?v=' . $v;
        }));
        $this->twig->addFunction(new TwigFunction('icon', function (string $name, string $class = ''): Markup {
            $n = preg_replace('/[^a-z0-9-]/', '', $name);
            $c = htmlspecialchars(trim('icon ' . $class), ENT_QUOTES);
            return new Markup("<svg class=\"$c\" aria-hidden=\"true\" focusable=\"false\"><use href=\"#i-$n\"></use></svg>", 'UTF-8');
        }));
        $this->twig->addFunction(new TwigFunction('img', fn(mixed $m, string $sizes = '100vw', array $attr = []): Markup
            => new Markup($this->media->render($m, $sizes, $attr), 'UTF-8')));
        $this->twig->addFunction(new TwigFunction('media_url', fn(mixed $m, int $w = 1600): string
            => $this->media->url($m, $w)));
        $this->twig->addFunction(new TwigFunction('abs_url', fn(string $p): string
            => rtrim($this->baseUrl, '/') . '/' . ltrim($p, '/')));
        $this->twig->addFilter(new TwigFilter('money', static function (?int $cents, string $currency = 'EUR'): string {
            if ($cents === null) {
                return '';
            }
            $f = new \NumberFormatter('de_DE', \NumberFormatter::CURRENCY);
            return (string) $f->formatCurrency($cents / 100, $currency);
        }));
        $this->twig->addFilter(new TwigFilter('tel', static fn(?string $n): string
            => preg_replace('/[^0-9+]/', '', (string) $n) ?? ''));
        $this->twig->addFilter(new TwigFilter('json_ld', static fn(array $d): Markup
            => new Markup(json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}', 'UTF-8')));
        // Datenbankzeiten sind UTC; der date-Filter zeigt sie danach in Europe/Berlin an
        $this->twig->addFilter(new TwigFilter('utc', static fn(mixed $v): ?\DateTimeImmutable
            => $v === null || $v === '' ? null : ($v instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($v) : new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))));
        $this->twig->addFilter(new TwigFilter('json_decode', static fn(?string $v): mixed => $v === null ? null : json_decode($v, true)));
        $this->twig->addFilter(new TwigFilter('obfuscate', static fn(string $s): string => base64_encode(strrev($s))));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }
}
