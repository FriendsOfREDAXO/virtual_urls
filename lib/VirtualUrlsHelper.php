<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_clang;
use rex_escape;
use rex_sql;
use rex_string;
use rex_yform_manager_dataset;
use rex_yform_manager_table;

/**
 * Helper-Klasse zum Erzeugen von virtuellen URLs und Links.
 *
 * Nutzung in Modulen/Templates:
 *   $url = VirtualUrlsHelper::getUrl('rex_news', 42);
 *   $link = VirtualUrlsHelper::getLink('rex_news', 42, 'Zum Artikel');
 *   $urls = VirtualUrlsHelper::getUrlList('rex_news', 'status = 1');
 */
class VirtualUrlsHelper
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $profileCache = null;

    /**
     * Erzeugt die vollständige URL für einen Datensatz.
     *
     * @param string $table YForm-Tabellenname, z.B. 'rex_news'
     * @param int $datasetId ID des Datensatzes
     * @param int $clangId Sprach-ID (Standard: aktuelle Sprache)
     * @return string|null Die URL oder null wenn nicht auflösbar
     */
    public static function getUrl(string $table, int $datasetId, int $clangId = -1): ?string
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        $profile = self::getProfileByTable($table, $clangId);
        if ($profile === null) {
            return null;
        }

        $dataset = rex_yform_manager_dataset::get($datasetId, $table);
        if ($dataset === null) {
            return null;
        }

        return self::buildUrl($profile, $dataset, $clangId);
    }

    /**
     * Erzeugt die vollständige URL für ein YForm-Dataset-Objekt.
     *
     * @param rex_yform_manager_dataset $dataset Das Dataset-Objekt
     * @param int $clangId Sprach-ID (Standard: aktuelle Sprache)
     * @return string|null Die URL oder null wenn nicht auflösbar
     */
    public static function getUrlByDataset(rex_yform_manager_dataset $dataset, int $clangId = -1): ?string
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        $table = $dataset->getTableName();
        $profile = self::getProfileByTable($table, $clangId);
        if ($profile === null) {
            return null;
        }

        return self::buildUrl($profile, $dataset, $clangId);
    }

    /**
     * Erzeugt einen HTML-Link für einen Datensatz.
     *
     * @param string $table YForm-Tabellenname
     * @param int $datasetId ID des Datensatzes
     * @param string $label Link-Text (wenn leer, wird der Slug verwendet)
     * @param array<string, string> $attributes Weitere HTML-Attribute
     * @param int $clangId Sprach-ID
     * @return string HTML-Link oder leerer String
     */
    public static function getLink(string $table, int $datasetId, string $label = '', array $attributes = [], int $clangId = -1): string
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        $url = self::getUrl($table, $datasetId, $clangId);
        if ($url === null) {
            return '';
        }

        if ($label === '') {
            $dataset = rex_yform_manager_dataset::get($datasetId, $table);
            $profile = self::getProfileByTable($table, $clangId);
            $label = $dataset !== null && $profile !== null ? $dataset->getValue($profile['url_field']) : $url;
        }

        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= ' ' . rex_escape($key) . '="' . rex_escape($value) . '"';
        }

        return '<a href="' . rex_escape($url) . '"' . $attrs . '>' . rex_escape($label) . '</a>';
    }

    /**
     * Erzeugt eine Liste aller URLs für eine Tabelle.
     *
     * @param string $table YForm-Tabellenname
     * @param string $where Optionale SQL WHERE-Klausel (z.B. 'status = 1')
     * @param string $orderBy Optionale Sortierung (z.B. 'name ASC')
     * @param int $clangId Sprach-ID
     * @return list<array{id: int, url: string, slug: string, dataset: rex_yform_manager_dataset}> Liste mit URL-Daten
     */
    public static function getUrlList(string $table, string $where = '', string $orderBy = '', int $clangId = -1): array
    {
        if ($table === '') {
            return [];
        }

        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        $profile = self::getProfileByTable($table, $clangId);
        if ($profile === null) {
            return [];
        }

        // Nur YForm-Manager-Tabellen sind erlaubt
        if (rex_yform_manager_table::get($table) === null) {
            return [];
        }

        $query = rex_yform_manager_dataset::query($table);
        if ($where !== '' && self::isSafeWhereClause($where)) {
            $query->whereRaw($where);
        }
        if ($orderBy !== '') {
            self::applyOrderBy($query, $orderBy);
        }

        $result = [];
        foreach ($query->find() as $dataset) {
            $url = self::buildUrl($profile, $dataset, $clangId);
            $slug = self::buildSlugSegment($dataset, (string) $profile['url_field']);
            if ($url !== null) {
                $result[] = [
                    'id' => $dataset->getId(),
                    'url' => $url,
                    'slug' => $slug ?? '',
                    'dataset' => $dataset,
                ];
            }
        }

        return $result;
    }

    /**
     * Prüft ob eine URL von einem Profil aufgelöst werden kann.
     *
     * @param string $url Die URL zum Testen (z.B. '/news/sport/mein-artikel')
     * @param string|null $domainName Domain-Name (null = alle Domains prüfen)
     * @return array{resolved: bool, profile: ?array<string, mixed>, dataset: ?rex_yform_manager_dataset, article_id: ?int, relation_id: ?int, message: string}
     */
    public static function testUrl(string $url, ?string $domainName = null): array
    {
        $url = trim($url, '/');
        $segments = explode('/', $url);

        $profiles = self::getAllProfiles();

        foreach ($profiles as $profile) {
            // Domain-Filter
            if ($domainName !== null && $profile['domain'] !== '' && $profile['domain'] !== $domainName) {
                continue;
            }

            $trigger = $profile['trigger_segment'];
            $hasRelation = self::profileHasRelation($profile);
            $triggerIndex = array_search($trigger, $segments);

            if ($triggerIndex === false) {
                continue;
            }

            if ($hasRelation) {
                if (!isset($segments[$triggerIndex + 1], $segments[$triggerIndex + 2])) {
                    continue;
                }
                if (count($segments) > $triggerIndex + 3) {
                    continue;
                }

                $relationSlug = $segments[$triggerIndex + 1];
                $slug = $segments[$triggerIndex + 2];

                // Resolve relation
                $relationId = self::resolveRelationSlugPublic(
                    $profile['relation_table'],
                    $profile['relation_slug_field'],
                    $relationSlug
                );

                if ($relationId === null) {
                    return [
                        'resolved' => false,
                        'profile' => $profile,
                        'dataset' => null,
                        'article_id' => null,
                        'relation_id' => null,
                        'message' => 'Relation-Slug "' . $relationSlug . '" konnte in ' . $profile['relation_table'] . '.' . $profile['relation_slug_field'] . ' nicht aufgelöst werden.',
                    ];
                }

                $dataset = self::findDatasetByRequestedSlug(
                    (string) $profile['table_name'],
                    (string) $profile['url_field'],
                    $slug,
                    (string) $profile['relation_field'],
                    $relationId
                );

                if ($dataset === null) {
                    return [
                        'resolved' => false,
                        'profile' => $profile,
                        'dataset' => null,
                        'article_id' => (int) $profile['article_id'],
                        'relation_id' => $relationId,
                        'message' => 'Datensatz mit ' . $profile['url_field'] . '="' . $slug . '" und ' . $profile['relation_field'] . '=' . $relationId . ' nicht gefunden in ' . $profile['table_name'] . '.',
                    ];
                }

                return [
                    'resolved' => true,
                    'profile' => $profile,
                    'dataset' => $dataset,
                    'article_id' => (int) $profile['article_id'],
                    'relation_id' => $relationId,
                    'message' => 'URL aufgelöst → Tabelle: ' . $profile['table_name'] . ', ID: ' . $dataset->getId() . ', Artikel: ' . $profile['article_id'] . ', Relation: ' . $relationId,
                ];
            }

            // Ohne Relation
            if (!isset($segments[$triggerIndex + 1])) {
                continue;
            }
            if (count($segments) > $triggerIndex + 2) {
                continue;
            }

            $slug = $segments[$triggerIndex + 1];
            $dataset = self::findDatasetByRequestedSlug(
                (string) $profile['table_name'],
                (string) $profile['url_field'],
                $slug
            );

            if ($dataset === null) {
                return [
                    'resolved' => false,
                    'profile' => $profile,
                    'dataset' => null,
                    'article_id' => (int) $profile['article_id'],
                    'relation_id' => null,
                    'message' => 'Datensatz mit ' . $profile['url_field'] . '="' . $slug . '" nicht gefunden in ' . $profile['table_name'] . '.',
                ];
            }

            return [
                'resolved' => true,
                'profile' => $profile,
                'dataset' => $dataset,
                'article_id' => (int) $profile['article_id'],
                'relation_id' => null,
                'message' => 'URL aufgelöst → Tabelle: ' . $profile['table_name'] . ', ID: ' . $dataset->getId() . ', Artikel: ' . $profile['article_id'],
            ];
        }

        return [
            'resolved' => false,
            'profile' => null,
            'dataset' => null,
            'article_id' => null,
            'relation_id' => null,
            'message' => 'Kein passendes Profil für diese URL gefunden.',
        ];
    }

    /**
     * Gibt alle registrierten Profile zurück.
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllProfiles(): array
    {
        $sql = rex_sql::factory();
        return $sql->getArray('SELECT * FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE status = 1');
    }

    /**
     * Gibt das Profil für eine bestimmte Tabelle und Sprache zurück.
     *
     * Präferiert ein sprachspezifisches Profil, fällt auf "Alle Sprachen" zurück.
     *
     * @return array<string, mixed>|null
     */
    public static function getProfileByTable(string $table, int $clangId = -1): ?array
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        if (self::$profileCache === null) {
            self::$profileCache = [];
            foreach (self::getAllProfiles() as $profile) {
                $key = $profile['table_name'] . '|' . (int) ($profile['clang_id'] ?? -1);
                self::$profileCache[$key] = $profile;
            }
        }

        // Erst sprachspezifisches Profil, dann Fallback auf "Alle"
        return self::$profileCache[$table . '|' . $clangId]
            ?? self::$profileCache[$table . '|-1']
            ?? null;
    }

    /**
     * Cache zurücksetzen (z.B. nach Profil-Änderung).
     */
    public static function clearCache(): void
    {
        self::$profileCache = null;
    }

    /**
     * Baut die URL für ein Dataset anhand eines Profils.
     */
    private static function buildUrl(array $profile, rex_yform_manager_dataset $dataset, int $clangId = -1): ?string
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }

        $articleId = (int) $profile['article_id'];
        if ($articleId <= 0) {
            return null;
        }

        // Basis-URL des Renderer-Artikels
        $articleUrl = rex_getUrl($articleId, $clangId);
        $baseUrl = rtrim($articleUrl, '/');

        $slug = self::buildSlugSegment($dataset, (string) $profile['url_field']);
        if ($slug === '') {
            return null;
        }

        $hasRelation = self::profileHasRelation($profile);

        if ($hasRelation) {
            $relationId = (int) $dataset->getValue($profile['relation_field']);
            $relationSlug = self::getRelationSlugById(
                $profile['relation_table'],
                $profile['relation_slug_field'],
                $relationId
            );

            if ($relationSlug === null) {
                return null;
            }

            return $baseUrl . '/' . $profile['trigger_segment'] . '/' . $relationSlug . '/' . $slug;
        }

        return $baseUrl . '/' . $profile['trigger_segment'] . '/' . $slug;
    }

    /**
     * Prüft ob ein Profil eine Relation konfiguriert hat.
     *
     * @param array<string, mixed> $profile
     */
    private static function profileHasRelation(array $profile): bool
    {
        return trim($profile['relation_field'] ?? '') !== ''
            && trim($profile['relation_table'] ?? '') !== ''
            && trim($profile['relation_slug_field'] ?? '') !== '';
    }

    /**
     * Gibt den normalisierten Slug für eine Relation-ID zurück.
     */
    private static function getRelationSlugById(string $table, string $slugField, int $id): ?string
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT ' . $sql->escapeIdentifier($slugField) . ' FROM ' . $sql->escapeIdentifier($table) . ' WHERE id = :id',
            ['id' => $id]
        );

        if (count($rows) === 0) {
            return null;
        }

        $value = (string) $rows[0][$slugField];
        $id = $id;
        return self::buildNormalizedSlug($value, $id);
    }

    /**
     * Öffentliche Variante von resolveRelationSlug für den URL-Tester.
     */
    private static function resolveRelationSlugPublic(string $table, string $slugField, string $slug): ?int
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT id, ' . $sql->escapeIdentifier($slugField) . ' FROM ' . $sql->escapeIdentifier($table)
        );

        foreach ($rows as $row) {
            $normalized = self::buildNormalizedSlug((string) $row[$slugField], (int) $row['id']);
            if ($normalized === $slug) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    public static function buildSlugSegment(rex_yform_manager_dataset $dataset, string $field): ?string
    {
        $value = (string) $dataset->getValue($field);
        return self::buildNormalizedSlug($value, $dataset->getId());
    }

    private static function buildNormalizedSlug(string $value, int $id): ?string
    {
        $normalized = rex_string::normalize($value, '-', '_');
        if ($normalized === '') {
            return null;
        }

        // If source value is not slug-like, append ID to keep URLs collision-safe.
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $value) !== 1) {
            return $normalized . '-' . $id;
        }

        return $normalized;
    }

    private static function findDatasetByRequestedSlug(
        string $table,
        string $field,
        string $requestedSlug,
        ?string $relationField = null,
        ?int $relationId = null
    ): ?rex_yform_manager_dataset {
        $query = rex_yform_manager_dataset::query($table)->where($field, $requestedSlug);
        if ($relationField !== null && $relationField !== '' && $relationId !== null) {
            $query->where($relationField, $relationId);
        }

        $dataset = $query->findOne();
        if ($dataset !== null) {
            return $dataset;
        }

        if (preg_match('/^(.*)-([0-9]+)$/', $requestedSlug, $matches) !== 1) {
            return null;
        }

        $baseSlug = (string) $matches[1];
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

        $expectedSlug = self::buildSlugSegment($dataset, $field);
        if ($expectedSlug === null) {
            return null;
        }

        if ($expectedSlug !== $requestedSlug && $expectedSlug !== $baseSlug . '-' . $datasetId) {
            return null;
        }

        return $dataset;
    }

    private static function applyOrderBy($query, string $orderBy): void
    {
        if (preg_match('/^([a-zA-Z0-9_]+)(?:\s+(ASC|DESC))?$/i', trim($orderBy), $matches) === 1) {
            $direction = strtoupper($matches[2] ?? 'ASC');
            $query->orderBy($matches[1], $direction === 'DESC' ? 'DESC' : 'ASC');
        }
    }

    private static function isSafeWhereClause(string $where): bool
    {
        if (preg_match('/(;|--|\/\*|\*\/)/', $where) === 1) {
            return false;
        }

        if (preg_match('/\b(UNION|INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|REVOKE)\b/i', $where) === 1) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9_\s\(\)\'"\.=<>!%:+\-\/,&|]+$/', $where) === 1;
    }
}
