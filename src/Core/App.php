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
    public readonly ContentRepository $content;
    public readonly PublicController $publicPages;

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
        $noindex = $this->config->bool('APP_NOINDEX');
        $media = $this->media = new MediaRepository($this->db);
        $this->crypto = Crypto::fromConfig($this->config);
        $this->html = new HtmlSanitizer($root . '/storage/cache/htmlpurifier');
        $this->view = new View($root, $debug, $media, $baseUrl);

        $settings = $this->settings = new SettingsRepository($this->db);
        $this->content = new ContentRepository($this->db);
        $locations = $this->locations = new LocationRepository($this->db);
        $exams = $this->exams = new ExamRepository($this->db, new PriceCalculator());
        $this->mailer = new Mailer($this->db, new TemplateRenderer($this->db, $root), $settings, $baseUrl);
        $this->audit = new AuditLog($this->db, $this->crypto);
        $limiter = new RateLimiter($this->db, $this->crypto->hash('ratelimit', 'salt'));
        $this->auth = new AuthService($this->db, $this->crypto, new Totp($this->clock), $limiter, $this->mailer, $this->audit, $this->clock);

        $this->view->addGlobal('site', $settings->all());
        $this->view->addGlobal('main_menu', $this->content->menu('main'));
        $this->view->addGlobal('footer_menu', $this->content->menu('footer'));
        $this->view->addGlobal('legal_menu', $this->content->menu('legal'));
        $this->view->addGlobal('all_locations', $locations->active());
        $this->view->addGlobal('noindex', $noindex);
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
        $seo = new SeoController($this->content, $exams, $locations, $baseUrl, $noindex);

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
        $r->get('/kontakt/', $contact->show(...));
        $r->post('/kontakt/', $contact->submit(...));
        $r->get('/sitemap.xml', $seo->sitemap(...));
        $r->get('/robots.txt', $seo->robots(...));
        $r->get('/{parent:[a-z0-9-]+}/{slug:[a-z0-9-]+}/', $pages->childService(...));
        $r->get('/{slug:[a-z0-9-]+}/', $pages->slug(...));
    }

    private ?\Mea\Module\Payments\PaymentGateway $gateway = null;
    private ?\Mea\Module\Bookings\BookingService $bookingService = null;

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
        try {
            $response = $this->dispatch($req);
        } catch (HttpException $e) {
            $response = $this->errorPage($e->getCode() ?: 404);
        } catch (\Throwable $e) {
            $this->log->error($e->getMessage(), ['exception' => $e, 'path' => $req->path]);
            $response = $this->errorPage(500);
        }
        return $this->secure($response, $req);
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
        $csp = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
            . "frame-src 'self' https://www.openstreetmap.org; connect-src 'self'; form-action 'self'; "
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
        if ($this->config->bool('APP_NOINDEX')) {
            $h['X-Robots-Tag'] = 'noindex, nofollow';
        }
        foreach ($h as $k => $v) {
            $r->headers[$k] ??= $v;
        }
        return $r;
    }
}
