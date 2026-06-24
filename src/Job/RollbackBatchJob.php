<?php
namespace CuratedTextLinks\Job;

use Omeka\Job\AbstractJob;

class RollbackBatchJob extends AbstractJob
{
    public function perform(): void
    {
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $batchId = (int) $this->getArg('batch_id');
        $connection->update('curated_text_link_annotation', [
            'status' => 'deleted',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['batch_id' => $batchId]);
        $connection->update('curated_text_link_batch', [
            'status' => 'reverted',
            'reverted_at' => date('Y-m-d H:i:s'),
        ], ['id' => $batchId]);
    }
}

