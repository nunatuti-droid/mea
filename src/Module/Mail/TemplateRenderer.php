<?php
declare(strict_types=1);

namespace Mea\Module\Mail;

use Mea\Core\Database;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Rendert E-Mail-Vorlagen aus der Datenbank. Platzhalter wie {{ teilnehmer.vorname }}
 * laufen in einer Twig-Sandbox: nur if/for, wenige Filter, keine Methodenaufrufe.
 */
final class TemplateRenderer
{
    private Environment $twig;

    public function __construct(private Database $db, private string $root)
    {
        $this->twig = new Environment(new ArrayLoader(), ['autoescape' => 'html', 'strict_variables' => false]);
        $policy = new SecurityPolicy(
            ['if', 'for', 'apply'],
            ['escape', 'e', 'upper', 'lower', 'nl2br', 'default', 'join', 'length', 'raw_link'],
            [], [], [],
        );
        $this->twig->addExtension(new SandboxExtension($policy, true));
        // Link-Filter: gibt eine geprüfte URL als Link aus (nur http/https)
        $this->twig->addFilter(new \Twig\TwigFilter('raw_link', static function (string $url, string $label = ''): string {
            if (!preg_match('#^https?://#', $url)) {
                return htmlspecialchars($label ?: $url);
            }
            $u = htmlspecialchars($url, ENT_QUOTES);
            if ($label === '') {
                return '<a href="' . $u . '" style="color:#317566;font-weight:bold;word-break:break-all">' . $u . '</a>';
            }
            // Mit Beschriftung als Schaltfläche
            return '<a href="' . $u . '" style="display:inline-block;background:#317566;color:#ffffff;font-weight:bold;text-decoration:none;'
                . 'padding:13px 24px;border-radius:10px;font-size:16px;line-height:1.2">' . htmlspecialchars($label) . '</a>';
        }, ['is_safe' => ['html']]));
    }

    /** @return array{subject:string, html:string, text:string} */
    public function render(string $key, array $vars): array
    {
        $tpl = $this->db->one('SELECT subject, body_html FROM email_templates WHERE template_key = ?', [$key]);
        if ($tpl === null) {
            throw new \RuntimeException("E-Mail-Vorlage $key fehlt.");
        }
        return $this->renderRaw((string) $tpl['subject'], (string) $tpl['body_html'], $vars);
    }

    /** @param array<string,mixed> $vars @return array{subject:string, html:string, text:string} */
    public function renderRaw(string $subject, string $body, array $vars): array
    {
        $s = $this->twig->createTemplate($subject)->render($vars);
        $inner = self::style($this->twig->createTemplate($body)->render($vars));
        $company = $vars['firma']['name'] ?? 'MEA Bildungszentrum';
        $layout = (string) file_get_contents($this->root . '/templates/email/layout.html');
        $html = strtr($layout, [
            '%%SUBJECT%%' => htmlspecialchars(html_entity_decode($s), ENT_QUOTES),
            '%%BODY%%' => $inner,
            '%%COMPANY%%' => htmlspecialchars((string) $company, ENT_QUOTES),
            '%%FOOTER%%' => htmlspecialchars((string) ($vars['firma']['footer'] ?? ''), ENT_QUOTES),
        ]);
        return ['subject' => trim(html_entity_decode(strip_tags($s), ENT_QUOTES)), 'html' => $html, 'text' => self::toText($inner)];
    }

    /**
     * Gestaltet das Editor-HTML für Mailprogramme mit Inline-Styles (viele ignorieren <style>).
     * Der Editor speichert keine style-Attribute, daher kommt die Gestaltung immer von hier.
     */
    public static function style(string $html): string
    {
        $rules = [
            'p' => 'margin:0 0 16px',
            'h2' => 'margin:8px 0 12px;font-size:20px;line-height:1.3;color:#0e3042',
            'h3' => 'margin:8px 0 10px;font-size:17px;line-height:1.3;color:#0e3042',
            'ul' => 'margin:0 0 16px;padding-left:22px',
            'ol' => 'margin:0 0 16px;padding-left:22px',
            'li' => 'margin:0 0 6px',
            'hr' => 'border:0;border-top:1px solid #dbe6e3;margin:24px 0',
            'blockquote' => 'margin:0 0 16px;padding:12px 16px;border-left:3px solid #3f8d7b;background:#f3f8f6',
            'th' => 'text-align:left;vertical-align:top;padding:9px 12px 9px 18px;font-weight:normal;color:#4a5b66;font-size:14px;width:36%',
            'td' => 'vertical-align:top;padding:9px 18px 9px 12px;font-weight:bold;color:#0e3042',
            'a' => 'color:#317566;font-weight:bold',
        ];
        $out = preg_replace_callback('#<(p|h2|h3|ul|ol|li|hr|blockquote|th|td|a)\b((?:(?!style=)[^>])*)>#i', static function (array $m) use ($rules): string {
            return '<' . $m[1] . $m[2] . ' style="' . $rules[strtolower($m[1])] . '">';
        }, $html) ?? $html;
        // Tabellen als ruhiger Kasten
        $out = preg_replace('#<table\b(?![^>]*style=)[^>]*>#i', '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;margin:4px 0 22px;background:#f3f8f6;border:1px solid #dbe6e3;border-radius:12px;border-collapse:separate;padding:6px 0">', $out) ?? $out;
        return $out;
    }

    public static function toText(string $html): string
    {
        $t = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $t = preg_replace('#</th>#i', ': ', $t) ?? $t;
        $t = preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/table)\b[^>]*>#i', "\n", $t) ?? $t;
        $t = preg_replace('#<li\b[^>]*>#i', '- ', $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t) ?? $t);
    }
}
