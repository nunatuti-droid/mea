<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\Response;
use Mea\Core\View;

/** Komplette HTML-Seite aus einem Seitenaufbau: dieselbe Ausgabe für Website, Vorschau und Editor. */
final class PageView
{
    public function __construct(
        private View $view,
        private PageRenderer $renderer,
        private PageService $pages,
        private string $cssPublicBase,
    ) {}

    /**
     * @param array<string,mixed> $page Zeile aus pages
     * @param array<string,mixed> $ctx query, form
     * @param array{draft?:bool, editing?:bool, css_url?:?string} $mode
     */
    public function html(array $page, array $ctx = [], array $mode = []): string
    {
        $draft = $mode['draft'] ?? false;
        $tree = PageService::tree($page, $draft);
        $r = $this->renderer->render($tree, $ctx + ['page' => $page, 'draft' => $draft, 'editing' => $mode['editing'] ?? false]);
        $css = array_key_exists('css_url', $mode) ? $mode['css_url'] : $this->pages->cssUrl($page, $this->cssPublicBase);
        return $this->view->render('public/builder_page.twig', [
            'page' => $page, 'content_html' => $r['html'], 'meta' => $r['meta'], 'page_css' => $css,
            'is_home' => (int) ($page['is_home'] ?? 0) === 1, 'canonical' => PageService::url($page),
            'editing' => $mode['editing'] ?? false, 'draft' => $draft,
        ]);
    }

    /** @param array<string,mixed> $ctx */
    public function respond(string $slug, array $ctx = [], int $status = 200): ?Response
    {
        $page = $this->pages->published($slug);
        return $page === null ? null : Response::html($this->html($page, $ctx), $status);
    }
}
