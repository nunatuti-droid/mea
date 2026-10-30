<?php
declare(strict_types=1);

namespace Mea\Core;

use Mea\Module\Content\ContentRepository;
use Mea\Module\Content\PublicController;
use Mea\Module\Content\SettingsRepository;
use Mea\Module\Exams\ExamController;
use Mea\Module\Exams\ExamRepository;
use Mea\Module\Exams\PriceCalculator;
use Mea\Module\Inquiries\ContactController;
use Mea\Module\Inquiries\InquiryService;
use Mea\Module\Inquiries\RateLimiter;
use Mea\Module\Locations\LocationController;
use Mea\Module\Locations\LocationRepository;
use Mea\Module\Media\MediaRepository;
use Mea\Module\Seo\SeoController;
use Mea\Admin\AdminKernel;
use Mea\Admin\AdminModule;
use Mea\Admin\Labels;
use Mea\Module\Audit\AuditLog;
use Mea\Module\Auth\AuthService;
use Mea\Module\Auth\Totp;
use Mea\Module\Mail\Mailer;
use Mea\Module\Mail\OutboxWorker;
use Mea\Module\Mail\TemplateRenderer;
use Mea\Module\Mail\TransportFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/** Baut die Anwendung zusammen und beantwortet eine Anfrage. */
final class App
{
    public readonly Config $config;
    public readonly Database $db;
    public readonly View $view;
    public readonly Logger $log;
    public readonly Crypto $crypto;
    public readonly SettingsRepository $settings;
    public readonly Mailer $mailer;
    public readonly AuditLog $audit;
    public readonly AuthService $auth;
    public readonly ExamRepository $exams;
    public readonly LocationRepository $locations;
    public readonly MediaRepository $media;
    public readonly HtmlSanitizer $html;
    public readonly \Psr\Clock\ClockInterface $clock;
    public readonly string $baseUrl;
    private Router $router;
    /** Nur für den Selbsttest: Wartungsmodus und Offline-Schalter übergehen, damit nach einem Update der neue Stand geprüft werden kann. */
    public bool $bypassMaintenance = false;
    public readonly ContentRepository $content;
    public readonly PublicController $publicPages;
    public readonly \Mea\Module\Security\Firewall $firewall;
    public readonly \Mea\Module\Ops\SiteStatus $siteStatus;
    public readonly \Mea\Module\Consent\ConsentConfig $consent;

