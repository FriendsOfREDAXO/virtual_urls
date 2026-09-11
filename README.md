# Virtual URLs AddOn für REDAXO cms

Dieses AddOn ermöglicht es, YForm-Datensätze (z.B. News, Produkte, Mitarbeiter) als virtuelle Unterseiten in die bestehende Struktur-Hierarchie einzuhängen — ohne für jede Ansicht innerhalt der Struktur einen eigenes Profil anlegen zu müssen. Es arbeitet mit Triggern. 

## Features

- 🚀 **Dynamisches Routing:** URLs wie `/news/mein-artikel` ohne echte Artikel
- 🗺️ **Sitemap Integration:** Automatische Aufnahme in die `sitemap.xml` (via YRewrite)
- 🧭 **Smart Navigation:** Der aktive Menüpunkt bleibt erhalten (Mount Point Detection)
- ⚡ **Auto-Caching:** Bei Änderungen an Datensätzen wird der Cache sofort aktualisiert
- 🐌 **Slug-Generator:** YForm-Feldtyp + Bulk-Generator für bestehende Datensätze
- 🔗 **Relation-URLs:** Optionale Kategorie-Segmente in der URL (`/news/sport/mein-artikel`)
- 🧠 **Intelligente Relation-Konfiguration:** Relationstabelle wird aus dem gewählten Relationsfeld automatisch abgeleitet
- 🌐 **Mehrsprachigkeit:** Pro Sprache eigene Profile mit unterschiedlichen Triggern und Slug-Feldern
- 🏢 **Multi-Domain:** Profile können auf einzelne Domains beschränkt werden
- 🔍 **SEO-Integration:** Automatische Generierung von Canonical-URLs, Meta-Titles, Descriptions und Images
- 🧪 **URL-Tester:** Backend-Tool zum Testen und Debuggen von URLs
- 📖 **Helper-Klasse:** API zum Erzeugen von URLs und Links in Modulen/Templates
- 🔒 **Sicherere Backend-Aktionen:** Mutierende Aktionen mit POST + CSRF
- 🚦 **Status Feld:** Optionaler Online/Offline-Filter, der Routing und Sitemap gleichermaßen betrifft
- ↪️ **301-Redirects:** Geänderte Slugs leiten automatisch von der alten auf die aktuelle URL weiter, statt einen 404 zu erzeugen
- 🧩 **Eigene Extension Points:** `VIRTUAL_URLS_PROFILE_QUERY`, `VIRTUAL_URLS_BUILD_URL`, `VIRTUAL_URLS_RESOLVED` für Erweiterungen ohne Fork
- 🌍 **`yform_lang_fields`-Integration:** Mehrsprachige Felder (`lang_text`/`lang_textarea`/`lang_media`) funktionieren direkt als URL-, SEO- oder Relation-Slug-Feld


## Einordnung

Dieses AddOn ist keine Ersatzlösung für das URL-AddOn.
Es ist bewusst auf YForm-basierte Routing-Profile zugeschnitten und deckt damit einen klar abgegrenzten Einsatzbereich ab.
Das URL-AddOn ist weitaus universeller anzusehen.

**Wesentlicher Unterschied:** Das URL-AddOn koppelt jede erzeugte URL fest an die tatsächliche Position des Profil-Artikels im Struktur-Baum – die URL ist `<echter Struktur-Pfad des Artikels>/<Datensatz-Segment>` und wird pro Datensatz in einer eigenen Tabelle (`url_generator_url`) vorgeneriert und gespeichert. Verschiebt sich der Artikel im Baum, ändert sich die URL entsprechend, und die gespeicherten Einträge müssen neu generiert werden.

