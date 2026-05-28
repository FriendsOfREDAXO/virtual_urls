<?php

use FriendsOfRedaxo\VirtualUrl\VirtualUrls;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsCache;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsSeo;
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsSitemap;

if (rex::isBackend() && rex_be_controller::getCurrentPagePart(1) === 'virtual_urls') {
    rex_view::addJsFile(rex_addon::get('virtual_urls')->getAssetsUrl('profiles.js'));
}

// 1. Register YForm Value (Global, needed in Backend & Frontend)
if (rex_addon::get('yform')->isAvailable()) {
    // 2. Register Cache Buster (Global, needed in Backend mainly)
    VirtualUrlsCache::init();
}

// 3. Frontend / YRewrite integration
if (rex_addon::get('yrewrite')->isAvailable()) {
    
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
