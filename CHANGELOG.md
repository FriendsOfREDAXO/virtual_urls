# Changelog

## 1.6.0 - 2026-09-10

### Added
- **301-Redirects bei geänderten Slugs:** Ändert sich der Wert des URL-Felds eines Datensatzes (z.B. der Titel), wurde die alte URL bisher zu einem 404. Virtual URLs merkt sich jetzt automatisch den vorherigen normalisierten Slug (bei `YFORM_DATA_UPDATED`) und leitet eine Anfrage auf die alte URL per echtem `301 Moved Permanently` auf die aktuelle URL weiter, sofern kein aktueller Datensatz mehr direkt passt. Neue Tabelle `virtual_urls_old_slugs`, ein Eintrag pro (Tabelle, Trigger, Relation-Slug, alter Slug) — jede erneute Änderung überschreibt den vorherigen Eintrag.

## 1.5.0 - 2026-09-10

### Added
- Neues optionales Profil-Feld **Status Feld** (+ **Status Wert**, Standard `1`): filtert sowohl beim Routing (Datensatz mit abweichendem Statuswert ist über seine URL nicht mehr auflösbar) als auch in der Sitemap. Bisher betraf der `Sitemap Filter` ausschließlich die Sitemap, das Routing selbst kannte keinen eingebauten Online/Offline-Filter.
- URL-Tester weist in der "nicht gefunden"-Meldung darauf hin, wenn ein Status-Feld konfiguriert ist, da das der häufigste Grund für eine unerwartet nicht auflösbare URL ist.

## 1.4.0 - 2026-09-10

### Added
- Drei eigene Extension Points für Drittanbieter-Code, ohne Fork:
  - `VIRTUAL_URLS_PROFILE_QUERY` – zusätzliche Einschränkungen auf die Lookup-Query bei der URL-Auflösung (z.B. Online-Status, Embargo-Datum).
  - `VIRTUAL_URLS_BUILD_URL` – Nachbearbeitung/Ersetzung einer generierten URL.
  - `VIRTUAL_URLS_RESOLVED` – Reaktion, nachdem ein Request erfolgreich auf einen Datensatz aufgelöst wurde (Logging, Tracking, ...).
- Profil-Formular markiert `yform_lang_fields`-Felder (`lang_text`/`lang_textarea`/`lang_media`) in den Spalten-Dropdowns für URL-Feld und Relation-Slug-Feld mit 🌐 und zeigt einen Hinweis, dass dafür kein eigenes Slug-Feld benötigt wird.

## 1.3.0 - 2026-09-10

### Fixed
- Kompatibilität mit dem AddOn `yform_lang_fields`: Ist ein für URL-Slug, SEO-Title/-Description/-Image oder Relations-Slug konfiguriertes Feld ein `lang_text`/`lang_textarea`/`lang_media`-Feld, wurde bisher das rohe JSON (`[{"clang_id":1,"value":"..."}]`) statt des Textwerts der aktuellen Sprache verwendet – sichtbar u.a. als kaputte Slugs, JSON in Meta-Tags und in der Sitemap. Betroffene Stellen lösen den Wert jetzt über `yform_lang_fields` auf, sofern das AddOn installiert ist; ohne `yform_lang_fields` (oder bei normalen, nicht-mehrsprachigen Feldern) ändert sich das Verhalten nicht.

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
