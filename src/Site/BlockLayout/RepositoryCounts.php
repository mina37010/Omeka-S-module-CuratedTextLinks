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

class RepositoryCounts extends AbstractBlockLayout
{
    private AnnotationService $service;

    public function __construct(AnnotationService $service)
    {
        $this->service = $service;
    }

    public function getLabel()
    {
        return 'Curated Text Links: 所蔵件数一覧'; // @translate
    }

    public function prepareRender(PhpRenderer $view)
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/curated-text-links.css', 'CuratedTextLinks'));
    }

    public function form(PhpRenderer $view, SiteRepresentation $site, ?SitePageRepresentation $page = null, ?SitePageBlockRepresentation $block = null)
    {
        $data = $this->blockData($block);
        $form = new Form();
        foreach ([
            'show_collections' => 'コレクション',
            'show_items' => 'アイテム',
            'show_images' => '画像',
        ] as $name => $label) {
            $form->add([
                'name' => 'o:block[__blockIndex__][o:data][' . $name . ']',
                'type' => 'checkbox',
                'options' => ['label' => $label],
            ]);
        }
        $form->setData([
            'o:block[__blockIndex__][o:data][show_collections]' => $data['show_collections'],
            'o:block[__blockIndex__][o:data][show_items]' => $data['show_items'],
            'o:block[__blockIndex__][o:data][show_images]' => $data['show_images'],
        ]);
        return $view->formCollection($form);
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData() ?: [];
        $block->setData([
            'show_collections' => !empty($data['show_collections']),
            'show_items' => !empty($data['show_items']),
            'show_images' => !empty($data['show_images']),
        ]);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $this->blockData($block);
        return $view->partial('curated-text-links/site/block/repository-counts', [
            'counts' => $this->service->repositoryCounts(),
            'display' => $data,
        ]);
    }

    private function blockData(?SitePageBlockRepresentation $block): array
    {
        $data = $block ? $block->data() : [];
        return [
            'show_collections' => array_key_exists('show_collections', $data) ? (bool) $data['show_collections'] : true,
            'show_items' => array_key_exists('show_items', $data) ? (bool) $data['show_items'] : true,
            'show_images' => array_key_exists('show_images', $data) ? (bool) $data['show_images'] : true,
        ];
    }
}
