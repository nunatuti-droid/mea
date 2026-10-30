<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\Csrf;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\Router;
use Mea\Core\Session;
use Mea\Core\View;
use Mea\Module\Audit\AuditLog;
use Mea\Module\Auth\AuthService;
use Mea\Module\Auth\Roles;

/**
 * Schützt jede Verwaltungsseite: gültige Sitzung, Recht der Rolle, CSRF bei POST.
 * Alle Verwaltungsantworten werden nicht gecacht und nicht indexiert.
 */
final class AdminKernel
{
    public function __construct(
        private Router $router,
        private AuthService $auth,
        private View $view,
        private AuditLog $audit,
    ) {}

    public function get(string $path, string $permission, callable $handler): void
    {
        $this->router->get($path, fn(Request $r, string ...$v) => $this->guard($r, $permission, $handler, $v));
    }

    public function post(string $path, string $permission, callable $handler): void
    {
        $this->router->post($path, fn(Request $r, string ...$v) => $this->guard($r, $permission, $handler, $v));
    }

    /** Für Seiten ohne Anmeldung (Login, Einladung): Sitzung + CSRF, aber kein Benutzer. */
    public function publicGet(string $path, callable $handler): void
    {
        $this->router->get($path, fn(Request $r, string ...$v) => $this->open($r, $handler, $v));
    }

    public function publicPost(string $path, callable $handler): void
    {
        $this->router->post($path, fn(Request $r, string ...$v) => $this->open($r, $handler, $v));
    }

    /** @param list<string> $vars */
    private function open(Request $req, callable $handler, array $vars): Response
    {
        Session::start($req->isSecure(), 'admin');
        if ($req->isPost() && !Csrf::check($req->input('_csrf'))) {
            return self::noStore(Response::html($this->view->render('admin/auth/expired.twig', []), 400));
        }
        $this->view->addGlobal('csrf', Csrf::token());
        return self::noStore($handler($req, ...$vars));
    }

    /** @param list<string> $vars */
    private function guard(Request $req, string $permission, callable $handler, array $vars): Response
    {
        Session::start($req->isSecure(), 'admin');
        $resolved = $this->auth->resolveSession((string) Session::get('admin_token', ''));
        if ($resolved === null) {
            Session::forget('admin_token');
            $next = $req->method === 'GET' ? '?weiter=' . rawurlencode($req->path) : '';
            return self::noStore(Response::redirect('/verwaltung/anmelden/' . $next, 303));
        }
        $ctx = new AdminContext($resolved['user'], $resolved['session'], Csrf::token());
        $this->audit->setActor($ctx->id(), $req->ip());
        if ($req->isPost() && !Csrf::check($req->input('_csrf'))) {
            return self::noStore(Response::html($this->view->render('admin/auth/expired.twig', []), 400));
        }
        $this->view->addGlobal('me', $ctx->user + ['role_label' => Roles::label((string) $ctx->user['role'])]);
        $this->view->addGlobal('csrf', $ctx->csrf);
        $this->view->addGlobal('can', array_map(fn($p) => $ctx->can($p), array_combine(array_keys(Roles::PERMISSIONS), array_keys(Roles::PERMISSIONS))));
        $this->view->addGlobal('current_path', $req->path);
        $this->view->addGlobal('flash', Session::flash('admin_flash'));
        if (!$ctx->can($permission)) {
            return self::noStore(Response::html($this->view->render('admin/forbidden.twig', []), 403));
        }
        try {
            return self::noStore($handler($req, $ctx, ...$vars));
        } catch (HttpException $e) {
            if ($e->getCode() === 403) {
                return self::noStore(Response::html($this->view->render('admin/forbidden.twig', []), 403));
            }
            throw $e;
        }
    }

    public static function noStore(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        Session::flash('admin_flash', ['type' => $type, 'message' => $message]);
    }
}
