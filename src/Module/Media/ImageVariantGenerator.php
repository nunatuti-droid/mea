<?php
declare(strict_types=1);

namespace Mea\Module\Media;

use Mea\Core\Database;

/**
 * Speichert ein Originalbild außerhalb des Webroots und erzeugt WebP-Varianten
 * in public/media. Bilder werden neu kodiert, Metadaten (EXIF) gehen dabei verloren.
 */
final class ImageVariantGenerator
{
    public const WIDTHS = [480, 960, 1440, 1920];
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private Database $db,
        private string $storageDir,
        private string $publicDir,
    ) {}

    /** @return int ID des Medien-Datensatzes */
    public function import(string $sourceFile, string $altText, string $title = '', string $credit = '', ?string $originalName = null): int
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourceFile) ?: '';
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new \InvalidArgumentException("Dateityp $mime ist nicht erlaubt");
        }
        $info = @getimagesize($sourceFile);
        if ($info === false || $info[0] * $info[1] > 40_000_000) {
            throw new \InvalidArgumentException('Das Bild ist beschädigt oder zu groß (höchstens 40 Megapixel).');
        }
        $originalName ??= basename($sourceFile);
        $hash = hash_file('sha256', $sourceFile) ?: '';
        $existing = $this->db->value('SELECT id FROM media WHERE sha256 = ?', [$hash]);
        if ($existing !== null) {
            return (int) $existing;
        }
        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourceFile),
            'image/png' => imagecreatefrompng($sourceFile),
            'image/webp' => imagecreatefromwebp($sourceFile),
        };
        if ($image === false) {
            throw new \RuntimeException('Bild konnte nicht gelesen werden');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $w = imagesx($image);
        $h = imagesy($image);

        $slug = self::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $stored = bin2hex(random_bytes(8)) . '.' . match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'webp' };
        @mkdir($this->storageDir, 0750, true);
        copy($sourceFile, $this->storageDir . '/' . $stored);

        $id = $this->db->insert('media', [
            'original_name' => mb_substr($originalName, 0, 255),
            'storage_path' => $stored,
            'mime' => $mime,
            'width' => $w,
            'height' => $h,
            'size_bytes' => filesize($sourceFile) ?: 0,
            'sha256' => $hash,
            'alt_text' => $altText,
            'title' => $title,
            'credit' => $credit,
        ]);

        @mkdir($this->publicDir, 0755, true);
        $widths = array_filter(self::WIDTHS, fn(int $x) => $x < $w);
        $widths[] = min($w, 1920);
        foreach (array_unique($widths) as $tw) {
            $th = (int) round($h * $tw / $w);
            $dst = imagecreatetruecolor($tw, $th);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
            $file = sprintf('%d-%s-%d.webp', $id, $slug, $tw);
            imagewebp($dst, $this->publicDir . '/' . $file, 80);
            imagedestroy($dst);
            $this->db->insert('media_variants', [
                'media_id' => $id, 'width' => $tw, 'height' => $th, 'format' => 'webp', 'path' => $file,
            ]);
        }
        imagedestroy($image);
        return $id;
    }

    /** PDF-Dokument speichern (öffentlich herunterladbar). Nur echte PDFs, keine anderen Typen. */
    public function importDocument(string $sourceFile, string $originalName, string $title): int
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($sourceFile) ?: '';
        $head = (string) file_get_contents($sourceFile, false, null, 0, 5);
        if ($mime !== 'application/pdf' || $head !== '%PDF-') {
            throw new \InvalidArgumentException('Nur PDF-Dokumente sind erlaubt.');
        }
        $hash = hash_file('sha256', $sourceFile) ?: '';
        $existing = $this->db->value('SELECT id FROM media WHERE sha256 = ?', [$hash]);
        if ($existing !== null) {
            return (int) $existing;
        }
        $slug = self::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $stored = bin2hex(random_bytes(8)) . '.pdf';
        @mkdir($this->storageDir, 0750, true);
        copy($sourceFile, $this->storageDir . '/' . $stored);
        $id = $this->db->insert('media', [
            'original_name' => mb_substr($originalName, 0, 255), 'storage_path' => $stored, 'mime' => $mime, 'kind' => 'document',
            'size_bytes' => filesize($sourceFile) ?: 0, 'sha256' => $hash, 'alt_text' => '', 'title' => mb_substr($title, 0, 200),
        ]);
        @mkdir($this->publicDir . '/dokumente', 0755, true);
        $file = sprintf('dokumente/%d-%s.pdf', $id, $slug);
        copy($sourceFile, $this->publicDir . '/' . $file);
        $this->db->run('UPDATE media SET public_path = ? WHERE id = ?', [$file, $id]);
        return $id;
    }

    /** Dateien eines Mediums entfernen (nach Prüfung der Verwendungen). */
    public function deleteFiles(int $id): void
    {
        $m = $this->db->one('SELECT * FROM media WHERE id = ?', [$id]);
        if ($m === null) {
            return;
        }
        foreach ($this->db->all('SELECT path FROM media_variants WHERE media_id = ?', [$id]) as $v) {
            $f = $this->publicDir . '/' . basename((string) $v['path']);
            if (is_file($f)) {
                unlink($f);
            }
        }
        foreach ([$this->storageDir . '/' . basename((string) $m['storage_path']), $m['public_path'] ? $this->publicDir . '/dokumente/' . basename((string) $m['public_path']) : null] as $f) {
            if ($f !== null && is_file($f)) {
                unlink($f);
            }
        }
    }

    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim(substr($s, 0, 60), '-') ?: 'bild';
    }
}
