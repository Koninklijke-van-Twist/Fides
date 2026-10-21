# Fides

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git), naast de Business Central-credentials:

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet gaan OData-fetches en company-discovery eerst naar Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Fides dezelfde data op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over. Laat die BC-credentials in `auth.php` staan naast `$mimirApi`; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug.

Dat geldt voor de live pagina's (`index.php`, `contract_progress.php`, `contract_export.php`) én voor CLI: er is geen aparte nachtelijke job, maar `php`-scripts die `odata.php` gebruiken krijgen onder `cli` de lange Mímir-timeout (600s). Webverzoeken gebruiken ongeveer 90s, met een connect-timeout van 10s. Zonder `$mimirApi` blijft alleen het bestaande BC-pad actief.

De Mímir-fallback hoort in `web/odata.php`. Tim Falken heeft die uitzondering op de regel “`odata.php` niet wijzigen” goedgekeurd op 2026-09-28.

Zie `web/auth_TEMPLATE.php`.

`web/analytics/analytics.sqlite` (en `*.sqlite-*`) blijft op de server staan bij een deploy: de FTP-mirror slaat die bestanden al over. Dezelfde mirror slaat `.htaccess` over, dus een gecommit bestand komt niet mee en een bestaand bestand wordt niet gewist. `analytics.php` schrijft `web/analytics/.htaccess` als die ontbreekt (of vult hem aan als het woord `sqlite` er nog niet in staat), zodat Apache de database weigert en `analytics.php` zelf bereikbaar blijft.
