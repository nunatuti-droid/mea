<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\Csrf;
use Mea\Tests\Support\FakeGateway;
use OTPHP\TOTP;

/** Schutz der Verwaltung: Anmeldung, Zwei-Faktor, Rollen, CSRF, Sperren, letzter Administrator, Webhook. */
final class AdminSecurityTest extends IntegrationTestCase
{
    private function loginForm(string $email, string $password, string $code): array
    {
        $r1 = $this->request('POST', '/verwaltung/anmelden/', ['_csrf' => Csrf::token(), 'email' => $email, 'password' => $password, 'weiter' => '']);
        if ($r1->status !== 303) {
            return [$r1, null];
        }
        return [$r1, $this->request('POST', '/verwaltung/anmelden/code/', ['_csrf' => Csrf::token(), 'code' => $code])];
    }

    public function testAdminPagesRequireLogin(): void
    {
        $r = $this->get('/verwaltung/buchungen/');
        self::assertSame(303, $r->status);
        self::assertStringStartsWith('/verwaltung/anmelden/', $r->headers['Location']);
        self::assertSame(303, $this->request('POST', '/verwaltung/pruefungen/neu/', ['_csrf' => Csrf::token(), 'public_name' => 'x'])->status);
        self::assertSame(0, (int) self::db()->value("SELECT COUNT(*) FROM exam_types WHERE public_name = 'x'"));
    }

    public function testFullLoginWithTotpAndNoReplay(): void
    {
        $u = $this->loginAs('admin', 'chefin@example.org');
        $_SESSION = [];
        $code = TOTP::createFromSecret('JBSWY3DPEHPK3PXP')->now();
        [$r1, $r2] = $this->loginForm('chefin@example.org', 'Lange sichere Passphrase 2026', $code);
        self::assertSame('/verwaltung/anmelden/code/', $r1->headers['Location']);
        self::assertSame(303, $r2->status);
        self::assertSame('/verwaltung/', $r2->headers['Location']);
        $dash = $this->get('/verwaltung/');
        self::assertSame(200, $dash->status);
        self::assertSame('no-store', $dash->headers['Cache-Control']);
        self::assertSame('noindex, nofollow', $dash->headers['X-Robots-Tag']);

        // derselbe Code ein zweites Mal: abgelehnt
        $_SESSION = [];
        [, $again] = $this->loginForm('chefin@example.org', 'Lange sichere Passphrase 2026', $code);
        self::assertSame(422, $again->status);
        self::assertNotNull($u);
    }

    public function testWrongPasswordLocksAccount(): void
    {
        $this->loginAs('admin', 'sperre@example.org');
        $_SESSION = [];
        for ($i = 0; $i < 5; $i++) {
            [$r] = $this->loginForm('sperre@example.org', 'falsches Passwort ' . $i, '');
            self::assertSame(422, $r->status);
        }
        [$r] = $this->loginForm('sperre@example.org', 'Lange sichere Passphrase 2026', '');
        self::assertSame(429, $r->status, 'Konto ist gesperrt, auch mit richtigem Passwort');
        self::assertNotNull(self::db()->value("SELECT locked_until FROM users WHERE email = 'sperre@example.org'"));
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'security_notice' AND to_email = 'sperre@example.org'"));
        // Unbekannte Adresse verrät nichts
        [$unknown] = $this->loginForm('niemand@example.org', 'irgendwas', '');
        self::assertSame(422, $unknown->status);
    }

    public function testRolesAreEnforced(): void
    {
        $this->loginAs('editor');
        self::assertSame(200, $this->get('/verwaltung/pruefungen/')->status);
        self::assertSame(403, $this->get('/verwaltung/buchungen/')->status);
        self::assertSame(403, $this->get('/verwaltung/benutzer/')->status);
        self::assertSame(403, $this->get('/verwaltung/system/')->status);

        $_SESSION = [];
        $this->loginAs('booking');
        self::assertSame(200, $this->get('/verwaltung/buchungen/')->status);
        self::assertSame(403, $this->get('/verwaltung/pruefungen/neu/')->status);
        self::assertSame(403, $this->adminPost('/verwaltung/seiten/neu/', ['title' => 'Hack'])->status);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->loginAs('admin');
        $r = $this->request('POST', '/verwaltung/einstellungen/', ['setting__company__name' => 'Geändert']);
        self::assertSame(400, $r->status);
        $r = $this->request('POST', '/verwaltung/einstellungen/', ['_csrf' => 'falsch', 'setting__company__name' => 'Geändert']);
        self::assertSame(400, $r->status);
    }