    public function __construct(public readonly string $root, ?Config $config = null, ?Database $db = null, ?\Psr\Clock\ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->config = $config ?? Config::fromEnvironment($root);
        date_default_timezone_set('UTC');
        $this->log = new Logger('mea');
        @mkdir($root . '/storage/logs', 0750, true);
        $this->log->pushHandler(new StreamHandler($root . '/storage/logs/app.log'));
        $this->db = $db ?? Database::fromConfig($this->config);

        $baseUrl = $this->baseUrl = $this->config->get('APP_URL', 'http://localhost') ?? '';
        $debug = $this->config->bool('APP_DEBUG');
        $media = $this->media = new MediaRepository($this->db);
        $this->crypto = Crypto::fromConfig($this->config);
        $this->html = new HtmlSanitizer($root . '/storage/cache/htmlpurifier');
        $this->view = new View($root, $debug, $media, $baseUrl);

        $settings = $this->settings = new SettingsRepository($this->db);
        $this->siteStatus = new \Mea\Module\Ops\SiteStatus($settings, $this->config);
        $this->consent = new \Mea\Module\Consent\ConsentConfig($settings);
        $this->content = new ContentRepository($this->db);
        $locations = $this->locations = new LocationRepository($this->db);
        $exams = $this->exams = new ExamRepository($this->db, new PriceCalculator());
        $this->mailer = new Mailer($this->db, new TemplateRenderer($this->db, $root), $settings, $baseUrl);
        $this->audit = new AuditLog($this->db, $this->crypto);
        $this->firewall = new \Mea\Module\Security\Firewall($this->db, $this->crypto, $this->clock);
        $limiter = new RateLimiter($this->db, $this->crypto->hash('ratelimit', 'salt'));
        $this->auth = new AuthService($this->db, $this->crypto, new Totp($this->clock), $limiter, $this->mailer, $this->audit, $this->clock);

        $this->view->addGlobal('site', $settings->all());
        $this->view->addGlobal('main_menu', $this->content->menu('main'));
        $this->view->addGlobal('footer_menu', $this->content->menu('footer'));
        $this->view->addGlobal('legal_menu', $this->content->menu('legal'));
        $this->view->addGlobal('all_locations', $locations->active());
        $this->view->addGlobal('exam_center', $locations->examCenter());
        $this->view->addGlobal('consent', ['categories' => $this->consent->categories(), 'client' => $this->consent->clientConfig()]);
        $this->view->addGlobal('noindex', !$this->siteStatus->indexingAllowed());
        $this->view->addGlobal('site_live', ['online' => true, 'indexing' => true]);
        $this->view->addGlobal('base_url', rtrim($baseUrl, '/'));
        $this->view->addGlobal('rating_summary', $this->content->ratingSummary());
        $this->view->addGlobal('footer_reviews', $this->content->reviews());

        $pages = $this->publicPages = new PublicController($this->view, $this->content, $exams, $locations, $settings);
        $examCtl = new ExamController($this->view, $exams, $this->content);
        $locCtl = new LocationController($this->view, $locations, $this->content);
        $contact = new ContactController(
            $this->view,
            new InquiryService($this->db),
            $limiter,
            $locations,
            $this->content,
            $this->mailer,
        );
        $seo = new SeoController($this->content, $exams, $locations, $baseUrl, fn(): bool => !$this->siteStatus->indexingAllowed());

        $r = $this->router = new Router();
        // Verwaltung zuerst, damit die allgemeinen Seiten-Routen sie nicht überdecken
        $this->view->addGlobal('labels', Labels::MAPS);
        // Werte der Verwaltung vorab anlegen; Twig erlaubt nach dem ersten Rendern nur Änderungen bestehender Globals
        foreach (['me' => null, 'csrf' => '', 'can' => [], 'current_path' => '', 'flash' => null] as $k => $v) {
            $this->view->addGlobal($k, $v);
        }
        (new AdminModule($this))->register(new AdminKernel($r, $this->auth, $this->view, $this->audit));
        $r->get('/', $pages->home(...));
        $r->get('/leistungen/', $pages->servicesIndex(...));
        $r->get('/faq/', $pages->faq(...));
        $r->get('/telc-pruefungszentrum/', $examCtl->index(...));
        $r->get('/pruefungen/', fn() => Response::redirect('/telc-pruefungszentrum/', 301));
        $r->get('/pruefungen/{slug:[a-z0-9-]+}/', $examCtl->show(...));
        $booking = new \Mea\Module\Bookings\BookingController($this, $limiter);
        $booking->register($r);
        $r->get('/preise/', $examCtl->prices(...));
        $r->get('/standorte/', $locCtl->index(...));
        $r->get('/standorte/{slug:[a-z0-9-]+}/', $locCtl->show(...));
        $isTesting = $this->config->get('APP_ENV') === 'testing';
        $apply = new \Mea\Module\Applications\ApplicationController($this->view, $this->applications(), $limiter, $this->content,
            fn(string $tmp): bool => $isTesting ? is_file($tmp) : is_uploaded_file($tmp), $this->mailer, $this->log);
        $r->get('/karriere/bewerbung/', $apply->show(...));
        $r->post('/karriere/bewerbung/', $apply->submit(...));
        $r->get('/kontakt/', $contact->show(...));
        $r->post('/kontakt/', $contact->submit(...));
        $r->post('/einwilligung/', (new \Mea\Module\Consent\ConsentController($this->db, $this->consent, $limiter))->record(...));
        $r->get('/sitemap.xml', $seo->sitemap(...));
        $r->get('/robots.txt', $seo->robots(...));
        $r->get('/{parent:[a-z0-9-]+}/{slug:[a-z0-9-]+}/', $pages->childService(...));
        $r->get('/{slug:[a-z0-9-]+}/', $pages->slug(...));
    }

    private ?\Mea\Module\Payments\PaymentGateway $gateway = null;
    private ?\Mea\Module\Bookings\BookingService $bookingService = null;
    private ?\Mea\Module\Applications\ApplicationService $applicationService = null;

    public function applications(): \Mea\Module\Applications\ApplicationService
    {
        return $this->applicationService ??= new \Mea\Module\Applications\ApplicationService($this->db, $this->crypto, $this->root . '/storage/applications');
    }

    public function paymentGateway(): \Mea\Module\Payments\PaymentGateway
    {
        return $this->gateway ??= new \Mea\Module\Payments\PayPalGateway(
            new \GuzzleHttp\Client(['http_errors' => true]),
            (string) ($this->config->get('PAYPAL_MODE') ?? 'sandbox'),
            (string) ($this->config->get('PAYPAL_CLIENT_ID') ?? ''),
            (string) ($this->config->get('PAYPAL_SECRET') ?? ''),
            (string) ($this->config->get('PAYPAL_WEBHOOK_ID') ?? ''),
        );
    }

    /** Für Tests: anderen Zahlungsanbieter einsetzen. */
    public function useGateway(\Mea\Module\Payments\PaymentGateway $g): void
    {
        $this->gateway = $g;
        $this->bookingService = null;
    }

