<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\View;
use Mea\Module\Content\ContentRepository;
use Mea\Module\Design\FontLibrary;
use Mea\Module\Exams\ExamRepository;
use Mea\Module\Inquiries\InquiryService;
use Mea\Module\Locations\LocationRepository;
use Twig\Markup;

/**
 * Wandelt einen Seitenaufbau in HTML um. Website, Vorschau und Editor verwenden genau diesen Weg und dieselben Templates
 * (templates/blocks/*.twig) und dieselben CSS-Regeln; der Editor ergänzt nur data-b-Attribute zum Auswählen.
 */
final class PageRenderer
{
    /** @var array<string,mixed> */
    private array $ctx = [];
    /** @var array<string,mixed> */
    private array $memo = [];
    /** Gesammelte Angaben für Kopfbereich und strukturierte Daten */
    private array $meta = [];
    private int $componentDepth = 0;
    private bool $emptySection = false;

    /** @param \Closure(int, bool):?array $component lädt einen globalen Baustein (id, Entwurf?) @param \Closure():array{tablet:int,mobile:int} $breakpoints */
    public function __construct(
        private View $view,
        private ContentRepository $content,
        private ExamRepository $exams,
        private LocationRepository $locations,
        private FontLibrary $fonts,
        private \Closure $component,
        private \Closure $breakpoints,
    ) {}

    /** Twig-Funktionen blocks() und battr(); App meldet sie beim Start an. @param list<array<string,mixed>>|null $nodes */
    public function children(?array $nodes): Markup
    {
        return new Markup($this->nodes($nodes ?? []), 'UTF-8');
    }

    /**
     * @param list<array<string,mixed>> $tree
     * @param array<string,mixed> $ctx editing (bool), draft (bool), page (array), query (array), form (array|null)
     * @return array{html:string, meta:array<string,mixed>}
     */
    public function render(array $tree, array $ctx = []): array
    {
        $prev = [$this->ctx, $this->meta];
        $this->ctx = $ctx + ['editing' => false, 'draft' => false, 'page' => [], 'query' => [], 'form' => null];
        $this->meta = ['faqs' => [], 'exam_list' => null, 'preload' => null, 'og' => null, 'components' => []];
        try {
            $html = $this->nodes($tree);
            return ['html' => $html, 'meta' => $this->meta];
        } finally {
            [$this->ctx, $this->meta] = $prev;
        }
    }

