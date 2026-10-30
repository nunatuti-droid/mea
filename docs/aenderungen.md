# Änderungsprotokoll: Website-Updates und Erweiterungen

Auftrag vom 30.10.2026 („Projektanweisungen – Website-Updates und Erweiterungen“).
Jede Änderung wird hier festgehalten. Das Gesamt-Update wird erst erstellt, wenn alle Punkte erledigt und freigegeben sind.

## Punkt 5: Website online/offline und Suchmaschinen-Indexierung

**Wo:** Verwaltung → System → Website-Status (`/verwaltung/website-status/`), nur mit Recht „System“ (Administrator).

**Was neu ist**
- Schalter „Website online“. Ausgeschaltet sehen Besucher eine Wartungsseite mit Logo, Hinweistext, Telefon, WhatsApp und Standorten. Der Server antwortet mit Status 503 und „Retry-After“, damit Suchmaschinen die Seite nicht als dauerhaft weg werten.
- Der Hinweistext der Wartungsseite ist im Backend änderbar (max. 600 Zeichen).
- Auch offline erreichbar bleiben: die Verwaltung, PayPal-Rückmeldungen (`/zahlung/`), Bilder/CSS und `robots.txt`.
- Schalter „Suchmaschinen dürfen die Website aufnehmen“. Ausgeschaltet: `noindex` im HTML-Kopf, Header `X-Robots-Tag: noindex, nofollow` und `robots.txt` mit `Disallow: /`. Eingeschaltet: normale `robots.txt` (Verwaltung, Buchung und Zahlung gesperrt) mit Sitemap.
- Solange nichts gespeichert wurde, gilt wie bisher der Wert `APP_NOINDEX` aus der `.env`.
- Jede Umschaltung wird im Änderungsprotokoll mit Name und Zeit gespeichert.
- Hinweisleiste oben in der Verwaltung, solange die Website offline oder die Indexierung gesperrt ist.
- Die getrennte technische Wartung bei Updates (`storage/maintenance.flag`) bleibt unverändert.

**Dateien:** `src/Module/Ops/SiteStatus.php` (neu), `src/Admin/SiteStatusController.php` (neu), `templates/admin/site/status.twig` (neu), `templates/public/offline.twig` (neu), `src/Core/App.php`, `src/Module/Seo/SeoController.php`, `src/Admin/AdminModule.php`, `templates/admin/layout.twig`, `public/assets/css/app.css`, `public/assets/css/admin.css`, `tests/Integration/SiteStatusTest.php` (neu).

**Nebenbei:** Text der Vorschau-Leiste gekürzt („Vorschau mit Beispieldaten: Preise und Termine sind nicht echt.“).

**Geprüft:** 107 automatische Tests grün (4 neue für diesen Punkt), Wartungsseite und Verwaltungsseite am Desktop und Handy angesehen.