    public function bookings(): \Mea\Module\Bookings\BookingService
    {
        return $this->bookingService ??= new \Mea\Module\Bookings\BookingService(
            $this->db, new PriceCalculator(), $this->paymentGateway(), $this->mailer, $this->audit,
            $this->crypto, $this->clock, $this->log, $this->baseUrl,
        );
    }

    public function version(): string
    {
        return trim((string) @file_get_contents($this->root . '/VERSION')) ?: '0.0.0';
    }

    public function backups(): \Mea\Module\Ops\BackupService
    {
        $b64 = $this->config->get('BACKUP_KEY');
        $key = $b64 !== null ? (string) base64_decode($b64, true) : $this->crypto->subKey('backup');
        return new \Mea\Module\Ops\BackupService($this->db, $this->root, new \Mea\Module\Ops\BackupCrypto($key), $this->log, $this->version());
    }

    public function updates(): \Mea\Module\Ops\UpdateService
    {
        return new \Mea\Module\Ops\UpdateService($this->db, $this->root, $this->config->get('RELEASE_PUBLIC_KEY'), $this->backups(), $this->log, $this->version());
    }

    public function fileGuard(): \Mea\Module\Security\FileGuard
    {
        return new \Mea\Module\Security\FileGuard($this->root, $this->config->get('RELEASE_PUBLIC_KEY'));
    }

    public function outbox(): OutboxWorker
    {
        $c = $this->settings->get('company', []);
        return new OutboxWorker(
            $this->db,
            TransportFactory::fromConfig($this->config, $this->root),
            (string) ($this->config->get('MAIL_FROM') ?? ''),
            (string) ($this->config->get('MAIL_FROM_NAME') ?? ($c['name'] ?? 'MEA Bildungszentrum')),
        );
    }

    public function handle(Request $req): Response
    {
        $guard = null;
        try {
            // Einstellung kann sich zwischen Anfragen ändern (Schalter in der Verwaltung)
            $this->view->addGlobal('noindex', !$this->siteStatus->indexingAllowed());
            $this->view->addGlobal('site_live', ['online' => $this->siteStatus->isOnline(), 'indexing' => $this->siteStatus->indexingAllowed()]);
            $guard = $this->guard($req);
            $response = $guard ?? $this->dispatch($req);
        } catch (HttpException $e) {
            $response = $this->errorPage($e->getCode() ?: 404);
        } catch (\Throwable $e) {
            $this->log->error($e->getMessage(), ['exception' => $e, 'path' => $req->path]);
            $response = $this->errorPage(500);
        }
        if ($guard === null && $response->status === 404) {
            $this->noteNotFound($req);
        }
        return $this->secure($response, $req);
    }

