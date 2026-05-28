<?php

$package = rex_addon::get('virtual_urls');

$selectedTable = rex_request('slug_table', 'string', '');
$sourceField = rex_request('slug_source', 'string', '');
$targetField = rex_request('slug_target', 'string', '');
$mode = rex_request('slug_mode', 'string', 'empty_only');
$generate = rex_request('slug_generate', 'int', 0);
$csrfToken = rex_csrf_token::factory('virtual_urls_tools_generate');
$csrfParams = $csrfToken->getUrlParams();
$csrfName = (string) array_key_first($csrfParams);
$csrfValue = $csrfName !== '' ? (string) ($csrfParams[$csrfName] ?? '') : '';

// Verfügbare YForm-Tabellen laden
$tables = [];
if (rex_addon::get('yform')->isAvailable()) {
    foreach (rex_yform_manager_table::getAll() as $table) {
        $tables[$table->getTableName()] = $table->getTableName() . ' (' . $table->getName() . ')';
    }
}

// Felder der gewählten Tabelle laden
$fields = [];
$textFields = [];
if ($selectedTable !== '' && isset($tables[$selectedTable])) {
    $yformTable = rex_yform_manager_table::get($selectedTable);
    if ($yformTable !== null) {
        foreach ($yformTable->getFields() as $field) {
            if ($field->getType() === 'value') {
                $fieldName = $field->getName();
                $fieldLabel = $field->getLabel() !== '' ? $field->getLabel() : $fieldName;
                $fields[$fieldName] = $fieldName . ' (' . $fieldLabel . ' – ' . $field->getTypeName() . ')';

                // Text-artige Felder als Quellfelder
                $textTypes = ['text', 'textarea', 'varchar', 'hashvalue'];
                if (in_array($field->getTypeName(), $textTypes, true) || str_contains($field->getElement('type_name') ?? '', 'text')) {
                    $textFields[$fieldName] = $fieldName . ' (' . $fieldLabel . ')';
                }
            }
        }
    }
}

// --- Slug-Generierung ausführen ---
if (
    $generate === 1
    && rex_request_method() === 'post'
    && $csrfToken->isValid()
    && $selectedTable !== ''
    && $sourceField !== ''
    && $targetField !== ''
    && isset($tables[$selectedTable])
    && isset($fields[$sourceField], $fields[$targetField])
) {
    $sql = rex_sql::factory();
    $tableIdentifier = $sql->escapeIdentifier($selectedTable);

    // Alle Datensätze laden
    if ($mode === 'empty_only') {
        $items = $sql->getArray(
            'SELECT id, ' . $sql->escapeIdentifier($sourceField) . ', ' . $sql->escapeIdentifier($targetField) .
            ' FROM ' . $tableIdentifier .
            ' WHERE ' . $sql->escapeIdentifier($targetField) . ' = "" OR ' . $sql->escapeIdentifier($targetField) . ' IS NULL'
        );
    } else {
        $items = $sql->getArray(
            'SELECT id, ' . $sql->escapeIdentifier($sourceField) . ', ' . $sql->escapeIdentifier($targetField) .
            ' FROM ' . $tableIdentifier
        );
    }

    if (count($items) === 0) {
        echo rex_view::info('Keine Datensätze zum Verarbeiten gefunden.');
    } else {
        // Bestehende Slugs laden für Duplikat-Prüfung
        $existingSlugs = [];
        if ($mode === 'empty_only') {
            $existing = $sql->getArray(
                'SELECT ' . $sql->escapeIdentifier($targetField) . ' FROM ' . $tableIdentifier .
                ' WHERE ' . $sql->escapeIdentifier($targetField) . ' != "" AND ' . $sql->escapeIdentifier($targetField) . ' IS NOT NULL'
            );
            foreach ($existing as $row) {
                $existingSlugs[$row[$targetField]] = true;
            }
        }

        $updated = 0;
        $errors = 0;

        foreach ($items as $item) {
            $source = (string) $item[$sourceField];
            if ($source === '') {
                $errors++;
                continue;
            }

            $baseSlug = rex_string::normalize($source, '-', '_');
            if ($baseSlug === '') {
                $errors++;
                continue;
            }

            // Duplikat-Prüfung: slug, slug-1, slug-2, ...
            $slug = $baseSlug;
            $counter = 1;
            while (isset($existingSlugs[$slug])) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            $existingSlugs[$slug] = true;

            // Slug schreiben
            $update = rex_sql::factory();
            $update->setTable($selectedTable);
            $update->setWhere(['id' => $item['id']]);
            $update->setValue($targetField, $slug);
            try {
                $update->update();
                $updated++;
            } catch (rex_sql_exception $e) {
                $errors++;
            }
        }

        echo rex_view::success($updated . ' Slugs erfolgreich generiert.' . ($errors > 0 ? ' ' . $errors . ' Fehler.' : ''));

        // YForm-Cache leeren
        rex_yform_manager_table::deleteCache();
    }
} elseif ($generate === 1 && rex_request_method() === 'post' && !$csrfToken->isValid()) {
    echo rex_view::error('CSRF-Fehler beim Generieren der Slugs.');
} elseif ($generate === 1 && rex_request_method() === 'post') {
    echo rex_view::error('Bitte Tabelle und gültige Quell-/Zielfelder wählen.');
}

