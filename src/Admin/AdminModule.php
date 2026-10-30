<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Admin\Crud\CrudController;
use Mea\Admin\Resources;
use Mea\Core\App;

/** Registriert alle Verwaltungsseiten unter /verwaltung/. */
final class AdminModule
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $app = $this->app;
        (new AuthController($app->auth, $app->view, $app->crypto))->register($k);
        $dash = new DashboardController($app);
        $k->get('/verwaltung/', 'dashboard', $dash->index(...));

        foreach ([
            new ExamAdminController($app), new SessionAdminController($app), new MediaController($app),
            new SettingsController($app), new BookingAdminController($app), new UserController($app),
            new EmailLogController($app), new AuditController($app), new SystemController($app),
        ] as $controller) {
            $controller->register($k);
        }

        $crud = new CrudController($app->db, $app->view, $app->html, $app->audit);
        $db = $app->db;
        $resources = [
            new Resources\ExamTypeResource($db, fn(array $row) => $app->view->render('public/exam_show.twig', [
                'exam' => $app->exams->previewRow($row), 'faqs' => [], 'others' => [], 'notice' => null,
            ])),
            new Resources\ProviderResource($db),
            new Resources\BookingRuleResource($db),
            new Resources\SessionResource($db, fn(int $sessionId, string $template, string $message) => $app->bookings()->notifySession($sessionId, $template, $message)),
            new Resources\PageResource($db, fn(array $row) => $app->publicPages->pageHtml($row)),
            new Resources\ServiceResource($db, fn(array $row) => $app->publicPages->serviceHtml($row)),
            new Resources\FaqResource($db),
            new Resources\ReviewResource($db),
            new Resources\MenuItemResource($db),
            new Resources\LocationResource($db),
            new Resources\HolidayResource($db),
            new Resources\InquiryResource($db),
            new Resources\EmailTemplateResource($db),
        ];
        foreach ($resources as $r) {
            $crud->register($k, $r);
        }
    }
}
