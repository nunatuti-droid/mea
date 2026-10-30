# Betrieb und Notfälle

Diese Anleitung beschreibt Sicherungen, Updates, Schutzfunktionen und was im Notfall zu tun ist. Alle Befehle werden per SSH im Projektordner ausgeführt (`cd /pfad/zur/website`).

## Überblick

| Aufgabe | Wo | Takt |
|---|---|---|
| Reservierungen freigeben, E-Mails senden | Cron | jede Minute |
| Abgelaufene Tokens, Sitzungen, Sperren, temporäre Dateien entfernen | Cron | stündlich |
| Sicherung, alte Sicherungen aufräumen | Cron | nachts ab 2 Uhr |
| Anonymisierung nach Fristen | Cron | nachts |
| Terminerinnerungen an Teilnehmende | Cron | nachts |
| Dateiwächter, Feiertage, Systemprüfung | Cron | nachts |
| Erinnerung „Sicherung extern speichern“ | Cron | monatlich, wenn 30 Tage keine Sicherung heruntergeladen wurde |
| Stand aller Jobs, Sicherungen, Dateiwächter | Verwaltung → System | jederzeit |

Cron-Eintrag (einmal einrichten):

```
* * * * * cd /pfad/zur/website && php bin/console cron >/dev/null 2>&1
```

Jeder Job hat eine eigene Sperre und einen Eintrag in `cron_runs`. Schlägt ein Job fehl, bekommen alle aktiven Administratoren eine E-Mail; der Job wird frühestens 30 Minuten später erneut versucht. Ein einzelner Job lässt sich sofort starten: `php bin/console cron backup`.

## Schlüssel

| Schlüssel | Ort | Zweck | Wenn er verloren geht |
|---|---|---|---|
| `APP_KEY` | `.env` | verschlüsselt 2FA-Geheimnisse, Hash für IP-Adressen; ohne `BACKUP_KEY` auch die Sicherungen | 2FA aller Konten neu einrichten (`user:reset-2fa`), alte Sicherungen nicht mehr lesbar |
| `BACKUP_KEY` (optional) | `.env` | eigener Schlüssel für Sicherungen | Sicherungen nicht mehr lesbar |
| `RELEASE_PUBLIC_KEY` | `.env` | prüft Updatepakete und die Referenz des Dateiwächters | Updates werden abgelehnt, bis der Schlüssel wieder eingetragen ist |
| geheimer Release-Schlüssel | nur Entwicklungsrechner | signiert Updates | neues Schlüsselpaar erzeugen und `RELEASE_PUBLIC_KEY` ersetzen |

Die `.env` ist **nicht** in den Sicherungen enthalten. Bewahren Sie eine Kopie der Schlüssel getrennt auf (z. B. Passwortmanager). Eine Sicherung ohne passenden Schlüssel ist wertlos.

## Sicherungen

- Inhalt: komplette Datenbank (ohne Sperrlisten und Cron-Stand), hochgeladene Originale (`storage/uploads`) und Medien (`public/media`). Programmdateien sind nicht enthalten; sie kommen aus dem Repository oder einem Updatepaket.
- Format: ZIP mit Inhaltsverzeichnis und SHA-256 je Datei, verschlüsselt mit libsodium (XChaCha20-Poly1305). Ablage in `storage/backups` (Rechte 0600, außerhalb des Webroots).
- Aufbewahrung (Verwaltung → Einstellungen): nächtliche Sicherungen 14 Tage, manuelle 90 Tage. Die drei neuesten erfolgreichen Sicherungen bleiben immer.
- **Externe Kopie:** Eine Sicherung auf demselben Server hilft nicht bei einem Serverausfall. Laden Sie regelmäßig die neueste Sicherung herunter (Verwaltung → Sicherungen) und bewahren Sie sie getrennt auf. Die Systemprüfung warnt, wenn das länger als 35 Tage her ist; zusätzlich kommt monatlich eine Erinnerungs-E-Mail.

