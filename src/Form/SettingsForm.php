<?php
namespace CuratedTextLinks\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class SettingsForm extends Form
{
    public function init(): void
    {
        $this->add(['name' => 'target_property_term', 'type' => Element\Select::class, 'options' => ['label' => 'Default metadata property', 'value_options' => ['schema:about' => 'schema:about', 'dcterms:subject' => 'dcterms:subject']]]);
        $this->add(['name' => 'allowed_roles', 'type' => Element\Textarea::class, 'options' => ['label' => 'Allowed roles']]);
        $this->add(['name' => 'authority_search_enabled', 'type' => Element\Checkbox::class, 'options' => ['label' => 'Enable authority search']]);
        $this->add(['name' => 'wikidata_endpoint', 'type' => Element\Url::class, 'options' => ['label' => 'Wikidata endpoint URL']]);
        $this->add(['name' => 'ndl_endpoint', 'type' => Element\Url::class, 'options' => ['label' => 'Web NDL Authorities endpoint URL']]);
        $this->add(['name' => 'authority_cache_ttl', 'type' => Element\Number::class, 'options' => ['label' => 'Authority cache TTL']]);
    }
}

