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
            return '<a href="' . $u . '" style="color:#317566">' . htmlspecialchars($label ?: $url) . '</a>';
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
        $inner = $this->twig->createTemplate($body)->render($vars);
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

    public static function toText(string $html): string
    {
        $t = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $t = preg_replace('#<(br|/p|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $t) ?? $t;
        $t = preg_replace('#<li\b[^>]*>#i', '- ', $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t) ?? $t);
    }
}
