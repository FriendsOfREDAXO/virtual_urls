<?php

class rex_yform_value_virtual_url_slug extends rex_yform_value_abstract
{
    public function enterObject()
    {
        // If value is already set and we are not in edit mode (or we want to preserve it), we might keep it.
        // But usually slugs update when title updates, unless manually changed.
        
        $sourceField = $this->getElement('source_field');
        $sourceValue = '';

        // Find the source field value in the email/db object
        foreach ($this->params['values'] as $value) {
            if ($value->getName() == $sourceField) {
                // Falls source_field ein yform_lang_fields-Feld ist (lang_text/lang_textarea),
                // liegt hier zu diesem Zeitpunkt entweder das rohe POST-Array oder ein JSON-String
                // vor. resolveRawValue() löst beides auf die aktuelle Sprache auf; für normale
                // Felder wird der Wert unverändert durchgereicht.
                $sourceValue = \FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper::resolveRawValue($value->getValue());
                break;
            }
        }

        // Only generate if empty or if we want to enforce sync? 
        // For now: Generate if empty or if it's a new entry. 
        // Better: Generate if empty. If user wants to change it, they can (if we make it editable).
        
        $currentValue = (string) $this->getValue();
        
        // 1. Generate if empty and source is available
        if ($currentValue == "" && $sourceValue != "") {
             $currentValue = rex_string::normalize($sourceValue);
        }
        
        // 2. Always normalize the value (also user input) to ensure valid URL slugs
        if ($currentValue != "") {
             $currentValue = rex_string::normalize($currentValue);
        }
        
        $this->setValue($currentValue);
        
        // Output the field
        if ($this->needsOutput()) {
            $visibility = $this->getElement('visibility');
            
            if ($visibility == 'hidden') {
                $this->params['form_output'][$this->getId()] = $this->parse('value.hidden.tpl.php');
            } elseif ($this->isViewable()) {
                if ($visibility == 'readonly') {
                    // Force readonly attribute for text template
                    $this->setElement('attributes', array_merge($this->getElement('attributes', []), ['readonly' => 'readonly']));
                    $this->params['form_output'][$this->getId()] = $this->parse('value.text.tpl.php');
                } else {
                    // Visible and editable
                    $this->params['form_output'][$this->getId()] = $this->parse('value.text.tpl.php');
                }
            }
        }

        $this->params['value_pool']['email'][$this->getName()] = $this->getValue();
        if ($this->saveInDb()) {
            $this->params['value_pool']['sql'][$this->getName()] = $this->getValue();
        }
    }

    public function getDescription(): string
    {
        return 'virtual_url_slug|name|label|source_field_name|[no_db]';
    }

    public function getDefinitions(): array
    {
        return [
            'type' => 'value',
            'name' => 'virtual_url_slug',
            'values' => [
                'name' => ['type' => 'name',    'label' => 'Feldname'],
                'label' => ['type' => 'text',    'label' => 'Bezeichnung'],
                'source_field' => ['type' => 'text',    'label' => 'Quell-Feld (z.B. title)'],
                'visibility' => ['type' => 'choice', 'label' => 'Sichtbarkeit', 'default' => 'visible', 'choices' => 'sichtbar & editierbar=visible,sichtbar & schreibgeschützt=readonly,versteckt=hidden'],
                'no_db' => ['type' => 'no_db',   'label' => 'Datenbank',  'default' => 0],
            ],
            'description' => 'Generiert einen URL-Slug aus einem anderen Feld',
            'db_type' => ['varchar(191)'],
        ];
    }
}
