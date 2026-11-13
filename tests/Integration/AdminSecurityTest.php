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

    
    private function passwordStep(string $email, array $cookies = [], array $extra = []): \Mea\Core\Response
    {
        $_SESSION = [];
        return $this->app->handle(new \Mea\Core\Request('POST', '/verwaltung/anmelden/', [], [
            '_csrf' => Csrf::token(), 'email' => $email, 'password' => 'Lange sichere Passphrase 2026', 'weiter' => '',
        ] + $extra, ['REMOTE_ADDR' => '203.0.113.7'], $cookies));
    }

    private static function cookieValue(\Mea\Core\Response $r, string $name): ?string
    {
        foreach ($r->cookies as $c) {
            if (str_starts_with($c, $name . '=')) {
                return rawurldecode(explode(';', substr($c, strlen($name) + 1))[0]);
            }
        }
        return null;
    }

    public function testTrustedDeviceSkipsCodeOnlyForThatAccountAndUntilRevoked(): void
    {
        $this->loginAs('admin', 'vertraut@example.org');
        $other = $this->loginAs('admin', 'fremd@example.org');
        $code = TOTP::createFromSecret('JBSWY3DPEHPK3PXP')->now();

        // Ohne Haken im Code-Formular: kein Cookie, nächster Login verlangt wieder den Code
        $_SESSION = [];
        $this->passwordStep('vertraut@example.org');
        $r = $this->request('POST', '/verwaltung/anmelden/code/', ['_csrf' => Csrf::token(), 'code' => $code]);
        self::assertSame(303, $r->status);
        self::assertNull(self::cookieValue($r, 'mea-trust'));
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM user_trusted_devices'));

        // Mit Haken: Cookie (HttpOnly, Strict), nur der Hash liegt in der Datenbank
        $_SESSION = [];
        $this->passwordStep('vertraut@example.org');
        $r = $this->request('POST', '/verwaltung/anmelden/code/', ['_csrf' => Csrf::token(), 'code' => TOTP::createFromSecret('JBSWY3DPEHPK3PXP')->at(time() + 30), 'trust' => '1']);
        self::assertSame(303, $r->status);
        $token = self::cookieValue($r, 'mea-trust');
        self::assertNotNull($token);
        $cookie = implode('|', array_filter($r->cookies, fn($c) => str_starts_with($c, 'mea-trust=')));
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM user_trusted_devices WHERE token_hash = ?', [$token]));
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM user_trusted_devices'));

        // Passwort richtig + Cookie: direkt angemeldet, ohne Code
        $r = $this->passwordStep('vertraut@example.org', ['mea-trust' => $token]);
        self::assertSame('/verwaltung/', $r->headers['Location']);
        self::assertSame(200, $this->get('/verwaltung/')->status);

        // Cookie gehört zum Konto: bei einem anderen Konto wird weiter der Code verlangt
        $r = $this->passwordStep('fremd@example.org', ['mea-trust' => $token]);
        self::assertSame('/verwaltung/anmelden/code/', $r->headers['Location']);
        self::assertNotNull($other);

        // Falsches Passwort + Cookie: kein Login
        $_SESSION = [];
        $bad = $this->app->handle(new \Mea\Core\Request('POST', '/verwaltung/anmelden/', [], ['_csrf' => Csrf::token(), 'email' => 'vertraut@example.org', 'password' => 'falsch falsch falsch', 'weiter' => ''], ['REMOTE_ADDR' => '203.0.113.7'], ['mea-trust' => $token]));
        self::assertSame(422, $bad->status);

        // Abgelaufen: Code wieder nötig
        self::db()->run('UPDATE user_trusted_devices SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        self::assertSame('/verwaltung/anmelden/code/', $this->passwordStep('vertraut@example.org', ['mea-trust' => $token])->headers['Location']);
        self::db()->run('UPDATE user_trusted_devices SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 DAY');
        self::assertSame('/verwaltung/', $this->passwordStep('vertraut@example.org', ['mea-trust' => $token])->headers['Location']);

        // Widerrufen: Code wieder nötig
        $uid = (int) self::db()->value("SELECT id FROM users WHERE email = 'vertraut@example.org'");
        self::assertSame(1, $this->app->auth->revokeTrustedDevices($uid));
        self::assertSame('/verwaltung/anmelden/code/', $this->passwordStep('vertraut@example.org', ['mea-trust' => $token])->headers['Location']);
    }

    public function testPasswordChangeAndTotpResetRevokeTrustedDevices(): void
    {
        $u = $this->loginAs('admin', 'pw@example.org');
        $a = $this->app->auth;
        $t1 = $a->trustDevice($u, 'PHPUnit');
        self::assertNotNull($a->trustedDeviceId((int) $u['id'], $t1));
        $a->changePassword((int) $u['id'], 'Noch eine lange Passphrase 2027');
        self::assertNull($a->trustedDeviceId((int) $u['id'], $t1));
        $t2 = $a->trustDevice($u, 'PHPUnit');
        $a->resetTotp((int) $u['id']);
        self::assertNull($a->trustedDeviceId((int) $u['id'], $t2));
        self::assertNull($a->trustedDeviceId((int) $u['id'], 'kein-token'));
    }

    public function testRememberMeExtendsSessionLifetimeAndCookieResumesIt(): void
    {
        $u = $this->loginAs('admin', 'bleiben@example.org');
        $a = $this->app->auth;
        $normal = $a->startSession($u, '203.0.113.7', 'PHPUnit', '');
        $kept = $a->startSession($u, '203.0.113.7', 'PHPUnit', '', true);

        // 2 Tage ohne Aktivität: normale Sitzung abgelaufen, „angemeldet bleiben“ noch gültig
        self::db()->run('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 2 DAY, created_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE user_id = ?', [$u['id']]);
        self::assertNull($a->resolveSession($normal['token']));
        self::assertNotNull($a->resolveSession($kept['token']));

        // 15 Tage ohne Aktivität: auch diese ist abgelaufen
        self::db()->run('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 15 DAY WHERE user_id = ?', [$u['id']]);
        self::assertNull($a->resolveSession($kept['token']));

        // 31 Tage seit Anmeldung, aber aktiv: absolute Höchstdauer
        $again = $a->startSession($u, '203.0.113.7', 'PHPUnit', '', true);
        self::db()->run('UPDATE user_sessions SET created_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE token_hash = ?', [$this->app->crypto->hash($again['token'], 'session')]);
        self::assertNull($a->resolveSession($again['token']));

        // Cookie trägt die Sitzung, auch wenn die PHP-Sitzung weg ist
        $fresh = $a->startSession($u, '203.0.113.7', 'PHPUnit', '', true);
        $_SESSION = [];
        $r = $this->app->handle(new \Mea\Core\Request('GET', '/verwaltung/', [], [], ['REMOTE_ADDR' => '203.0.113.7'], ['mea-remember' => $fresh['token']]));
        self::assertSame(200, $r->status);
        // ohne Cookie: Weiterleitung zur Anmeldung
        $_SESSION = [];
        self::assertSame(303, $this->get('/verwaltung/')->status);
    }

    public function testLoginFormOffersRememberAndCodeFormOffersTrust(): void
    {
        $this->loginAs('admin', 'formular@example.org');
        $_SESSION = [];
        self::assertStringContainsString('name="remember"', $this->get('/verwaltung/anmelden/')->body);
        $this->passwordStep('formular@example.org');
        self::assertStringContainsString('name="trust"', $this->get('/verwaltung/anmelden/code/')->body);
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
