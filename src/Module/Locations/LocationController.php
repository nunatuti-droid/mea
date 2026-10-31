<?php
declare(strict_types=1);

namespace Mea\Module\Locations;

use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\View;
use Mea\Module\Content\ContentRepository;

final class LocationController
{
    public function __construct(
        private View $view,
        private LocationRepository $locations,
        private ContentRepository $content,
        /** @var \Closure(string, array<string,mixed>, int):?\Mea\Core\Response */
        private \Closure $page,
    ) {}

    public function index(Request $req): Response
    {
        return ($this->page)('standorte', ['query' => $req->query], 200) ?? throw HttpException::notFound();
    }

    public function show(Request $req, string $slug): Response
    {
        $loc = $this->locations->bySlug($slug);
        if ($loc === null) {
            throw HttpException::notFound();
        }
        return Response::html($this->view->render('public/location.twig', [
            'location' => $loc,
            'others' => array_values(array_filter($this->locations->active(), fn($l) => $l['id'] !== $loc['id'])),
        ]));
    }
}
