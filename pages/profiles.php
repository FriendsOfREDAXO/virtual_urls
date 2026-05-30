<?php

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$csrfToken = rex_csrf_token::factory('virtual_urls_profiles_actions');
$csrfParams = $csrfToken->getUrlParams();
$csrfName = (string) array_key_first($csrfParams);
$csrfValue = $csrfName !== '' ? (string) ($csrfParams[$csrfName] ?? '') : '';

$getRelationFieldMap = static function (string $tableName): array {
    $map = [];
    $table = rex_yform_manager_table::get($tableName);
    if ($table === null) {
        return $map;
    }

    foreach ($table->getFields() as $field) {
        if ($field->getType() !== 'value') {
            continue;
        }

        $typeName = (string) $field->getTypeName();
        if (!in_array($typeName, ['be_manager_relation', 'relation_select'], true)) {
            continue;
        }

        $relationTable = trim((string) $field->getElement('table'));
        if ($relationTable === '') {
            $relationTable = trim((string) $field->getElement('relation_table'));
        }

        if ($relationTable !== '' && rex_yform_manager_table::get($relationTable) !== null) {
            $map[(string) $field->getName()] = $relationTable;
        }
    }

    return $map;
};

if ($func === 'delete') {
    if (rex_request_method() !== 'post' || !$csrfToken->isValid()) {
        echo rex_view::error('CSRF-Fehler beim Löschen des Profils.');
        return;
    }

    $sql = rex_sql::factory();
    $sql->setQuery('DELETE FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE id = :id', ['id' => $id]);
    \FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper::clearCache();
    echo rex_view::success('Profil gelöscht');
    rex_response::sendRedirect(rex_url::currentBackendPage());
}

