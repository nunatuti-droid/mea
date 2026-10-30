# Installation auf dem Server (Netcup / Plesk)

Diese Anleitung richtet die Website auf einem Webhosting mit Plesk ein. Sie gilt sinngemäß für jedes Hosting mit PHP 8.4, MySQL 8 oder MariaDB 10.6+, SSH und Cron. Bezeichnungen in Plesk können je nach Version leicht abweichen.

Netcup-spezifische Punkte konnten noch nicht auf dem echten Server geprüft werden (kein Zugang). Sie sind mit **(auf dem Server prüfen)** markiert.

## 1. Voraussetzungen

| Was | Wert |
|---|---|
| PHP | 8.4 (8.3 geht auch), als FPM oder FastCGI |
| PHP-Erweiterungen | `pdo_mysql`, `intl`, `mbstring`, `gd` mit WebP, `sodium`, `zip`, `openssl`, `curl`, `fileinfo` |
| PHP-Einstellungen | `upload_max_filesize` ≥ 25M, `post_max_size` ≥ 30M (PDFs bis 20 MB), `memory_limit` ≥ 256M (Bildvarianten) |
| Datenbank | MySQL 8.0+ oder MariaDB 10.6+, Zeichensatz `utf8mb4` |
| Zugang | SSH (für Composer, Migrationen und Notfallbefehle), Cron im Minutentakt |
| HTTPS | Zertifikat (Let's Encrypt in Plesk) |

## 2. Ordner und Document Root

Empfohlen: Die neue Website bekommt einen eigenen Ordner neben WordPress, z. B.

```
/httpdocs/               ← bisher WordPress (bleibt bis nach dem Umzug unverändert)
/mea-app/                ← neue Anwendung (Projektordner)
/mea-app/public/         ← Document Root der neuen Website
```

In Plesk: **Websites & Domains → Domain → Hosting-Einstellungen → Dokumentenstamm** auf `mea-app/public` setzen. **(auf dem Server prüfen)**

Für die Vorschau zuerst eine Subdomain (z. B. `neu.mea.ghazi-online.de`) mit diesem Dokumentenstamm anlegen. Beim Livegang wird nur der Dokumentenstamm der Hauptdomain umgestellt (siehe `golive.md`). So bleibt WordPress für einen Rückweg unberührt.

**Ausweichlösung**, falls der Dokumentenstamm nicht auf `public/` zeigen kann: Die Datei `/.htaccess` im Projektordner leitet alle Anfragen nach `public/` um. Programmdateien, `.env`, `storage/` und Sicherungen sind auch dann nicht abrufbar (mit Apache 2.4 geprüft, siehe Testbericht). Die empfohlene Variante bleibt `public/` als Dokumentenstamm.

## 3. Dateien hochladen

Variante A (empfohlen): per Git und SSH

```bash
cd ~ && git clone <Repository> mea-app && cd mea-app
composer install --no-dev --optimize-autoloader
```

Variante B: Projektordner lokal mit `composer install --no-dev --optimize-autoloader` vorbereiten und per SFTP hochladen (ohne `.git`, `tests`, `node_modules`).

Spätere Updates kommen als signiertes Paket über die Verwaltung (siehe `betrieb.md`).

## 4. Datenbank und Konfiguration

1. In Plesk eine Datenbank und einen eigenen Benutzer anlegen (Zeichensatz `utf8mb4_unicode_ci`).
2. `.env` aus `.env.example` erzeugen und ausfüllen:

```bash
cp .env.example .env
chmod 600 .env
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # für APP_KEY
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # für BACKUP_KEY
```

Wichtige Werte für den Betrieb:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mea.ghazi-online.de
APP_NOINDEX=false          # auf der Vorschau-Subdomain: true
DB_HOST=… DB_NAME=… DB_USER=… DB_PASS=…
APP_KEY=…                  # nie wieder ändern (sonst 2FA und Sicherungen unlesbar)
BACKUP_KEY=…
RELEASE_PUBLIC_KEY=…       # vom Entwickler
MAIL_DSN=smtp://…  MAIL_FROM=…  MAIL_FROM_NAME="MEA Bildungszentrum"
PAYPAL_MODE=sandbox        # erst nach erfolgreichem Testkauf: live
PAYPAL_CLIENT_ID=… PAYPAL_SECRET=… PAYPAL_WEBHOOK_ID=…
```

3. **Die `.env` sofort zusätzlich getrennt aufbewahren** (Passwortmanager). Ohne `APP_KEY`/`BACKUP_KEY` lassen sich Sicherungen nicht öffnen.

4. Datenbank anlegen und Startdaten einspielen:

```bash
php bin/console migrate
php bin/console seed            # ohne --demo! (keine Beispieltermine im Betrieb)
php bin/console user:create-admin person@example.org "Vorname Name"
```

Der letzte Befehl gibt einen Einladungslink aus. Darüber Passwort und Zwei-Faktor-Anmeldung einrichten. Eine zweite Person als Vertretung einladen (Verwaltung → Benutzer).

## 5. Dateirechte

```bash
chmod 600 .env
chmod -R u+rwX,go-rwx storage
chmod -R u+rwX,go+rX public/media
```

Der Webserver braucht Schreibrechte nur für `storage/` und `public/media/`. Empfehlung: Alle anderen Programmdateien für den Webserver-Benutzer nur lesbar machen. Dann kann selbst eine Sicherheitslücke keine Programmdateien verändern. Updates laufen dann per SSH (`php bin/console update:install paket.zip --ja`). **(auf dem Server prüfen, welcher Benutzer PHP ausführt)**

## 6. Cronjob

In Plesk: **Websites & Domains → Geplante Aufgaben → Aufgabe hinzufügen**, Typ „Befehl ausführen“, jede Minute (`* * * * *`):

```
cd /pfad/zu/mea-app && /opt/plesk/php/8.4/bin/php bin/console cron
```

Den PHP-Pfad an die in Plesk gewählte Version anpassen. **(auf dem Server prüfen)**

## 7. Besucher-IP hinter dem Proxy

Plesk stellt oft nginx vor Apache. Der Anwendungsschutz nutzt nur `REMOTE_ADDR`. Prüfen Sie nach der Einrichtung unter **Verwaltung → Sicherheit**, dass bei Ereignissen echte (gekürzte) Adressen stehen und nicht `127.0.0.x`. Kommen alle Anfragen von `127.0.0.1`, sperrt die Anwendung niemanden und meldet ein kritisches Ereignis „proxy_ip“. Dann in Plesk die Weitergabe der Besucher-IP aktivieren bzw. den Hoster fragen.

## 8. Signierte Referenz für den Dateiwächter

Mit jedem Updatepaket kommt eine signierte Liste der Programmdateien mit. Bei der Erstinstallation per Git erzeugt der Entwickler sie mit dem geheimen Schlüssel auf seinem Rechner (`php bin/console release:sign-manifest <geheimer Schlüssel>` im selben Programmstand) und lädt `integrity.json` und `integrity.sig` hoch. Danach: `php bin/console integrity:check`.

## 9. Prüfen

```bash
php bin/console selftest
php bin/console golive:check
```

`golive:check` listet alles, was vor dem Livegang noch fehlt, und endet mit Fehlercode, solange etwas offen ist. Zusätzlich in der Verwaltung **System → Systemprüfung** aufrufen.

Zum Schluss eine Sicherung erstellen, herunterladen und einmal wiederherstellen (Verwaltung → Sicherungen). Erst dann gilt die Installation als abgenommen.

## 10. PayPal und E-Mail

- Webhook in der PayPal-Developer-Konsole: `https://DOMAIN/zahlung/paypal/webhook/` mit den Ereignissen `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`. Die Webhook-ID in `PAYPAL_WEBHOOK_ID` eintragen.
- Erst im Sandbox-Modus eine Testbuchung vollständig durchspielen (inklusive Erstattung), dann auf `live` umstellen.
- E-Mail: in der Verwaltung unter **E-Mail-Vorlagen → Testversand** an eine eigene Adresse senden. SPF/DKIM der Absenderdomain beim Hoster prüfen, sonst landen Mails im Spam.
