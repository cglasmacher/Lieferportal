# Lieferportal

Regionaler Bestellmarktplatz. Restaurants sind Verkäufer, Zahlungsempfänger und Lieferverantwortliche. Geplante Vermittlungsprovision: 5 % auf den Bruttowarenwert, ohne Lieferung, Trinkgeld oder Pfand, zuzüglich Umsatzsteuer auf die Provision.

## Aktueller Entwicklungsstand

- Restaurantportal unter `/portal`: Session-Login mit CSRF-Schutz und Anmeldelimit, rollenbasierte Restaurantzuordnung, Speisekartenverwaltung und Lieferparameter. Inhaber/Manager bearbeiten, Mitarbeiter lesen.
- Laravel 13, React 19 und TypeScript; responsive Restaurantliste, Flyer-Einstieg `/r/{slug}`, Speisekarte und Warenkorb.
- Versionierte Katalog- und Angebotsendpunkte `/api/v1/restaurants`, `/api/v1/restaurants/{slug}` und `POST /api/v1/restaurants/{slug}/quote`.
- Preise werden serverseitig ermittelt; Prüfung von Liefergebiet, Mindestbestellwert, Verfügbarkeit und Restaurantzugehörigkeit.
- Getrennte, getestete Request-Spezifikationen für Stripe Connect Direct Charges und PayPal Multiparty mit Restaurant als Zahlungsempfänger.
- Checkout ist absichtlich vollständig gesperrt. Die Request-Spezifikationen senden keine API-Anfragen. Es werden keine Bestellungen gespeichert, keine Händlerkonten angebunden und keine Zahlungen gestartet.

## Lokal starten

Voraussetzungen: PHP 8.4.27+, Composer 2, Node 22.12+ und npm. PHP-Erweiterungen: PDO SQLite für Entwicklung/Tests, PDO MySQL für Produktion, mbstring, XML, curl, zip. Die Composer-Plattform ist auf PHP 8.4.27 festgelegt.

```bash
composer install
cp .env.example .env
php artisan key:generate
# Für lokale Entwicklung: DB_CONNECTION=sqlite in .env setzen.
# Die Datei database/database.sqlite einmal leer anlegen.
php artisan migrate
php artisan db:seed --class=DemoCatalogSeeder
npm ci
npm run build
php artisan serve
```

Dann `http://127.0.0.1:8000/r/demo-kueche` öffnen. Demo-Liefergebiete: 40721, 40723, 40724. Beispieldaten sind fiktiv; der Demo-Seeder verweigert Produktion und ist wiederholbar. Der normale Seeder legt keine Betriebe oder Testbenutzer an.

Für Frontend-Entwicklung `npm run dev` zusätzlich zu `php artisan serve` starten.

## Tests

```bash
php artisan test
npm run build
vendor/bin/pint --test
```

Feature-Tests verwenden SQLite im Speicher. CI prüft zusätzlich Migrationen auf MySQL. Geldbeträge werden in Integer-Cents gespeichert/verarbeitet. Provision und deren Umsatzsteuer werden je Bestellung kaufmännisch gerundet. Bei 30,00 EUR Warenwert: 1,50 EUR Nettoprovision + 0,29 EUR Umsatzsteuer = 1,79 EUR Bruttogebühr.

## Payment: nächste Umsetzung

Stripe: eigenes verbundenes Konto je Restaurant, Direct Charges mit Händlerkontext; Händlerprüfung, Fähigkeiten, Gebühren- und Verlusthaftung vor Aktivierung bestätigen. Niemals ohne Händlerkonto auf das Plattformkonto zurückfallen.

PayPal: Multiparty-/Plattformfreigabe und Verkäufer-Onboarding vor Zahlung. Restaurant-Merchant-ID als `payee`, Plattformgebühr separat. Normales PayPal Checkout für unser eigenes Geschäftskonto erfüllt dieses Modell nicht. Partnerfreigabe und Gebührenfunktionen mit PayPal klären; ein erfolgreicher Browser-Redirect ist kein Zahlungsnachweis.

Vor Freischaltung fehlen insbesondere persistente Bestellungen/Zahlungsversuche, Zahlungsanbieter-Onboarding, Provider-Clients, signaturgeprüfte Webhooks, Idempotenz, Erstattungen und Abgleich. Je Anbieter ein Zahlungsversuch, keine automatische Umschaltung einer laufenden Zahlung. Geheimnisse nur serverseitig, niemals im Repository oder Vite-Variablen.

## Geplante Ausbauschritte

1. Bestell- und Zahlungsmodell mit Zustandsübergängen. Restaurant-Login, Rollen und Speisekartenverwaltung sind implementiert.
2. Stripe- und PayPal-Onboarding, Sandbox-Zahlungen, bestätigte Provider-Ereignisse und Erstattungen.
3. Restaurant-Bestelltafel, Annahmefristen, Benachrichtigungen und Provisionsabrechnung.
4. Zwei eigene Capacitor-Apps für Kunden und Fahrer auf derselben API; Hintergrundortung zuerst auf echten Geräten erproben.
5. Restaurantbezogene Flyer-Kampagnen und messbare Wiederbestellungen; datenschutzgerechte Zuordnung gesondert implementieren.

## Plesk / Betrieb

