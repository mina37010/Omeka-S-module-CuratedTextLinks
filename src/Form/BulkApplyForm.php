<?php
namespace CuratedTextLinks\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

class BulkApplyForm extends Form implements InputFilterProviderInterface
{
    public function init(): void
    {
        $this->add(['name' => 'label', 'type' => Element\Text::class, 'options' => ['label' => 'Batch label']]);
        $this->add(['name' => 'target_key', 'type' => Element\Select::class, 'options' => ['label' => 'Registered target', 'value_options' => []], 'attributes' => ['required' => true]]);
        $this->add(['name' => 'target_text', 'type' => Element\Textarea::class, 'options' => ['label' => 'Link text / search terms'], 'attributes' => ['placeholder' => 'One term per line, or leave blank to use the target label', 'rows' => 3]]);
        $this->add(['name' => 'target_type', 'type' => Element\Hidden::class]);
        $this->add(['name' => 'target_uri', 'type' => Element\Hidden::class]);
        $this->add(['name' => 'target_label', 'type' => Element\Hidden::class]);
        $this->add(['name' => 'target_resource_id', 'type' => Element\Hidden::class]);
        $this->add(['name' => 'target_property_term', 'type' => Element\Select::class, 'options' => ['label' => 'Save metadata to', 'value_options' => ['schema:about' => 'schema:about', 'dcterms:subject' => 'dcterms:subject']]]);
        $this->add(['name' => 'item_set_id', 'type' => Element\Number::class, 'options' => ['label' => 'Item set ID']]);
        $this->add(['name' => 'property_id', 'type' => Element\Select::class, 'options' => ['label' => 'Search property', 'value_options' => []], 'attributes' => ['required' => true]]);
        $this->add(['name' => 'normalize', 'type' => Element\Checkbox::class, 'options' => ['label' => 'Use normalization']]);
        $this->add(['name' => 'exclude_overlaps', 'type' => Element\Checkbox::class, 'options' => ['label' => 'Exclude existing overlaps']]);
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'label' => ['required' => false],
            'target_key' => ['required' => true],
            'target_text' => ['required' => false, 'allow_empty' => true],
            'target_type' => ['required' => false, 'allow_empty' => true],
            'target_uri' => ['required' => false, 'allow_empty' => true],
            'target_label' => ['required' => false, 'allow_empty' => true],
            'target_resource_id' => ['required' => false, 'allow_empty' => true],
            'target_property_term' => ['required' => true],
            'item_set_id' => ['required' => false, 'allow_empty' => true],
            'property_id' => ['required' => true],
            'normalize' => ['required' => false],
            'exclude_overlaps' => ['required' => false],
        ];
    }
}
