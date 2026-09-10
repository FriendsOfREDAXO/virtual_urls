<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_response;
use rex_sql;
use rex_yform_manager_dataset;

/**
 * Verwaltet die Historie alter, normalisierter Slugs und leitet Requests auf
 * eine mittlerweile geänderte URL per 301 auf die aktuelle URL weiter.
 *
 * Ohne diese Historie würde eine URL, die sich durch einen geänderten
 * Titel/Slug-Wert ändert, für alle bestehenden Links/Backlinks/Suchmaschinen-
 * Einträge einfach ins Leere laufen (404) statt weiterzuleiten.
 */
class VirtualUrlsRedirects
{
    public static function init(): void
    {
        rex_extension::register('YFORM_DATA_UPDATED', [self::class, 'recordOldSlug']);
    }

    /**
     * Trägt den alten Slug-Wert eines geänderten Datensatzes in die Historie
     * ein, sofern sich der normalisierte Slug durch das Update geändert hat
     * und die Tabelle in mindestens einem Profil als url_field/table_name
     * verwendet wird.
     */
    public static function recordOldSlug(rex_extension_point $ep): void
    {
        $tableObj = $ep->getParam('table');
        if (!is_object($tableObj) || !method_exists($tableObj, 'getTableName')) {
            return;
        }
        $tableName = (string) $tableObj->getTableName();

        $dataset = $ep->getParam('data');
        $oldData = $ep->getParam('old_data');
        if (!$dataset instanceof rex_yform_manager_dataset || !is_array($oldData)) {
            return;
        }

        $sql = rex_sql::factory();
        $profiles = $sql->getArray(
            'SELECT * FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE table_name = :table AND status = 1',
            ['table' => $tableName],
        );

        foreach ($profiles as $profile) {
            self::recordOldSlugForProfile($profile, $dataset, $oldData);
        }
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $oldData
     */
    private static function recordOldSlugForProfile(array $profile, rex_yform_manager_dataset $dataset, array $oldData): void
    {
        $urlField = (string) ($profile['url_field'] ?? '');
        if ($urlField === '' || !array_key_exists($urlField, $oldData)) {
            return;
        }

        $oldValue = VirtualUrlsHelper::resolveRawValue($oldData[$urlField]);
        $newValue = VirtualUrlsHelper::resolveFieldValue($dataset, $urlField);

        $oldSlug = VirtualUrlsHelper::normalizeSlug($oldValue, $dataset->getId());
        $newSlug = VirtualUrlsHelper::normalizeSlug($newValue, $dataset->getId());

        if ('' === $oldSlug || $oldSlug === $newSlug) {
            return;
        }

        $relationSlug = self::resolveRelationSlugForProfile($profile, $dataset);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('virtual_urls_old_slugs'));
        $sql->setValue('table_name', (string) $profile['table_name']);
        $sql->setValue('trigger_segment', (string) $profile['trigger_segment']);
        $sql->setValue('relation_slug', $relationSlug);
        $sql->setValue('old_slug', $oldSlug);
        $sql->setValue('dataset_id', $dataset->getId());
        $sql->setDateTimeValue('createdate', time());
        // Nutzt INSERT ... ON DUPLICATE KEY UPDATE über den Unique-Index
        // (table_name, trigger_segment, relation_slug, old_slug): ein erneut
        // geänderter alter Slug aktualisiert nur die dataset_id, statt einen
        // Duplikatfehler zu werfen.
        $sql->insertOrUpdate();
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function resolveRelationSlugForProfile(array $profile, rex_yform_manager_dataset $dataset): string
    {
        $relationField = trim((string) ($profile['relation_field'] ?? ''));
        $relationTable = trim((string) ($profile['relation_table'] ?? ''));
        $relationSlugField = trim((string) ($profile['relation_slug_field'] ?? ''));

        if ('' === $relationField || '' === $relationTable || '' === $relationSlugField) {
            return '';
        }

        $relationId = (int) $dataset->getValue($relationField);
        if ($relationId <= 0) {
            return '';
        }

        return VirtualUrlsHelper::getRelationSlugById($relationTable, $relationSlugField, $relationId) ?? '';
    }

    /**
     * Prüft, ob eine der übergebenen Requests-Segmente einem früher gültigen
     * Slug eines der Profile entspricht, und leitet bei Treffer per 301 auf
     * die aktuelle URL des Datensatzes weiter (beendet den Request).
     *
     * @param list<array<string, mixed>> $profiles
     * @param list<string> $segments
     */
    public static function redirectIfOldSlug(array $profiles, array $segments, int $clangId): void
    {
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return;
        }

        foreach ($profiles as $profile) {
            $trigger = (string) ($profile['trigger_segment'] ?? '');
            if ('' === $trigger) {
                continue;
            }

            $triggerIndex = array_search($trigger, $segments, true);
            if (false === $triggerIndex) {
                continue;
            }

            $hasRelation = trim((string) ($profile['relation_field'] ?? '')) !== ''
                && trim((string) ($profile['relation_table'] ?? '')) !== ''
                && trim((string) ($profile['relation_slug_field'] ?? '')) !== '';

            if ($hasRelation) {
                if (!isset($segments[$triggerIndex + 1], $segments[$triggerIndex + 2]) || count($segments) > $triggerIndex + 3) {
                    continue;
                }
                $relationSlug = $segments[$triggerIndex + 1];
                $requestedSlug = $segments[$triggerIndex + 2];
            } else {
                if (!isset($segments[$triggerIndex + 1]) || count($segments) > $triggerIndex + 2) {
                    continue;
                }
                $relationSlug = '';
                $requestedSlug = $segments[$triggerIndex + 1];
            }

            $datasetId = self::lookupDatasetId((string) $profile['table_name'], $trigger, $relationSlug, $requestedSlug);
            if (null === $datasetId) {
                continue;
            }

            $dataset = rex_yform_manager_dataset::get($datasetId, (string) $profile['table_name']);
            if (null === $dataset) {
                continue;
            }

            $profileClang = (int) ($profile['clang_id'] ?? -1);
            $targetClang = $profileClang >= 0 ? $profileClang : $clangId;

            $newUrl = VirtualUrlsHelper::getUrlByProfile($profile, $dataset->getId(), $targetClang);
            if (null === $newUrl) {
                continue;
            }

            rex_response::sendRedirect($newUrl, rex_response::HTTP_MOVED_PERMANENTLY);
        }
    }

    private static function lookupDatasetId(string $table, string $trigger, string $relationSlug, string $oldSlug): ?int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT dataset_id FROM ' . rex::getTable('virtual_urls_old_slugs')
            . ' WHERE table_name = :table AND trigger_segment = :trigger AND relation_slug = :relation_slug AND old_slug = :old_slug LIMIT 1',
            [
                'table' => $table,
                'trigger' => $trigger,
                'relation_slug' => $relationSlug,
                'old_slug' => $oldSlug,
            ],
        );

        if ($sql->getRows() === 0) {
            return null;
        }

        return (int) $sql->getValue('dataset_id');
    }
}
