<?php
namespace CuratedTextLinks;

use CuratedTextLinks\Controller\Admin\AnnotationController;
use CuratedTextLinks\Controller\Admin\BatchController;
use CuratedTextLinks\Controller\Admin\BulkController;
use CuratedTextLinks\Controller\Admin\SettingsController;
use CuratedTextLinks\Service\AnnotationService;
use Laminas\EventManager\Event;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Module\AbstractModule;
use Psr\Container\ContainerInterface;

class Module extends AbstractModule
{
    private ?ContainerInterface $services = null;

    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        $this->services = $event->getApplication()->getServiceManager();
        $this->ensureSchema($this->services->get('Omeka\Connection'));
        $acl = $this->services->get('Omeka\Acl');
        foreach ([AnnotationController::class, BulkController::class, BatchController::class, SettingsController::class] as $resource) {
            if (!$acl->hasResource($resource)) {
                $acl->addResource($resource);
            }
            $acl->allow(['global_admin', 'site_admin', 'editor'], $resource);
        }

        $eventManager = $event->getApplication()->getEventManager();
        $shared = $eventManager->getSharedManager();
        $shared->attach('Omeka\Controller\Site\Item', 'view.show.after', [$this, 'injectSiteAnnotationUi']);
        $shared->attach(ValueRepresentation::class, 'rep.value.html', [$this, 'renderLinkedDescription']);
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS curated_text_link_annotation (
  id INT AUTO_INCREMENT NOT NULL,
  item_id INT NOT NULL,
  property_id INT NOT NULL,
  value_id INT DEFAULT NULL,
  exact_text TEXT NOT NULL,
  normalized_text VARCHAR(255) NOT NULL,
  prefix_text TEXT DEFAULT NULL,
  suffix_text TEXT DEFAULT NULL,
  start_offset INT NOT NULL,
  end_offset INT NOT NULL,
  link_label VARCHAR(1024) DEFAULT NULL,
  link_type VARCHAR(64) DEFAULT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_uri TEXT DEFAULT NULL,
  target_label VARCHAR(1024) DEFAULT NULL,
  target_resource_id INT DEFAULT NULL,
  target_property_term VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'candidate',
  confidence DECIMAL(5,4) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  batch_id INT DEFAULT NULL,
  note TEXT DEFAULT NULL,
  INDEX IDX_CTL_ANNOTATION_ITEM_PROPERTY (item_id, property_id),
  INDEX IDX_CTL_ANNOTATION_TEXT (normalized_text),
  INDEX IDX_CTL_ANNOTATION_STATUS (status),
  INDEX IDX_CTL_ANNOTATION_BATCH (batch_id),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS curated_text_link_batch (
  id INT AUTO_INCREMENT NOT NULL,
  label VARCHAR(255) DEFAULT NULL,
  target_text TEXT NOT NULL,
  normalized_text VARCHAR(255) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_uri TEXT DEFAULT NULL,
  target_label VARCHAR(1024) DEFAULT NULL,
  target_resource_id INT DEFAULT NULL,
  target_property_term VARCHAR(64) NOT NULL,
  item_set_id INT DEFAULT NULL,
  property_id INT NOT NULL,
  match_mode VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  executed_at DATETIME DEFAULT NULL,
  reverted_at DATETIME DEFAULT NULL,
  summary_json LONGTEXT DEFAULT NULL,
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS curated_text_link_authority_cache (
  id INT AUTO_INCREMENT NOT NULL,
  source VARCHAR(64) NOT NULL,
  query TEXT NOT NULL,
  normalized_query VARCHAR(255) NOT NULL,
  response_json LONGTEXT NOT NULL,
  fetched_at DATETIME NOT NULL,
  expires_at DATETIME DEFAULT NULL,
  INDEX IDX_CTL_AUTHORITY_QUERY (source, normalized_query),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS curated_text_link_alias (
  id INT AUTO_INCREMENT NOT NULL,
  alias VARCHAR(1024) NOT NULL,
  normalized_alias VARCHAR(255) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_uri TEXT DEFAULT NULL,
  target_label VARCHAR(1024) DEFAULT NULL,
  target_resource_id INT DEFAULT NULL,
  note TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  INDEX IDX_CTL_ALIAS_NORMALIZED (normalized_alias),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
SQL;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $connection->executeStatement($statement);
        }

        $this->ensureSchema($connection);

        $settings = $services->get('Omeka\Settings');
        $settings->set('curated_text_links.target_property_term', 'schema:about');
        $settings->set('curated_text_links.allowed_roles', ['global_admin', 'site_admin', 'editor', 'reviewer']);
        $settings->set('curated_text_links.authority_search_enabled', false);
        $settings->set('curated_text_links.wikidata_endpoint', 'https://www.wikidata.org/w/api.php');
        $settings->set('curated_text_links.ndl_endpoint', 'https://id.ndl.go.jp/auth/ndlna/');
        $settings->set('curated_text_links.authority_cache_ttl', 86400);
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        foreach ([
            'DROP TABLE IF EXISTS curated_text_link_alias',
            'DROP TABLE IF EXISTS curated_text_link_authority_cache',
            'DROP TABLE IF EXISTS curated_text_link_annotation',
            'DROP TABLE IF EXISTS curated_text_link_batch',
        ] as $statement) {
            $connection->executeStatement($statement);
        }
    }

    private function ensureSchema($connection): void
    {
        try {
            foreach ([
                'link_label' => 'ALTER TABLE curated_text_link_annotation ADD link_label VARCHAR(1024) DEFAULT NULL AFTER end_offset',
                'link_type' => 'ALTER TABLE curated_text_link_annotation ADD link_type VARCHAR(64) DEFAULT NULL AFTER link_label',
            ] as $column => $sql) {
                $exists = $connection->fetchOne('SHOW COLUMNS FROM curated_text_link_annotation LIKE ?', [$column]);
                if (!$exists) {
                    $connection->executeStatement($sql);
                }
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->services ?: $renderer->getHelperPluginManager()->getCreationContext();
        $form = $services->get('FormElementManager')->get(\CuratedTextLinks\Form\SettingsForm::class);
        $settings = $services->get('Omeka\Settings');
        $form->setData([
            'target_property_term' => $settings->get('curated_text_links.target_property_term', 'schema:about'),
            'allowed_roles' => implode("\n", (array) $settings->get('curated_text_links.allowed_roles', [])),
            'authority_search_enabled' => (bool) $settings->get('curated_text_links.authority_search_enabled', false),
            'wikidata_endpoint' => $settings->get('curated_text_links.wikidata_endpoint', ''),
            'ndl_endpoint' => $settings->get('curated_text_links.ndl_endpoint', ''),
            'authority_cache_ttl' => (int) $settings->get('curated_text_links.authority_cache_ttl', 86400),
        ]);
        $form->prepare();
        return $renderer->formCollection($form);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $controller->getEvent()->getApplication()->getServiceManager();
        $post = $controller->params()->fromPost();
        SettingsController::saveSettings($services, $post);
        return true;
    }

    public function renderLinkedDescription(Event $event): void
    {
        $value = $event->getTarget();
        if (!$value instanceof ValueRepresentation || $value->type() !== 'literal') {
            return;
        }
        $services = $this->services;
        if (!$services) {
            return;
        }
        $resource = $value->resource();
        if ($resource->resourceName() !== 'items') {
            return;
        }
        $property = $value->property();
        $valueId = AnnotationService::valueId($value);
        $html = $services->get(AnnotationService::class)->renderText(
            (string) $value->value(),
            (int) $resource->id(),
            (int) $property->id(),
            $valueId,
            (string) $event->getParam('html')
        );
        $event->setParam('html', $html);
    }

    public function injectSiteAnnotationUi(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof PhpRenderer || !$this->services) {
            return;
        }
        if (!$view->status()->isSiteRequest()) {
            return;
        }
        $item = $view->vars()->offsetGet('item');
        if (!$item) {
            return;
        }
        $view->headLink()->appendStylesheet($view->assetUrl('css/curated-text-links.css', 'CuratedTextLinks'));
        $view->headScript()->appendFile($view->assetUrl('js/curated-text-links.js', 'CuratedTextLinks'));
        $titlePropertyId = $this->services->get(AnnotationService::class)->propertyId('dcterms:title');
        $titleHtml = $titlePropertyId ? $this->services->get(AnnotationService::class)->renderText(
            (string) $item->displayTitle(),
            (int) $item->id(),
            (int) $titlePropertyId,
            null,
            $view->escapeHtml((string) $item->displayTitle())
        ) : '';
        echo $view->partial('curated-text-links/site/title-renderer', [
            'titleHtml' => $titleHtml,
        ]);
        $identity = $view->identity();
        if (!$identity || !$this->services->get(AnnotationService::class)->userCanAnnotate($identity)) {
            return;
        }
        echo $view->partial('curated-text-links/site/annotation-ui', [
            'item' => $item,
            'saveUrl' => $view->url('site/curated-text-links', ['action' => 'create'], [], true),
            'searchUrl' => $view->url('site/curated-text-links', ['action' => 'search-items'], [], true),
            'candidatesUrl' => $view->url('site/curated-text-links', ['action' => 'candidates'], [], true),
            'authorityUrl' => $view->url('site/curated-text-links', ['action' => 'authority'], [], true),
            'defaultTargetProperty' => $this->services->get('Omeka\Settings')->get('curated_text_links.target_property_term', 'schema:about'),
        ]);
    }
}