if ($func === 'status') {
    if (rex_request_method() !== 'post' || !$csrfToken->isValid()) {
        echo rex_view::error('CSRF-Fehler beim Ändern des Status.');
        return;
    }

    $status = rex_request('status', 'int', 0);
    $sql = rex_sql::factory();
    $sql->setQuery('UPDATE ' . rex::getTable('virtual_urls_profiles') . ' SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
    \FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper::clearCache();
    echo rex_view::success('Status geändert');
    $func = ''; // Zurück zur Liste
}

if ($func === 'edit' || $func === 'add') {
    if (rex_request_method() === 'post') {
        $postedTable = rex_request('table_name', 'string', '');
        $postedRelationField = rex_request('relation_field', 'string', '');
        $postedMap = $postedTable !== '' ? $getRelationFieldMap($postedTable) : [];
        $derivedRelationTable = $postedRelationField !== '' && isset($postedMap[$postedRelationField])
            ? $postedMap[$postedRelationField]
            : '';

        // rex_form reads from request data; keep relation_table consistent with selected relation field.
        $_POST['relation_table'] = $derivedRelationTable;
        $_REQUEST['relation_table'] = $derivedRelationTable;
    }

    $form = rex_form::factory(rex::getTable('virtual_urls_profiles'), '', $func === 'edit' ? 'id=' . $id : 'id=0', 'post', false);
    if ($func === 'edit') {
        $form->addParam('id', $id);
    }

    $field = $form->addSelectField('status');
    $field->setLabel('Status');
    $select = $field->getSelect();
    $select->addOption('Aktiv', 1);
    $select->addOption('Inaktiv', 0);

    // Sprache
    $field = $form->addSelectField('clang_id');
    $field->setLabel('Sprache');
    $field->setNotice('Die Sprache, für die dieses Profil gilt. "Alle Sprachen" = sprachunabhängig.');
    $select = $field->getSelect();
    $select->addOption('Alle Sprachen', '-1');
    foreach (rex_clang::getAll() as $clang) {
        $select->addOption($clang->getName(), $clang->getId());
    }

    // Domain-Auswahl aus YRewrite
    $field = $form->addSelectField('domain');
    $field->setLabel('Domain');
    $field->setNotice('Die Domain, für die dieses Profil gilt. "Alle Domains" = Profil greift überall.');
    $field->setDefaultSaveValue('');
    $select = $field->getSelect();
    $select->addOption('Alle Domains', '');
    if (rex_addon::get('yrewrite')->isAvailable()) {
        foreach (rex_yrewrite::getDomains() as $domain) {
            $name = $domain->getName();
            if ($name !== 'default') {
                $select->addOption($name, $name);
            }
        }
    }

    $field = $form->addSelectField('table_name');
    $field->setLabel('YForm Tabelle');
    $field->setNotice('Die Tabelle, aus der die Datensätze geladen werden sollen.');
    $field->setAttribute('id', 'virtual-urls-table-name');
    $field->setAttribute('data-ajax-url', rex_url::backendPage('virtual_urls/ajax.columns'));
    $field->setAttribute('data-csrf-name', $csrfName);
    $field->setAttribute('data-csrf-value', $csrfValue);
    $select = $field->getSelect();
    $select->addOption('Bitte wählen...', '');
    foreach (rex_yform_manager_table::getAll() as $yformTable) {
        $select->addOption($yformTable->getTableName(), $yformTable->getTableName());
    }

    // Im Edit-Modus Spalten der gespeicherten Tabellen vorladen (damit rex_form die Werte speichern kann)
    $preloadedMainColumns = [];
    $preloadedRelationFields = [];
    $preloadedRelationColumns = [];
    $relationFieldMap = [];
    if ($form->isEditMode()) {
        $savedTableName = (string) $form->getSql()->getValue('table_name');
        $savedRelationField = (string) $form->getSql()->getValue('relation_field');
        $savedRelationTable = (string) $form->getSql()->getValue('relation_table');
        $sqlHelper = rex_sql::factory();
        if ($savedTableName !== '' && rex_yform_manager_table::get($savedTableName) !== null) {
            $colRows = $sqlHelper->getArray('SHOW COLUMNS FROM ' . $sqlHelper->escapeIdentifier($savedTableName));
            foreach ($colRows as $col) {
                $preloadedMainColumns[] = $col['Field'];
            }

            $relationFieldMap = $getRelationFieldMap($savedTableName);
            $preloadedRelationFields = array_keys($relationFieldMap);

            if ($savedRelationField !== '' && isset($relationFieldMap[$savedRelationField])) {
                $savedRelationTable = $relationFieldMap[$savedRelationField];
            }
        }
        if ($savedRelationTable !== '' && rex_yform_manager_table::get($savedRelationTable) !== null) {
            $colRows = $sqlHelper->getArray('SHOW COLUMNS FROM ' . $sqlHelper->escapeIdentifier($savedRelationTable));
            foreach ($colRows as $col) {
                $preloadedRelationColumns[] = $col['Field'];
            }
        }
    }

    $field = $form->addTextField('trigger_segment');
    $field->setLabel('URL Trigger Segment');
    $field->setNotice('Der Teil der URL, der die virtuelle URL einleitet, z.B. news');

    $field = $form->addSelectField('url_field');
    $field->setLabel('Slug Feld Name');
    $field->setNotice('z.B. code oder url');
    $field->setAttribute('class', 'form-control virtual-urls-main-column-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('url_field') : '');
    $select = $field->getSelect();
    $select->addOption('Bitte Tabelle wählen...', '');
    foreach ($preloadedMainColumns as $col) {
        $select->addOption($col, $col);
    }

    $field = $form->addLinkmapField('article_id');
    $field->setLabel('Renderer Artikel');

    $field = $form->addLinkmapField('default_category_id');
    $field->setLabel('Standard Kategorie für Sitemap');
    $field->setNotice('Unter dieser Kategorie werden die URLs in der Sitemap ausgegeben (Canonical Basis)');

    $field = $form->addSelectField('relation_field');
    $field->setLabel('Relation Feld (Optional)');
    $field->setNotice('Nur echte Relationsfelder (z.B. be_manager_relation) werden angeboten. Die Relationstabelle wird automatisch daraus ermittelt.');
    $field->setAttribute('id', 'virtual-urls-relation-field');
    $field->setAttribute('class', 'form-control virtual-urls-relation-field-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('relation_field') : '');
    $field->setAttribute('data-relation-map', (string) json_encode($relationFieldMap));
    $select = $field->getSelect();
    $select->addOption('Keine Relation', '');
    foreach ($preloadedRelationFields as $relationField) {
        $select->addOption($relationField, $relationField);
    }

    $field = $form->addSelectField('relation_table');
    $field->setLabel('Relation Tabelle (Optional)');
    $field->setNotice('Wird automatisch über das gewählte Relationsfeld gesetzt.');
    $field->setAttribute('id', 'virtual-urls-relation-table');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('relation_table') : '');
    $select = $field->getSelect();
    $select->addOption('Keine Relation', '');
    if ($form->isEditMode()) {
        $savedRelationTable = (string) $form->getSql()->getValue('relation_table');
        if ($savedRelationTable !== '') {
            $select->addOption($savedRelationTable, $savedRelationTable);
        }
    }

    $field = $form->addSelectField('relation_slug_field');
    $field->setLabel('Relation Slug Feld (Optional)');
    $field->setNotice('Feld in der Relationstabelle für den URL-Teil, z.B. name oder url_slug. Wird automatisch normalisiert.');
    $field->setAttribute('class', 'form-control virtual-urls-relation-column-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('relation_slug_field') : '');
    $select = $field->getSelect();
    $select->addOption('Bitte Relationstabelle wählen...', '');
    foreach ($preloadedRelationColumns as $col) {
        $select->addOption($col, $col);
    }

    $field = $form->addTextField('sitemap_filter');
    $field->setLabel('Sitemap Filter (SQL Where)');
    $field->setNotice('z.B. status = 1 AND date <= "###NOW -1 DAY###"');

    $field = $form->addSelectField('sitemap_changefreq');
    $field->setLabel('Sitemap Changefreq');
    $field->setNotice('Wie oft ändert sich der Inhalt voraussichtlich?');
    $select = $field->getSelect();
    $select->addOptions(['always' => 'always', 'hourly' => 'hourly', 'daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly', 'yearly' => 'yearly', 'never' => 'never']);

    $field = $form->addSelectField('sitemap_priority');
    $field->setLabel('Sitemap Priority');
    $field->setNotice('Priorität der URLs im Vergleich zu anderen URLs auf der Website (0.0 bis 1.0).');
    $select = $field->getSelect();
    $select->addOptions(['1.0' => '1.0', '0.9' => '0.9', '0.8' => '0.8', '0.7' => '0.7', '0.6' => '0.6', '0.5' => '0.5', '0.4' => '0.4', '0.3' => '0.3', '0.2' => '0.2', '0.1' => '0.1', '0.0' => '0.0']);

    // SEO Felder
    $form->addFieldset('SEO Einstellungen');

    $field = $form->addSelectField('seo_title_field');
    $field->setLabel('SEO Title Feld');
    $field->setNotice('Spalte in der YForm-Tabelle für den Meta-Title (z.B. title oder name). Leer lassen für Standard.');
    $field->setAttribute('class', 'form-control virtual-urls-main-column-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('seo_title_field') : '');
    $select = $field->getSelect();
    $select->addOption('- Leer (Standard) -', '');
    foreach ($preloadedMainColumns as $col) {
        $select->addOption($col, $col);
    }

    $field = $form->addSelectField('seo_description_field');
    $field->setLabel('SEO Description Feld');
    $field->setNotice('Spalte in der YForm-Tabelle für die Meta-Description (z.B. description oder text). HTML wird entfernt und Text gekürzt.');
    $field->setAttribute('class', 'form-control virtual-urls-main-column-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('seo_description_field') : '');
    $select = $field->getSelect();
    $select->addOption('- Leer (Standard) -', '');
    foreach ($preloadedMainColumns as $col) {
        $select->addOption($col, $col);
    }

    $field = $form->addSelectField('seo_image_field');
    $field->setLabel('SEO Image Feld');
    $field->setNotice('Spalte in der YForm-Tabelle für das Meta-Image (z.B. image oder teaser_image).');
    $field->setAttribute('class', 'form-control virtual-urls-main-column-select');
    $field->setAttribute('data-selected', $form->isEditMode() ? $form->getSql()->getValue('seo_image_field') : '');
    $select = $field->getSelect();
    $select->addOption('- Leer (Standard) -', '');
    foreach ($preloadedMainColumns as $col) {
        $select->addOption($col, $col);
    }

    $content = $form->get();

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', ($func === 'edit') ? 'Profil bearbeiten' : 'Profil erstellen', false);
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');
} else {
    // Liste
    $list = rex_list::factory('SELECT * FROM ' . rex::getTable('virtual_urls_profiles'));

    $list->addTableAttribute('class', 'table-striped');

    $list->setColumnLabel('id', 'ID');
    $list->setColumnLabel('status', 'Status');
    $list->setColumnLabel('clang_id', 'Sprache');
    $list->setColumnLabel('domain', 'Domain');
    $list->setColumnLabel('table_name', 'Tabelle');
    $list->setColumnLabel('trigger_segment', 'URL Trigger');

    // Unnötige Spalten ausblenden
    $list->removeColumn('url_field');
    $list->removeColumn('article_id');
    $list->removeColumn('relation_field');
    $list->removeColumn('relation_table');
    $list->removeColumn('relation_slug_field');
    $list->removeColumn('default_category_id');
    $list->removeColumn('sitemap_filter');
    $list->removeColumn('sitemap_changefreq');
    $list->removeColumn('sitemap_priority');
    $list->removeColumn('seo_title_field');
    $list->removeColumn('seo_description_field');
    $list->removeColumn('seo_image_field');

    $list->setColumnFormat('status', 'custom', static function ($params) use ($csrfName, $csrfValue) {
        $status = (int) $params['list']->getValue('status');
        $id = (int) $params['list']->getValue('id');
        if ($status === 1) {
            $targetStatus = 0;
            $label = '<span class="rex-online"><i class="rex-icon rex-icon-active-true"></i> Online</span>';
        } else {
            $targetStatus = 1;
            $label = '<span class="rex-offline"><i class="rex-icon rex-icon-active-false"></i> Offline</span>';
        }

        $action = rex_url::currentBackendPage();
        $page = rex_be_controller::getCurrentPage();

        return '<form method="post" action="' . rex_escape($action) . '" style="display:inline;">'
            . '<input type="hidden" name="page" value="' . rex_escape($page) . '">'
            . '<input type="hidden" name="func" value="status">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="status" value="' . $targetStatus . '">'
            . '<input type="hidden" name="' . rex_escape($csrfName) . '" value="' . rex_escape($csrfValue) . '">'
            . '<button type="submit" class="btn btn-link btn-xs" style="padding:0; text-decoration:none;">' . $label . '</button>'
            . '</form>';
    });

    $list->setColumnFormat('clang_id', 'custom', static function ($params) {
        $value = (int) $params['list']->getValue('clang_id');
        if ($value === -1) {
            return '<em>Alle Sprachen</em>';
        }
        $clang = rex_clang::get($value);
        return $clang ? $clang->getName() : 'Unbekannt';
    });

    $list->setColumnFormat('domain', 'custom', static function ($params) {
        $value = $params['list']->getValue('domain');
        return $value !== '' ? rex_escape($value) : '<em>Alle Domains</em>';
    });

    $list->addColumn('edit', '<i class="rex-icon rex-icon-edit"></i> Bearbeiten');
    $list->setColumnLayout('edit', ['<th class="rex-table-action" colspan="2">###VALUE###</th>', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('edit', ['func' => 'edit', 'id' => '###id###']);

    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen');
    $list->setColumnLayout('delete', ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnFormat('delete', 'custom', static function ($params) use ($csrfName, $csrfValue) {
        $id = (int) $params['list']->getValue('id');
        $action = rex_url::currentBackendPage();
        $page = rex_be_controller::getCurrentPage();

        return '<form method="post" action="' . rex_escape($action) . '" style="display:inline;" onsubmit="return confirm(\'Profil wirklich löschen?\');">'
            . '<input type="hidden" name="page" value="' . rex_escape($page) . '">'
            . '<input type="hidden" name="func" value="delete">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="' . rex_escape($csrfName) . '" value="' . rex_escape($csrfValue) . '">'
            . '<button type="submit" class="btn btn-link btn-xs" style="padding:0;">'
            . '<i class="rex-icon rex-icon-delete"></i> Löschen'
            . '</button>'
            . '</form>';
    });

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('content', $content, false);
    $content = $fragment->parse('core/page/section.php');

    echo '<a href="' . rex_url::currentBackendPage(['func' => 'add']) . '" class="btn btn-primary">Neues Profil anlegen</a><br><br>';
    echo $content;
}
