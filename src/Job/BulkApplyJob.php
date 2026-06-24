<?php
namespace CuratedTextLinks\Job;

use CuratedTextLinks\Service\AnnotationService;
use Omeka\Job\AbstractJob;

class BulkApplyJob extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $service = $services->get(AnnotationService::class);
        $batchId = (int) $this->getArg('batch_id');
        $batch = $service->batch($batchId);
        if (!$batch) {
            return;
        }
        $summary = json_decode((string) $batch['summary_json'], true) ?: [];
        $preview = $summary['preview'] ?? [];
        $created = 0;
        foreach ($preview as $hit) {
            $hit += [
                'target_type' => $batch['target_type'],
                'target_uri' => $batch['target_uri'],
                'target_label' => $batch['target_label'],
                'target_resource_id' => $batch['target_resource_id'],
                'target_property_term' => $batch['target_property_term'],
                'batch_id' => $batchId,
            ];
            $service->createAnnotation($hit, (int) $batch['created_by'], 'approved');
            $created++;
        }
        $summary['executed'] = ['created' => $created, 'executed_at' => date('c')];
        $connection->update('curated_text_link_batch', [
            'status' => 'executed',
            'executed_at' => date('Y-m-d H:i:s'),
            'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE),
        ], ['id' => $batchId]);
    }
}

