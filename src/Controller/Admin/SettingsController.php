<?php
namespace CuratedTextLinks\Controller\Admin;

use CuratedTextLinks\Form\SettingsForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Psr\Container\ContainerInterface;

class SettingsController extends AbstractActionController
{
    public function indexAction(): ViewModel
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(SettingsForm::class);
        $form->setData([
            'target_property_term' => $settings->get('curated_text_links.target_property_term', 'schema:about'),
            'allowed_roles' => implode("\n", (array) $settings->get('curated_text_links.allowed_roles', [])),
            'authority_search_enabled' => (bool) $settings->get('curated_text_links.authority_search_enabled', false),
            'wikidata_endpoint' => $settings->get('curated_text_links.wikidata_endpoint', ''),
            'ndl_endpoint' => $settings->get('curated_text_links.ndl_endpoint', ''),
            'authority_cache_ttl' => (int) $settings->get('curated_text_links.authority_cache_ttl', 86400),
        ]);
        if ($this->getRequest()->isPost()) {
            $form->setData($this->params()->fromPost());
            if ($form->isValid()) {
                self::saveSettings($services, $form->getData());
                $this->messenger()->addSuccess('Settings saved.');
                return $this->redirect()->toRoute('admin/curated-text-links/settings');
            }
            $this->messenger()->addFormErrors($form);
        }
        return new ViewModel(['form' => $form]);
    }

    public static function saveSettings(ContainerInterface $services, array $data): void
    {
        $settings = $services->get('Omeka\Settings');
        $settings->set('curated_text_links.target_property_term', (string) ($data['target_property_term'] ?? 'schema:about'));
        $roles = preg_split('/\R+/', (string) ($data['allowed_roles'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $settings->set('curated_text_links.allowed_roles', array_values(array_map('trim', $roles)));
        $settings->set('curated_text_links.authority_search_enabled', !empty($data['authority_search_enabled']));
        $settings->set('curated_text_links.wikidata_endpoint', (string) ($data['wikidata_endpoint'] ?? ''));
        $settings->set('curated_text_links.ndl_endpoint', (string) ($data['ndl_endpoint'] ?? ''));
        $settings->set('curated_text_links.authority_cache_ttl', (int) ($data['authority_cache_ttl'] ?? 86400));
    }
}

