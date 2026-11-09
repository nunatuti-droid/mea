<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Ops\UpdateJobs;

/** Update in der Verwaltung: Fortschrittsseite und Statusabfrage. */
final class UpdateAdminTest extends IntegrationTestCase
{
    public function testProgressPageAndStatusEndpoint(): void
    {
        $this->loginAs('admin');
        $jobs = new UpdateJobs($this->app);
        $token = bin2hex(random_bytes(16));
        $jobs->create($token, '9.9.9', null);
        try {
            $page = $this->get('/verwaltung/updates/fortschritt/' . $token . '/');
            self::assertSame(200, $page->status);
            self::assertStringContainsString('Update auf Version 9.9.9', $page->body);
            self::assertStringContainsString('data-update-progress', $page->body);
            $st = $this->get('/verwaltung/updates/status/' . $token . '/');
            self::assertSame(200, $st->status);
            self::assertSame('queued', json_decode($st->body, true)['status']);
            self::assertSame(404, $this->get('/verwaltung/updates/status/' . str_repeat('0', 32) . '/')->status);
            // „Ausführen“ ohne Paket: Auftrag scheitert sauber statt hängen zu bleiben
            $r = $this->adminPost('/verwaltung/updates/ausfuehren/', ['token' => $token]);
            self::assertTrue(json_decode($r->body, true)['started']);
            self::assertNotNull($r->after);
            ($r->after)();
            $job = $jobs->get($token);
            self::assertSame('failed', $job['status']);
            self::assertStringContainsString('nicht mehr vorhanden', $job['message']);
        } finally {
            @unlink($jobs->dir() . '/job-' . $token . '.json');
        }
    }

    public function testUpdatesPageOffersChunkedUpload(): void
    {
        $this->loginAs('admin');
        $body = $this->get('/verwaltung/updates/')->body;
        self::assertStringContainsString('data-chunk-upload="/verwaltung/updates/hochladen-teil/"', $body);
        $r = $this->adminPost('/verwaltung/updates/hochladen-teil/', ['upload_id' => 'kaputt', 'index' => '0', 'total' => '1']);
        self::assertSame(400, $r->status);
    }
}
