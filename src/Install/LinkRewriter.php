<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;

/** Ersetzt interne Adressen in Texten, Bausteinen und Einstellungen (alte Adresse => neue Adresse). */
final class LinkRewriter
{
    /** @param array<string,string> $map */
    public function __construct(private Database $db, private array $map) {}

    public function run(): string
    {
        foreach ([
            ['services', 'id', ['intro', 'teaser', 'body_html', 'cta_url']],
            ['pages', 'id', ['intro', 'body_html']],
            ['faqs', 'id', ['answer_html']],
        ] as [$table, $key, $cols]) {
            foreach ($this->db->all("SELECT $key, " . implode(',', $cols) . " FROM $table") as $row) {
                $set = [];
                foreach ($cols as $c) {
                    if (is_string($row[$c] ?? null) && ($new = $this->text($row[$c])) !== $row[$c]) {
                        $set[$c] = $new;
                    }
                }
                $this->update($table, $key, $row[$key], $set);
            }
        }
        foreach ([['services', 'id', ['facts_json']], ['pages', 'id', ['blocks_json', 'draft_json']], ['builder_components', 'id', ['tree_json', 'draft_json']]] as [$table, $key, $cols]) {
            foreach ($this->db->all("SELECT $key, " . implode(',', $cols) . " FROM $table") as $row) {
                $set = [];
                foreach ($cols as $c) {
                    $new = $this->json($row[$c] ?? null);
                    if ($new !== null) {
                        $set[$c] = $new;
                    }
                }
                $this->update($table, $key, $row[$key], $set);
            }
        }
        foreach ($this->db->all('SELECT setting_key, value_json FROM settings') as $row) {
            $new = $this->json($row['value_json'] ?? null);
            if ($new !== null) {
                $this->db->run('UPDATE settings SET value_json = ? WHERE setting_key = ?', [$new, $row['setting_key']]);
            }
        }
        return 'Interne Verweise auf die neuen Adressen umgestellt.';
    }

    /** Neues JSON, wenn sich etwas geändert hat, sonst null. */
    private function json(mixed $raw): ?string
    {
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $new = json_encode($this->deep($data), $flags);
        return $new !== json_encode($data, $flags) ? $new : null;
    }

    /** @param array<string,mixed> $set */
    private function update(string $table, string $key, mixed $id, array $set): void
    {
        if ($set === []) {
            return;
        }
        $sql = "UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . " WHERE $key = ?";
        $this->db->run($sql, [...array_values($set), $id]);
    }

    private function text(string $s): string
    {
        foreach ($this->map as $old => $new) {
            $s = preg_replace('#(?<![\w/.\-])' . preg_quote($old, '#') . '#', $new, $s) ?? $s;
        }
        return $s;
    }

    private function deep(mixed $v): mixed
    {
        if (is_string($v)) {
            return $this->text($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = $this->deep($x);
            }
        }
        return $v;
    }
}
