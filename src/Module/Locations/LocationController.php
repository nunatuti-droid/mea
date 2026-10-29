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
    ) {}

    public function index(Request $req): Response
    {
        return Response::html($this->view->render('public/locations.twig', [
            'page' => $this->content->publishedPage('standorte'),
            'locations' => $this->locations->active(),
        ]));
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
