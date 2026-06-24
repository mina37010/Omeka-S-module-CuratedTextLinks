<?php
namespace CuratedTextLinks\Site\BlockLayout;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\Form\Form;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;

class Network extends AbstractBlockLayout
{
    private AnnotationService $service;

    public function __construct(AnnotationService $service)
    {
        $this->service = $service;
    }

    public function getLabel()
    {
        return 'Curated Text Links: network'; // @translate
    }

    public function prepareRender(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/curated-text-links.css', 'CuratedTextLinks'));
        $view->headScript()->appendFile($view->assetUrl('js/curated-text-links.js', 'CuratedTextLinks'));
    }

    public function form(PhpRenderer $view, SiteRepresentation $site, ?SitePageRepresentation $page = null, ?SitePageBlockRepresentation $block = null)
    {
        $data = $this->blockData($block);
        $form = new Form();
        $form->add(['name' => 'o:block[__blockIndex__][o:data][heading]', 'type' => 'text', 'options' => ['label' => 'Heading']]);
        $form->add(['name' => 'o:block[__blockIndex__][o:data][types]', 'type' => 'text', 'options' => ['label' => 'Target types'], 'attributes' => ['placeholder' => 'person,event,work']]);
        $form->add(['name' => 'o:block[__blockIndex__][o:data][limit]', 'type' => 'number', 'options' => ['label' => 'Limit (0 for all)']]);
        $form->setData([
            'o:block[__blockIndex__][o:data][heading]' => $data['heading'],
            'o:block[__blockIndex__][o:data][types]' => $data['types'],
            'o:block[__blockIndex__][o:data][limit]' => $data['limit'],
        ]);
        return $view->formCollection($form);
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData() ?: [];
        $data['heading'] = trim((string) ($data['heading'] ?? ''));
        $data['types'] = trim((string) ($data['types'] ?? 'person,event,work,organization,place,concept'));
        $limit = (int) ($data['limit'] ?? 0);
        $data['limit'] = $limit > 0 ? min(5000, $limit) : 0;
        $block->setData($data);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $this->blockData($block);
        $data['site_slug'] = $block->page()->site()->slug();
        return $view->partial('curated-text-links/site/block/network', [
            'heading' => $data['heading'],
            'networkUrl' => $view->url('site/curated-text-links', ['action' => 'network'], [
                'query' => [
                    'types' => $data['types'],
                    'limit' => 0,
                ],
            ], true),
        ]);
    }

    private function blockData(?SitePageBlockRepresentation $block): array
    {
        $data = $block ? $block->data() : [];
        return [
            'heading' => (string) ($data['heading'] ?? '人物・事件・作品のネットワーク'),
            'types' => (string) ($data['types'] ?? 'person,event,work,organization,place,concept'),
            'limit' => (int) ($data['limit'] ?? 0),
        ];
    }
}