Virtual URLs speichert keine URLs und ist nicht an eine Artikel-Position gebunden: Der Trigger wird zur Laufzeit irgendwo im angeforderten Pfad gesucht (siehe oben), unabhängig davon, ob davor ein echter, im Struktur-Baum existierender Artikel-Pfad steht. Dadurch lässt sich derselbe Trigger unter beliebig vielen „Wurzel-Pfaden" ansprechen, und mehrere Profile auf derselben Tabelle mit unterschiedlichen Triggern erlauben es, denselben Datensatz an mehreren Stellen der Website mit jeweils eigenem Renderer-Artikel/Layout auftauchen zu lassen – ohne Duplikat, ohne Neu-Generierung, ohne eigene URL-Tabelle (siehe [„Dieselbe Tabelle an mehreren Stellen einhängen"](#dieselbe-tabelle-an-mehreren-stellen-einhängen)).


## Konzept

*Virtual URLs* arbeitet mit **Profilen**:

1. **Trigger:** Ein URL-Segment (z.B. `news`), das signalisiert: Hier beginnt ein virtueller Bereich
2. **Matching:** Das AddOn prüft, ob der folgende Slug in der konfigurierten YForm-Tabelle existiert
3. **Rendering:** Ist der Datensatz gefunden, wird der definierte „Renderer-Artikel" geladen, aber der URL-Pfad bleibt erhalten

### URL-Schemas

| Typ | Schema | Beispiel |
|---|---|---|
| Ohne Relation | `/<pfad>/<trigger>/<slug>` | `/spielberechtigungen/xnews/mein-artikel` |
| Mit Relation | `/<pfad>/<trigger>/<relation-slug>/<slug>` | `/spielberechtigungen/xnews/sport/mein-artikel` |

### Der Trigger ist frei im Pfad platzierbar

Der Trigger wird nicht nur am Anfang der URL gesucht, sondern irgendwo in den Pfad-Segmenten (`array_search`). Das bedeutet: `<pfad>` davor ist beliebig lang und beliebig tief – solange direkt nach dem Trigger die passende Anzahl Segmente folgt (Slug, bzw. Relation-Slug + Slug) und danach nichts mehr kommt, matcht das Profil. Alle folgenden URLs matchen z.B. dasselbe Profil mit Trigger `news`:

```
/news/mein-artikel
/aktuelles/news/mein-artikel
/verein/fc-bayern/news/mein-artikel
/2026/saison/news/mein-artikel
```

Das ist der Grund, warum sich dieselbe Datenquelle mühelos an mehreren Stellen im Seitenbaum einhängen lässt, ohne den Datensatz zu duplizieren – siehe [„Dieselbe Tabelle an mehreren Stellen einhängen"](#dieselbe-tabelle-an-mehreren-stellen-einhängen) unten.

**Einschränkung:** Der Trigger muss innerhalb der für Domain/Sprache gültigen Profile eindeutig sein. Zwei aktive Profile mit demselben Trigger-Wort würden sich gegenseitig ins Gehege kommen – es gewinnt das erste in der internen Profil-Liste gefundene.

## Einrichtung

### 1. Profil anlegen

Unter **Virtual URLs → Profile** ein neues Profil erstellen:

| Feld | Pflicht | Beschreibung |
|---|---|---|
| **Status** | Ja | Aktiv/Inaktiv Schalter für das Profil |
| **Sprache** | Nein | Sprache für dieses Profil. „Alle Sprachen" = sprachunabhängig |
| **Domain** | Nein | Auf eine Domain beschränken. „Alle Domains" = überall aktiv |
| **YForm Tabelle** | Ja | Name der Datentabelle, z.B. `rex_news` |
| **URL Trigger Segment** | Ja | Segment, das die virtuelle URL einleitet, z.B. `news` |
| **Slug Feld Name** | Ja | Feld mit dem normalisierten URL-Slug, z.B. `url` oder `code` |
| **Renderer Artikel** | Ja | REDAXO-Artikel, der den Datensatz rendert |
| **Standard Kategorie** | Nein | Basis-Kategorie für Sitemap-URLs |
| **Relation Feld** | Nein | Relationsfeld aus der Datentabelle (z.B. `category_id`) |
| **Relation Tabelle** | Nein | Wird automatisch aus dem gewählten Relationsfeld gesetzt |
| **Relation Slug Feld** | Nein | Feld für den URL-Teil (z.B. `name`), wird automatisch normalisiert |
| **Status Feld** | Nein | Feld für Online/Offline-Status (z.B. `status`). Greift bei Routing UND Sitemap gleichermaßen |
| **Status Wert** | Nein | Wert, der als "online" gilt (Standard: `1`) |
| **Sitemap Filter** | Nein | SQL WHERE-Klausel mit optionalen Platzhaltern. Zusätzlich zum Status-Feld, falls beide gesetzt sind |
| **Sitemap Changefreq** | Nein | Wie oft ändert sich der Inhalt voraussichtlich? |
| **Sitemap Priority** | Nein | Priorität der URLs im Vergleich zu anderen URLs (0.0 bis 1.0) |
| **SEO Title Feld** | Nein | Spalte für den Meta-Title (z.B. `title`). Leer = Standard |
| **SEO Description Feld** | Nein | Spalte für die Meta-Description. HTML wird entfernt, Text gekürzt |
| **SEO Image Feld** | Nein | Spalte für das Meta-Image (z.B. `image`) |

### 2. Slug-Feld einrichten

#### Option A: YForm-Feldtyp `virtual_url_slug`

1. In der YForm-Feldverwaltung ein Feld vom Typ `virtual_url_slug` anlegen
2. **Name:** `url` (oder `slug`)
3. **Quell-Feld:** `title` (oder das Feld, aus dem der Slug erzeugt wird)
4. **Sichtbarkeit:** `visible` / `readonly` / `hidden`

Der Slug wird beim Anlegen automatisch aus dem Quellfeld generiert. Bestehende Slugs werden beim Bearbeiten nicht überschrieben.

#### Option B: Slug-Generator für bestehende Daten

Unter **Virtual URLs → Slug-Generator**:

1. YForm-Tabelle wählen
2. Quellfeld wählen (z.B. `title`)
3. Zielfeld wählen (z.B. `url`)
4. Modus: „Nur leere Felder füllen" oder „Alle überschreiben"
5. Vorschau prüfen und generieren

Duplikate werden automatisch mit Suffix (`-1`, `-2`, …) versehen.

### 3. Mehrsprachigkeit

Für mehrsprachige Seiten pro Sprache ein eigenes Profil anlegen:

| Sprache | Trigger | Slug-Feld | Renderer |
|---|---|---|---|
| Deutsch | `nachrichten` | `slug_de` | Artikel 10 (DE) |
| Englisch | `news` | `slug_en` | Artikel 10 (EN) |

Das Routing filtert automatisch nach der aktuellen Sprache. Der Helper nutzt immer das sprachspezifische Profil und fällt auf „Alle Sprachen" zurück.

**Alternative: ein Feld für alle Sprachen (`yform_lang_fields`)**

Ist das AddOn [`yform_lang_fields`](https://github.com/KLXM/yform_lang_fields) installiert, kann statt separater Spalten pro Sprache (`slug_de`, `slug_en`, …) auch ein einzelnes `lang_text`/`lang_textarea`/`lang_media`-Feld als URL-Slug-, SEO-Title-/-Description-/-Image- oder Relation-Slug-Feld ausgewählt werden. Virtual URLs löst den JSON-Wert dieser Felder automatisch für die jeweils aktuelle Sprache auf (Routing, URL-Erzeugung, SEO-Tags und Sitemap). Ohne `yform_lang_fields` bleibt das Verhalten für normale Felder unverändert.

### 4. Relation-URLs

Für hierarchische URLs (z.B. `/news/sport/mein-artikel`):

1. In der Datentabelle braucht es ein echtes Relationsfeld (z.B. `be_manager_relation`)
2. Die Relationstabelle wird automatisch aus diesem Feld ermittelt
3. Im Profil muss nur das Relation-Feld und ein passendes Relation-Slug-Feld gewählt werden

Die Relation wird automatisch normalisiert: „Sport & Fitness" → `sport-fitness`.

### 5. URL-Feld: echtes Slug-Feld vs. beliebiges Feld

Ein eigenes Slug-Feld (per `virtual_url_slug`-Feldtyp oder Slug-Generator) ist **optional**, kein Pflichtbestandteil. Als "Slug Feld Name" im Profil kann direkt jede beliebige Spalte der Tabelle gewählt werden, z.B. `title`:

- Wenn das gewählte URL-Feld bereits slug-artige Werte enthält, werden diese direkt genutzt.
- Wenn das URL-Feld keine slug-artigen Werte enthält, wird intern normalisiert und zur Kollisionsvermeidung ein `-<id>` Suffix verwendet.

Beispiel:

- Quellwert: `Mein Artikel`
- URL-Segment: `mein-artikel-42`

Dadurch bleiben URLs eindeutig, auch bei gleichen Titeln.

### 6. Status-Feld (Online/Offline)

Der **Sitemap Filter** (SQL WHERE-Klausel) betrifft ausschließlich die `sitemap.xml` — ein per Sitemap-Filter ausgeschlossener Datensatz bleibt über seine URL trotzdem erreichbar und lässt sich weiterhin auflösen. Für den häufigsten Fall (ein einzelnes Online/Offline-Flag, das sowohl das Routing als auch die Sitemap betreffen soll) gibt es das **Status Feld**:

- **Status Feld:** Spalte in der Datentabelle, z.B. `status`
- **Status Wert:** Wert, der als "online" gilt (Standard: `1`)

Ist ein Status-Feld gesetzt, lässt sich die URL eines Datensatzes, dessen Wert nicht mit dem Status-Wert übereinstimmt, nicht mehr auflösen (404 statt Rendering), und der Datensatz wird auch nicht mehr in die Sitemap aufgenommen. `Sitemap Filter` und `Status Feld` lassen sich kombinieren — beide Bedingungen müssen dann erfüllt sein.

Für Filterlogik, die über ein einzelnes Feld/Wert-Paar hinausgeht (z.B. ein Embargo-Datum), lässt sich stattdessen der Extension Point `VIRTUAL_URLS_PROFILE_QUERY` nutzen (siehe unten).

**Mit `yform_lang_fields`:** Ist ein `lang_text`/`lang_textarea`/`lang_media`-Feld als URL-Feld (oder Relation-Slug-Feld) gewählt, wird automatisch der Wert der aktuellen Sprache aus dem gespeicherten JSON aufgelöst und daraus der Slug gebildet — auch hier ist kein separates Slug-Feld pro Sprache nötig. Im Profil-Formular werden solche Felder in der Auswahlliste mit 🌐 markiert.

## Verwendung im Modul

### Datensatz im Renderer-Artikel abrufen

```php
use FriendsOfRedaxo\VirtualUrl\VirtualUrls;

$data = VirtualUrls::getCurrentData();
$profile = VirtualUrls::getCurrentProfile();

if ($data) {
    echo '<h1>' . rex_escape($data->getValue('title')) . '</h1>';
    echo '<div>' . $data->getValue('text') . '</div>';
} else {
    echo 'Kein Datensatz gefunden.';
}
```

### URLs und Links erzeugen

```php
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;

// URL für einen Datensatz
$url = VirtualUrlsHelper::getUrl('rex_news', 42);
// → "/news/mein-artikel" oder "/news/sport/mein-artikel"

// URL für eine bestimmte Sprache
$url = VirtualUrlsHelper::getUrl('rex_news', 42, 2); // clang=2

// URL aus bestehendem Dataset
$dataset = rex_yform_manager_dataset::get(42, 'rex_news');
$url = VirtualUrlsHelper::getUrlByDataset($dataset);

// HTML-Link erzeugen
$link = VirtualUrlsHelper::getLink('rex_news', 42, 'Zum Artikel');
// → <a href="/news/mein-artikel">Zum Artikel</a>

// Link mit CSS-Klassen
$link = VirtualUrlsHelper::getLink('rex_news', 42, 'Mehr', ['class' => 'btn btn-primary']);

// Alle URLs einer Tabelle (z.B. für Übersichtsseiten)
$urls = VirtualUrlsHelper::getUrlList('rex_news', 'status = 1', 'date DESC');
foreach ($urls as $item) {
    echo '<li><a href="' . $item['url'] . '">' . $item['dataset']->getValue('title') . '</a></li>';
    // $item['id'], $item['url'], $item['slug'], $item['dataset']
}
```

### URLs per `rex_getUrl()`

Wie beim url-Addon lässt sich eine virtuelle URL auch über `rex_getUrl()` erzeugen. Parameter-Schlüssel ist `<trigger>-id`:

```php
echo rex_getUrl('', '', ['news-id' => 42]);             // /pfad/news/mein-artikel
echo rex_getUrl('', '', ['news-id' => 42, 'page' => 2]); // weitere Parameter werden als Query angehängt
```

Bei mehreren Profilen mit gleichem Trigger (Multi-Domain, Mehrsprachigkeit) entscheiden aktuelle Domain und Sprache.

### Mehrere Profile pro Tabelle (Multi-Domain, Mehrsprachigkeit)

Eine Tabelle kann mehrere Profile haben, etwa je Domain oder je Sprache. `getUrl()` wählt automatisch das passende Profil: aktuelle Domain und Sprache zuerst, dann domainunabhängige Profile. Wer ein bestimmtes Profil braucht, etwa ein Link-Picker mit Auswahl, nutzt die Profil-API:

```php
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;

// Alle aktiven Profile einer Tabelle (Zeilen aus rex_virtual_urls_profiles)
foreach (VirtualUrlsHelper::getProfilesByTable('rex_news') as $profile) {
    echo $profile['id'] . ': /' . $profile['trigger_segment'] . '/ → Artikel ' . $profile['article_id']
        . ' (' . ($profile['domain'] ?: 'alle Domains') . ', clang ' . $profile['clang_id'] . ')';
    // -1 bei clang_id bzw. '' bei domain = gilt überall
}

// URL über genau dieses Profil, unabhängig von der aktuellen Domain
$profile = VirtualUrlsHelper::getProfileById(3);
$url = $profile ? VirtualUrlsHelper::getUrlByProfile($profile, 42) : null;
// → null, wenn der Datensatz fehlt, kein Slug hat oder das Profil eine andere Sprache hat

// Alle URLs eines Datensatzes, z. B. für hreflang oder eine Auswahl im Backend
foreach (VirtualUrlsHelper::getUrls('rex_news', 42) as $entry) {
    echo $entry['profile']['domain'] . ': ' . $entry['url'];
    // Profil ohne Domain-Bindung liefert die URL der aktuellen Domain
}

// Profil gezielt nach Domain wählen (Default: aktuelle yrewrite-Domain)
$profile = VirtualUrlsHelper::getProfileByTable('rex_news', 1, 'shop.example.org');
// Reihenfolge: Domain + Sprache, Domain + alle Sprachen, alle Domains + Sprache, alle Domains + alle Sprachen
```

Typischer Einsatz in einem Template mit zwei Domains, die dieselbe News-Tabelle anzeigen:

```php
// Kanonische URL auf der Hauptdomain, unabhängig davon, wo gerade gerendert wird
$main = VirtualUrlsHelper::getProfileByTable('rex_news', rex_clang::getCurrentId(), 'www.example.org');
$canonical = $main ? VirtualUrlsHelper::getUrlByProfile($main, $dataset->getId()) : null;
```

### Dieselbe Tabelle an mehreren Stellen einhängen

Ein klassischer Fall: Eine News-Tabelle soll gleichzeitig in einem allgemeinen News-Bereich UND in mehreren thematischen Unterbereichen auftauchen, ohne die Datensätze zu duplizieren. Das löst man nicht über Relationen, sondern einfach über **mehrere Profile** auf derselben Tabelle mit **unterschiedlichen Triggern** und unterschiedlichem Renderer-Artikel:

| Profil | Trigger | Renderer-Artikel | Resultierende URL (Datensatz 42) |
|---|---|---|---|
| Allgemein | `news` | Artikel 10 (allg. News-Layout) | `/news/mein-artikel-42` |
| Sport | `sport-news` | Artikel 22 (Sport-Layout, eigene Sidebar) | `/sport/sport-news/mein-artikel-42` |
| Kreisliga | `kreisliga-news` | Artikel 35 (Kreisliga-Layout) | `/kreisliga/kreisliga-news/mein-artikel-42` |

Der Datensatz mit `id=42` existiert nur **einmal** in `rex_news`. Über drei Profile ist er trotzdem unter drei verschiedenen URLs mit drei verschiedenen Layouts erreichbar – jedes Profil zeigt auf einen eigenen Renderer-Artikel, liest aber dieselbe Zeile. Ändert sich Titel oder Text, ist die Änderung sofort an allen drei Stellen sichtbar, ohne Sync-Logik.

```php
// Alle drei URLs desselben Datensatzes ermitteln, z.B. für eine Übersicht im Backend
foreach (VirtualUrlsHelper::getUrls('rex_news', 42) as $entry) {
    echo $entry['profile']['trigger_segment'] . ': ' . $entry['url'];
}
// news: /news/mein-artikel-42
// sport-news: /sport/sport-news/mein-artikel-42
// kreisliga-news: /kreisliga/kreisliga-news/mein-artikel-42
```

Auf der jeweiligen Bereichsseite selbst reicht ein einfacher Link mit dem passenden Trigger:

```php
// Im Sport-Bereich: Link zur Sport-Ansicht derselben News
echo VirtualUrlsHelper::getLink('rex_news', 42, 'Zum Artikel');
// nutzt automatisch das Profil, das zur aktuellen Domain/Sprache passt -
// bei mehreren passenden Profilen ggf. gezielt per getProfileById()/getUrlByProfile() wählen
```

Sitemap und SEO-Tags laufen pro Profil getrennt – die Sport-URL bekommt ihre eigene Sitemap-Priorität/-Changefreq und eigene Meta-Tags, obwohl der Inhalt mit der allgemeinen News-URL identisch ist.

### URL programmatisch testen

```php
use FriendsOfRedaxo\VirtualUrl\VirtualUrlsHelper;

$result = VirtualUrlsHelper::testUrl('/news/sport/mein-artikel', 'wdfv.de');

if ($result['resolved']) {
    echo 'Datensatz ID: ' . $result['dataset']->getId();
    echo 'Artikel: ' . $result['article_id'];
} else {
    echo 'Fehler: ' . $result['message'];
}
```

## Backend-Seiten

| Tab | Beschreibung |
|---|---|
| **Profile** | Profilverwaltung (Erstellen, Bearbeiten, Löschen) |
| **URLs & Tester** | Übersicht aller generierten URLs + interaktiver URL-Tester |
| **Slug-Generator** | Bulk-Generierung von Slugs für bestehende YForm-Tabellen |
| **Hilfe** | API-Referenz mit Code-Beispielen |

## Navigation & Active State

Das AddOn erkennt intelligent den Navigations-Kontext:

URL: `/unternehmen/aktuelles/news/mein-artikel`

1. Trigger ist `news`
2. Das System prüft, ob `/unternehmen/aktuelles` einem echten Artikel entspricht
3. **Falls ja:** Dieser Artikel wird als aktiver Menüpunkt markiert → Menü bleibt aufgeklappt
4. **Falls nein:** Der im Profil definierte Renderer-Artikel wird verwendet

## Sitemap

Datensätze werden automatisch in die `sitemap.xml` aufgenommen wenn:

- Eine **Standard Kategorie** im Profil definiert ist
- Der optionale **Sitemap Filter** den Datensatz einschließt

### Platzhalter im Sitemap-Filter

| Platzhalter | Beschreibung |
|---|---|
| `###NOW###` | Aktuelles Datum + Uhrzeit (`Y-m-d H:i:s`) |
| `###CURRENT_DATE###` | Aktuelles Datum (`Y-m-d`) |
| `###CURRENT_TIMESTAMP###` | Unix Timestamp |

Relative Angaben: `###NOW -1 YEAR###`, `###NOW +30 MINUTES###`, `###CURRENT_DATE -2 WEEKS###` (gemäß PHP `strtotime`).

**Beispiele:**
```
status = 1
status = 1 AND online_date <= "###NOW###"
online_date >= "###NOW -1 YEAR###"
```

## Caching

Das AddOn überwacht `YFORM_DATA_ADDED`, `YFORM_DATA_UPDATED` und `YFORM_DATA_DELETED`.
Bei Änderungen an konfigurierten Quell- oder Relationstabellen wird der YRewrite-Cache automatisch invalidiert.

## 301-Redirects bei geänderten Slugs

Ändert sich der Wert des URL-Felds eines Datensatzes (z.B. weil der Titel angepasst wurde), ändert sich damit auch die URL. Ohne weitere Vorkehrung würde die alte URL für alle bestehenden Links, Backlinks und Suchmaschinen-Einträge ins Leere laufen (404).

Virtual URLs merkt sich bei jeder Änderung automatisch den vorherigen, normalisierten Slug (`YFORM_DATA_UPDATED`). Lässt sich eine angefragte URL keinem aktuellen Datensatz zuordnen, wird als letzter Schritt in dieser Historie nachgeschaut — bei Treffer erfolgt ein echter `301 Moved Permanently` auf die aktuelle URL des Datensatzes.

- Es wird nur der zuletzt gültige alte Slug pro Datensatz gespeichert (kein unbegrenzt wachsender Verlauf); jede weitere Änderung überschreibt den vorherigen Eintrag für denselben alten Slug.
- Gilt pro Profil (Tabelle + Trigger + ggf. Relation-Slug) — bei mehreren Profilen für dieselbe Tabelle wird die Historie je Profil getrennt gehalten.
- Ein manuell in der Datenbank gelöschter Datensatz hinterlässt einen inaktiven Eintrag in der Historie; der Redirect greift dann nicht mehr (der Datensatz lässt sich nicht mehr laden), es entsteht aber auch kein Fehler.

## System-Integration

- Extension Point `YREWRITE_PREPARE` für URL-Auflösung
- Extension Point `YREWRITE_DOMAIN_SITEMAP` für Sitemap-Einträge
- Benötigt: YRewrite ≥ 2.0, YForm ≥ 4.0, REDAXO ≥ 5.10
- Optional: [`yform_lang_fields`](https://github.com/KLXM/yform_lang_fields) – wenn installiert, werden `lang_text`/`lang_textarea`/`lang_media`-Felder überall dort, wo Virtual URLs einen Feldwert als String benötigt (URL-Slug, Relation-Slug, SEO-Title/-Description/-Image), automatisch für die aktuelle Sprache aufgelöst

## Eigene Extension Points

Virtual URLs registriert drei eigene Extension Points, über die eigener Code ohne Fork in URL-Auflösung und -Erzeugung eingreifen kann:

### `VIRTUAL_URLS_PROFILE_QUERY`

Läuft direkt vor dem Datenbank-Lookup, wenn ein Request auf einen Datensatz aufgelöst wird. Erlaubt zusätzliche Einschränkungen auf die Query, z.B. Online-Status oder ein Embargo-Datum.

- **Subject:** `rex_yform_manager_query`
- **Params:** `table` (string), `field` (string, das URL-Feld), `slug` (string, der angeforderte Slug), `profile` (array, das komplette Profil)

```php
rex_extension::register('VIRTUAL_URLS_PROFILE_QUERY', function (rex_extension_point $ep) {
    $query = $ep->getSubject();
    if ('rex_news' === $ep->getParam('table')) {
        $query->where('status', 1);
    }
    return $query;
});
```

> Betrifft nur den regulären Slug-Lookup. Der Fallback über das numerische `-<id>`-Suffix (z.B. wenn zwei Datensätze denselben normalisierten Slug ergeben) fragt den Datensatz direkt per ID ab und durchläuft diese Query nicht.

### `VIRTUAL_URLS_BUILD_URL`

Läuft am Ende von `VirtualUrlsHelper::getUrl()`, nachdem die URL zusammengebaut wurde. Erlaubt Nachbearbeitung oder Ersetzung der generierten URL.

- **Subject:** `string` (die generierte URL)
- **Params:** `profile` (array), `dataset` (`rex_yform_manager_dataset`), `clang_id` (int)

```php
rex_extension::register('VIRTUAL_URLS_BUILD_URL', function (rex_extension_point $ep) {
    $url = $ep->getSubject();
    // z.B. Tracking-Parameter, alternative Slug-Schemata, ...
    return $url;
});
```

### `VIRTUAL_URLS_RESOLVED`

Läuft, nachdem ein Request erfolgreich auf einen Datensatz aufgelöst wurde, bevor das Ergebnis an YRewrite zurückgegeben wird. Gedacht zum Reagieren (Logging, Tracking, zusätzliches Caching), nicht primär zum Verändern des Routings.

- **Subject:** `array{article_id: int, clang?: int}`
- **Params:** `dataset` (`rex_yform_manager_dataset`), `profile` (array), `domain` (`rex_yrewrite_domain`)

```php
rex_extension::register('VIRTUAL_URLS_RESOLVED', function (rex_extension_point $ep) {
    $dataset = $ep->getParam('dataset');
    // z.B. Aufrufzähler hochzählen, Analytics-Event feuern, ...
    return $ep->getSubject();
});
```

## API-Referenz

### `VirtualUrls` (Routing)

| Methode | Beschreibung |
|---|---|
| `getCurrentData(): ?rex_yform_manager_dataset` | Aktueller Datensatz im Renderer |
| `getCurrentProfile(): ?array` | Aktuelles Profil im Renderer |

### `VirtualUrlsHelper` (URL-Erzeugung)

| Methode | Beschreibung |
|---|---|
| `getUrl(string $table, int $id, int $clang = -1): ?string` | URL für einen Datensatz |
| `getUrlByDataset(rex_yform_manager_dataset $d, int $clang = -1): ?string` | URL aus Dataset |
| `getLink(string $table, int $id, string $label, array $attrs, int $clang): string` | HTML-Link |
| `getUrlList(string $table, string $where, string $order, int $clang): array` | Alle URLs einer Tabelle |
| `getUrlByProfile(array $profile, int $id, int $clang = -1): ?string` | URL über ein konkretes Profil (bei mehreren Profilen pro Tabelle) |
| `getUrls(string $table, int $id, int $clang = -1): array` | Alle URLs eines Datensatzes über sämtliche passenden Profile (`[['profile' => …, 'url' => …], …]`) |
| `getProfilesByTable(string $table): array` | Alle aktiven Profile einer Tabelle |
| `getProfileById(int $id): ?array` | Aktives Profil per ID |
| `getProfileByTable(string $table, int $clang = -1, ?string $domain = null): ?array` | Profil nach Tabelle, Sprache und Domain (Default: aktuelle yrewrite-Domain) |
| `handleUrlRewrite(rex_extension_point $ep): ?string` | `URL_REWRITE`-Hook für `rex_getUrl('', '', ['<trigger>-id' => $id])` (wird in boot.php registriert) |
| `testUrl(string $url, ?string $domain): array` | URL testen |
| `getAllProfiles(): array` | Alle aktiven Profile (gecacht) |
| `clearCache(): void` | Profil-Cache leeren |
| `resolveFieldValue(rex_yform_manager_dataset $d, string $field, int $clang = -1): string` | Feldwert als String, löst `yform_lang_fields`-JSON für die angegebene Sprache auf |
| `resolveRawValue($raw, int $clang = -1): string` | Wie `resolveFieldValue()`, aber für einen bereits gelesenen Rohwert (z.B. aus `rex_sql::getValue()`) |
| `getRelationSlugById(string $table, string $slugField, int $id): ?string` | Normalisierter Slug einer Relation-Zeile |
| `buildSlugSegment(rex_yform_manager_dataset $d, string $field): ?string` | Normalisiertes Slug-Segment für ein Dataset-Feld |
| `normalizeSlug(string $value, int $id): string` | Normalisiert einen bereits gelesenen Wert zu einem Slug-Segment (ohne Dataset) |

### `VirtualUrlsRedirects` (301-Redirects)

| Methode | Beschreibung |
|---|---|
| `init(): void` | Registriert den `YFORM_DATA_UPDATED`-Listener (wird in boot.php aufgerufen) |
| `recordOldSlug(rex_extension_point $ep): void` | Schreibt den alten Slug in die Historie, falls er sich geändert hat |
| `redirectIfOldSlug(array $profiles, array $segments, int $clangId): void` | Prüft die Historie und sendet bei Treffer einen `301` (wird von `VirtualUrls::handle()` aufgerufen) |

## Autor

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO

## Credits

**Projektleitung**

[Thomas Skerbis](https://github.com/skerbis)

## Lizenz

MIT License – siehe [LICENSE](LICENSE)
