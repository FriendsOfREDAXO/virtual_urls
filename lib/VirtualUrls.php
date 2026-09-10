<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_clang;
use rex_extension;
use rex_extension_point;
use rex_sql;
use rex_string;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yrewrite;
use rex_yrewrite_domain;

class VirtualUrls
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $profilesCache = [];

    /** @var array<string, array<string, int>> */
    private static array $relationSlugCache = [];

    /**
     * Handle virtual URL resolution via YREWRITE_PREPARE EP.
     *
     * @param rex_extension_point $ep
     * @return array{article_id: int, clang?: int}|null
     */
    public static function handle(rex_extension_point $ep): ?array
    {
        $url = $ep->getParam('url');
        $domain = $ep->getParam('domain');

        if (!is_object($domain) || !method_exists($domain, 'getName')) {
            return null;
        }

        $url = trim($url, '/');
        $segments = explode('/', $url);

        // Get profiles matching the current domain and language
        $clangId = rex_clang::getCurrentId();
        $profiles = self::getProfilesForDomainAndClang($domain->getName(), $clangId);

        foreach ($profiles as $profile) {
            if (!self::isValidProfile($profile)) {
                continue;
            }

            $trigger = $profile['trigger_segment'];
            $hasRelation = trim($profile['relation_field'] ?? '') !== '' 
                && trim($profile['relation_table'] ?? '') !== '' 
                && trim($profile['relation_slug_field'] ?? '') !== '';

            // Find trigger in segments
            $triggerIndex = array_search($trigger, $segments);

            if ($triggerIndex === false) {
                continue;
            }

            if ($hasRelation) {
                // URL: /<path>/<trigger>/<relation-slug>/<item-slug>
                if (!isset($segments[$triggerIndex + 1], $segments[$triggerIndex + 2])) {
                    continue;
                }
                // Ensure the path ends here
                if (count($segments) > $triggerIndex + 3) {
                    continue;
                }

                $relationSlug = $segments[$triggerIndex + 1];
                $slug = $segments[$triggerIndex + 2];

                // Resolve relation slug to ID
                $relationId = self::resolveRelationSlug(
                    $profile['relation_table'],
                    $profile['relation_slug_field'],
                    $relationSlug
                );

                if ($relationId === null) {
                    continue;
                }

                // Find dataset matching slug AND relation
                $table = $profile['table_name'];
                $field = $profile['url_field'];

                $dataset = self::findDatasetByRequestedSlug(
                    $table,
                    $field,
                    $slug,
                    (string) $profile['relation_field'],
                    $relationId,
                    $profile
                );
            } else {
                // URL: /<path>/<trigger>/<item-slug> (ohne Relation)
                if (!isset($segments[$triggerIndex + 1])) {
                    continue;
                }
                // Ensure the path ends here
                if (count($segments) > $triggerIndex + 2) {
                    continue;
                }

                $slug = $segments[$triggerIndex + 1];

                $table = $profile['table_name'];
                $field = $profile['url_field'];

                $dataset = self::findDatasetByRequestedSlug($table, $field, $slug, null, null, $profile);
            }

            if ($dataset) {
                // Match found!

                // Determine the article_id to render
                $articleId = (int) $profile['article_id'];

                // Try to resolve the path UP TO the trigger to a real article
                $checkPath = implode('/', array_slice($segments, 0, $triggerIndex + 1));
                $mountId = self::getArticleIdByPath($checkPath, $domain);

                if ($mountId) {
                    $articleId = $mountId;
                }

                // Store data for usage in module/template
                rex::setProperty('virtual_urls.data', $dataset);
                rex::setProperty('virtual_urls.profile', $profile);

                // Determine clang from profile
                $clang = (int) ($profile['clang_id'] ?? -1);

                // Return article_id (and clang if set) to YRewrite's path resolver
                $result = ['article_id' => $articleId];
                if ($clang >= 0) {
                    $result['clang'] = $clang;
                }

                /**
                 * Fired after ein Request erfolgreich auf einen Datensatz aufgelöst wurde,
                 * bevor das Ergebnis an YRewrite zurückgegeben wird. Zum reinen Reagieren
                 * gedacht (Logging, Tracking, zusätzliches Caching) - das Subject kann
                 * verändert zurückgegeben werden, wird aber von YRewrite selbst nicht
                 * validiert.
                 *
                 * Subject: array{article_id: int, clang?: int}
                 * Params: dataset, profile, domain
                 */
                $result = rex_extension::registerPoint(new rex_extension_point(
                    'VIRTUAL_URLS_RESOLVED',
                    $result,
                    ['dataset' => $dataset, 'profile' => $profile, 'domain' => $domain],
                ));

                return $result;
            }
        }

        // Keine URL passt zu einem aktuellen Datensatz - prüfen, ob es sich um
        // einen früher gültigen (inzwischen geänderten) Slug handelt und ggf.
        // per 301 auf die aktuelle URL weiterleiten.
        VirtualUrlsRedirects::redirectIfOldSlug($profiles, $segments, $clangId);

        return null;
    }
    
    /**
     * Resolve a relation slug to its ID.
     *
     * Compares the normalized slug field value from the relation table
     * against the URL segment.
     */
    private static function resolveRelationSlug(string $table, string $slugField, string $slug): ?int
    {
        $cacheKey = $table . '|' . $slugField;
        if (!isset(self::$relationSlugCache[$cacheKey])) {
            self::$relationSlugCache[$cacheKey] = [];
            $sql = rex_sql::factory();
            $rows = $sql->getArray(
                'SELECT id, ' . $sql->escapeIdentifier($slugField) . ' FROM ' . $sql->escapeIdentifier($table)
            );

            foreach ($rows as $row) {
                $normalized = self::buildNormalizedSlug(VirtualUrlsHelper::resolveRawValue($row[$slugField]), (int) $row['id']);
                if ($normalized !== '') {
                    self::$relationSlugCache[$cacheKey][$normalized] = (int) $row['id'];
                }
            }
        }

        return self::$relationSlugCache[$cacheKey][$slug] ?? null;
    }

    /**
     * @param rex_yrewrite_domain|null $domain
     */
    private static function getArticleIdByPath(string $path, $domain = null): ?int
    {
        if (!class_exists('rex_yrewrite')) {
            return null;
        }

        if (!$domain) {
            $domain = rex_yrewrite::getCurrentDomain();
        }
        if (!$domain) {
            return null;
        }

        $url = trim($path, '/') . '/';
        $result = rex_yrewrite::getArticleIdByUrl($domain, $url);

        if ($result !== false && is_array($result)) {
            return (int) array_key_first($result);
        }

        return null;
    }

    public static function getCurrentData()
    {
        return rex::getProperty('virtual_urls.data');
    }

    public static function getCurrentProfile()
    {
        return rex::getProperty('virtual_urls.profile');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function getProfilesForDomainAndClang(string $domainName, int $clangId): array
    {
        $cacheKey = $domainName . '|' . $clangId;
        if (!isset(self::$profilesCache[$cacheKey])) {
            $sql = rex_sql::factory();
            self::$profilesCache[$cacheKey] = $sql->getArray(
                'SELECT * FROM ' . rex::getTable('virtual_urls_profiles')
                . ' WHERE status = 1 AND (domain = :domain OR domain = :empty) AND (clang_id = :clang OR clang_id = -1)',
                ['domain' => $domainName, 'empty' => '', 'clang' => $clangId]
            );
        }

        return self::$profilesCache[$cacheKey];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function isValidProfile(array $profile): bool
    {
        $table = (string) ($profile['table_name'] ?? '');
        $urlField = (string) ($profile['url_field'] ?? '');

        if ($table === '' || $urlField === '') {
            return false;
        }

        $tableObject = rex_yform_manager_table::get($table);
        if ($tableObject === null) {
            return false;
        }

        $fieldNames = [];
        foreach ($tableObject->getFields() as $field) {
            $fieldNames[$field->getName()] = true;
        }

        if (!isset($fieldNames[$urlField])) {
            return false;
        }

        $statusField = (string) ($profile['status_field'] ?? '');
        if ($statusField !== '' && !isset($fieldNames[$statusField])) {
            return false;
        }

        $relationField = (string) ($profile['relation_field'] ?? '');
        $relationTable = (string) ($profile['relation_table'] ?? '');
        $relationSlugField = (string) ($profile['relation_slug_field'] ?? '');

        $hasRelation = $relationField !== '' || $relationTable !== '' || $relationSlugField !== '';
        if (!$hasRelation) {
            return true;
        }

        if ($relationField === '' || $relationTable === '' || $relationSlugField === '') {
            return false;
        }

        if (!isset($fieldNames[$relationField])) {
            return false;
        }

        $relationTableObject = rex_yform_manager_table::get($relationTable);
        if ($relationTableObject === null) {
            return false;
        }

        foreach ($relationTableObject->getFields() as $field) {
            if ($field->getName() === $relationSlugField) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function findDatasetByRequestedSlug(
        string $table,
        string $field,
        string $requestedSlug,
        ?string $relationField = null,
        ?int $relationId = null,
        array $profile = []
    ): ?rex_yform_manager_dataset {
        $query = rex_yform_manager_dataset::query($table)->where($field, $requestedSlug);
        if ($relationField !== null && $relationField !== '' && $relationId !== null) {
            $query->where($relationField, $relationId);
        }

        $statusField = trim((string) ($profile['status_field'] ?? ''));
        if ($statusField !== '') {
            $statusValue = (string) ($profile['status_value'] ?? '1');
            $query->where($statusField, $statusValue);
        }

        /**
         * Erlaubt Drittanbieter-Code, zusätzliche Einschränkungen auf die
         * Lookup-Query anzuwenden (z.B. Online-Status, Embargo-Datum,
         * Mandanten-Filter), bevor der Datensatz zur URL aufgelöst wird.
         *
         * Hinweis: Betrifft nur den regulären Slug-Lookup. Der Fallback über
         * das numerische "-<id>"-Suffix weiter unten fragt den Datensatz
         * direkt per ID ab und durchläuft diese Query (und damit auch diesen
         * EP) nicht - das Status-Feld wird dort separat geprüft, individuelle
         * Einschränkungen über diesen EP jedoch nicht.
         *
         * Subject: rex_yform_manager_query
         * Params: table, field, slug, profile
         */
        $query = rex_extension::registerPoint(new rex_extension_point(
            'VIRTUAL_URLS_PROFILE_QUERY',
            $query,
            ['table' => $table, 'field' => $field, 'slug' => $requestedSlug, 'profile' => $profile],
        ));

        $dataset = $query->findOne();
        if ($dataset !== null) {
            return $dataset;
        }

        if (preg_match('/^(.*)-([0-9]+)$/', $requestedSlug, $matches) !== 1) {
            return null;
        }

        $datasetId = (int) $matches[2];
        if ($datasetId <= 0) {
            return null;
        }

        $dataset = rex_yform_manager_dataset::get($datasetId, $table);
        if ($dataset === null) {
            return null;
        }

        if ($relationField !== null && $relationField !== '' && $relationId !== null) {
            if ((int) $dataset->getValue($relationField) !== $relationId) {
                return null;
            }
        }

        if ($statusField !== '' && (string) $dataset->getValue($statusField) !== $statusValue) {
            return null;
        }

        $expectedSlug = self::buildNormalizedSlug(VirtualUrlsHelper::resolveFieldValue($dataset, $field), $dataset->getId());
        if ($expectedSlug === '' || $expectedSlug !== $requestedSlug) {
            return null;
        }

        return $dataset;
    }

    private static function buildNormalizedSlug(string $value, int $id): string
    {
        $normalized = rex_string::normalize($value, '-', '_');
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) !== 1) {
            return $normalized . '-' . $id;
        }

        return $normalized;
    }
}
