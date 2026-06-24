<?php
namespace CuratedTextLinks\Controller\Admin;

use CuratedTextLinks\Form\BulkApplyForm;
use CuratedTextLinks\Job\BulkApplyJob;
use CuratedTextLinks\Service\AnnotationService;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class BulkController extends AbstractActionController
{
    public function indexAction(): ViewModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $service = $services->get(AnnotationService::class);
        $form = $services->get('FormElementManager')->get(BulkApplyForm::class);
        $targetOptions = $service->registeredTargetOptions();
        $form->get('target_key')->setValueOptions($targetOptions);
        $propertyOptions = [];
        foreach (['dcterms:title', 'dcterms:description', 'schema:name'] as $term) {
            $propertyId = $service->propertyId($term);
            if ($propertyId) {
                $propertyOptions[$propertyId] = $term;
            }
        }
        $form->get('property_id')->setValueOptions($propertyOptions);
        $form->setData([
            'target_property_term' => $services->get('Omeka\Settings')->get('curated_text_links.target_property_term', 'schema:about'),
            'property_id' => $service->propertyId('dcterms:description'),
            'exclude_overlaps' => 1,
        ]);

        $preview = [];
        $bulkData = null;
        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            if (isset($post['apply_bulk'])) {
                $bulkData = json_decode((string) ($post['bulk_data'] ?? ''), true) ?: null;
                $data = is_array($bulkData['data'] ?? null) ? $bulkData['data'] : [];
                $preview = is_array($bulkData['preview'] ?? null) ? $bulkData['preview'] : [];
                $approved = array_flip(array_map('intval', (array) ($post['approved_hits'] ?? [])));
                $preview = array_values(array_filter($preview, fn($hit, $index) => isset($approved[$index]), ARRAY_FILTER_USE_BOTH));
                if (!$preview) {
                    $this->messenger()->addWarning('No candidate items were allowed.');
                } elseif ($data) {
                    $batchId = $service->createBatch($data, $preview, $this->identityId());
                    $dispatcher = $services->get('Omeka\Job\Dispatcher');
                    $job = $dispatcher->dispatch(BulkApplyJob::class, ['batch_id' => $batchId]);
                    $this->messenger()->addSuccess('Bulk apply job queued.');
                    return $this->redirect()->toUrl($this->url()->fromRoute('admin/id', [
                        'controller' => 'job',
                        'action' => 'show',
                        'id' => $job->getId(),
                    ]));
                }
            } else {
                $form->setData($post);
                if ($form->isValid()) {
                    $data = $service->applyRegisteredTarget($form->getData());
                    if (empty($data['target_key'])) {
                        $this->messenger()->addError('Registered target is required.');
                    } elseif (trim((string) ($data['target_text'] ?? '')) === '') {
                        $this->messenger()->addError('Link text is required when the selected target has no label.');
                    } elseif (isset($post['preview'])) {
                        $preview = $service->previewSearchTerms($data);
                        if (!$preview) {
                            $this->messenger()->addWarning('No candidate items were found.');
                        }
                        $bulkData = ['data' => $data, 'preview' => $preview];
                    }
                } else {
                    $this->messenger()->addFormErrors($form);
                }
            }
        }
        return new ViewModel([
            'form' => $form,
            'preview' => $preview,
            'bulkData' => $bulkData,
            'targetOptions' => $targetOptions,
        ]);
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
