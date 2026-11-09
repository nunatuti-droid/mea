<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Builder\TreeValidator;
use Mea\Module\Content\TemplateTexts;

/** Kopf- und Fußbereich: Texte und Links, die auf allen Seiten erscheinen (global, nur mit Recht „publish“). */
final class TemplateTextsController
{
    private const BASE = '/verwaltung/vorlagentexte/';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get(self::BASE, 'publish', $this->edit(...));
        $k->post(self::BASE, 'publish', $this->save(...));
    }

    public function edit(Request $req, AdminContext $ctx, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        $groups = [];
        foreach (TemplateTexts::catalog() as $key => $def) {
            $groups[$def['group']][$key] = $def;
        }
        return Response::html($this->app->view->render('admin/texts.twig', [
            'title' => 'Kopf- und Fußbereich', 'groups' => $groups, 'errors' => $errors,
            'values' => $values ?? (array) $this->app->settings->get(TemplateTexts::SETTING, []),
        ]), $status);
    }

    public function save(Request $req, AdminContext $ctx): Response
    {
        $raw = $req->inputArray('t');
        $old = (array) $this->app->settings->get(TemplateTexts::SETTING, []);
        $values = [];
        $errors = [];
        foreach (TemplateTexts::catalog() as $key => $def) {
            $v = is_string($raw[$key] ?? null) ? trim(strip_tags($raw[$key])) : '';
            $v = mb_substr(preg_replace('/\s+/u', ' ', $v) ?? '', 0, $def['max']);
            if ($v === '' || $v === $def['default']) {
                continue;
            }
            if ($def['type'] === 'url' && !TreeValidator::safeUrl($v)) {
                $errors[$key] = 'Bitte eine Adresse wie /kontakt/, https://…, mailto: oder tel: angeben.';
            }
            $values[$key] = $v;
        }
        if ($errors !== []) {
            return $this->edit($req, $ctx, $errors, $values, 422);
        }
        $this->app->settings->set(TemplateTexts::SETTING, $values);
        $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($values)]) as $k) {
            if (($old[$k] ?? null) !== ($values[$k] ?? null)) {
                $changes[$k] = [$old[$k] ?? null, $values[$k] ?? null];
            }
        }
        $this->app->audit->record('texts.updated', 'Kopf- und Fußbereich geändert', 'settings', null, ['changes' => $changes]);
        AdminKernel::flash($changes === [] ? 'Keine Änderungen.' : 'Gespeichert. Die Änderungen gelten sofort auf allen Seiten.');
        return Response::redirect(self::BASE, 303);
    }
}
