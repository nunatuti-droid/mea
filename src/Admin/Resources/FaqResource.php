<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Core\Request;

final class FaqResource extends Resource
{
    public string $key = 'faq';
    public string $table = 'faqs';
    public string $permission = 'content';
    public string $singular = 'Frage';
    public string $plural = 'Häufige Fragen';
    public string $entity = 'faq';
    public string $intro = 'Fragen und Antworten. Wählen Sie, wo eine Frage erscheint: Startseite, FAQ-Seite, bei bestimmten Prüfungen oder Leistungen.';
    public string $orderBy = 'sort_order, id';
    public array $searchColumns = ['question', 'category'];

    public function columns(): array
    {
        return [
            ['key' => 'question', 'label' => 'Frage'],
            ['key' => 'category', 'label' => 'Kategorie'],
            ['key' => 'is_active', 'label' => 'Aktiv', 'format' => 'status:bool'],
            ['key' => 'sort_order', 'label' => 'Sortierung', 'format' => 'int'],
        ];
    }

    /** @return array<string,string> */
    private function targets(): array
    {
        $t = ['home' => 'Startseite', 'faq_page' => 'FAQ-Seite'];
        foreach ($this->db->all("SELECT id, public_name FROM exam_types WHERE status <> 'archived' ORDER BY sort_order") as $e) {
            $t['exam_type:' . $e['id']] = 'Prüfung: ' . $e['public_name'];
        }
        foreach ($this->db->all('SELECT id, title FROM services ORDER BY parent_id IS NOT NULL, sort_order') as $s) {
            $t['service:' . $s['id']] = 'Leistung: ' . $s['title'];
        }
        return $t;
    }

    public function toForm(array $row): array
    {
        if (isset($row['id'])) {
            $row['targets'] = array_map(fn($a) => $a['target_id'] ? $a['target_type'] . ':' . $a['target_id'] : $a['target_type'],
                $this->db->all('SELECT target_type, target_id FROM faq_assignments WHERE faq_id = ?', [$row['id']]));
        }
        return $row;
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'question', 'label' => 'Frage', 'required' => true, 'max' => 300, 'wide' => true],
            ['name' => 'answer_html', 'label' => 'Antwort', 'type' => 'html', 'required' => true],
            ['name' => 'category', 'label' => 'Kategorie', 'max' => 80],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0],
            ['name' => 'is_active', 'label' => 'Aktiv', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'targets', 'label' => 'Anzeigen auf', 'type' => 'multi', 'options' => $this->targets(), 'virtual' => true],
        ];
    }

    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void
    {
        $this->db->run('DELETE FROM faq_assignments WHERE faq_id = ?', [$id]);
        $allowed = $this->targets();
        foreach ($req->inputList('targets') as $t) {
            if (!isset($allowed[$t])) {
                continue;
            }
            [$type, $tid] = array_pad(explode(':', $t), 2, null);
            $this->db->insert('faq_assignments', ['faq_id' => $id, 'target_type' => $type, 'target_id' => $tid !== null ? (int) $tid : null]);
        }
    }
}