// --- Formular ---
$formContent = '';

$formContent .= '<form method="post" action="' . rex_url::currentBackendPage() . '">';
$formContent .= '<input type="hidden" name="page" value="' . rex_escape(rex_request('page', 'string')) . '">';
if ($csrfName !== '') {
    $formContent .= '<input type="hidden" name="' . rex_escape($csrfName) . '" value="' . rex_escape($csrfValue) . '">';
}

// Schritt 1: Tabelle wählen
$formContent .= '<fieldset><legend>1. Tabelle wählen</legend>';
$formContent .= '<div class="form-group">';
$formContent .= '<label for="slug_table">YForm Tabelle</label>';
$formContent .= '<select name="slug_table" id="slug_table" class="form-control" onchange="this.form.submit()">';
$formContent .= '<option value="">– Tabelle wählen –</option>';
foreach ($tables as $tName => $tLabel) {
    $selected = $selectedTable === $tName ? ' selected' : '';
    $formContent .= '<option value="' . rex_escape($tName) . '"' . $selected . '>' . rex_escape($tLabel) . '</option>';
}
$formContent .= '</select>';
$formContent .= '</div>';
$formContent .= '</fieldset>';

// Schritt 2: Felder wählen (nur wenn Tabelle gewählt)
if ($selectedTable !== '' && count($fields) > 0) {
    $formContent .= '<fieldset><legend>2. Felder konfigurieren</legend>';

    // Quellfeld
    $formContent .= '<div class="form-group">';
    $formContent .= '<label for="slug_source">Quellfeld (z.B. title, name)</label>';
    $formContent .= '<select name="slug_source" id="slug_source" class="form-control">';
    $formContent .= '<option value="">– Quellfeld wählen –</option>';
    foreach ($textFields as $fName => $fLabel) {
        $selected = $sourceField === $fName ? ' selected' : '';
        $formContent .= '<option value="' . rex_escape($fName) . '"' . $selected . '>' . rex_escape($fLabel) . '</option>';
    }
    $formContent .= '</select>';
    $formContent .= '<p class="help-block">Aus diesem Feld wird der Slug generiert (z.B. "Mein Titel" → "mein-titel")</p>';
    $formContent .= '</div>';

    // Zielfeld
    $formContent .= '<div class="form-group">';
    $formContent .= '<label for="slug_target">Zielfeld (Slug-Feld)</label>';
    $formContent .= '<select name="slug_target" id="slug_target" class="form-control">';
    $formContent .= '<option value="">– Zielfeld wählen –</option>';
    foreach ($fields as $fName => $fLabel) {
        $selected = $targetField === $fName ? ' selected' : '';
        $formContent .= '<option value="' . rex_escape($fName) . '"' . $selected . '>' . rex_escape($fLabel) . '</option>';
    }
    $formContent .= '</select>';
    $formContent .= '<p class="help-block">In dieses Feld wird der generierte Slug geschrieben</p>';
    $formContent .= '</div>';

    // Modus
    $formContent .= '<div class="form-group">';
    $formContent .= '<label>Modus</label>';
    $formContent .= '<div class="radio"><label><input type="radio" name="slug_mode" value="empty_only"' . ($mode === 'empty_only' ? ' checked' : '') . '> Nur leere Felder füllen (bestehende Slugs behalten)</label></div>';
    $formContent .= '<div class="radio"><label><input type="radio" name="slug_mode" value="overwrite"' . ($mode === 'overwrite' ? ' checked' : '') . '> Alle Slugs neu generieren (überschreibt bestehende!)</label></div>';
    $formContent .= '</div>';

    $formContent .= '</fieldset>';

    // Vorschau: erste 10 Datensätze
    if ($sourceField !== '' && $targetField !== '') {
        $previewSql = rex_sql::factory();
        $previewTable = $previewSql->escapeIdentifier($selectedTable);
        $previewItems = $previewSql->getArray(
            'SELECT id, ' . $previewSql->escapeIdentifier($sourceField) . ', ' . $previewSql->escapeIdentifier($targetField) .
            ' FROM ' . $previewTable . ' LIMIT 10'
        );

        if (count($previewItems) > 0) {
            $formContent .= '<fieldset><legend>Vorschau (erste 10 Einträge)</legend>';
            $formContent .= '<table class="table table-condensed table-striped">';
            $formContent .= '<thead><tr><th>ID</th><th>Quelle (' . rex_escape($sourceField) . ')</th><th>Aktueller Slug</th><th>Neuer Slug</th></tr></thead>';
            $formContent .= '<tbody>';

            $previewSlugs = [];
            foreach ($previewItems as $pItem) {
                $source = (string) $pItem[$sourceField];
                $currentSlug = (string) $pItem[$targetField];
                $newSlug = $source !== '' ? rex_string::normalize($source, '-', '_') : '<em class="text-danger">leer</em>';

                // Duplikat-Markierung in Vorschau
                if ($source !== '' && isset($previewSlugs[$newSlug])) {
                    $counter = 1;
                    while (isset($previewSlugs[$newSlug . '-' . $counter])) {
                        $counter++;
                    }
                    $newSlug = $newSlug . '-' . $counter;
                }
                if ($source !== '') {
                    $previewSlugs[$newSlug] = true;
                }

                $willUpdate = ($mode === 'overwrite' || $currentSlug === '') ? true : false;
                $rowClass = $willUpdate ? '' : ' class="text-muted"';

                $formContent .= '<tr' . $rowClass . '>';
                $formContent .= '<td>' . (int) $pItem['id'] . '</td>';
                $formContent .= '<td>' . rex_escape($source) . '</td>';
                $formContent .= '<td><code>' . ($currentSlug !== '' ? rex_escape($currentSlug) : '<em>leer</em>') . '</code></td>';
                $formContent .= '<td>' . ($willUpdate ? '<code><strong>' . $newSlug . '</strong></code>' : '<small>wird übersprungen</small>') . '</td>';
                $formContent .= '</tr>';
            }

            $formContent .= '</tbody></table>';
            $formContent .= '</fieldset>';
        }
    }

    // Generate-Button
    $formContent .= '<button type="submit" name="slug_generate" value="1" class="btn btn-primary" onclick="return confirm(\'Slugs jetzt generieren?\')"><i class="rex-icon fa-cogs"></i> Slugs generieren</button>';
}

