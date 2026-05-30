<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_sql;
use rex_yrewrite;

class VirtualUrlsCache
{
    public static function init()
    {
        $extensionPoints = [
            'YFORM_DATA_ADDED',
            'YFORM_DATA_UPDATED',
            'YFORM_DATA_DELETED',
        ];

        foreach ($extensionPoints as $ep) {
            rex_extension::register($ep, [self::class, 'checkCacheClear']);
        }
    }

    public static function checkCacheClear(rex_extension_point $ep)
    {
        $params = $ep->getParams();
        $table = $params['table'] ?? null;
        if (!is_object($table) || !method_exists($table, 'getTableName')) {
            return;
        }
        $tableName = (string) $table->getTableName();
        
        // Only proceed if YRewrite is available
        if (!rex_addon::get('yrewrite')->isAvailable()) {
            return;
        }

        // Get all configured tables from profiles
        // We use a simple query to avoid overhead, or maybe cache this config too?
        // Since this happens on write operations (save), a small DB query is fine.
        
        $sql = rex_sql::factory();
        // Check if the modified table is used in any profile (source or relation table)
        $sql->setQuery(
            'SELECT id FROM ' . rex::getTable('virtual_urls_profiles') . ' WHERE table_name = :table OR relation_table = :table LIMIT 1',
            ['table' => $tableName]
        );
        
        if ($sql->getRows() > 0) {
            // Table is used in Virtual URLs -> Clear YRewrite Cache
            rex_yrewrite::deleteCache();
            VirtualUrlsHelper::clearCache();
        }
    }
}