### Wiederherstellen

In der Verwaltung unter **Sicherungen → Wiederherstellen** (erneute Passwortbestätigung und Eingabe von `WIEDERHERSTELLEN`) oder per SSH:

```
php bin/console backup:list
php bin/console backup:restore <id> --ja
```

Ablauf: Sicherung prüfen → aktuellen Stand sichern → Wartungsmodus → Datenbank einspielen → ggf. Datenbank auf den aktuellen Programmstand bringen → Dateien austauschen → Prüfung (Tabellen und Zeilenzahlen). Schlägt ein Schritt fehl, wird der vorher gesicherte Stand automatisch zurückgespielt.

Eine Sicherung von einem anderen Server kann in der Verwaltung hochgeladen werden. Sie muss mit demselben Schlüssel erstellt worden sein.

Grenzfall: Sicherungen aus einer **älteren** Programmversion werden nach dem Einspielen automatisch migriert. Sicherungen aus einer **neueren** Version als der installierten sollten nicht eingespielt werden; erst das passende Update installieren.

## Updates

Updates kommen als signiertes Paket (`.zip`) vom Entwickler. Das Paket enthält den vollständigen Programmstand, eine Beschreibung mit SHA-256 je Datei und eine Ed25519-Signatur. Geprüft wird gegen `RELEASE_PUBLIC_KEY` aus der `.env`, nie gegen eine Prüfsumme aus dem Paket selbst.

Installation in der Verwaltung unter **Updates** (Paket hochladen → Prüfergebnis lesen → installieren mit Passwortbestätigung) oder per SSH:

```
php bin/console update:install update-1.2.0.zip --ja
```

Ablauf: Signatur, Prüfsummen, Version, PHP-Version und Erweiterungen prüfen → Sicherung → Wartungsmodus → alte Dateien zur Rücknahme ablegen (`storage/updates/rollback-<id>`) → neue Dateien schreiben, veraltete entfernen → Datenbank migrieren → Selbsttest in einem eigenen PHP-Prozess. Schlägt der Selbsttest fehl, werden Dateien und Datenbank automatisch zurückgesetzt.

Überschrieben werden nur Programmbereiche (`src`, `templates`, `bin`, `migrations`, `vendor`, `public/assets`, `public/index.php`, `public/.htaccess`, `composer.*`, `VERSION`, Dateiwächter-Referenz). `.env`, `storage/` und `public/media` werden nie verändert.

Letztes Update zurücknehmen: Verwaltung → Updates → „Letztes Update zurücknehmen“ oder `php bin/console update:revert --ja`. Dabei wird auch die Datenbank auf den Stand vor dem Update gesetzt; seither eingegangene Buchungen gehen dabei verloren. Deshalb vorher eine Sicherung herunterladen.

### Paket bauen (nur Entwicklungsrechner)

```
php bin/console release:keygen ~/mea-release-keys          # einmalig; Ordner außerhalb des Projekts, gibt RELEASE_PUBLIC_KEY aus
# Build-Ordner: sauberer Checkout + composer install --no-dev --optimize-autoloader
php bin/console release:build /pfad/build 1.2.0 "$(cat ~/mea-release-keys/mea-release.secret)" update-1.2.0.zip --changelog="…" --min=1.0.0
```

Der geheime Schlüssel kommt nie auf den Server und nie ins Repository.

## Dateiwächter

Vergleicht alle Programmdateien mit der signierten Referenz (`integrity.json` + `integrity.sig`, erzeugt beim Paketbau). Meldet geänderte, fehlende und unerwartete Dateien sowie alles in `public/media`, was kein Bild oder PDF ist. Läuft nachts; manuell in der Verwaltung (System → „Dateien jetzt prüfen“) oder per `php bin/console integrity:check`.

