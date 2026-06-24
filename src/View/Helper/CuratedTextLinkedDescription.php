<?php
namespace CuratedTextLinks\View\Helper;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\View\Helper\AbstractHelper;

class CuratedTextLinkedDescription extends AbstractHelper
{
    private AnnotationService $service;

    public function __construct(AnnotationService $service)
    {
        $this->service = $service;
    }

    public function __invoke(string $text, int $itemId, int $propertyId, ?int $valueId = null): string
    {
        return $this->service->renderText($text, $itemId, $propertyId, $valueId, nl2br($this->getView()->escapeHtml($text)));
    }
}

