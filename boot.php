<?php

use FriendsOfRedaxo\VirtualUrl\VirtualUrls;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsCache;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsRedirects;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsSeo;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsSitemap;

if (rex::isBackend() && rex_be_controller::getCurrentPagePart(1) === 'virtual_urls') {
    rex_view::addJsFile(rex_addon::get('virtual_urls')->getAssetsUrl('profiles.js'));
}

// 1. Register YForm Value (Global, needed in Backend & Frontend)
if (rex_addon::get('yform')->isAvailable()) {
    // 2. Register Cache Buster (Global, needed in Backend mainly)
    VirtualUrlsCache::init();

    // Alte Slugs mitschreiben (Datensätze werden i.d.R. im Backend bearbeitet,
    // daher unabhängig von rex::isBackend() registrieren).
    VirtualUrlsRedirects::init();
}

// 3. Frontend / YRewrite integration
if (rex_addon::get('yrewrite')->isAvailable()) {

    // rex_getUrl('', '', ['<trigger>-id' => 42]) -> virtuelle URL (Backend und
    // Frontend, gleiches Muster wie das url-Addon). Siehe VirtualUrlsHelper::handleUrlRewrite().
    rex_extension::register('URL_REWRITE', [VirtualUrlsHelper::class, 'handleUrlRewrite'], rex_extension::EARLY);
    
    // Routing Logic via YREWRITE_PREPARE (fires when YRewrite can't resolve a URL)
    if (!rex::isBackend()) {
        rex_extension::register('YREWRITE_PREPARE', function (rex_extension_point $ep) {
            return VirtualUrls::handle($ep);
        });
        
        // SEO Tags Integration
        rex_extension::register('YREWRITE_SEO_TAGS', [VirtualUrlsSeo::class, 'handleSeoTags']);
    }
    
    // Sitemap Integration
    rex_extension::register('YREWRITE_DOMAIN_SITEMAP', [VirtualUrlsSitemap::class, 'addToSitemap']);
}
