# Changelog

## 1.2.0

### Added
- `URL_REWRITE`-Hook: `rex_getUrl('', '', ['<trigger>-id' => $id])` erzeugt virtuelle URLs (Backend und Frontend), analog zum url-Addon.
- Helper-Methoden `getProfilesByTable()`, `getProfileById()`, `getUrlByProfile()`, `getUrls()`; `getProfileByTable()` mit optionalem Domain-Parameter.

### Changed
- Profilwahl im Helper ist domainbewusst: bei mehreren Profilen pro Tabelle (Multi-Domain) gewinnt das Profil der aktuellen Domain, danach die Sprache, statt eines zufällig zuletzt geladenen.
- Profil-Cache lädt einmal alle aktiven Profile.

## 1.1.0 - 2026-05-28

### Added
- Relation-Setup im Profil verbessert: Relationstabelle wird aus dem gewählten Relationsfeld automatisch abgeleitet.
- AJAX-Metadaten für Profilformular erweitert, um Relationsfelder und deren Zieltabellen gezielt bereitzustellen.

### Changed
- URL-Segment-Aufbereitung vereinheitlicht:
  - Slug-artige Feldwerte werden direkt genutzt.
  - Nicht slug-artige Feldwerte werden normalisiert und mit `-<id>` ergänzt, um Kollisionen zu vermeiden.
- Resolver, Helper und Sitemap verwenden dieselbe Slug-Logik, damit erzeugte und auflösbare URLs konsistent sind.
- Profile-UI fokussiert auf echte Relationsfelder statt freier Tabellenwahl.

### Security
- Mutierende Backend-Aktionen auf POST + CSRF umgestellt (Profilstatus, Profil löschen, Slug-Generator).
- AJAX-Endpunkt für Spaltenliste validiert CSRF und akzeptiert nur YForm-Tabellen.

### Performance
- Profil- und Relation-Slug-Caches in der Laufzeitlogik ergänzt.
- Cache-Invalidierung erweitert: Änderungen an Quell- und Relationstabellen leeren YRewrite-Cache.

### Standards
- Backend-JS für das AddOn zentral über `boot.php` geladen.
- Berechtigungsangabe in `package.yml` auf `admin[]` gesetzt.
