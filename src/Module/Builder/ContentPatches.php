<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\Database;
use Mea\Module\Content\SettingsRepository;
use Mea\Module\Media\ImageVariantGenerator;

/**
 * Einmalige Inhaltsänderungen, die mit einem Update kommen (z. B. ein neues Bild im Startbereich).
 * Läuft beim ersten Aufruf mit dem neuen Programmstand; erledigte Schritte stehen in der Einstellung „content_patches“.
 * Bilder liegen in migrations/media/, weil dieser Ordner mit jedem Update ausgeliefert wird.
 */
final class ContentPatches
{
    private const SETTING = 'content_patches';

    public function __construct(private Database $db, private SettingsRepository $settings, private string $root) {}

    /** @return list<string> Protokoll */
    public function apply(): array
    {
        $done = (array) $this->settings->get(self::SETTING, []);
        $log = [];
        foreach (['hero-2026-10-31' => fn() => $this->heroImage(), 'campus-2026-11-11' => fn() => implode(' ', (new \Mea\Install\CampusStructure($this->db))->run()),
            'referenz-2026-11-11' => fn() => implode(' ', (new \Mea\Install\ReferenceStructure($this->db))->run()),
            'referenz-inhalte-2026-11-12' => fn() => $this->db->transaction(fn() => (new \Mea\Install\ReferenceStructure($this->db))->content()),
            'referenz-bilder-2026-11-12' => fn() => $this->db->transaction(fn() => (new \Mea\Install\ReferenceStructure($this->db))->images()),
            'referenz-seiten-2026-11-12' => fn() => $this->db->transaction(fn() => (new \Mea\Install\ReferenceStructure($this->db))->aboutPage() . ' ' . (new \Mea\Install\ReferenceStructure($this->db))->contactPage()),
            'onboarding-sechste-box-2026-11-14' => fn() => $this->onboardingSixthModule()] as $key => $patch) {
            if (in_array($key, $done, true)) {
                continue;
            }
            $log[] = $patch();
            $done[] = $key;
            $this->settings->set(self::SETTING, $done);
        }
        return $log;
    }

    /** Neues Foto „Gemeinsames Lernen“ im Startbereich der Startseite (veröffentlicht und Entwurf). */
    private function heroImage(): string
    {
        $file = $this->root . '/migrations/media/hero-gemeinsames-lernen.jpg';
        $home = $this->db->one('SELECT id, blocks_json, draft_json FROM pages WHERE is_home = 1');
        if (!is_file($file) || $home === null) {
            return 'Startbild übersprungen (Datei oder Startseite fehlt).';
        }
        $sha = hash_file('sha256', $file);
        $id = $this->db->value('SELECT id FROM media WHERE sha256 = ?', [$sha]);
        if ($id === null) {
            $gen = new ImageVariantGenerator($this->db, $this->root . '/storage/uploads', $this->root . '/public/media');
            $id = $gen->import($file, 'Dozentin bespricht mit drei Teilnehmenden eine Aufgabe am Laptop', 'Gemeinsames Lernen', '', 'hero-gemeinsames-lernen.jpg');
        }
        foreach (['blocks_json', 'draft_json'] as $col) {
            $tree = json_decode((string) $home[$col], true);
            if (!is_array($tree)) {
                continue;
            }
            $changed = false;
            foreach ($tree as &$n) {
                if (($n['type'] ?? '') === 'hero_home') {
                    $n['f']['media'] = (int) $id;
                    $changed = true;
                }
            }
            unset($n);
            if ($changed) {
                $this->db->run("UPDATE pages SET $col = ? WHERE id = ?", [json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int) $home['id']]);
            }
        }
        return 'Neues Bild im Startbereich der Startseite.';
    }

    /** Sechste Box „Start im Betrieb“ im Abschnitt „Was zum Onboarding gehören kann“ (nur wenn noch nicht vorhanden). */
    private function onboardingSixthModule(): string
    {
        $row = $this->db->one("SELECT id, facts_json FROM services WHERE slug = 'onboarding' AND parent_id = (SELECT id FROM services WHERE slug = 'recruiting' AND parent_id IS NULL)");
        $facts = $row !== null ? json_decode((string) $row['facts_json'], true) : null;
        if (!is_array($facts) || !is_array($facts['modules'] ?? null)) {
            return 'Onboarding-Box übersprungen (Seite oder Bausteine fehlen).';
        }
        foreach ($facts['modules'] as $m) {
            if (($m['title'] ?? '') === 'Start im Betrieb') {
                return 'Onboarding-Box war bereits vorhanden.';
            }
        }
        $facts['modules'][] = ['icon' => 'briefcase', 'title' => 'Start im Betrieb', 'text' => 'Abstimmung von Abläufen, Ansprechpersonen und nächsten Schritten, damit der Arbeitsstart gut vorbereitet ist.'];
        $this->db->run('UPDATE services SET facts_json = ? WHERE id = ?', [json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int) $row['id']]);
        return 'Sechste Box im Onboarding ergänzt.';
    }
}