$formContent .= '</form>';

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Slug-Generator für bestehende Datensätze', false);
$fragment->setVar('body', $formContent, false);
echo $fragment->parse('core/page/section.php');

// Info-Box
$infoContent = '
<p>Der Slug-Generator erzeugt URL-freundliche Slugs aus einem Quellfeld (z.B. Titel) und schreibt sie in ein Zielfeld.</p>
<ul>
    <li><strong>Normalisierung:</strong> Umlaute, Sonderzeichen und Leerzeichen werden automatisch umgewandelt</li>
    <li><strong>Duplikate:</strong> Bei gleichen Slugs wird automatisch ein Zähler angehängt (-1, -2, ...)</li>
    <li><strong>Modus "Nur leere":</strong> Bestehende Slugs bleiben erhalten, nur leere Felder werden gefüllt</li>
    <li><strong>Modus "Alle":</strong> Alle Slugs werden neu generiert — Vorsicht, ändert bestehende URLs!</li>
</ul>
<p class="text-danger"><strong>Hinweis:</strong> Nach der Generierung sollte der YRewrite-Cache geleert werden, damit die neuen URLs wirksam werden.</p>
';

$fragment = new rex_fragment();
$fragment->setVar('class', 'info', false);
$fragment->setVar('title', 'Hinweise', false);
$fragment->setVar('body', $infoContent, false);
echo $fragment->parse('core/page/section.php');