Document Root: `public/`. `.env` nicht veröffentlichen. Für Produktion MySQL konfigurieren, `APP_DEBUG=false`, HTTPS, sichere Cookies und `APP_KEY` setzen. Kein `migrate:fresh` in Produktion verwenden. Diese Vorschau ist noch nicht für öffentlichen Bestellbetrieb freigegeben. Öffnungszeiten, Adressprüfung, Varianten, Rabatte, Steuerlogik für Speisen, rechtliche Pflichtinformationen und Supportabläufe müssen vor einem öffentlichen Start vervollständigt werden.

## Restaurantzugang einrichten

Nach dem Update zuerst `php artisan migrate` und `npm ci && npm run build` ausführen.

Für ein echtes Restaurant legt die Plattformverwaltung zunächst einen unveröffentlichten und pausierten Datensatz an:

```bash
php artisan restaurant:create mein-restaurant "Mein Restaurant"
php artisan merchant:grant mein-restaurant betreiber@example.de --name="Max Muster"
```

Der zweite Befehl fragt bei neuen Benutzern ein Passwort zweimal verdeckt ab (mindestens 12 Zeichen). Es gibt keine Standardpasswörter und keine Passwörter als Kommandoargument. Für vorhandene Benutzer bleibt das Passwort unverändert. Die Rolle ist standardmäßig `owner`; alternativ `--role=manager` oder `--role=staff`. Die Befehle sind nur für vertrauenswürdige Serveradministratoren gedacht und senden keine E-Mails. Keine öffentliche Registrierung, kein Passwort-Reset und noch keine Benutzerverwaltung im Browser.

Für die lokale Demo nach dem Demo-Seeder:

```bash
php artisan merchant:grant demo-kueche betreiber@example.de --name="Demo Betreiber"
```

Anmeldung anschließend unter `/portal/login`. Menüänderungen werden in der Kundenansicht sichtbar; pausierte Artikel verschwinden dort. Liefer-Postleitzahlen, Mindestbestellwert, Lieferkosten und geschätzte Zeit sind bearbeitbar. Veröffentlichungsfreigabe und Zahlungsanbieter-IDs können über diese Endpunkte nicht geändert werden. Restaurant-Stammdaten und Veröffentlichung bleiben bis zum vollständigen Onboarding Aufgabe der Plattformverwaltung. Das Aktivieren der Bestellannahme schaltet den weiterhin gesperrten Checkout nicht frei.

## Alternative Monatstarife (Konfigurationsstand)

Neben Flex mit 5 % Provision gibt es monatliche Pauschalen je Restaurantstandort. Alle Preise netto zuzüglich Umsatzsteuer, Zahlungsanbietergebühren separat. Bei Pauschalen fällt **keine zusätzliche Bestellprovision** an. Preise und Leistungsstaffelung liegen zentral in `config/billing.php`.

| Tarif | Monat netto | Bestellprovision | Teamzugänge | Speisekarten-CSV | Änderungsprotokoll |
|---|---:|---:|---:|---|---|
| Flex | 0 € | 5 % | unbegrenzt | nein | nein |
| Starter | 49 € | 0 % | 2 | nein | nein |
| Wachstum | 89 € | 0 % | 5 | ja | nein |
| Pro | 129 € | 0 % | 15 | ja | letzte 50 Einträge |

Alle Tarife enthalten die implementierte Restaurantseite, Speisekarte und Lieferparameter. Fahrer-App, Website-Widget, Flyer-Generator, Kampagnenauswertung und weitere Integrationen sind in den Tarifkarten ausdrücklich als **geplante**, noch nicht verfügbare Vorteile markiert. Die Paketstaffelung ist eine erste Produktfestlegung und zentral anpassbar.

Inhaber sehen im Portal die Tarifkarten, den aktuellen Tarif und eine Monatsgebührenvorschau. Eine Tarifanfrage ändert keine Gebühren und löst keine Zahlung aus. Manager/Mitarbeiter können keine Tarifwechsel anfragen. Nach außerhalb der Software geklärter Vereinbarung ordnet die Plattformverwaltung den Tarif zu:

```bash
php artisan billing:set-plan mein-restaurant starter
# Alternativen: flex, growth, pro
```

Teamlimits gelten einschließlich Inhaber; sie werden bei Zugangserteilung geprüft. Ein Wechsel in einen Tarif mit weniger Plätzen als vorhandenen Zuordnungen wird abgewiesen. Bestehende Zuordnungen werden nicht automatisch gelöscht. Tarif-, Speisekarten- und Lieferparameteränderungen werden protokolliert. In Pro sehen berechtigte Inhaber/Manager die letzten 50 Ereignisse ihres Restaurants.

`Plans::forOrder()` berechnet die tarifabhängige Bestellprovision, `Plans::monthlyFee()` die Monatsgebühr einschließlich separat ausgewiesener Umsatzsteuer. Die vorbereiteten Stripe-/PayPal-Requests lassen bei 0 % die Plattformgebühr weg. Künftige Zahlungsaufrufe müssen den Tarifservice verwenden; der niedrigere Commission-Rechner bleibt ein reiner Rechenbaustein.

**Noch keine automatische Abo-Abrechnung:** Die Vorschau nimmt einen vollen Monat an. Rechnungsstellung, Einzug der Monatsgebühren, Vertragslaufzeit, Kündigung, Wechsel zum Periodenbeginn, anteilige Monate und historische Periodenabrechnung sind noch nicht implementiert. `billing:set-plan` ändert die aktuelle Konfiguration unmittelbar; es ist kein rückwirkender Abrechnungsauftrag. Historische Abrechnungen dürfen später nicht aus dem jeweils aktuellen Tarif neu berechnet werden. Bestellcheckout bleibt gesperrt.
