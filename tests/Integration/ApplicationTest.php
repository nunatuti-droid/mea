<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\Request;

/** Bewerbungsformular: nur PDF, verschlüsselte Ablage, Download nur mit Recht „Bewerbungen“, Löschfristen. */
final class ApplicationTest extends IntegrationTestCase
{
    private function pdf(string $content = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"): string
    {
        $p = tempnam(sys_get_temp_dir(), 'mea-pdf');
        file_put_contents($p, $content);
        return $p;
    }

    /** @param list<string> $paths */
    private function submit(array $post, array $paths, array $names = []): \Mea\Core\Response
    {
        \Mea\Core\Session::start(false);
        $files = ['documents' => ['name' => [], 'tmp_name' => [], 'size' => [], 'error' => [], 'type' => []]];
        foreach ($paths as $i => $p) {
            $files['documents']['name'][] = $names[$i] ?? 'Lebenslauf.pdf';
            $files['documents']['tmp_name'][] = $p;
            $files['documents']['size'][] = filesize($p);
            $files['documents']['error'][] = UPLOAD_ERR_OK;
            $files['documents']['type'][] = 'application/pdf';
        }
        $post += ['_csrf' => \Mea\Core\Csrf::token(), '_t' => (string) (time() - 30), 'website' => '',
            'position' => 'sprachdozent', 'first_name' => 'Anna', 'last_name' => 'Beispiel', 'email' => 'anna@example.org', 'privacy' => '1'];
        return $this->app->handle(new Request('POST', '/karriere/bewerbung/', [], $post, ['REMOTE_ADDR' => '203.0.113.' . random_int(10, 250)], [], $files));
    }

    public function testFormIsReachableFromCareerPage(): void
    {
        self::assertStringContainsString('href="/karriere/bewerbung/"', $this->get('/karriere/')->body);
        $res = $this->get('/karriere/bewerbung/');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('enctype="multipart/form-data"', $res->body);
    }

    public function testValidPdfIsStoredEncrypted(): void
    {
        $content = "%PDF-1.4\nGeheimer Lebenslauf\n%%EOF\n";
        $res = $this->submit([], [$this->pdf($content)]);
        self::assertSame(303, $res->status);
        $a = self::db()->one("SELECT * FROM applications WHERE email = 'anna@example.org' ORDER BY id DESC LIMIT 1");
        self::assertNotNull($a);
        $f = self::db()->one('SELECT * FROM application_files WHERE application_id = ?', [$a['id']]);
        $stored = (string) file_get_contents(self::root() . '/storage/applications/' . $f['stored_name']);
        self::assertStringNotContainsString('Geheimer Lebenslauf', $stored, 'Datei liegt verschlüsselt');
        self::assertSame($content, $this->app->applications()->readFile((int) $a['id'], (int) $f['id'])['content']);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'application_notification' AND related_id = ?", [$a['id']]));
        $this->app->applications()->delete((int) $a['id']);
        self::assertFileDoesNotExist(self::root() . '/storage/applications/' . $f['stored_name']);
    }

    public function testRejectsNonPdfAndMissingFiles(): void
    {
        $res = $this->submit([], [$this->pdf("MZ\x90\x00 kein pdf")], ['virus.pdf']);
        self::assertSame(422, $res->status);
        self::assertStringContainsString('ist keine PDF-Datei', $res->body);
        $res = $this->submit([], []);
        self::assertSame(422, $res->status);
        self::assertStringContainsString('mindestens eine Datei', $res->body);
        $res = $this->submit([], [$this->pdf(), $this->pdf(), $this->pdf(), $this->pdf()]);
        self::assertStringContainsString('höchstens 3 Dateien', $res->body);
    }

    public function testDownloadNeedsApplicationsPermission(): void
    {
        $this->submit(['email' => 'perm@example.org'], [$this->pdf()]);
        $id = (int) self::db()->value("SELECT id FROM applications WHERE email = 'perm@example.org'");
        $fid = (int) self::db()->value('SELECT id FROM application_files WHERE application_id = ?', [$id]);
        $this->loginAs('editor');
        self::assertSame(403, $this->get("/verwaltung/bewerbungen/$id/datei/$fid/")->status);
        $this->loginAs('admin');
        $res = $this->get("/verwaltung/bewerbungen/$id/datei/$fid/");
        self::assertSame(200, $res->status);
        self::assertStringStartsWith('%PDF-', $res->body);
        $this->app->applications()->delete($id);
    }

    public function testPurgeAfterRetention(): void
    {
        $this->submit(['email' => 'old@example.org'], [$this->pdf()]);
        $id = (int) self::db()->value("SELECT id FROM applications WHERE email = 'old@example.org'");
        self::db()->run("UPDATE applications SET status = 'closed', closed_at = UTC_TIMESTAMP() - INTERVAL 7 MONTH WHERE id = ?", [$id]);
        self::assertSame(1, $this->app->applications()->purge(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertNull(self::db()->value('SELECT id FROM applications WHERE id = ?', [$id]));
    }
}
