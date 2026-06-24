<?php
namespace CuratedTextLinks\Controller\Admin;

use CuratedTextLinks\Job\RollbackBatchJob;
use CuratedTextLinks\Service\AnnotationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class BatchController extends AbstractActionController
{
    public function browseAction(): ViewModel
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        return new ViewModel(['batches' => $service->batches()]);
    }

    public function showAction(): ViewModel
    {
        $service = $this->getEvent()->getApplication()->getServiceManager()->get(AnnotationService::class);
        return new ViewModel(['batch' => $service->batch((int) $this->params()->fromRoute('id'))]);
    }

    public function rollbackAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $job = $services->get('Omeka\Job\Dispatcher')->dispatch(RollbackBatchJob::class, ['batch_id' => (int) $this->params()->fromRoute('id')]);
        $this->messenger()->addSuccess('Rollback job queued.');
        return $this->redirect()->toUrl($this->url()->fromRoute('admin/id', [
            'controller' => 'job',
            'action' => 'show',
            'id' => $job->getId(),
        ]));
    }
}