    /** @param list<array<string,mixed>> $nodes */
    private function nodes(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $b) {
            $html .= $this->node($b);
        }
        return $html;
    }

    /** @param array<string,mixed> $b */
    private function node(array $b): string
    {
        $def = BlockTypes::get((string) ($b['type'] ?? ''));
        if ($def === null || (!empty($b['off']) && !$this->ctx['editing'])) {
            return '';
        }
        $f = ($b['f'] ?? []) + BlockTypes::defaults($b['type']);
        if ($b['type'] === 'component') {
            return $this->component($b, $f);
        }
        $this->collect($b['type'], $f);
        if ($b['type'] === 'section') {
            // Abschnitte mit „ausblenden, wenn leer“ (z. B. keine freien Termine) erscheinen dann gar nicht
            $this->emptySection = false;
            $html = $this->view->render('blocks/section.twig', ['b' => $b, 'f' => $f, 'ctx' => $this->ctx, 'd' => []]);
            $hide = $this->emptySection && !$this->ctx['editing'];
            $this->emptySection = false;
            return $hide ? '' : $html;
        }
        return $this->view->render('blocks/' . $b['type'] . '.twig', [
            'b' => $b, 'f' => $f, 'ctx' => $this->ctx, 'd' => $this->data($b['type'], $f),
        ]);
    }

    /** @param array<string,mixed> $b @param array<string,mixed> $f */
    private function component(array $b, array $f): string
    {
        $c = $f['ref'] ? ($this->component)((int) $f['ref'], (bool) $this->ctx['draft']) : null;
        if ($c === null || $this->componentDepth > 2) {
            return $this->ctx['editing'] ? '<div' . $this->attr($b, 'b-missing') . '>Globaler Baustein nicht gefunden</div>' : '';
        }
        $this->meta['components'][(int) $f['ref']] = true;
        $this->componentDepth++;
        try {
            $inner = $this->nodes($c['tree']);
        } finally {
            $this->componentDepth--;
        }
        return '<div' . $this->attr($b, 'b-contents', ['data-global' => $c['name']]) . '>' . $inner . '</div>';
    }

    /** Im Editor: Feld direkt im Arbeitsbereich bearbeitbar machen. */
    public function editAttr(string $field, string $kind = 'text'): Markup
    {
        if (!($this->ctx['editing'] ?? false) || !preg_match('/^[a-z0-9_.]{1,40}$/', $field)) {
            return new Markup('', 'UTF-8');
        }
        return new Markup(' data-f="' . $field . '" data-fk="' . ($kind === 'rich' ? 'rich' : ($kind === 'lines' ? 'lines' : 'text')) . '"', 'UTF-8');
    }

    /** class-, id- und (im Editor) data-b-Attribute für das äußerste Element eines Bausteins. @param array<string,mixed> $b @param array<string,string> $attr */
    public function attr(array $b, string $class = '', array $attr = []): Markup
    {
        $id = (string) ($b['id'] ?? '');
        $classes = trim($class . ' b-' . $id . (!empty($b['off']) ? ' b-off' : ''));
        $out = ' class="' . htmlspecialchars($classes, ENT_QUOTES) . '"';
        if ($this->ctx['editing'] ?? false) {
            $attr['data-b'] = $id;
            $attr['data-t'] = (string) $b['type'];
        }
        foreach ($attr as $k => $v) {
            if ($v === null || $v === false || !preg_match('/^[a-z][a-z0-9-]*$/', (string) $k)) {
                continue;
            }
            $out .= ' ' . $k . ($v === true ? '' : '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"');
        }
        return new Markup($out, 'UTF-8');
    }

    /** Merkt sich, was Kopfbereich und strukturierte Daten brauchen. @param array<string,mixed> $f */
    private function collect(string $type, array $f): void
    {
        if (in_array($type, ['hero_home', 'page_hero', 'image', 'image_text'], true) && $this->meta['og'] === null && !empty($f['media'])) {
            $this->meta['og'] = (int) $f['media'];
            if ($type === 'hero_home') {
                $this->meta['preload'] = (int) $f['media'];
            }
        }
        if ($type === 'accordion' && $f['schema']) {
            foreach ($f['items'] as $i) {
                $this->meta['faqs'][] = ['question' => $i['title'], 'answer_html' => $i['html']];
            }
        }
    }

    /** Daten aus der Verwaltung für dynamische Bausteine. @param array<string,mixed> $f @return array<string,mixed> */
    private function data(string $type, array $f): array
    {
        switch ($type) {
            case 'services_grid':
                $s = $this->memo['services'] ??= $this->content->topServices();
                return ['services' => $f['limit'] > 0 ? array_slice($s, 0, $f['limit']) : $s];
            case 'exam_cards':
                return ['exams' => array_slice($this->memo['exams'] ??= $this->exams->listed(), 0, $f['limit'])];
            case 'price_table':
                return ['exams' => $this->memo['exams'] ??= $this->exams->listed()];
            case 'exam_list':
                $q = $this->ctx['query'];
                $filter = ['provider' => self::q($q, 'anbieter'), 'level' => self::q($q, 'niveau'), 'category' => self::q($q, 'kategorie')];
                $exams = $this->exams->listed($filter);
                $this->meta['exam_list'] = $exams;
                $filtered = $filter['provider'] !== '' || $filter['level'] !== '' || $filter['category'] !== '';
                $this->meta['noindex'] = $filtered;
                return ['exams' => $exams, 'filter' => $filter, 'options' => $this->exams->filterOptions(), 'categories' => $this->exams->categories(), 'is_filtered' => $filtered];
            case 'next_sessions':
                $sessions = $this->exams->nextBookableSessions($f['limit']);
                $this->emptySection = $this->emptySection || ($sessions === [] && $f['hide_empty']);
                return ['sessions' => $sessions];
            case 'testimonials':
                if ($f['source'] === 'manual') {
                    return ['reviews' => array_map(fn($i) => ['body' => $i['quote'], 'display_name' => $i['name'], 'role_label' => $i['role'], 'rating' => $i['rating'] !== '' ? (int) $i['rating'] : null], $f['items'])];
                }
                $reviews = array_slice($this->memo['reviews'] ??= $this->content->reviews(), 0, $f['limit']);
                $this->emptySection = $this->emptySection || ($reviews === [] && $f['hide_empty']);
                return ['reviews' => $reviews];
            case 'faq':
                $faqs = $f['source'] === 'all' ? $this->content->allFaqs() : $this->content->faqsFor($f['source']);
                if ($f['limit'] > 0) {
                    $faqs = array_slice($faqs, 0, $f['limit']);
                }
                $this->emptySection = $this->emptySection || ($faqs === [] && $f['hide_empty']);
                if ($f['schema']) {
                    array_push($this->meta['faqs'], ...$faqs);
                }
                $groups = [];
                if ($f['grouped']) {
                    foreach ($faqs as $q) {
                        $groups[$q['category'] ?: 'Allgemein'][] = $q;
                    }
                }
                return ['faqs' => $faqs, 'groups' => $groups];
            case 'locations':
            case 'contact_info':
                return ['locations' => $this->memo['locations'] ??= $this->locations->active()];
            case 'contact_form':
                $form = $this->ctx['form'] ?? null;
                return ($form ?? ['values' => [], 'errors' => [], 'form_error' => null, 'sent' => false, 'csrf' => '', 'form_started' => 0, 'preview' => true])
                    + ['locations' => $this->memo['locations'] ??= $this->locations->active(), 'topics' => InquiryService::TOPICS];
        }
        return [];
    }

    /** @param array<string,mixed> $q */
    private static function q(array $q, string $k): string
    {
        return is_string($q[$k] ?? null) ? mb_substr($q[$k], 0, 60) : '';
    }

    /** CSS aller Elementstile eines Baums. @param list<array<string,mixed>> $tree */
    public function css(array $tree): string
    {
        $bp = ($this->breakpoints)();
        $css = '';
        $walk = function (array $nodes) use (&$walk, &$css, $bp): void {
            foreach ($nodes as $n) {
                if (($n['type'] ?? '') === 'spacer') {
                    $css .= '.b-' . $n['id'] . ' { height: ' . max(0, min(400, (int) ($n['f']['height'] ?? 48))) . "px; }\n";
                }
                if (!empty($n['s'])) {
                    $css .= BlockStyle::css((string) $n['id'], $n['s'], $bp, $this->fonts);
                }
                if (!empty($n['c'])) {
                    $walk($n['c']);
                }
            }
        };
        $walk($tree);
        return $css;
    }
}
