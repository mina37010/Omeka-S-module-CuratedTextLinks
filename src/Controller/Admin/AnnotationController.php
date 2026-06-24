<?php
namespace CuratedTextLinks\Controller\Admin;

use CuratedTextLinks\Service\AnnotationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class AnnotationController extends AbstractActionController
{
    public function browseAction(): ViewModel
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = max(1, min(100, (int) $this->params()->fromQuery('per_page', 25)));
        $pagination = $service->paginatedAnnotationApplicationGroups($page, $perPage);
        return new ViewModel([
            'annotations' => $pagination['items'],
            'totalCount' => $pagination['total'],
            'currentPage' => $pagination['page'],
            'perPage' => $pagination['per_page'],
        ]);
    }

    public function updateAction()
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        $action = (string) $this->params()->fromPost('batch_action', '');
        $ids = $action === 'approve_all'
            ? $service->candidateAnnotationIds()
            : $this->postedAnnotationIds();
        if (!$ids) {
            $this->messenger()->addWarning('No annotations selected.');
            return $this->redirect()->toRoute('admin/curated-text-links');
        }
        if ($action === 'approve' || $action === 'approve_all') {
            $service->approveMany($ids, $this->identityId());
            $this->messenger()->addSuccess($action === 'approve_all' ? 'All candidate annotations approved.' : 'Selected annotations approved.');
        } elseif ($action === 'reject') {
            $service->setStatusMany($ids, 'rejected', $this->identityId());
            $this->messenger()->addSuccess('Selected annotations rejected.');
        } elseif ($action === 'delete') {
            $service->deleteAnnotations($ids);
            $this->messenger()->addSuccess('Selected annotations deleted.');
        } else {
            $this->messenger()->addError('Invalid annotation action.');
        }
        return $this->redirect()->toRoute('admin/curated-text-links');
    }

    public function approveAction()
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        $ids = $this->annotationIds();
        $service->approveMany($ids, $this->identityId());
        $this->messenger()->addSuccess('Annotation approved.');
        return $this->redirect()->toRoute('admin/curated-text-links');
    }

    public function rejectAction()
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        $ids = $this->annotationIds();
        $service->setStatusMany($ids, 'rejected', $this->identityId());
        $this->messenger()->addSuccess('Annotation rejected.');
        return $this->redirect()->toRoute('admin/curated-text-links');
    }

    public function deleteAction()
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        $ids = $this->annotationIds();
        $service->deleteAnnotations($ids);
        $this->messenger()->addSuccess('Annotation deleted.');
        return $this->redirect()->toRoute('admin/curated-text-links');
    }

    private function annotationIds(): array
    {
        $ids = (string) $this->params()->fromQuery('ids', '');
        if ($ids !== '') {
            return array_filter(array_map('intval', explode(',', $ids)));
        }
        return [(int) $this->params()->fromRoute('id')];
    }

    private function postedAnnotationIds(): array
    {
        $ids = [];
        foreach ((array) $this->params()->fromPost('selected_ids', []) as $value) {
            foreach (explode(',', (string) $value) as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
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
}
