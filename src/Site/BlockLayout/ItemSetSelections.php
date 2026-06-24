<?php
namespace CuratedTextLinks\Site\BlockLayout;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;

class ItemSetSelections extends AbstractBlockLayout
{
    private AnnotationService $service;
    private string $label;
    private string $description;
    private string $kind;

    public function __construct(AnnotationService $service, string $label, string $description, string $kind)
    {
        $this->service = $service;
        $this->label = $label;
        $this->description = $description;
        $this->kind = $kind;
    }

    public function getLabel()
    {
        return 'Curated Text Links: ' . $this->label; // @translate
    }

    public function prepareRender(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/curated-text-links.css', 'CuratedTextLinks'));
    }

    public function form(PhpRenderer $view, SiteRepresentation $site, ?SitePageRepresentation $page = null, ?SitePageBlockRepresentation $block = null)
    {
        return '';
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $block->setData($block->getData() ?: []);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        return $view->partial('curated-text-links/site/block/item-set-selections', [
            'heading' => $this->label,
            'collections' => $this->service->itemSetSelections($this->description, 4, true),
            'siteSlug' => $block->page()->site()->slug(),
            'reloadLabel' => 'Reload',
            'moreUrl' => $view->url('site/curated-text-links', ['action' => 'collections'], [
                'query' => ['kind' => $this->kind],
            ], true),
        ]);
    }
}
