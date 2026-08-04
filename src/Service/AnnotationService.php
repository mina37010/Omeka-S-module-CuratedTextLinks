<?php
namespace CuratedTextLinks\Service;

use Doctrine\DBAL\Connection;
use Omeka\Api\Representation\ValueRepresentation;
use Psr\Container\ContainerInterface;

class AnnotationService
{
    private ContainerInterface $services;
    private Connection $connection;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
        $this->connection = $services->get('Omeka\Connection');
    }

    public function userCanAnnotate($user): bool
    {
        if (!$user) {
            return false;
        }
        $role = method_exists($user, 'role') ? $user->role() : (method_exists($user, 'getRole') ? $user->getRole() : '');
        $roles = (array) $this->services->get('Omeka\Settings')->get('curated_text_links.allowed_roles', []);
        return in_array($role, $roles, true) || in_array($role, ['global_admin', 'site_admin', 'editor'], true);
    }

    public static function valueId(ValueRepresentation $value): ?int
    {
        try {
            $ref = new \ReflectionClass($value);
            $property = $ref->getProperty('value');
            $property->setAccessible(true);
            $entity = $property->getValue($value);
            return $entity && method_exists($entity, 'getId') ? (int) $entity->getId() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function normalize(string $text, bool $aggressive = false): string
    {
        $text = trim($text);
        if (class_exists('\Normalizer')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }
        $text = mb_strtolower($text, 'UTF-8');
        if ($aggressive) {
            $text = str_replace(['・', '･', ' ', '　', '-', '‐', '‑', '–', '—'], '', $text);
        }
        return mb_substr($text, 0, 255, 'UTF-8');
    }

    public function renderText(string $plainText, int $itemId, int $propertyId, ?int $valueId, string $fallbackHtml): string
    {
        $wrap = function (string $html) use ($itemId, $propertyId, $valueId): string {
            return '<span class="curated-text-description" data-ctl-item-id="' . $itemId . '" data-ctl-property-id="' . $propertyId . '"' .
                ($valueId ? ' data-ctl-value-id="' . $valueId . '"' : '') . '>' . $html . '</span>';
        };
        if ($plainText === '') {
            return $wrap($fallbackHtml);
        }
        $qb = $this->connection->createQueryBuilder();
        $qb->select('*')
            ->from('curated_text_link_annotation')
            ->where('item_id = :item_id')
            ->andWhere('property_id = :property_id')
            ->andWhere('status = :status')
            ->setParameters(['item_id' => $itemId, 'property_id' => $propertyId, 'status' => 'approved'])
            ->orderBy('start_offset', 'ASC');
        if ($valueId) {
            $qb->andWhere('(value_id = :value_id OR value_id IS NULL)')
                ->setParameter('value_id', $valueId);
        }
        $statement = $qb->execute();
        $rows = method_exists($statement, 'fetchAllAssociative')
            ? $statement->fetchAllAssociative()
            : $statement->fetchAll();
        if (!$rows) {
            return $wrap($fallbackHtml);
        }
        $groups = $this->selectNonOverlappingGroups($rows);
        if (!$groups) {
            return $wrap($fallbackHtml);
        }

        $out = '';
        $cursor = 0;
        foreach ($groups as $group) {
            $start = (int) $group['start_offset'];
            $end = (int) $group['end_offset'];
            if ($start < $cursor || $end <= $start || $start < 0 || $end > mb_strlen($plainText, 'UTF-8')) {
                continue;
            }
            $out .= htmlspecialchars(mb_substr($plainText, $cursor, $start - $cursor, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $label = mb_substr($plainText, $start, $end - $start, 'UTF-8');
            $links = $this->targetLinks($group['rows']);
            if (count($links) === 1) {
                $link = $links[0];
                $out .= sprintf(
                    '<a class="curated-text-link curated-text-link-%s" href="%s">%s</a>',
                    htmlspecialchars((string) $link['target_type'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $link['href'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            } elseif (count($links) > 1) {
                $out .= '<span class="curated-text-link-choice" tabindex="0">';
                $out .= '<span class="curated-text-link-choice-label">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                $out .= '<span class="curated-text-link-choice-menu">';
                foreach ($links as $link) {
                    $out .= sprintf(
                        '<a class="curated-text-link-choice-option curated-text-link-%s" href="%s"><span class="curated-text-link-choice-option-label">%s</span><span class="curated-text-link-choice-option-meta">%s</span></a>',
                        htmlspecialchars((string) $link['target_type'], ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string) $link['href'], ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string) $link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars((string) $link['meta'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    );
                }
                $out .= '</span></span>';
            } else {
                $row = $group['rows'][0] ?? [];
                $out .= sprintf(
                    '<span class="curated-text-link curated-text-link-%s curated-text-link-local">%s</span>',
                    htmlspecialchars((string) (($row['link_type'] ?? '') ?: ($row['target_type'] ?? 'concept')), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
            $cursor = $end;
        }
        $out .= htmlspecialchars(mb_substr($plainText, $cursor, null, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $wrap(nl2br($out));
    }

    public function createAnnotation(array $data, ?int $userId, string $status = 'candidate'): int
    {
        $now = date('Y-m-d H:i:s');
        $targetUri = trim((string) ($data['target_uri'] ?? ''));
        $targetLabel = trim((string) ($data['target_label'] ?? ''));
        $targetResourceId = !empty($data['target_resource_id']) ? (int) $data['target_resource_id'] : null;
        $targetPropertyTerm = (string) (($data['target_property_term'] ?? '') ?: 'schema:about');
        $linkLabel = trim((string) ($data['link_label'] ?? '')) ?: (string) $data['exact_text'];
        $linkType = trim((string) ($data['link_type'] ?? '')) ?: (string) ($data['target_type'] ?? 'concept');
        $this->connection->insert('curated_text_link_annotation', [
            'item_id' => (int) $data['item_id'],
            'property_id' => (int) $data['property_id'],
            'value_id' => !empty($data['value_id']) ? (int) $data['value_id'] : null,
            'exact_text' => (string) $data['exact_text'],
            'normalized_text' => $this->normalize((string) $data['exact_text'], true),
            'prefix_text' => (string) ($data['prefix_text'] ?? ''),
            'suffix_text' => (string) ($data['suffix_text'] ?? ''),
            'start_offset' => (int) $data['start_offset'],
            'end_offset' => (int) $data['end_offset'],
            'link_label' => $linkLabel,
            'link_type' => $linkType,
            'target_type' => (string) ($data['target_type'] ?? 'concept'),
            'target_uri' => $targetUri ?: null,
            'target_label' => $targetLabel ?: (string) $data['exact_text'],
            'target_resource_id' => $targetResourceId,
            'target_property_term' => $targetPropertyTerm,
            'status' => $status,
            'confidence' => $data['confidence'] ?? null,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'batch_id' => $data['batch_id'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
        $id = (int) $this->connection->lastInsertId();
        if (in_array($status, ['candidate', 'approved'], true)) {
            $data['target_uri'] = $targetUri;
            $data['target_label'] = $targetLabel ?: (string) $data['exact_text'];
            $data['target_resource_id'] = $targetResourceId;
            $this->writeBackMetadata((int) $data['item_id'], $targetPropertyTerm, $data);
        }
        return $id;
    }

    public function approve(int $id, ?int $userId): void
    {
        $this->connection->update('curated_text_link_annotation', [
            'status' => 'approved',
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        $row = $this->annotation($id);
        if ($row) {
            $this->writeBackMetadata((int) $row['item_id'], (string) $row['target_property_term'], $row);
        }
    }

    public function setStatus(int $id, string $status, ?int $userId): void
    {
        $this->connection->update('curated_text_link_annotation', [
            'status' => $status,
            'reviewed_by' => $userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function deleteAnnotation(int $id): void
    {
        $row = $this->annotation($id);
        if (!$row) {
            return;
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->delete('curated_text_link_annotation', ['id' => $id]);
            $this->removeMetadataIfUnused($row);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function annotation(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM curated_text_link_annotation WHERE id = ?', [$id]);
        return $row ?: null;
    }

    public function annotations(array $query = []): array
    {
        $sql = 'SELECT a.*, r.title AS item_title, p.label AS property_label, u.name AS created_by_name
            FROM curated_text_link_annotation a
            LEFT JOIN resource r ON r.id = a.item_id
            LEFT JOIN property p ON p.id = a.property_id
            LEFT JOIN user u ON u.id = a.created_by
            ORDER BY a.created_at DESC';
        return $this->connection->fetchAllAssociative($sql);
    }

    public function annotationGroups(array $query = []): array
    {
        $groups = [];
        foreach ($this->annotations($query) as $row) {
            $key = implode(':', [
                $row['item_id'],
                $row['property_id'],
                $row['value_id'] ?: '',
                $row['start_offset'],
                $row['end_offset'],
                $this->linkLabel($row),
                $this->linkType($row),
                $row['status'],
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = $row + [
                    'ids' => [],
                    'targets' => [],
                ];
            }
            $groups[$key]['ids'][] = (int) $row['id'];
            $groups[$key]['targets'][] = [
                'id' => (int) $row['id'],
                'target_type' => (string) $row['target_type'],
                'target_label' => (string) ($row['target_label'] ?: $row['target_uri'] ?: $row['target_resource_id']),
                'target_uri' => $row['target_uri'],
                'target_resource_id' => $row['target_resource_id'],
            ];
            if (strtotime((string) $row['created_at']) < strtotime((string) $groups[$key]['created_at'])) {
                $groups[$key]['created_at'] = $row['created_at'];
            }
        }
        return array_values($groups);
    }

    public function annotationApplicationGroups(array $query = []): array
    {
        return $this->groupAnnotationApplicationRows($this->annotations($query));
    }

    public function annotationApplicationGroupByIds(array $ids): ?array
    {
        $ids = $this->cleanIds($ids);
        if (!$ids) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.*, r.title AS item_title, p.label AS property_label, u.name AS created_by_name
             FROM curated_text_link_annotation a
             LEFT JOIN resource r ON r.id = a.item_id
             LEFT JOIN property p ON p.id = a.property_id
             LEFT JOIN user u ON u.id = a.created_by
             WHERE a.id IN (' . $placeholders . ')
             ORDER BY a.created_at DESC',
            $ids
        );
        $groups = $this->groupAnnotationApplicationRows($rows);
        return $groups[0] ?? null;
    }

    private function groupAnnotationApplicationRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = !empty($row['batch_id'])
                ? ('batch:' . (int) $row['batch_id'] . ':' . $row['status'])
                : implode(':', [
                    $row['normalized_text'],
                    $this->linkLabel($row),
                    $this->linkType($row),
                    $row['target_type'],
                    $row['target_uri'] ?: '',
                    $row['target_resource_id'] ?: '',
                    $row['target_label'] ?: '',
                    $row['status'],
                ]);
            if (!isset($groups[$key])) {
                $groups[$key] = $row + [
                    'ids' => [],
                    'items' => [],
                    'spans' => [],
                    'span_keys' => [],
                    'targets' => [],
                ];
            }
            $groups[$key]['ids'][] = (int) $row['id'];
            $targetKey = implode(':', [
                $row['target_type'],
                $row['target_uri'] ?: '',
                $row['target_resource_id'] ?: '',
                $row['target_label'] ?: '',
            ]);
            $groups[$key]['targets'][$targetKey] = [
                'target_type' => (string) ($row['target_type'] ?: 'concept'),
                'target_label' => (string) ($row['target_label'] ?: $row['target_uri'] ?: $row['target_resource_id']),
                'target_uri' => $row['target_uri'],
                'target_resource_id' => $row['target_resource_id'] ? (int) $row['target_resource_id'] : null,
            ];
            $itemKey = (string) (int) $row['item_id'];
            if (!isset($groups[$key]['items'][$itemKey])) {
                $groups[$key]['items'][$itemKey] = [
                    'item_id' => (int) $row['item_id'],
                    'item_title' => (string) ($row['item_title'] ?: ('#' . $row['item_id'])),
                    'spans' => [],
                ];
            }
            $span = [
                'id' => (int) $row['id'],
                'property_label' => (string) ($row['property_label'] ?: ('Property #' . $row['property_id'])),
                'value_id' => $row['value_id'] ? (int) $row['value_id'] : null,
                'start_offset' => (int) $row['start_offset'],
                'end_offset' => (int) $row['end_offset'],
                'exact_text' => (string) $row['exact_text'],
            ];
            $spanKey = implode(':', [
                (int) $row['item_id'],
                (int) $row['property_id'],
                $row['value_id'] ?: '',
                (int) $row['start_offset'],
                (int) $row['end_offset'],
            ]);
            if (!isset($groups[$key]['span_keys'][$spanKey])) {
                $groups[$key]['span_keys'][$spanKey] = true;
                $groups[$key]['items'][$itemKey]['spans'][] = $span;
                $groups[$key]['spans'][] = $span + ['item_id' => (int) $row['item_id']];
            }
            if (strtotime((string) $row['created_at']) < strtotime((string) $groups[$key]['created_at'])) {
                $groups[$key]['created_at'] = $row['created_at'];
            }
        }
        foreach ($groups as &$group) {
            $group['items'] = array_values($group['items']);
            $group['targets'] = array_values($group['targets']);
            unset($group['span_keys']);
        }
        unset($group);
        return array_values($groups);
    }

    public function paginatedAnnotationApplicationGroups(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $groups = $this->annotationApplicationGroups();
        $total = count($groups);
        $offset = ($page - 1) * $perPage;
        return [
            'items' => array_slice($groups, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function approveMany(array $ids, ?int $userId): void
    {
        foreach ($this->cleanIds($ids) as $id) {
            $this->approve($id, $userId);
        }
    }

    public function setStatusMany(array $ids, string $status, ?int $userId): void
    {
        foreach ($this->cleanIds($ids) as $id) {
            $this->setStatus($id, $status, $userId);
        }
    }

    public function candidateAnnotationIds(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM curated_text_link_annotation WHERE status = ? ORDER BY created_at DESC',
            ['candidate']
        ));
    }

    public function deleteAnnotations(array $ids): void
    {
        foreach ($this->cleanIds($ids) as $id) {
            $this->deleteAnnotation($id);
        }
    }

    public function networkData(array $options = []): array
    {
        $limit = (int) ($options['limit'] ?? 0);
        $limit = $limit > 0 ? min(5000, $limit) : 0;
        $types = $this->filterTypes((string) ($options['types'] ?? 'person,event,work,concept,organization,place'));
        $siteSlug = trim((string) ($options['site_slug'] ?? ''));
        $rows = $this->linkRows($limit, $types);
        return $this->networkGraphFromRows($rows, $siteSlug);
    }

    public function itemNetworkData(int $itemId, array $options = []): array
    {
        if ($itemId <= 0) {
            return ['nodes' => [], 'edges' => []];
        }
        $types = $this->filterTypes((string) ($options['types'] ?? 'person,event,work,concept,organization,place'));
        $siteSlug = trim((string) ($options['site_slug'] ?? ''));
        $allRows = $this->linkRows(0, $types);
        $seedLinkKeys = [];
        $seedTargetKeys = [];
        foreach ($allRows as $row) {
            if ((int) $row['item_id'] !== $itemId) {
                continue;
            }
            $seedLinkKeys[$this->linkKey($row)] = true;
            $seedTargetKeys[$this->targetKey($row)] = true;
        }
        if (!$seedLinkKeys && !$seedTargetKeys) {
            return ['nodes' => [], 'edges' => []];
        }
        $rows = [];
        foreach ($allRows as $row) {
            if (isset($seedLinkKeys[$this->linkKey($row)]) || isset($seedTargetKeys[$this->targetKey($row)])) {
                $row['is_current_item'] = (int) $row['item_id'] === $itemId;
                $rows[] = $row;
            }
        }
        return $this->networkGraphFromRows($rows, $siteSlug);
    }

    private function networkGraphFromRows(array $rows, string $siteSlug): array
    {
        $nodes = [];
        $edges = [];
        foreach ($rows as $row) {
            $itemId = 'item:' . (int) $row['item_id'];
            if (!isset($nodes[$itemId])) {
                $nodes[$itemId] = [
                    'id' => $itemId,
                    'label' => (string) ($row['item_title'] ?: ('Item #' . $row['item_id'])),
                    'group' => 'item',
                    'kind' => 'item',
                    'url' => $siteSlug !== '' ? $this->siteItemUrl($siteSlug, (int) $row['item_id']) : null,
                    'thumbnail' => $this->itemThumbnailUrl((int) $row['item_id']),
                    'current' => !empty($row['is_current_item']),
                ];
            } elseif (!empty($row['is_current_item'])) {
                $nodes[$itemId]['current'] = true;
            }
            $linkId = $this->linkKey($row);
            if (!isset($nodes[$linkId])) {
                $nodes[$linkId] = [
                    'id' => $linkId,
                    'label' => $this->linkLabel($row),
                    'group' => $this->linkType($row),
                    'kind' => 'link',
                    'url' => null,
                    'targets' => [],
                ];
            }
            $targetHref = $this->targetHref($row);
            if ($targetHref) {
                $nodes[$linkId]['targets'][$this->targetKey($row)] = [
                    'id' => $this->targetKey($row),
                    'label' => (string) ($row['target_label'] ?: $row['target_uri'] ?: ('Item #' . $row['target_resource_id'])),
                    'type' => (string) ($row['target_type'] ?: 'concept'),
                    'url' => $targetHref,
                    'source' => $this->targetMeta($row, $targetHref),
                ];
            }
            $edgeKey = $itemId . '>' . $linkId;
            $edges[$edgeKey] = [
                'source' => $itemId,
                'target' => $linkId,
                'label' => $this->linkLabel($row),
                'url' => null,
                'target_type' => $this->linkType($row),
            ];
        }
        foreach ($nodes as &$node) {
            if (($node['kind'] ?? '') === 'link') {
                $node['targets'] = array_values($node['targets']);
            }
        }
        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    public function readingCards(array $options = []): array
    {
        $limit = max(1, min(200, (int) ($options['limit'] ?? 20)));
        $types = $this->filterTypes((string) ($options['types'] ?? 'person,event,work'));
        $rows = $this->linkRows(0, $types);
        $cards = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            if (!isset($cards[$itemId])) {
                $cards[$itemId] = [
                    'item_id' => $itemId,
                    'title' => (string) ($row['item_title'] ?: ('Item #' . $itemId)),
                    'links' => [],
                ];
            }
            $linkId = $this->linkKey($row);
            if (!isset($cards[$itemId]['links'][$linkId])) {
                $cards[$itemId]['links'][$linkId] = [
                    'id' => $linkId,
                    'label' => $this->linkLabel($row),
                    'type' => $this->linkType($row),
                    'targets' => [],
                ];
            }
            $cards[$itemId]['links'][$linkId]['targets'][$this->targetKey($row)] = [
                'id' => $this->targetKey($row),
                'label' => (string) ($row['target_label'] ?: $row['target_uri'] ?: ('Item #' . $row['target_resource_id'])),
                'type' => (string) ($row['target_type'] ?: 'concept'),
                'url' => $this->targetHref($row),
                'source' => $this->targetMeta($row, (string) ($this->targetHref($row) ?: '')),
            ];
        }
        foreach ($cards as &$card) {
            foreach ($card['links'] as &$link) {
                $link['targets'] = array_values($link['targets']);
            }
            $card['links'] = array_values($card['links']);
        }
        $cards = array_values($cards);
        shuffle($cards);
        return array_slice($cards, 0, $limit);
    }

    public function targetKey(array $row): string
    {
        $base = (string) ($row['target_uri'] ?: ($row['target_resource_id'] ? ('item:' . $row['target_resource_id']) : ($row['target_type'] . ':' . $row['target_label'])));
        return 'ctl-' . substr(sha1($base), 0, 16);
    }

    public function linkKey(array $row): string
    {
        $label = $this->normalize($this->linkLabel($row), true);
        $type = $this->normalize($this->linkType($row), true);
        $base = $type . ':' . $label;
        return 'ctl-link-' . substr(sha1($base), 0, 16);
    }

    private function linkLabel(array $row): string
    {
        return (string) (($row['link_label'] ?? '') ?: ($row['exact_text'] ?? 'Link'));
    }

    private function linkType(array $row): string
    {
        return (string) (($row['link_type'] ?? '') ?: ($row['target_type'] ?? 'concept'));
    }

    public function registeredTargetOptions(): array
    {
        $options = ['' => 'Select a target'];
        foreach ($this->registeredTargets() as $target) {
            $key = base64_encode(json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $suffix = $target['target_uri'] ?: ($target['target_resource_id'] ? ('Item #' . $target['target_resource_id']) : '');
            $options[$key] = $target['target_label'] . ($suffix ? ' - ' . $suffix : '');
        }
        return $options;
    }

    public function applyRegisteredTarget(array $data): array
    {
        $target = [];
        if (!empty($data['target_key'])) {
            $decoded = json_decode(base64_decode((string) $data['target_key'], true) ?: '', true);
            if (is_array($decoded)) {
                $target = $decoded;
            }
        }
        foreach (['target_type', 'target_uri', 'target_label', 'target_resource_id'] as $key) {
            if (array_key_exists($key, $target)) {
                $data[$key] = $target[$key];
            }
        }
        if (empty($data['target_text'])) {
            $data['target_text'] = (string) ($data['target_label'] ?? '');
        }
        return $data;
    }

    public function batches(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM curated_text_link_batch ORDER BY created_at DESC LIMIT 100');
    }

    private function registeredTargets(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT target_type, target_uri, target_label, target_resource_id, COUNT(*) AS use_count
             FROM curated_text_link_annotation
             WHERE status != ? AND (target_uri IS NOT NULL OR target_resource_id IS NOT NULL)
             GROUP BY target_type, target_uri, target_label, target_resource_id
             ORDER BY target_label, use_count DESC',
            ['deleted']
        );
        $targets = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['target_label'] ?: $row['target_uri'] ?: ('Item #' . $row['target_resource_id'])));
            if ($label === '') {
                continue;
            }
            $targets[] = [
                'target_type' => (string) ($row['target_type'] ?: 'concept'),
                'target_uri' => $row['target_uri'] ?: null,
                'target_label' => $label,
                'target_resource_id' => !empty($row['target_resource_id']) ? (int) $row['target_resource_id'] : null,
            ];
        }
        return $targets;
    }

    public function batch(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM curated_text_link_batch WHERE id = ?', [$id]);
        return $row ?: null;
    }

    public function createSiteApprovalBatch(array $data, ?int $userId): int
    {
        $targetText = trim((string) ($data['search_query'] ?? '')) ?: (string) ($data['exact_text'] ?? '');
        $label = trim((string) ($data['link_label'] ?? '')) ?: $targetText;
        $this->connection->insert('curated_text_link_batch', [
            'label' => 'Site selection: ' . $label,
            'target_text' => $targetText,
            'normalized_text' => $this->normalize($targetText, true),
            'target_type' => (string) (($data['link_type'] ?? '') ?: ($data['target_type'] ?? 'concept')),
            'target_uri' => null,
            'target_label' => $label,
            'target_resource_id' => null,
            'target_property_term' => (string) (($data['target_property_term'] ?? '') ?: 'schema:about'),
            'item_set_id' => null,
            'property_id' => (int) ($data['property_id'] ?? 0),
            'match_mode' => 'site-selection',
            'status' => 'candidate',
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'summary_json' => json_encode([
                'source' => 'site-selection',
                'item_id' => (int) ($data['item_id'] ?? 0),
                'exact_text' => (string) ($data['exact_text'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createBatch(array $data, array $preview, ?int $userId): int
    {
        $this->connection->insert('curated_text_link_batch', [
            'label' => $data['label'] ?: $data['target_text'],
            'target_text' => $data['target_text'],
            'normalized_text' => $this->normalize($data['target_text'], !empty($data['normalize'])),
            'target_type' => $data['target_type'] ?: 'concept',
            'target_uri' => $data['target_uri'] ?: null,
            'target_label' => $data['target_label'] ?: $data['target_text'],
            'target_resource_id' => !empty($data['target_resource_id']) ? (int) $data['target_resource_id'] : null,
            'target_property_term' => $data['target_property_term'] ?: 'schema:about',
            'item_set_id' => !empty($data['item_set_id']) ? (int) $data['item_set_id'] : null,
            'property_id' => (int) $data['property_id'],
            'match_mode' => !empty($data['normalize']) ? 'normalized' : 'exact',
            'status' => 'previewed',
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'summary_json' => json_encode(['preview' => $preview, 'options' => $data], JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function preview(array $data): array
    {
        $propertyId = (int) $data['property_id'];
        $target = (string) $data['target_text'];
        $itemSetId = !empty($data['item_set_id']) ? (int) $data['item_set_id'] : null;
        $excludeOverlaps = !empty($data['exclude_overlaps']);
        $normalize = !empty($data['normalize']);
        $limit = 500;
        $params = ['property_id' => $propertyId];
        $sql = 'SELECT v.id AS value_id, v.resource_id AS item_id, v.value, r.title AS item_title
            FROM value v INNER JOIN resource r ON r.id = v.resource_id';
        if ($itemSetId) {
            $sql .= ' INNER JOIN item_item_set iis ON iis.item_id = v.resource_id AND iis.item_set_id = :item_set_id';
            $params['item_set_id'] = $itemSetId;
        }
        $sql .= " WHERE v.property_id = :property_id AND v.type = 'literal' ORDER BY v.id DESC LIMIT $limit";
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $matches = [];
        foreach ($rows as $row) {
            $text = (string) $row['value'];
            foreach ($this->findOccurrences($text, $target, $normalize) as $hit) {
                if ($excludeOverlaps && $this->hasOverlap((int) $row['item_id'], $propertyId, (int) $hit['start'], (int) $hit['end'])) {
                    continue;
                }
                $matches[] = [
                    'item_id' => (int) $row['item_id'],
                    'item_title' => (string) $row['item_title'],
                    'value_id' => (int) $row['value_id'],
                    'property_id' => $propertyId,
                    'exact_text' => mb_substr($text, $hit['start'], $hit['end'] - $hit['start'], 'UTF-8'),
                    'start_offset' => (int) $hit['start'],
                    'end_offset' => (int) $hit['end'],
                    'prefix_text' => mb_substr($text, max(0, $hit['start'] - 30), min(30, $hit['start']), 'UTF-8'),
                    'suffix_text' => mb_substr($text, $hit['end'], 30, 'UTF-8'),
                    'has_existing' => $this->hasOverlap((int) $row['item_id'], $propertyId, (int) $hit['start'], (int) $hit['end']),
                ];
            }
        }
        return $matches;
    }

    public function previewSearchTerms(array $data): array
    {
        $matches = [];
        $seen = [];
        foreach ($this->searchTerms((string) ($data['target_text'] ?? '')) as $term) {
            $termData = $data;
            $termData['target_text'] = $term;
            foreach ($this->preview($termData) as $hit) {
                $key = implode(':', [
                    $hit['item_id'],
                    $hit['property_id'],
                    $hit['value_id'],
                    $hit['start_offset'],
                    $hit['end_offset'],
                    $term,
                ]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $hit['search_text'] = $term;
                $matches[] = $hit;
            }
        }
        return $matches;
    }

    public function previewItemTextMatches(int $itemId, string $target, bool $normalize = false, bool $excludeOverlaps = true): array
    {
        $target = trim($target);
        if ($itemId <= 0 || $target === '') {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT v.id AS value_id, v.resource_id AS item_id, v.property_id, v.value, r.title AS item_title, p.label AS property_label
             FROM value v
             INNER JOIN resource r ON r.id = v.resource_id
             INNER JOIN property p ON p.id = v.property_id
             WHERE v.resource_id = ? AND v.type = 'literal'
             ORDER BY v.id",
            [$itemId]
        );
        $matches = [];
        foreach ($rows as $row) {
            $propertyId = (int) $row['property_id'];
            $text = (string) $row['value'];
            foreach ($this->findOccurrences($text, $target, $normalize) as $hit) {
                if ($excludeOverlaps && $this->hasOverlap((int) $row['item_id'], $propertyId, (int) $hit['start'], (int) $hit['end'])) {
                    continue;
                }
                $matches[] = [
                    'item_id' => (int) $row['item_id'],
                    'item_title' => (string) $row['item_title'],
                    'value_id' => (int) $row['value_id'],
                    'property_id' => $propertyId,
                    'property_label' => (string) $row['property_label'],
                    'exact_text' => mb_substr($text, $hit['start'], $hit['end'] - $hit['start'], 'UTF-8'),
                    'start_offset' => (int) $hit['start'],
                    'end_offset' => (int) $hit['end'],
                    'prefix_text' => mb_substr($text, max(0, $hit['start'] - 30), min(30, $hit['start']), 'UTF-8'),
                    'suffix_text' => mb_substr($text, $hit['end'], 30, 'UTF-8'),
                    'has_existing' => $this->hasOverlap((int) $row['item_id'], $propertyId, (int) $hit['start'], (int) $hit['end']),
                ];
            }
        }
        return $matches;
    }

    public function searchTerms(string $text): array
    {
        $parts = preg_split('/[\r\n,，、]+/u', $text) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array($part, $terms, true)) {
                continue;
            }
            $terms[] = $part;
        }
        return $terms;
    }

    public function createEventItem(array $data): int
    {
        $api = $this->services->get('Omeka\ApiManager');
        $payload = [
            'dcterms:title' => [['type' => 'literal', 'property_id' => $this->propertyId('dcterms:title'), '@value' => $data['title']]],
        ];
        foreach ([
            'skos:altLabel' => 'alt_label',
            'schema:startDate' => 'start_date',
            'schema:endDate' => 'end_date',
            'schema:location' => 'location',
            'dcterms:description' => 'description',
            'dcterms:source' => 'source',
            'owl:sameAs' => 'same_as',
        ] as $term => $key) {
            if (!empty($data[$key]) && ($propertyId = $this->propertyId($term))) {
                $payload[$term][] = ['type' => $term === 'owl:sameAs' ? 'uri' : 'literal', 'property_id' => $propertyId, '@value' => $data[$key], '@id' => $data[$key]];
            }
        }
        return (int) $api->create('items', $payload)->getContent()->id();
    }

    public function propertyId(string $term): ?int
    {
        return $this->connection->fetchOne('SELECT p.id FROM property p INNER JOIN vocabulary v ON v.id = p.vocabulary_id WHERE CONCAT(v.prefix, ":", p.local_name) = ?', [$term]) ?: null;
    }

    public function itemSearch(string $query): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, title FROM resource WHERE resource_type = ? AND title LIKE ? ORDER BY title LIMIT 25', ['Omeka\Entity\Item', '%' . $query . '%']);
        return array_map(fn($row) => ['id' => (int) $row['id'], 'title' => (string) $row['title']], $rows);
    }

    public function relatedCandidates(string $query, ?int $currentItemId = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $normalized = $this->normalize($query, true);
        $like = '%' . addcslashes($query, '%_') . '%';
        $candidates = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT target_type, target_uri, target_label, target_resource_id, COUNT(*) AS use_count
             FROM curated_text_link_annotation
             WHERE status IN (?, ?) AND normalized_text = ? AND (target_uri IS NOT NULL OR target_resource_id IS NOT NULL)
             GROUP BY target_type, target_uri, target_label, target_resource_id
             ORDER BY use_count DESC
             LIMIT 8',
            ['approved', 'candidate', $normalized]
        ) as $row) {
            $candidates[] = $this->candidateRow('annotation', $row, (int) $row['use_count']);
        }

        foreach ($this->connection->fetchAllAssociative(
            'SELECT target_type, target_uri, target_label, target_resource_id, 1 AS use_count
             FROM curated_text_link_alias
             WHERE normalized_alias = ?
             ORDER BY updated_at DESC, created_at DESC
             LIMIT 8',
            [$normalized]
        ) as $row) {
            $candidates[] = $this->candidateRow('alias', $row, 90);
        }

        $params = [$like];
        $currentFilter = '';
        if ($currentItemId) {
            $currentFilter = ' AND r.id != ?';
            $params[] = $currentItemId;
        }
        $titlePropertyId = $this->propertyId('dcterms:title');
        foreach ($this->connection->fetchAllAssociative(
            'SELECT r.id, r.title, rc.label AS class_label
             FROM resource r
             LEFT JOIN resource_class rc ON rc.id = r.resource_class_id
             WHERE r.resource_type = ? AND r.title LIKE ?' . $currentFilter . '
             ORDER BY CASE WHEN r.title = ? THEN 0 ELSE 1 END, r.title
             LIMIT 30',
            array_merge(['Omeka\Entity\Item'], $params, [$query])
        ) as $row) {
            foreach ($this->localMatchDataList((int) $row['id'], $titlePropertyId, null, (string) $row['title'], $query) as $localMatch) {
                $localMatch['property_label'] = 'Title';
                $candidate = [
                    'source' => 'item_title',
                    'label' => (string) ($row['title'] ?: ('Item #' . $row['id'])),
                    'target_type' => $this->guessTypeFromClass((string) ($row['class_label'] ?? '')),
                    'target_uri' => null,
                    'target_resource_id' => null,
                    'local_item_id' => (int) $row['id'],
                    'score' => ((string) $row['title'] === $query) ? 85 : 70,
                    'note' => $row['class_label'] ? ('Item class: ' . $row['class_label']) : 'Item title match',
                ];
                $candidate['local_match'] = $localMatch;
                $candidate['note'] .= ' / Item #' . (int) $row['id'] . ' / matched title';
                $candidates[] = $candidate;
            }
        }

        $params = [$like];
        $currentFilter = '';
        if ($currentItemId) {
            $currentFilter = ' AND r.id != ?';
            $params[] = $currentItemId;
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT v.id AS value_id, v.property_id, r.id, r.title, rc.label AS class_label, p.label AS property_label, v.value
             FROM value v
             INNER JOIN resource r ON r.id = v.resource_id
             INNER JOIN property p ON p.id = v.property_id
             LEFT JOIN resource_class rc ON rc.id = r.resource_class_id
             WHERE r.resource_type = ? AND v.type = ? AND v.value LIKE ?' . $currentFilter . '
             ORDER BY r.title, v.property_id, v.id
             LIMIT 50',
            array_merge(['Omeka\Entity\Item', 'literal'], $params)
        ) as $row) {
            foreach ($this->localMatchDataList((int) $row['id'], (int) $row['property_id'], (int) $row['value_id'], (string) $row['value'], $query) as $localMatch) {
                $localMatch['property_label'] = (string) $row['property_label'];
                $candidate = [
                    'source' => 'item_value',
                    'label' => (string) ($row['title'] ?: ('Item #' . $row['id'])),
                    'target_type' => $this->guessTypeFromClass((string) ($row['class_label'] ?? '')),
                    'target_uri' => null,
                    'target_resource_id' => null,
                    'local_item_id' => (int) $row['id'],
                    'score' => 55,
                    'note' => 'Value match: ' . (string) $row['property_label'],
                ];
                $candidate['local_match'] = $localMatch;
                $candidate['note'] .= ' / Item #' . (int) $row['id'] . ' / "' . $localMatch['exact_text'] . '"';
                $candidates[] = $candidate;
            }
        }

        return array_slice($this->dedupeCandidates($candidates), 0, 40);
    }

    public function authorityCandidates(string $source, string $query, bool $forceRefresh = false): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if (!$forceRefresh) {
            $cache = $this->authorityCache($source, $query);
            if ($cache !== null) {
                return $cache;
            }
        }

        $settings = $this->services->get('Omeka\Settings');
        if ($source === 'wikidata') {
            $endpoint = (string) $settings->get('curated_text_links.wikidata_endpoint', 'https://www.wikidata.org/w/api.php');
            $candidates = $this->wikidataCandidates($endpoint, $query);
        } elseif ($source === 'ndl') {
            $endpoint = (string) $settings->get('curated_text_links.ndl_endpoint', 'https://id.ndl.go.jp/auth/ndlna/');
            $candidates = $this->ndlCandidates($endpoint, $query);
        } else {
            $candidates = [];
        }

        if ($candidates) {
            $this->storeAuthorityCache($source, $query, $candidates, $forceRefresh);
        }
        return $candidates;
    }

    private function writeBackMetadata(int $itemId, string $term, array $data): void
    {
        $propertyId = $this->propertyId($term);
        if (!$propertyId) {
            return;
        }
        if (!empty($data['target_resource_id'])) {
            $exists = $this->connection->fetchOne('SELECT id FROM value WHERE resource_id = ? AND property_id = ? AND value_resource_id = ? LIMIT 1', [$itemId, $propertyId, (int) $data['target_resource_id']]);
            if (!$exists) {
                $this->insertValue($itemId, $propertyId, 'resource:item', null, null, (int) $data['target_resource_id']);
            }
            return;
        }
        if (!empty($data['target_uri'])) {
            $exists = $this->connection->fetchOne('SELECT id FROM value WHERE resource_id = ? AND property_id = ? AND uri = ? LIMIT 1', [$itemId, $propertyId, (string) $data['target_uri']]);
            if (!$exists) {
                $this->insertValue($itemId, $propertyId, 'uri', $data['target_label'] ?: $data['target_uri'], (string) $data['target_uri'], null);
            }
        }
    }

    private function removeMetadataIfUnused(array $annotation): void
    {
        $propertyId = $this->propertyId((string) $annotation['target_property_term']);
        if (!$propertyId) {
            return;
        }
        $itemId = (int) $annotation['item_id'];
        if (!empty($annotation['target_resource_id'])) {
            $targetResourceId = (int) $annotation['target_resource_id'];
            $remaining = $this->connection->fetchOne(
                'SELECT id FROM curated_text_link_annotation
                 WHERE item_id = ? AND target_property_term = ? AND target_resource_id = ? AND status != ?
                 LIMIT 1',
                [$itemId, (string) $annotation['target_property_term'], $targetResourceId, 'deleted']
            );
            if (!$remaining) {
                $this->connection->delete('value', [
                    'resource_id' => $itemId,
                    'property_id' => $propertyId,
                    'value_resource_id' => $targetResourceId,
                ]);
            }
            return;
        }
        if (!empty($annotation['target_uri'])) {
            $targetUri = (string) $annotation['target_uri'];
            $remaining = $this->connection->fetchOne(
                'SELECT id FROM curated_text_link_annotation
                 WHERE item_id = ? AND target_property_term = ? AND target_uri = ? AND status != ?
                 LIMIT 1',
                [$itemId, (string) $annotation['target_property_term'], $targetUri, 'deleted']
            );
            if (!$remaining) {
                $this->connection->delete('value', [
                    'resource_id' => $itemId,
                    'property_id' => $propertyId,
                    'uri' => $targetUri,
                ]);
            }
        }
    }

    private function insertValue(int $resourceId, int $propertyId, string $type, ?string $value, ?string $uri, ?int $valueResourceId): void
    {
        $this->connection->insert('value', [
            'resource_id' => $resourceId,
            'property_id' => $propertyId,
            'type' => $type,
            'lang' => null,
            'value' => $value,
            'uri' => $uri,
            'value_resource_id' => $valueResourceId,
            'is_public' => 1,
            'value_annotation_id' => null,
        ]);
    }

    private function targetHref(array $row): ?string
    {
        if (!empty($row['target_uri'])) {
            return (string) $row['target_uri'];
        }
        if (!empty($row['target_resource_id'])) {
            $view = $this->services->get('ViewRenderer');
            return $view->url('site/resource-id', ['controller' => 'item', 'action' => 'show', 'id' => (int) $row['target_resource_id']], [], true);
        }
        return null;
    }

    private function siteItemUrl(string $siteSlug, int $itemId): ?string
    {
        try {
            $view = $this->services->get('ViewRenderer');
            return $view->url('site/resource-id', [
                'site-slug' => $siteSlug,
                'controller' => 'item',
                'action' => 'show',
                'id' => $itemId,
            ], [], true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function itemThumbnailUrl(int $itemId): ?string
    {
        try {
            $item = $this->services->get('Omeka\ApiManager')->read('items', $itemId)->getContent();
            if ($item && method_exists($item, 'thumbnailDisplayUrl')) {
                return $item->thumbnailDisplayUrl('square') ?: $item->thumbnailDisplayUrl('medium');
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private function wikidataCandidates(string $endpoint, string $query): array
    {
        $json = $this->fetchJson($endpoint, [
            'action' => 'wbsearchentities',
            'search' => $query,
            'language' => 'ja',
            'uselang' => 'ja',
            'format' => 'json',
            'limit' => 10,
        ]);
        $rows = is_array($json['search'] ?? null) ? $json['search'] : [];
        $ids = array_values(array_filter(array_map(fn($row) => (string) ($row['id'] ?? ''), $rows)));
        $sitelinks = $ids ? $this->wikidataWikipediaUrls($endpoint, $ids) : [];
        $candidates = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $label = (string) ($row['label'] ?? $id);
            $description = (string) ($row['description'] ?? '');
            $wikidataUri = (string) ($row['concepturi'] ?? ('https://www.wikidata.org/entity/' . $id));
            $targetUri = $sitelinks[$id]['url'] ?? $wikidataUri;
            $siteNote = !empty($sitelinks[$id]['site']) ? (' -> ' . $sitelinks[$id]['site']) : '';
            $candidates[] = [
                'source' => 'wikidata',
                'label' => $label,
                'target_type' => $this->guessTypeFromText($label . ' ' . $description),
                'target_uri' => $targetUri,
                'target_resource_id' => null,
                'score' => 100,
                'note' => trim($id . $siteNote . ($description ? ' - ' . $description : '')),
            ];
        }
        return $candidates;
    }

    private function wikidataWikipediaUrls(string $endpoint, array $ids): array
    {
        $json = $this->fetchJson($endpoint, [
            'action' => 'wbgetentities',
            'ids' => implode('|', $ids),
            'props' => 'sitelinks/urls',
            'sitefilter' => 'jawiki|enwiki',
            'format' => 'json',
        ]);
        $entities = is_array($json['entities'] ?? null) ? $json['entities'] : [];
        $urls = [];
        foreach ($entities as $id => $entity) {
            $sitelinks = is_array($entity['sitelinks'] ?? null) ? $entity['sitelinks'] : [];
            foreach (['jawiki', 'enwiki'] as $site) {
                if (empty($sitelinks[$site])) {
                    continue;
                }
                $url = (string) ($sitelinks[$site]['url'] ?? '');
                if ($url === '' && !empty($sitelinks[$site]['title'])) {
                    $host = $site === 'jawiki' ? 'ja.wikipedia.org' : 'en.wikipedia.org';
                    $title = str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', (string) $sitelinks[$site]['title'])));
                    $url = 'https://' . $host . '/wiki/' . $title;
                }
                if ($url !== '') {
                    $urls[(string) $id] = ['url' => $url, 'site' => $site];
                    break;
                }
            }
        }
        return $urls;
    }

    private function ndlCandidates(string $endpoint, string $query): array
    {
        $params = [
            'qw' => $query,
            'format' => 'json',
            'cnt' => 10,
        ];
        $body = $this->fetchBody($endpoint, $params);
        if (!$body) {
            return [];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return $this->ndlHtmlCandidates($endpoint, $body);
        }
        $rows = [];
        if (is_array($json['@graph'] ?? null)) {
            $rows = $json['@graph'];
        } elseif (is_array($json['graph'] ?? null)) {
            $rows = $json['graph'];
        } elseif (is_array($json['items'] ?? null)) {
            $rows = $json['items'];
        } elseif (is_array($json['result'] ?? null)) {
            $rows = $json['result'];
        } elseif ($json && array_keys($json) === range(0, count($json) - 1)) {
            $rows = $json;
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uri = (string) ($row['@id'] ?? $row['id'] ?? $row['uri'] ?? '');
            $label = $this->firstString($row['prefLabel'] ?? $row['label'] ?? $row['title'] ?? $row['name'] ?? null);
            if ($uri === '' && !empty($row['link'])) {
                $uri = (string) $row['link'];
            }
            if ($label === '' && !empty($row['dcndl:preferredLabel'])) {
                $label = $this->firstString($row['dcndl:preferredLabel']);
            }
            if ($label === '' && $uri === '') {
                continue;
            }
            $note = $this->firstString($row['description'] ?? $row['type'] ?? $row['@type'] ?? null);
            $candidates[] = [
                'source' => 'ndl',
                'label' => $label ?: $uri,
                'target_type' => $this->guessTypeFromText($label . ' ' . $note),
                'target_uri' => $uri,
                'target_resource_id' => null,
                'score' => 95,
                'note' => $note ?: 'Web NDL Authorities',
            ];
        }
        return array_slice($candidates, 0, 10);
    }

    private function fetchJson(string $endpoint, array $query): array
    {
        $body = $this->fetchBody($endpoint, $query);
        if (!$body) {
            return [];
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }

    private function fetchBody(string $endpoint, array $query): ?string
    {
        try {
            $client = new \Laminas\Http\Client($endpoint);
            $client->setOptions(['timeout' => 12]);
            $client->setMethod('GET');
            $client->setParameterGet($query);
            $response = $client->send();
            if (!$response->isSuccess()) {
                return null;
            }
            return $response->getBody();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function ndlHtmlCandidates(string $endpoint, string $html): array
    {
        if (!class_exists('\DOMDocument')) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[@id="ureslist"]/li/a[@href]');
        if (!$nodes) {
            return [];
        }
        $base = rtrim(preg_replace('~/auth/ndlna/?$~', '', $endpoint) ?: 'https://id.ndl.go.jp', '/');
        $candidates = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $label = $this->cleanText($node->textContent);
            $href = (string) $node->getAttribute('href');
            if ($label === '' || $href === '') {
                continue;
            }
            $uri = str_starts_with($href, 'http') ? $href : $base . $href;
            $schemeNode = $xpath->query('following-sibling::*[contains(concat(" ", normalize-space(@class), " "), " scheme ")][1]', $node)->item(0);
            $note = $schemeNode ? $this->cleanText($schemeNode->textContent) : 'Web NDL Authorities';
            $candidates[] = [
                'source' => 'ndl',
                'label' => $label,
                'target_type' => $this->guessTypeFromText($label . ' ' . $note),
                'target_uri' => $uri,
                'target_resource_id' => null,
                'score' => 95,
                'note' => $note ?: 'Web NDL Authorities',
            ];
            if (count($candidates) >= 10) {
                break;
            }
        }
        return $candidates;
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function cleanIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);
        return array_values(array_unique($ids));
    }

    private function filterTypes(string $types): array
    {
        $types = array_filter(array_map('trim', preg_split('/[\s,，、]+/u', $types) ?: []));
        $allowed = ['person', 'event', 'work', 'place', 'organization', 'concept'];
        $types = array_values(array_intersect($types, $allowed));
        return $types ?: $allowed;
    }

    private function linkRows(int $limit, array $types): array
    {
        $limit = $limit > 0 ? min(5000, $limit) : 0;
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = array_merge(['approved'], $types);
        $limitSql = $limit > 0 ? " LIMIT $limit" : '';
        return $this->connection->fetchAllAssociative(
            "SELECT a.*, r.title AS item_title
             FROM curated_text_link_annotation a
             INNER JOIN resource r ON r.id = a.item_id
             WHERE a.status = ? AND a.target_type IN ($placeholders)
             ORDER BY a.updated_at DESC, a.created_at DESC" . $limitSql,
            $params
        );
    }

    private function authorityCache(string $source, string $query): ?array
    {
        $normalized = $this->normalize($query, true);
        $row = $this->connection->fetchAssociative(
            'SELECT response_json FROM curated_text_link_authority_cache
             WHERE source = ? AND normalized_query = ? AND (expires_at IS NULL OR expires_at > ?)
             ORDER BY fetched_at DESC LIMIT 1',
            [$source, $normalized, date('Y-m-d H:i:s')]
        );
        if (!$row) {
            return null;
        }
        $json = json_decode((string) $row['response_json'], true);
        return is_array($json) && $json ? $json : null;
    }

    private function storeAuthorityCache(string $source, string $query, array $candidates, bool $replace = false): void
    {
        $ttl = (int) $this->services->get('Omeka\Settings')->get('curated_text_links.authority_cache_ttl', 86400);
        $normalized = $this->normalize($query, true);
        if ($replace) {
            $this->connection->delete('curated_text_link_authority_cache', [
                'source' => $source,
                'normalized_query' => $normalized,
            ]);
        }
        $this->connection->insert('curated_text_link_authority_cache', [
            'source' => $source,
            'query' => $query,
            'normalized_query' => $normalized,
            'response_json' => json_encode($candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fetched_at' => date('Y-m-d H:i:s'),
            'expires_at' => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
        ]);
    }

    private function firstString($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $found = $this->firstString($item);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    private function guessTypeFromText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (mb_strpos($text, 'person') !== false || mb_strpos($text, '人物') !== false || mb_strpos($text, '人名') !== false) {
            return 'person';
        }
        if (mb_strpos($text, 'event') !== false || mb_strpos($text, '事件') !== false || mb_strpos($text, '戦争') !== false) {
            return 'event';
        }
        if (mb_strpos($text, 'work') !== false || mb_strpos($text, '作品') !== false || mb_strpos($text, '著作') !== false) {
            return 'work';
        }
        if (mb_strpos($text, 'place') !== false || mb_strpos($text, '地名') !== false || mb_strpos($text, '場所') !== false) {
            return 'place';
        }
        if (mb_strpos($text, 'organization') !== false || mb_strpos($text, '団体') !== false || mb_strpos($text, '組織') !== false) {
            return 'organization';
        }
        return 'concept';
    }

    private function candidateRow(string $source, array $row, int $score): array
    {
        return [
            'source' => $source,
            'label' => (string) ($row['target_label'] ?: $row['target_uri'] ?: ('Item #' . $row['target_resource_id'])),
            'target_type' => (string) ($row['target_type'] ?: 'concept'),
            'target_uri' => $row['target_uri'] ?: null,
            'target_resource_id' => !empty($row['target_resource_id']) ? (int) $row['target_resource_id'] : null,
            'score' => $score,
            'note' => $source === 'annotation' ? 'Used in existing annotations' : 'Alias match',
        ];
    }

    private function localMatchData(int $itemId, ?int $propertyId, ?int $valueId, string $text, string $query): ?array
    {
        return $this->localMatchDataList($itemId, $propertyId, $valueId, $text, $query)[0] ?? null;
    }

    private function localMatchDataList(int $itemId, ?int $propertyId, ?int $valueId, string $text, string $query): array
    {
        if (!$propertyId || trim($text) === '' || trim($query) === '') {
            return [];
        }
        $hits = $this->findOccurrences($text, $query, false);
        if (!$hits) {
            $hits = $this->findOccurrences($text, $query, true);
        }
        $matches = [];
        foreach ($hits as $hit) {
            $start = (int) $hit['start'];
            $end = (int) $hit['end'];
            $matches[] = [
                'item_id' => $itemId,
                'property_id' => $propertyId,
                'value_id' => $valueId,
                'exact_text' => mb_substr($text, $start, $end - $start, 'UTF-8'),
                'start_offset' => $start,
                'end_offset' => $end,
                'prefix_text' => mb_substr($text, max(0, $start - 30), min(30, $start), 'UTF-8'),
                'suffix_text' => mb_substr($text, $end, 30, 'UTF-8'),
            ];
        }
        return $matches;
    }

    private function guessTypeFromClass(string $classLabel): string
    {
        $label = mb_strtolower($classLabel, 'UTF-8');
        if (mb_strpos($label, 'person') !== false || mb_strpos($label, '人物') !== false) {
            return 'person';
        }
        if (mb_strpos($label, 'event') !== false || mb_strpos($label, '事件') !== false) {
            return 'event';
        }
        if (mb_strpos($label, 'work') !== false || mb_strpos($label, '作品') !== false || mb_strpos($label, '著作') !== false) {
            return 'work';
        }
        if (mb_strpos($label, 'place') !== false || mb_strpos($label, '場所') !== false || mb_strpos($label, 'location') !== false) {
            return 'place';
        }
        if (mb_strpos($label, 'organization') !== false || mb_strpos($label, '組織') !== false) {
            return 'organization';
        }
        return 'concept';
    }

    private function dedupeCandidates(array $candidates): array
    {
        $seen = [];
        $deduped = [];
        usort($candidates, fn($a, $b) => (int) $b['score'] <=> (int) $a['score']);
        foreach ($candidates as $candidate) {
            $localMatch = is_array($candidate['local_match'] ?? null) ? $candidate['local_match'] : [];
            $localKey = $localMatch
                ? implode(':', [
                    $localMatch['item_id'] ?? '',
                    $localMatch['property_id'] ?? '',
                    $localMatch['value_id'] ?? '',
                    $localMatch['start_offset'] ?? '',
                    $localMatch['end_offset'] ?? '',
                ])
                : '';
            $key = ($candidate['target_uri'] ?: '') . '|' . ($candidate['target_resource_id'] ?: '') . '|' . $candidate['label'] . '|' . $localKey;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $candidate;
        }
        return $deduped;
    }

    private function selectNonOverlapping(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $lenA = (int) $a['end_offset'] - (int) $a['start_offset'];
            $lenB = (int) $b['end_offset'] - (int) $b['start_offset'];
            return $lenB <=> $lenA ?: (int) $a['start_offset'] <=> (int) $b['start_offset'];
        });
        $selected = [];
        foreach ($rows as $row) {
            foreach ($selected as $existing) {
                if ((int) $row['start_offset'] < (int) $existing['end_offset'] && (int) $row['end_offset'] > (int) $existing['start_offset']) {
                    continue 2;
                }
            }
            $selected[] = $row;
        }
        usort($selected, fn($a, $b) => (int) $a['start_offset'] <=> (int) $b['start_offset']);
        return $selected;
    }

    private function selectNonOverlappingGroups(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = (int) $row['start_offset'] . ':' . (int) $row['end_offset'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'start_offset' => (int) $row['start_offset'],
                    'end_offset' => (int) $row['end_offset'],
                    'rows' => [],
                ];
            }
            $grouped[$key]['rows'][] = $row;
        }

        $groups = array_values($grouped);
        usort($groups, function ($a, $b) {
            $lenA = (int) $a['end_offset'] - (int) $a['start_offset'];
            $lenB = (int) $b['end_offset'] - (int) $b['start_offset'];
            return $lenB <=> $lenA ?: (int) $a['start_offset'] <=> (int) $b['start_offset'];
        });
        $selected = [];
        foreach ($groups as $group) {
            foreach ($selected as $existing) {
                if ((int) $group['start_offset'] < (int) $existing['end_offset'] && (int) $group['end_offset'] > (int) $existing['start_offset']) {
                    continue 2;
                }
            }
            $selected[] = $group;
        }
        usort($selected, fn($a, $b) => (int) $a['start_offset'] <=> (int) $b['start_offset']);
        return $selected;
    }

    private function targetLinks(array $rows): array
    {
        $links = [];
        $seen = [];
        foreach ($rows as $row) {
            $href = $this->targetHref($row);
            if (!$href) {
                continue;
            }
            $label = (string) ($row['target_label'] ?: $row['target_uri'] ?: ('Item #' . $row['target_resource_id']));
            $key = $href . '|' . $label;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $links[] = [
                'href' => $href,
                'label' => $label,
                'meta' => $this->targetMeta($row, $href),
                'target_type' => (string) ($row['target_type'] ?: 'concept'),
            ];
        }
        return $links;
    }

    private function targetMeta(array $row, string $href): string
    {
        if (!empty($row['target_resource_id'])) {
            return 'Omeka Item #' . (int) $row['target_resource_id'];
        }
        $host = parse_url($href, PHP_URL_HOST) ?: '';
        if (str_contains($host, 'wikipedia.org')) {
            return 'Wikipedia - ' . $this->shortUrl($href);
        }
        if (str_contains($host, 'wikidata.org')) {
            return 'Wikidata - ' . $this->shortUrl($href);
        }
        if (str_contains($host, 'ndl.go.jp')) {
            return 'NDL Authorities - ' . $this->shortUrl($href);
        }
        return $this->shortUrl($href);
    }

    private function shortUrl(string $url): string
    {
        $url = preg_replace('~^https?://~', '', $url) ?? $url;
        return mb_strlen($url, 'UTF-8') > 80 ? mb_substr($url, 0, 77, 'UTF-8') . '...' : $url;
    }

    private function findOccurrences(string $text, string $target, bool $normalize): array
    {
        if ($target === '') {
            return [];
        }
        $matches = [];
        if (!$normalize) {
            $offset = 0;
            while (($pos = mb_strpos($text, $target, $offset, 'UTF-8')) !== false) {
                $matches[] = ['start' => $pos, 'end' => $pos + mb_strlen($target, 'UTF-8')];
                $offset = $pos + mb_strlen($target, 'UTF-8');
            }
            return $matches;
        }
        $pattern = '/' . preg_quote($target, '/') . '/iu';
        if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
            foreach ($found[0] as $hit) {
                $before = substr($text, 0, $hit[1]);
                $matches[] = [
                    'start' => mb_strlen($before, 'UTF-8'),
                    'end' => mb_strlen($before . $hit[0], 'UTF-8'),
                ];
            }
        }
        return $matches;
    }

    private function hasOverlap(int $itemId, int $propertyId, int $start, int $end): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT id FROM curated_text_link_annotation WHERE item_id = ? AND property_id = ? AND status != ? AND start_offset < ? AND end_offset > ? LIMIT 1',
            [$itemId, $propertyId, 'deleted', $end, $start]
        );
    }
}
