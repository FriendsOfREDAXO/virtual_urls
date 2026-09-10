<?php

namespace FriendsOfRedaxo\VirtualUrl;

use rex;
use rex_clang;
use rex_escape;
use rex_extension_point;
use rex_media;
use rex_media_manager;
use rex_yrewrite;

class VirtualUrlsSeo
{
    public static function handleSeoTags(rex_extension_point $ep)
    {
        $tags = $ep->getSubject();
        
        $dataset = VirtualUrls::getCurrentData();
        $profile = VirtualUrls::getCurrentProfile();
        
        if (!$dataset || !$profile) {
            return $tags;
        }
        
        // 1. Canonical URL
        $url = VirtualUrlsHelper::getUrlByDataset($dataset, rex_clang::getCurrentId());
        $domain = rex_yrewrite::getCurrentDomain();
        $domainUrl = $domain ? rtrim($domain->getUrl(), '/') : rtrim(rex::getServer(), '/');
        
        if ($url) {
            // Check if URL is already absolute
            if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
                $fullUrl = $url;
            } else {
                $fullUrl = $domainUrl . $url;
            }
            $tags['canonical'] = '<link rel="canonical" href="' . rex_escape($fullUrl) . '">';
            $tags['og:url'] = '<meta property="og:url" content="' . rex_escape($fullUrl) . '">';
            $tags['twitter:url'] = '<meta name="twitter:url" content="' . rex_escape($fullUrl) . '">';
        }
        
        // 2. Title
        $titleField = $profile['seo_title_field'] ?? '';
        if ($titleField && $dataset->hasValue($titleField)) {
            $title = VirtualUrlsHelper::resolveFieldValue($dataset, $titleField);
            if ($title) {
                $title = rex_escape(strip_tags($title));
                $tags['title'] = '<title>' . $title . '</title>';
                $tags['og:title'] = '<meta property="og:title" content="' . $title . '">';
                $tags['twitter:title'] = '<meta name="twitter:title" content="' . $title . '">';
            }
        }
        
        // 3. Description
        $descField = $profile['seo_description_field'] ?? '';
        if ($descField && $dataset->hasValue($descField)) {
            $description = VirtualUrlsHelper::resolveFieldValue($dataset, $descField);
            if ($description) {
                // Strip tags and truncate to ~160 chars
                $description = strip_tags($description);
                $description = str_replace(["\n", "\r"], [' ', ''], $description);
                if (mb_strlen($description) > 160) {
                    $description = mb_substr($description, 0, 157) . '...';
                }
                $description = rex_escape($description);
                
                $tags['description'] = '<meta name="description" content="' . $description . '">';
                $tags['og:description'] = '<meta property="og:description" content="' . $description . '">';
                $tags['twitter:description'] = '<meta name="twitter:description" content="' . $description . '">';
            }
        }
        
        // 4. Image
        $imageField = $profile['seo_image_field'] ?? '';
        if ($imageField && $dataset->hasValue($imageField)) {
            $image = VirtualUrlsHelper::resolveFieldValue($dataset, $imageField);
            if ($image) {
                // Handle comma-separated list (e.g. from media list)
                $images = explode(',', $image);
                $image = array_shift($images);
                
                $media = rex_media::get($image);
                if ($media) {
                    $mediaUrl = $domainUrl . rex_media_manager::getUrl('yrewrite_seo_image', $image);
                    
                    $tags['og:image'] = '<meta property="og:image" content="' . $mediaUrl . '">';
                    $tags['twitter:image'] = '<meta name="twitter:image" content="' . $mediaUrl . '">';
                    
                    if ($media->getTitle()) {
                        $tags['og:image:alt'] = '<meta property="og:image:alt" content="' . rex_escape($media->getTitle()) . '">';
                        $tags['twitter:image:alt'] = '<meta name="twitter:image:alt" content="' . rex_escape($media->getTitle()) . '">';
                    }
                    $tags['og:image:type'] = '<meta property="og:image:type" content="' . rex_escape($media->getType()) . '">';
                    $tags['twitter:card'] = '<meta name="twitter:card" content="summary_large_image">';
                }
            }
        }
        
        return $tags;
    }
}