    public function testRevokedSessionIsLoggedOut(): void
    {
        $u = $this->loginAs('admin');
        self::assertSame(200, $this->get('/verwaltung/')->status);
        $this->app->auth->revokeSessions((int) $u['id'], null);
        self::assertSame(303, $this->get('/verwaltung/')->status);
    }

    public function testLastAdminCannotBeDisabledAndCriticalChangesNeedReauth(): void
    {
        $u = $this->loginAs('admin');
        $others = (int) self::db()->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$u['id']]);
        self::assertSame(0, $others);
        $form = ['name' => 'Test Admin', 'role' => 'admin', 'status' => 'disabled'];
        $r = $this->adminPost('/verwaltung/benutzer/' . $u['id'] . '/', $form);
        self::assertStringStartsWith('/verwaltung/bestaetigen/', $r->headers['Location'], 'erst erneut bestätigen');

        self::db()->run('UPDATE user_sessions SET reauth_at = UTC_TIMESTAMP() WHERE user_id = ?', [$u['id']]);
        $this->adminPost('/verwaltung/benutzer/' . $u['id'] . '/', $form);
        self::assertSame('active', self::db()->value('SELECT status FROM users WHERE id = ?', [$u['id']]));
    }

    public function testAuditLogNeverStoresSecrets(): void
    {
        $this->loginAs('admin');
        $this->app->audit->record('test.secret', 'Test', 'user', 1, ['password' => 'geheim123', 'totp_secret_enc' => 'xyz', 'name' => 'ok']);
        $d = (string) self::db()->value("SELECT details_json FROM audit_log WHERE action = 'test.secret'");
        self::assertStringNotContainsString('geheim123', $d);
        self::assertStringNotContainsString('xyz', $d);
        self::assertStringContainsString('ok', $d);
    }

    public function testWebhookRejectsBadSignatureAndIgnoresDuplicates(): void
    {
        $gw = new FakeGateway();
        $this->app->useGateway($gw);
        $body = json_encode(['id' => 'WH-TEST-1', 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => ['id' => 'X', 'supplementary_data' => ['related_ids' => ['order_id' => 'NONE']]]]);
        $gw->webhookValid = false;
        $r = $this->request('POST', '/zahlung/paypal/webhook/', [], ['_RAW_BODY' => $body]);
        self::assertSame(400, $r->status);
        self::assertSame(0, (int) self::db()->value("SELECT COUNT(*) FROM payment_events WHERE event_id = 'WH-TEST-1'"));

        $gw->webhookValid = true;
        self::assertSame(200, $this->request('POST', '/zahlung/paypal/webhook/', [], ['_RAW_BODY' => $body])->status);
        $dup = $this->request('POST', '/zahlung/paypal/webhook/', [], ['_RAW_BODY' => $body]);
        self::assertSame(200, $dup->status);
        self::assertStringContainsString('duplicate', $dup->body);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM payment_events WHERE event_id = 'WH-TEST-1'"));
    }

    public function testEmailTemplatesAreSeededAndRender(): void
    {
        $this->loginAs('admin');
        self::assertSame(13, (int) self::db()->value('SELECT COUNT(*) FROM email_templates'));
        $id = (int) self::db()->value("SELECT id FROM email_templates WHERE template_key = 'booking_confirmation'");
        $r = $this->get("/verwaltung/email-vorlagen/$id/beispiel/");
        self::assertSame(200, $r->status);
        self::assertStringContainsString('MEA-BEISPIEL', $r->body);
        self::assertStringContainsString("sandbox", $r->headers['Content-Security-Policy']);
        // Platzhalter laufen in der Sandbox: keine Methodenaufrufe, keine fremden Filter
        $renderer = new \Mea\Module\Mail\TemplateRenderer(self::db(), self::root());
        foreach (['{{ teilnehmer.name|raw }}', '{{ _self.env.getRuntime("x") }}', '{% include "x" %}'] as $evil) {
            try {
                $renderer->renderRaw('Test', '<p>' . $evil . '</p>', ['teilnehmer' => ['name' => '<b>x</b>']]);
                self::fail('Sandbox hat zugelassen: ' . $evil);
            } catch (\Twig\Error\Error) {
                self::addToAssertionCount(1);
            }
        }
        $ok = $renderer->renderRaw('Hallo {{ name }}', '<p>{{ name }}</p>', ['name' => '<script>x</script>']);
        self::assertStringNotContainsString('<script>', $ok['html'], 'Werte werden maskiert');
    }
}
