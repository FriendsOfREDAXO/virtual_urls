<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_clang;
use rex_escape;
use rex_sql;
use rex_string;
use rex_addon;
use rex_extension_point;
use rex_yform_manager_dataset;
use rex_yform_manager_table;
use rex_yrewrite;

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
    /** @var list<array<string, mixed>>|null aktive Profile, siehe getAllProfiles() */
    private static ?array $profileCache = null;

    /** @var bool|null Cache, ob das AddOn yform_lang_fields installiert & aktiviert ist */
    private static ?bool $langFieldsAvailable = null;

    /**
     * Liest einen Datensatz-Feldwert (YOrm-Dataset) als String, auflösend für die
     * aktuelle (bzw. übergebene) Sprache, falls es sich um ein yform_lang_fields-Feld
     * (lang_text/lang_textarea/lang_media) handelt.
     */
    public static function resolveFieldValue(rex_yform_manager_dataset $dataset, string $field, int $clangId = -1): string
    {
        return self::resolveRawValue($dataset->getValue($field), $clangId);
    }

    /**
     * Wie resolveFieldValue(), aber für einen bereits gelesenen Rohwert, z.B. aus
     * rex_sql::getValue() (Sitemap-Generierung iteriert direkt über SQL-Zeilen,
     * nicht über YOrm-Datasets). Ist yform_lang_fields nicht installiert oder der
     * Wert kein lang-field-JSON, wird der Rohwert unverändert als String
     * zurückgegeben (Bestandsverhalten).
     *
     * @param mixed $raw
     */
    public static function resolveRawValue($raw, int $clangId = -1): string
    {
        if (self::isLangFieldsAvailable() && is_string($raw)) {
            $normalized = \KLXM\YformLangFields\LangHelper::normalizeLanguageData($raw);
            if ([] !== $normalized) {
                if ($clangId < 0) {
                    $clangId = rex_clang::getCurrentId();
                }
                return \KLXM\YformLangFields\LangHelper::getValueForLanguage($raw, $clangId);
            }
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    private static function isLangFieldsAvailable(): bool
    {
        if (null === self::$langFieldsAvailable) {
            self::$langFieldsAvailable = rex_addon::get('yform_lang_fields')->isAvailable()
                && class_exists(\KLXM\YformLangFields\LangHelper::class);
        }

        return self::$langFieldsAvailable;
    }

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
            $label = $dataset !== null && $profile !== null ? self::resolveFieldValue($dataset, $profile['url_field'], $clangId) : $url;
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
     * Gibt alle aktiven Profile zurück.
     *
     * @return list<array<string, mixed>>
     */
    public static function getAllProfiles(): array
    {
        if (self::$profileCache === null) {
            $sql = rex_sql::factory();
            self::$profileCache = $sql->getArray('SELECT * FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE status = 1 ORDER BY id');
        }
        return self::$profileCache;
    }

    /**
     * Gibt ein aktives Profil anhand seiner ID zurück.
     *
     * @return array<string, mixed>|null
     */
    public static function getProfileById(int $id): ?array
    {
        foreach (self::getAllProfiles() as $profile) {
            if ((int) $profile['id'] === $id) {
                return $profile;
            }
        }
        return null;
    }

    /**
     * Gibt alle aktiven Profile einer Tabelle zurück (alle Sprachen und Domains).
     *
     * @return list<array<string, mixed>>
     */
    public static function getProfilesByTable(string $table): array
    {
        $result = [];
        foreach (self::getAllProfiles() as $profile) {
            if ((string) $profile['table_name'] === $table) {
                $result[] = $profile;
            }
        }
        return $result;
    }

    /**
     * Gibt das Profil für eine Tabelle, Sprache und Domain zurück.
     *
     * Reihenfolge: Domain + Sprache, Domain + alle Sprachen, alle Domains +
     * Sprache, alle Domains + alle Sprachen. Ohne Angabe gilt die aktuelle
     * yrewrite-Domain; bei mehreren Profilen pro Tabelle (Multi-Domain)
     * gewinnt damit das passende statt eines zufälligen.
     *
     * @return array<string, mixed>|null
     */
    public static function getProfileByTable(string $table, int $clangId = -1, ?string $domain = null): ?array
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }
        if ($domain === null) {
            $domain = self::getCurrentDomainName();
        }

        $candidates = self::getProfilesByTable($table);
        foreach ([[$domain, $clangId], [$domain, -1], ['', $clangId], ['', -1]] as [$wantDomain, $wantClang]) {
            foreach ($candidates as $profile) {
                if ((string) ($profile['domain'] ?? '') !== $wantDomain) {
                    continue;
                }
                if ((int) ($profile['clang_id'] ?? -1) !== $wantClang) {
                    continue;
                }
                return $profile;
            }
        }

        return null;
    }

    /**
     * Erzeugt die URL eines Datensatzes über ein konkretes Profil.
     *
     * @param array<string, mixed> $profile Profil-Zeile (siehe getProfilesByTable())
     */
    public static function getUrlByProfile(array $profile, int $datasetId, int $clangId = -1): ?string
    {
        if ($clangId < 0) {
            $clangId = rex_clang::getCurrentId();
        }
        $profileClang = (int) ($profile['clang_id'] ?? -1);
        if ($profileClang > 0 && $profileClang !== $clangId) {
            return null;
        }
        $dataset = rex_yform_manager_dataset::get($datasetId, (string) $profile['table_name']);
        if ($dataset === null) {
            return null;
        }
        return self::buildUrl($profile, $dataset, $clangId);
    }

    /**
     * Alle URLs eines Datensatzes über sämtliche passenden Profile der Tabelle.
     *
     * @return list<array{profile: array<string, mixed>, url: string}>
     */
    public static function getUrls(string $table, int $datasetId, int $clangId = -1): array
    {
        $result = [];
        foreach (self::getProfilesByTable($table) as $profile) {
            $url = self::getUrlByProfile($profile, $datasetId, $clangId);
            if ($url !== null) {
                $result[] = ['profile' => $profile, 'url' => $url];
            }
        }
        return $result;
    }

    /**
     * URL_REWRITE-Hook (siehe boot.php): erlaubt rex_getUrl('', '', ['news-id' => 42])
     * analog zum url-Addon. Parameter-Schlüssel ist "<trigger_segment>-id" eines
     * aktiven Profils; Domain und Sprache entscheiden bei mehreren Profilen mit
     * gleichem Trigger. Weitere Parameter werden als Query angehängt.
     */
    public static function handleUrlRewrite(rex_extension_point $ep): ?string
    {
        if ((string) $ep->getSubject() !== '') {
            return null;
        }
        $params = (array) $ep->getParam('params');
        if ($params === []) {
            return null;
        }
        $clangId = (int) $ep->getParam('clang');
        $domain = self::getCurrentDomainName();

        foreach ($params as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '-id') || (int) $value < 1) {
                continue;
            }
            $trigger = substr($key, 0, -3);
            $matching = array_values(array_filter(self::getAllProfiles(), static fn (array $p): bool => (string) $p['trigger_segment'] === $trigger));
            if ($matching === []) {
                continue;
            }
            $profile = self::pickProfile($matching, $clangId, $domain);
            if ($profile === null) {
                continue;
            }
            $url = self::getUrlByProfile($profile, (int) $value, $clangId);
            if ($url === null) {
                continue;
            }
            unset($params[$key]);
            if ($params !== []) {
                $url .= '?' . rex_string::buildQuery($params, (string) $ep->getParam('separator'));
            }
            return $url;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return array<string, mixed>|null
     */
    private static function pickProfile(array $profiles, int $clangId, string $domain): ?array
    {
        foreach ([[$domain, $clangId], [$domain, -1], ['', $clangId], ['', -1]] as [$wantDomain, $wantClang]) {
            foreach ($profiles as $profile) {
                if ((string) ($profile['domain'] ?? '') === $wantDomain && (int) ($profile['clang_id'] ?? -1) === $wantClang) {
                    return $profile;
                }
            }
        }
        return null;
    }

    private static function getCurrentDomainName(): string
    {
        if (!rex_addon::get('yrewrite')->isAvailable() || !class_exists(rex_yrewrite::class)) {
            return '';
        }
        $domain = rex_yrewrite::getCurrentDomain();
        if ($domain === null && rex::isBackend()) {
            return '';
        }
        $name = $domain !== null ? (string) $domain->getName() : '';
        return $name === 'default' ? '' : $name;
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

        $value = self::resolveRawValue($rows[0][$slugField]);
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
            $normalized = self::buildNormalizedSlug(self::resolveRawValue($row[$slugField]), (int) $row['id']);
            if ($normalized === $slug) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    public static function buildSlugSegment(rex_yform_manager_dataset $dataset, string $field): ?string
    {
        $value = self::resolveFieldValue($dataset, $field);
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
