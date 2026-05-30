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
]);
exit;
