<?php
namespace CuratedTextLinks\Site\ResourcePageBlockLayout;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

class ItemNetwork implements ResourcePageBlockLayoutInterface
{
    private AnnotationService $service;

    public function __construct(AnnotationService $service)
    {
        $this->service = $service;
    }

    public function getLabel(): string
    {
        return 'Curated Text Links: item network'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/curated-text-links.css', 'CuratedTextLinks'));
        $view->headScript()->appendFile($view->assetUrl('js/curated-text-links.js', 'CuratedTextLinks'));
        $site = $view->currentSite();
        return $view->partial('curated-text-links/site/block/network', [
            'heading' => '人物・事件・作品のネットワーク',
            'networkUrl' => $view->url('site/curated-text-links', ['action' => 'network'], [
                'query' => [
                    'item_id' => (int) $resource->id(),
                    'types' => 'person,event,work,organization,place,concept',
                    'limit' => 0,
                ],
            ], true),
            'autoShow' => true,
        ]);
    }
}