    /** Nicht gefundene Adressen: Dateisuche zählt für den Anwendungsschutz, alles andere landet (ohne IP) im 404-Protokoll. */
    private function noteNotFound(Request $req): void
    {
        try {
            if (preg_match('/\.(php\d?|asp|aspx|jsp|cgi|bak|sql|zip|tar|gz|old|swp|ini|log)$/i', $req->path)) {
                $this->firewall->record('probe', $req, 1, 'info'); // Suche nach Dateien, die es hier nicht gibt
                return;
            }
            if (!in_array($req->method, ['GET', 'HEAD'], true) || str_starts_with($req->path, '/verwaltung/') || str_starts_with($req->path, '/buchung/') || strlen($req->path) > 255
                || !preg_match('#^/[\x21-\x7E]*$#', $req->path)) {
                return;
            }
            $ref = parse_url((string) ($req->server['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
            $this->db->run('INSERT INTO not_found_log (path, referrer_host) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen_at = UTC_TIMESTAMP(), resolved_at = NULL,
                            referrer_host = COALESCE(VALUES(referrer_host), referrer_host)',
                [$req->path, is_string($ref) ? mb_substr($ref, 0, 120) : null]);
        } catch (\Throwable $e) {
            $this->log->warning('404-Protokoll nicht schreibbar', ['error' => $e->getMessage()]);
        }
    }

    /** Wartungsmodus und Anwendungsschutz vor dem Routing. */
    private function guard(Request $req): ?Response
    {
        $isAdmin = str_starts_with($req->path, '/verwaltung/');
        if (is_file(self::maintenanceFlag($this->root)) && !$isAdmin && !$this->bypassMaintenance) {
            return self::staticPage(503, 'Wartungsarbeiten', 'Die Website wird gerade aktualisiert. Bitte versuchen Sie es in wenigen Minuten erneut.')
                ->withHeader('Retry-After', '300');
        }
        // Website im Backend offline geschaltet: Wartungsseite, Verwaltung und Zahlungsrückmeldungen bleiben erreichbar
        if (!$this->bypassMaintenance && !\Mea\Module\Ops\SiteStatus::alwaysReachable($req->path) && !$this->siteStatus->isOnline()) {
            return $this->offlinePage();
        }
        $check = $this->firewall->inspect($req);
        return match ($check['action']) {
            'block' => self::staticPage(403, 'Zugriff vorübergehend gesperrt', 'Von Ihrer Verbindung kamen auffällige Anfragen. Der Zugriff ist deshalb vorübergehend gesperrt. Wenn Sie uns erreichen möchten, rufen Sie uns bitte an.'),
            'reject' => self::staticPage(403, 'Anfrage abgelehnt', 'Diese Anfrage wurde aus Sicherheitsgründen abgelehnt.'),
            'probe' => $this->errorPage(404),
            'method' => self::staticPage(405, 'Methode nicht erlaubt', 'Diese Anfrage wird nicht unterstützt.')->withHeader('Allow', implode(', ', \Mea\Module\Security\Firewall::METHODS)),
            default => null,
        };
    }

    private function offlinePage(): Response
    {
        try {
            $body = $this->view->render('public/offline.twig', ['message' => $this->siteStatus->offlineMessage()]);
        } catch (\Throwable $e) {
            $this->log->error('Wartungsseite: ' . $e->getMessage());
            return self::staticPage(503, 'Wartungsarbeiten', $this->siteStatus->offlineMessage());
        }
        return Response::html($body, 503)->withHeader('Retry-After', '3600')->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    public static function maintenanceFlag(string $root): string
    {
        return $root . '/storage/maintenance.flag';
    }

    /** Einfache Seite ohne Datenbankzugriff (Sperre, Wartung). */
    private static function staticPage(int $code, string $title, string $text): Response
    {
        $t = htmlspecialchars($title, ENT_QUOTES);
        $body = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $t . '</title><link rel="stylesheet" href="/assets/css/app.css"></head>'
            . '<body><main class="container section"><h1>' . $t . '</h1><p class="lead">' . htmlspecialchars($text, ENT_QUOTES) . '</p></main></body></html>';
        return Response::html($body, $code)->withHeader('Cache-Control', 'no-store');
    }

    private function dispatch(Request $req): Response
    {
        // Einheitliche URLs mit Schrägstrich am Ende (alte WordPress-Struktur)
        if (in_array($req->method, ['GET', 'HEAD'], true) && !str_ends_with($req->path, '/')
            && !preg_match('/\.[a-z0-9]{2,5}$/', $req->path)) {
            $qs = http_build_query($req->query);
            return Response::redirect($req->path . '/' . ($qs ? '?' . $qs : ''), 301);
        }
        [$status, $handler, $vars] = $this->router->match($req->method, $req->path);
        if ($status === 200 && $handler !== null) {
            try {
                return $handler($req, ...array_values($vars));
            } catch (HttpException $e) {
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        } elseif ($status === 405) {
            return new Response('Methode nicht erlaubt', 405, ['Allow' => 'GET, HEAD']);
        }
        $redirect = $this->content->redirectFor($req->path);
        if ($redirect !== null) {
            if ((int) $redirect['status_code'] === 410) {
                return $this->errorPage(410);
            }
            return Response::redirect((string) $redirect['target_url'], (int) $redirect['status_code']);
        }
        throw HttpException::notFound();
    }

    private function errorPage(int $code): Response
    {
        try {
            $body = $this->view->render('public/error.twig', ['code' => $code]);
        } catch (\Throwable) {
            $body = '<!doctype html><title>Fehler</title><p>Es ist ein Fehler aufgetreten.</p>';
        }
        return Response::html($body, $code);
    }

    /** Sicherheitsheader für jede Antwort. */
    private function secure(Response $r, Request $req): Response
    {
        // Fremde Quellen nur für Dienste, die in der Verwaltung eingetragen sind; geladen werden sie erst nach Einwilligung
        $x = $this->consent->cspSources();
        $add = fn(array $l): string => $l !== [] ? ' ' . implode(' ', $l) : '';
        $csp = "default-src 'self'; script-src 'self'" . $add($x['script']) . "; style-src 'self'; img-src 'self' data:" . $add($x['img']) . "; font-src 'self'; "
            . "frame-src 'self' https://www.google.com" . $add($x['frame']) . "; connect-src 'self'" . $add($x['connect']) . "; form-action 'self'; "
            . "base-uri 'self'; frame-ancestors 'none'; object-src 'none'";
        $h = [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if ($req->isSecure()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        if (!$this->siteStatus->indexingAllowed()) {
            $h['X-Robots-Tag'] = 'noindex, nofollow';
        }
        foreach ($h as $k => $v) {
            $r->headers[$k] ??= $v;
        }
        return $r;
    }
}
