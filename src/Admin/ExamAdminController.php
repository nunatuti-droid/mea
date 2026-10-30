<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Admin\Crud\Form;
use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Audit\AuditLog;

/** Gebühren, Zusatzfelder für die Anmeldung, Dokumente und Kopieren einer Prüfungsart. */
final class ExamAdminController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post('/verwaltung/pruefungen/{id:\d+}/gebuehren/', 'exams', $this->saveFees(...));
        $k->post('/verwaltung/pruefungen/{id:\d+}/felder/', 'exams', $this->saveFields(...));
        $k->post('/verwaltung/pruefungen/{id:\d+}/dokumente/', 'exams', $this->addDocument(...));
        $k->post('/verwaltung/pruefungen/{id:\d+}/dokumente/{doc:\d+}/entfernen/', 'exams', $this->removeDocument(...));
        $k->post('/verwaltung/pruefungen/{id:\d+}/kopieren/', 'exams', $this->copy(...));
    }

    private function exam(string $id): array
    {
        $e = $this->app->db->one('SELECT * FROM exam_types WHERE id = ?', [(int) $id]);
        if ($e === null) {
            throw HttpException::notFound();
        }
        return $e;
    }

    private function back(string $id, string $anchor, string $msg, string $type = 'success'): Response
    {
        AdminKernel::flash($msg, $type);
        return Response::redirect('/verwaltung/pruefungen/' . (int) $id . '/#' . $anchor, 303);
    }

    public function saveFees(Request $req, AdminContext $ctx, string $id): Response
    {
        $exam = $this->exam($id);
        $rows = $req->inputArray('fees');
        $clean = [];
        $i = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $label = trim((string) ($r['label'] ?? ''));
            $amount = Form::parseMoney((string) ($r['amount'] ?? ''));
            if ($label === '' && ($r['amount'] ?? '') === '') {
                continue;
            }
            if ($label === '' || $amount === null || mb_strlen($label) > 120) {
                return $this->back($id, 'gebuehren', 'Jede Gebühr braucht eine Bezeichnung (max. 120 Zeichen) und einen gültigen Betrag.', 'error');
            }
            $clean[] = ['id' => ctype_digit((string) ($r['id'] ?? '')) ? (int) $r['id'] : null, 'label' => $label, 'amount_cents' => $amount,
                'is_mandatory' => ($r['mandatory'] ?? '') === '1' ? 1 : 0, 'sort_order' => $i++];
        }
        $before = $this->app->exams->fees((int) $id);
        $this->app->db->transaction(function () use ($clean, $id): void {
            $keep = array_filter(array_column($clean, 'id'));
            $this->app->db->run('DELETE FROM exam_type_fees WHERE exam_type_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''), [(int) $id]);
            foreach ($clean as $c) {
                if ($c['id'] !== null) {
                    $this->app->db->run('UPDATE exam_type_fees SET label = ?, amount_cents = ?, is_mandatory = ?, sort_order = ? WHERE id = ? AND exam_type_id = ?',
                        [$c['label'], $c['amount_cents'], $c['is_mandatory'], $c['sort_order'], $c['id'], (int) $id]);
                } else {
                    $this->app->db->insert('exam_type_fees', ['exam_type_id' => (int) $id] + array_diff_key($c, ['id' => 1]));
                }
            }
        });
        $this->app->audit->record('exam_type.fees', 'Gebühren geändert: ' . $exam['public_name'], 'exam_type', (int) $id, ['vorher' => array_map(fn($f) => $f['label'] . ' ' . $f['amount_cents'], $before), 'nachher' => array_map(fn($f) => $f['label'] . ' ' . $f['amount_cents'], $clean)]);
        return $this->back($id, 'gebuehren', 'Gebühren gespeichert. Bestehende Buchungen behalten ihren gebuchten Preis.');
    }

    public function saveFields(Request $req, AdminContext $ctx, string $id): Response
    {
        $exam = $this->exam($id);
        $types = ['text', 'textarea', 'date', 'select', 'checkbox', 'number'];
        $clean = [];
        $keys = [];
        $i = 0;
        foreach ($req->inputArray('fields') as $r) {
            if (!is_array($r)) {
                continue;
            }
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = Form::slugify((string) (($r['key'] ?? '') !== '' ? $r['key'] : $label));
            $key = substr(str_replace('-', '_', $key), 0, 40);
            $type = in_array($r['type'] ?? '', $types, true) ? $r['type'] : 'text';
            if ($key === '' || isset($keys[$key])) {
                return $this->back($id, 'zusatzfelder', "Die Feldkennung „{$key}“ ist leer oder doppelt.", 'error');
            }
            $keys[$key] = true;
            $options = $type === 'select' ? array_values(array_filter(array_map('trim', explode("\n", (string) ($r['options'] ?? ''))), fn($o) => $o !== '')) : [];
            if ($type === 'select' && $options === []) {
                return $this->back($id, 'zusatzfelder', "Das Auswahlfeld „{$label}“ braucht mindestens eine Option (eine pro Zeile).", 'error');
            }
            $clean[] = ['field_key' => $key, 'label' => mb_substr($label, 0, 150), 'field_type' => $type,
                'options_json' => $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
                'help_text' => mb_substr(trim((string) ($r['help'] ?? '')), 0, 255) ?: null,
                'is_required' => ($r['required'] ?? '') === '1' ? 1 : 0, 'sort_order' => $i++];
        }
        $this->app->db->transaction(function () use ($clean, $id): void {
            $this->app->db->run('DELETE FROM exam_form_fields WHERE exam_type_id = ?', [(int) $id]);
            foreach ($clean as $c) {
                $this->app->db->insert('exam_form_fields', ['exam_type_id' => (int) $id] + $c);
            }
        });
        $this->app->audit->record('exam_type.form_fields', 'Anmeldefelder geändert: ' . $exam['public_name'], 'exam_type', (int) $id, ['felder' => array_column($clean, 'label')]);
        return $this->back($id, 'zusatzfelder', 'Zusatzfelder gespeichert. Bestehende Buchungen behalten ihre Angaben.');
    }

    public function addDocument(Request $req, AdminContext $ctx, string $id): Response
    {
        $this->exam($id);
        $media = (int) $req->input('media_id');
        if ($this->app->db->value('SELECT 1 FROM media WHERE id = ?', [$media]) === null) {
            return $this->back($id, 'dokumente', 'Bitte eine Datei aus der Mediathek wählen.', 'error');
        }
        $role = $req->input('role') === 'document' ? 'document' : 'gallery';
        $this->app->db->insert('exam_type_media', ['exam_type_id' => (int) $id, 'media_id' => $media, 'role' => $role,
            'label' => mb_substr($req->input('label'), 0, 150) ?: null, 'sort_order' => (int) $this->app->db->value('SELECT COUNT(*) FROM exam_type_media WHERE exam_type_id = ?', [(int) $id])]);
        $this->app->audit->record('exam_type.media', 'Datei zugeordnet', 'exam_type', (int) $id, ['media' => $media]);
        return $this->back($id, 'dokumente', 'Datei zugeordnet.');
    }

    public function removeDocument(Request $req, AdminContext $ctx, string $id, string $doc): Response
    {
        $this->app->db->run('DELETE FROM exam_type_media WHERE id = ? AND exam_type_id = ?', [(int) $doc, (int) $id]);
        $this->app->audit->record('exam_type.media', 'Datei-Zuordnung entfernt', 'exam_type', (int) $id);
        return $this->back($id, 'dokumente', 'Zuordnung entfernt. Die Datei bleibt in der Mediathek.');
    }

    public function copy(Request $req, AdminContext $ctx, string $id): Response
    {
        $e = $this->exam($id);
        $newId = $this->app->db->transaction(function () use ($e): int {
            $slug = $e['slug'] . '-kopie';
            $n = 2;
            while ($this->app->db->value('SELECT 1 FROM exam_types WHERE slug = ?', [$slug]) !== null) {
                $slug = $e['slug'] . '-kopie-' . $n++;
            }
            $row = array_diff_key($e, array_flip(['id', 'created_at', 'updated_at', 'published_at']));
            $row['slug'] = $slug;
            $row['status'] = 'draft';
            $row['public_name'] = $e['public_name'] . ' (Kopie)';
            $row['internal_name'] = $e['internal_name'] . ' (Kopie)';
            $newId = $this->app->db->insert('exam_types', $row);
            foreach ($this->app->db->all('SELECT * FROM exam_type_fees WHERE exam_type_id = ?', [$e['id']]) as $f) {
                $this->app->db->insert('exam_type_fees', ['exam_type_id' => $newId] + array_diff_key($f, ['id' => 1, 'exam_type_id' => 1]));
            }
            foreach ($this->app->db->all('SELECT * FROM exam_form_fields WHERE exam_type_id = ?', [$e['id']]) as $f) {
                $this->app->db->insert('exam_form_fields', ['exam_type_id' => $newId] + array_diff_key($f, ['id' => 1, 'exam_type_id' => 1]));
            }
            return $newId;
        });
        $this->app->audit->record('exam_type.created', 'Prüfungsart kopiert aus ' . $e['public_name'], 'exam_type', $newId);
        AdminKernel::flash('Kopie als Entwurf angelegt. Passen Sie Name und URL-Kennung an.');
        return Response::redirect('/verwaltung/pruefungen/' . $newId . '/', 303);
    }
}