Bei einer Meldung: nicht ignorieren. Prüfen, ob gerade jemand Dateien von Hand geändert hat. Falls nicht: Wartungsmodus an, Zugangsdaten ändern, aktuelles Updatepaket erneut installieren (stellt alle Programmdateien wieder her), unerwartete Dateien löschen, Logs prüfen.

Empfehlung für den Server: Programmdateien für den Webserver nur lesbar machen, schreibbar nur `storage/` und `public/media`. Dann kann auch eine Sicherheitslücke keine Programmdateien verändern; Updates laufen dann per SSH.

## Schutzschicht (Firewall)

- Prüft Pfad und Abfrage jeder Anfrage auf typische Angriffsmuster (z. B. `wp-login.php`, `../`, `<script`, SQL-Einschleusung, `.env`). Formularinhalte werden nicht untersucht.
- Jede Auffälligkeit gibt Punkte für die IP-Adresse; fehlgeschlagene Anmeldungen und 2FA-Codes je 1 Punkt. Bei 10 Punkten in 10 Minuten wird die Adresse 60 Minuten gesperrt, bei Wiederholung innerhalb von 7 Tagen 24 Stunden.
- IP-Adressen werden nur als Hash und gekürzt (z. B. `203.0.113.x`) gespeichert.
- Verwaltung → Sicherheit: Ereignisse, aktive Sperren, eigene Sperr- und Erlaubnislisten (IP oder Bereich). Die eigene Adresse kann nicht gesperrt werden.

## Weiterleitungen und nicht gefundene Adressen

Verwaltung → **Weiterleitungen**: alte Adressen auf neue Seiten umleiten (301) oder als entfernt melden (410). Ein `*` am Ende gilt für alle Unteradressen. Darüber steht die Liste der Adressen, die Besucher zuletzt vergeblich aufgerufen haben (ohne IP-Adressen gespeichert, nach 180 Tagen gelöscht). Mit „Weiterleitung“ wird die Adresse direkt übernommen.

## Wartungsmodus

Besucher sehen eine Wartungsseite (HTTP 503), die Verwaltung bleibt erreichbar. Ein- und Ausschalten: Verwaltung → Updates oder `php bin/console maintenance:on` / `maintenance:off`. Updates und Wiederherstellungen schalten ihn selbst ein und aus.

## Notfälle per SSH

Die Verwaltung führt bewusst keine Shell-Befehle aus. Für Notfälle gibt es feste Befehle:

| Lage | Befehl |
|---|---|
| Eigene IP gesperrt | `php bin/console security:unblock 203.0.113.7` oder `--alle` |
| Handy mit Authenticator verloren | `php bin/console user:reset-2fa person@example.org` (nächste Anmeldung richtet 2FA neu ein) |
| Kein Administrator mehr | `php bin/console user:create-admin person@example.org "Name"` |
| Website nach Update kaputt | `php bin/console update:revert --ja` |
| Daten beschädigt | `php bin/console backup:list`, dann `backup:restore <id> --ja` |
| Seite hängt im Wartungsmodus | `php bin/console maintenance:off` |
| Funktioniert die Anwendung? | `php bin/console selftest` |
| Dateien manipuliert? | `php bin/console integrity:check` |

Logs: `storage/logs/`.

### Totalausfall des Servers

1. Neuen Webspace mit PHP 8.4 und MySQL/MariaDB einrichten.
2. Programmstand aus dem Repository oder dem letzten Updatepaket hochladen, `composer install --no-dev` (falls nicht im Paket).
3. `.env` aus der getrennt aufbewahrten Kopie anlegen (gleicher `APP_KEY` bzw. `BACKUP_KEY`!).
4. `php bin/console migrate`, ersten Administrator anlegen (`user:create-admin`).
5. In der Verwaltung unter Sicherungen die extern gespeicherte Sicherung hochladen und wiederherstellen.
6. Cron einrichten, Systemprüfung aufrufen.
