<?php
declare(strict_types=1);

namespace Mea\Module\Applications;

use Mea\Core\Crypto;
use Mea\Core\Database;

/**
 * Bewerbungen über das Bewerbungsformular: Prüfen, Speichern, Lesen und Löschen.
 * Unterlagen sind nur PDF, liegen außerhalb des Web-Ordners und werden verschlüsselt gespeichert.
 */
final class ApplicationService
{
    public const POSITIONS = [
        'jobcoach' => 'Jobcoach / Dozent:in (Honorarbasis)',
        'sprachdozent' => 'Sprachdozent:in (Honorarbasis)',
        'initiativ' => 'Initiativbewerbung',
    ];
    public const STATUS = ['new' => 'Neu', 'in_progress' => 'In Bearbeitung', 'closed' => 'Abgeschlossen'];
    public const MAX_FILES = 3;
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const CONTEXT = 'application-file';

    public function __construct(private Database $db, private Crypto $crypto, private string $dir) {}

    /**
     * @param array<string,string> $in
     * @param list<array{name:string,tmp:string,size:int,error:int}> $files
     * @return array<string,string> Fehler je Feld (leer = gültig)
     */
    public function validate(array $in, array $files): array
    {
        $e = [];
        if (!isset(self::POSITIONS[$in['position'] ?? ''])) {
            $e['position'] = 'Bitte wählen Sie aus, worauf Sie sich bewerben.';
        }
        foreach (['first_name' => 'Vornamen', 'last_name' => 'Nachnamen'] as $k => $label) {
            $v = $in[$k] ?? '';
            if (mb_strlen($v) < 1 || mb_strlen($v) > 80) {
                $e[$k] = 'Bitte geben Sie Ihren ' . $label . ' an.';
            }
        }
        if (!filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 190) {
            $e['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an, zum Beispiel name@beispiel.de.';
        }
        $phone = $in['phone'] ?? '';
        if ($phone !== '' && !preg_match('/^[0-9 +()\/-]{6,40}$/', $phone)) {
            $e['phone'] = 'Bitte nur Ziffern, Leerzeichen und + ( ) / - verwenden.';
        }
        if (mb_strlen($in['message'] ?? '') > 5000) {
            $e['message'] = 'Bitte kürzen Sie Ihre Nachricht auf höchstens 5.000 Zeichen.';
        }
        $fileError = $this->checkFiles($files);
        if ($fileError !== null) {
            $e['files'] = $fileError;
        }
        if (($in['privacy'] ?? '') !== '1') {
            $e['privacy'] = 'Bitte bestätigen Sie die Datenschutzhinweise.';
        }
        return $e;
    }

    /** @param list<array{name:string,tmp:string,size:int,error:int}> $files */
    private function checkFiles(array $files): ?string
    {
        if ($files === []) {
            return 'Bitte laden Sie mindestens eine Datei hoch, zum Beispiel Ihren Lebenslauf.';
        }
        if (count($files) > self::MAX_FILES) {
            return 'Bitte laden Sie höchstens ' . self::MAX_FILES . ' Dateien hoch. Tipp: Mehrere Unterlagen in einer PDF-Datei zusammenfassen.';
        }
        foreach ($files as $f) {
            $name = $f['name'];
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > self::MAX_FILE_BYTES) {
                return '„' . $name . '“ ist größer als 5 MB. Bitte verkleinern Sie die Datei.';
            }
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0) {
                return '„' . $name . '“ konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.';
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp']) ?: '';
            $head = (string) file_get_contents($f['tmp'], false, null, 0, 5);
            if ($mime !== 'application/pdf' || $head !== '%PDF-') {
                return '„' . $name . '“ ist keine PDF-Datei. Bitte laden Sie Ihre Unterlagen als PDF hoch.';
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $in
     * @param list<array{name:string,tmp:string,size:int,error:int}> $files
     */
    public function store(array $in, array $files): int
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Ordner für Bewerbungen kann nicht angelegt werden.');
        }
        $written = [];
        try {
            return $this->db->transaction(function () use ($in, $files, &$written): int {
                $id = $this->db->insert('applications', [
                    'position' => $in['position'],
                    'first_name' => $in['first_name'], 'last_name' => $in['last_name'],
                    'email' => $in['email'], 'phone' => ($in['phone'] ?? '') ?: null,
                    'message' => ($in['message'] ?? '') ?: null,
                    'privacy_accepted_at' => gmdate('Y-m-d H:i:s'),
                ]);
                foreach ($files as $f) {
                    $stored = bin2hex(random_bytes(32));
                    $plain = (string) file_get_contents($f['tmp']);
                    if (file_put_contents($this->dir . '/' . $stored, $this->crypto->encrypt($plain, self::CONTEXT), LOCK_EX) === false) {
                        throw new \RuntimeException('Datei konnte nicht gespeichert werden.');
                    }
                    $written[] = $stored;
                    $this->db->insert('application_files', [
                        'application_id' => $id, 'original_name' => self::safeName($f['name']),
                        'stored_name' => $stored, 'size_bytes' => strlen($plain),
                    ]);
                }
                return $id;
            });
        } catch (\Throwable $e) {
            foreach ($written as $s) {
                @unlink($this->dir . '/' . $s);
            }
            throw $e;
        }
    }

    /** @return array{name:string, content:string}|null */
    public function readFile(int $applicationId, int $fileId): ?array
    {
        $f = $this->db->one('SELECT * FROM application_files WHERE id = ? AND application_id = ?', [$fileId, $applicationId]);
        if ($f === null || !preg_match('/^[a-f0-9]{64}$/', (string) $f['stored_name'])) {
            return null;
        }
        $raw = @file_get_contents($this->dir . '/' . $f['stored_name']);
        if ($raw === false) {
            return null;
        }
        return ['name' => (string) $f['original_name'], 'content' => $this->crypto->decrypt($raw, self::CONTEXT)];
    }

    /** Bewerbung samt Unterlagen endgültig löschen. */
    public function delete(int $id): void
    {
        foreach ($this->db->all('SELECT stored_name FROM application_files WHERE application_id = ?', [$id]) as $f) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) $f['stored_name'])) {
                @unlink($this->dir . '/' . $f['stored_name']);
            }
        }
        $this->db->run('DELETE FROM applications WHERE id = ?', [$id]);
    }

    /**
     * Löschfristen: 6 Monate nach Abschluss; nie abgeschlossene Bewerbungen nach 12 Monaten.
     * @return int Anzahl gelöschter Bewerbungen
     */
    public function purge(\DateTimeImmutable $now): int
    {
        $t = $now->format('Y-m-d H:i:s');
        $ids = $this->db->all("SELECT id FROM applications WHERE (status = 'closed' AND closed_at < ? - INTERVAL 6 MONTH)
                               OR (status <> 'closed' AND created_at < ? - INTERVAL 12 MONTH)", [$t, $t]);
        foreach ($ids as $r) {
            $this->delete((int) $r['id']);
        }
        return count($ids);
    }

    /**
     * $_FILES-Eintrag eines Mehrfach-Uploads in eine Liste umwandeln; leere Felder werden übersprungen.
     * @param mixed $entry
     * @return list<array{name:string,tmp:string,size:int,error:int}>
     */
    public static function normalizeFiles(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }
        $names = (array) $entry['name'];
        $out = [];
        foreach ($names as $i => $name) {
            $error = (int) (((array) $entry['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => (string) $name, 'tmp' => (string) (((array) $entry['tmp_name'])[$i] ?? ''),
                'size' => (int) (((array) $entry['size'])[$i] ?? 0), 'error' => $error];
        }
        return $out;
    }

    public static function safeName(string $name): string
    {
        $base = preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename($name)) ?? 'datei';
        $base = trim(mb_substr($base, 0, 150));
        return str_ends_with(strtolower($base), '.pdf') ? $base : $base . '.pdf';
    }
}
