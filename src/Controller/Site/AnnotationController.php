<?php
namespace CuratedTextLinks\Controller\Site;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;

class AnnotationController extends AbstractActionController
{
    public function createAction(): JsonModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        $identity = $this->identity();
        if (!$service->userCanAnnotate($identity)) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => 'Forbidden']);
        }
        $data = json_decode($this->getRequest()->getContent(), true) ?: $this->params()->fromPost();
        if (empty($data['exact_text']) || empty($data['item_id']) || empty($data['property_id'])) {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => 'Missing annotation data']);
        }
        try {
            if (!empty($data['targets']) && is_array($data['targets'])) {
                $ids = [];
                $localItemIds = [];
                $localHits = [];
                $localTargetData = null;
                $remoteTargets = [];
                foreach ($data['targets'] as $target) {
                    if (!is_array($target)) {
                        continue;
                    }
                    $targetData = array_merge($data, [
                        'target_type' => $target['target_type'] ?? 'concept',
                        'target_uri' => $target['target_uri'] ?? null,
                        'target_resource_id' => $target['target_resource_id'] ?? null,
                        'target_label' => $target['target_label'] ?? $target['label'] ?? $data['exact_text'],
                    ]);
                    $localMatch = is_array($target['local_match'] ?? null) ? $target['local_match'] : null;
                    $localItemId = !empty($target['local_item_id'])
                        ? (int) $target['local_item_id']
                        : (!empty($localMatch['item_id']) ? (int) $localMatch['item_id'] : null);
                    if ($localItemId) {
                        $targetData['target_uri'] = null;
                        $targetData['target_resource_id'] = null;
                        $targetData['target_label'] = $data['link_label'] ?? $data['exact_text'];
                    }
                    if (!$localItemId && empty($targetData['target_uri']) && empty($targetData['target_resource_id'])) {
                        continue;
                    }
                    unset($targetData['targets'], $targetData['event'], $targetData['create_event_item']);
                    if ($localItemId) {
                        $localItemIds[$localItemId] = true;
                        $localTargetData ??= $targetData;
                        if ($localMatch) {
                            $localHits[$this->spanKey($localMatch)] = $localMatch;
                        }
                    } else {
                        $remoteTargets[] = $targetData;
                    }
                }

                $connection = $services->get('Omeka\Connection');
                $connection->beginTransaction();
                try {
                    $batchId = ($remoteTargets || $localTargetData)
                        ? $service->createSiteApprovalBatch($data, $this->identityId())
                        : null;
                    foreach ($remoteTargets as $targetData) {
                        $targetData['batch_id'] = $batchId;
                        $ids[] = $service->createAnnotation($targetData, $this->identityId(), 'candidate');
                    }
                    foreach ($localHits as $hit) {
                        foreach ($remoteTargets as $targetData) {
                            if ($this->sameSpan($data, $hit)) {
                                continue;
                            }
                            $targetData['batch_id'] = $batchId;
                            $ids[] = $service->createAnnotation(array_merge($targetData, $hit), $this->identityId(), 'candidate');
                        }
                    }
                    if ($localItemIds && $localTargetData) {
                        $searchText = trim((string) ($data['search_query'] ?? '')) ?: (string) $data['exact_text'];
                        $localTargetData['batch_id'] = $batchId;
                        if (!$remoteTargets) {
                            $ids[] = $service->createAnnotation($localTargetData, $this->identityId(), 'approved');
                        }
                        foreach (array_keys($localItemIds) as $localItemId) {
                            foreach ($service->previewItemTextMatches((int) $localItemId, $searchText, false, true) as $hit) {
                                if ($this->sameSpan($data, $hit)) {
                                    continue;
                                }
                                if (isset($localHits[$this->spanKey($hit)])) {
                                    continue;
                                }
                                if (!$remoteTargets) {
                                    $ids[] = $service->createAnnotation(array_merge($localTargetData, $hit), $this->identityId(), 'approved');
                                }
                            }
                        }
                    }
                    $connection->commit();
                } catch (\Throwable $e) {
                    $connection->rollBack();
                    throw $e;
                }
                if (!$ids) {
                    $this->getResponse()->setStatusCode(400);
                    return new JsonModel(['error' => 'No target data selected']);
                }
                return new JsonModel(['ids' => $ids, 'status' => $localItemIds ? 'approved' : 'candidate']);
            }
            if (!empty($data['create_event_item'])) {
                $eventItemId = $service->createEventItem($data['event'] ?? ['title' => $data['target_label'] ?: $data['exact_text']]);
                $data['target_resource_id'] = $eventItemId;
                $data['target_uri'] = null;
            }
            $id = $service->createAnnotation($data, $this->identityId(), 'candidate');
            return new JsonModel(['id' => $id, 'status' => 'candidate']);
        } catch (\Throwable $e) {
            $services->get('Omeka\Logger')->err($e);
            $this->getResponse()->setStatusCode(500);
            return new JsonModel(['error' => $e->getMessage()]);
        }
    }

    public function searchItemsAction(): JsonModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        if (!$service->userCanAnnotate($this->identity())) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => 'Forbidden']);
        }
        return new JsonModel(['items' => $service->itemSearch((string) $this->params()->fromQuery('q', ''))]);
    }

    public function candidatesAction(): JsonModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        if (!$service->userCanAnnotate($this->identity())) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => 'Forbidden']);
        }
        return new JsonModel([
            'candidates' => $service->relatedCandidates(
                (string) $this->params()->fromQuery('q', ''),
                (int) $this->params()->fromQuery('item_id', 0) ?: null
            ),
        ]);
    }

    public function authorityAction(): JsonModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        if (!$service->userCanAnnotate($this->identity())) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => 'Forbidden']);
        }
        $source = (string) $this->params()->fromQuery('source', '');
        $query = (string) $this->params()->fromQuery('q', '');
        if (!in_array($source, ['wikidata', 'ndl'], true) || trim($query) === '') {
            $this->getResponse()->setStatusCode(400);
            return new JsonModel(['error' => 'Invalid authority query']);
        }
        return new JsonModel([
            'candidates' => $service->authorityCandidates(
                $source,
                $query,
                (bool) $this->params()->fromQuery('refresh', false)
            ),
        ]);
    }

    public function networkAction(): JsonModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        $options = [
            'types' => (string) $this->params()->fromQuery('types', 'person,event,work,organization,place,concept'),
            'limit' => (int) $this->params()->fromQuery('limit', 0),
            'site_slug' => (string) $this->params()->fromRoute('site-slug', ''),
        ];
        $itemId = (int) $this->params()->fromQuery('item_id', 0);
        return new JsonModel($itemId > 0
            ? $service->itemNetworkData($itemId, $options)
            : $service->networkData($options));
    }

    private function identityId(): ?int
    {
        $identity = $this->identity();
        if (!$identity) {
            return null;
        }
        if (method_exists($identity, 'id')) {
            return (int) $identity->id();
        }
        if (method_exists($identity, 'getId')) {
            return (int) $identity->getId();
        }
        return null;
    }

    private function sameSpan(array $left, array $right): bool
    {
        return (int) ($left['item_id'] ?? 0) === (int) ($right['item_id'] ?? 0)
            && (int) ($left['property_id'] ?? 0) === (int) ($right['property_id'] ?? 0)
            && (string) ($left['value_id'] ?? '') === (string) ($right['value_id'] ?? '')
            && (int) ($left['start_offset'] ?? -1) === (int) ($right['start_offset'] ?? -2)
            && (int) ($left['end_offset'] ?? -1) === (int) ($right['end_offset'] ?? -2);
    }

    private function spanKey(array $span): string
    {
        return implode(':', [
            (int) ($span['item_id'] ?? 0),
            (int) ($span['property_id'] ?? 0),
            (string) ($span['value_id'] ?? ''),
            (int) ($span['start_offset'] ?? -1),
            (int) ($span['end_offset'] ?? -1),
        ]);
    }
}
