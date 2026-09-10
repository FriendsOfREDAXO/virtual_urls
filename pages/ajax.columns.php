<?php

$table = rex_request('table', 'string', '');
$csrfToken = rex_csrf_token::factory('virtual_urls_profiles_actions');

if ($table === '' || !$csrfToken->isValid() || rex_yform_manager_table::get($table) === null) {
    rex_response::sendJson([]);
    exit;
}

$sql = rex_sql::factory();
$columns = [];
$relationFields = [];
$langFields = [];

// Feldtypen, aus denen yform_lang_fields (falls installiert) mehrsprachige
// JSON-Werte pro clang_id speichert. Wird genutzt, um im Profil-Formular einen
// Hinweis zu geben, dass für ein solches Feld als url_field/relation_slug_field
// kein eigenes Slug-Feld benötigt wird - Virtual Urls normalisiert den Wert der
// aktuellen Sprache automatisch.
$langFieldTypes = ['lang_text', 'lang_textarea', 'lang_media'];

try {
    $result = $sql->getArray('SHOW COLUMNS FROM ' . $sql->escapeIdentifier($table));
    foreach ($result as $row) {
        $columns[] = $row['Field'];
    }

    $tableObject = rex_yform_manager_table::get($table);
    if ($tableObject !== null) {
        foreach ($tableObject->getFields() as $field) {
            if ($field->getType() !== 'value') {
                continue;
            }

            $typeName = (string) $field->getTypeName();

            if (in_array($typeName, $langFieldTypes, true)) {
                $langFields[] = (string) $field->getName();
            }

            if (!in_array($typeName, ['be_manager_relation', 'relation_select'], true)) {
                continue;
            }

            $relationTable = trim((string) $field->getElement('table'));
            if ($relationTable === '') {
                $relationTable = trim((string) $field->getElement('relation_table'));
            }

            if ($relationTable !== '' && rex_yform_manager_table::get($relationTable) !== null) {
                $relationFields[(string) $field->getName()] = $relationTable;
            }
        }
    }
} catch (rex_sql_exception $e) {
    // Table might not exist
}

rex_response::sendJson([
    'columns' => $columns,
    'relation_fields' => $relationFields,
    'lang_fields' => $langFields,
]);
exit;
