<?php

// Tabelle anlegen/aktualisieren via rex_sql_table
rex_sql_table::get(rex::getTable('virtual_urls_profiles'))
    ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(11)', false, '-1'))
    ->ensureColumn(new rex_sql_column('domain', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('table_name', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('trigger_segment', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('url_field', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('article_id', 'int(11) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('relation_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('relation_table', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('relation_slug_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('default_category_id', 'int(11) unsigned', true))
    ->ensureColumn(new rex_sql_column('sitemap_filter', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('sitemap_changefreq', 'varchar(20)', false, 'weekly'))
    ->ensureColumn(new rex_sql_column('sitemap_priority', 'varchar(5)', false, '0.5'))
    ->ensureColumn(new rex_sql_column('seo_title_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('seo_description_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('seo_image_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('status_field', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('status_value', 'varchar(255)', false, '1'))
    ->setPrimaryKey('id')
    ->ensure();

// Historie alter, normalisierter Slugs für 301-Redirects bei Slug-Änderungen.
// Pro (table_name, trigger_segment, relation_slug, old_slug) genau ein Eintrag,
// der immer auf die aktuelle dataset_id zeigt (siehe VirtualUrlsRedirects).
rex_sql_table::get(rex::getTable('virtual_urls_old_slugs'))
    ->ensureColumn(new rex_sql_column('id', 'int(11) unsigned', false, null, 'auto_increment'))
    ->ensureColumn(new rex_sql_column('table_name', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('trigger_segment', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('relation_slug', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('old_slug', 'varchar(255)', false, ''))
    ->ensureColumn(new rex_sql_column('dataset_id', 'int(11) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime', true))
    ->setPrimaryKey('id')
    ->ensureIndex(new rex_sql_index(
        'old_slug_lookup',
        ['table_name', 'trigger_segment', 'relation_slug', 'old_slug'],
        rex_sql_index::UNIQUE,
    ))
    ->ensure();
