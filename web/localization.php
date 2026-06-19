<?php

/**
 * Constants
 */

const FLAG_SVGS = [
    'nl' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#AE1C28"/><rect width="900" height="400" fill="#fff"/><rect width="900" height="200" fill="#fff"/><rect width="900" height="200" y="0" fill="#AE1C28"/><rect width="900" height="200" y="200" fill="#fff"/><rect width="900" height="200" y="400" fill="#21468B"/></svg>',
    'en' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40"><clipPath id="a"><path d="M0 0v40h60V0z"/></clipPath><clipPath id="b"><path d="M30 20h30v20zv20H0zH0V0zV0h30z"/></clipPath><g clip-path="url(#a)"><path d="M0 0v40h60V0z" fill="#012169"/><path d="M0 0l60 40m0-40L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40m0-40L0 40" clip-path="url(#b)" stroke="#C8102E" stroke-width="5"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="8"/></g></svg>',
    'de' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5 3"><rect width="5" height="3" y="0" fill="#000"/><rect width="5" height="2" y="1" fill="#D00"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg>',
    'fr' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#ED2939"/><rect width="600" height="600" fill="#fff"/><rect width="300" height="600" fill="#002395"/></svg>',
];

const SUPPORTED_LANGUAGES = [
    'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    'en' => ['flag' => '🇬🇧', 'label' => 'English'],
    'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
    'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
];

const LOCALE_BY_LANG = [
    'nl' => 'nl-NL',
    'en' => 'en-GB',
    'de' => 'de-DE',
    'fr' => 'fr-FR',
];

const TRANSLATIONS = [
    'nl' => [
        'lang.menu_aria' => 'Taal kiezen',
        'lang.switch_to' => 'Schakel naar %s',
        'app.title' => 'Contracten',
        'contract.hero.title' => 'Contracten & werkorders',
        'contract.hero.subtitle' => 'Zoek op klantnaam, klantnummer of contractnummer.',
        'contract.label.company' => 'Bedrijf',
        'contract.label.search' => 'Zoekterm',
        'contract.placeholder.search' => 'Klantnaam, klantnr. of contractnr.',
        'contract.btn.search' => 'Zoeken',
        'contract.btn.open_contract' => 'Open contract',
        'contract.btn.back' => 'Nieuwe zoekopdracht',
        'contract.section.customers' => 'Kies een klant',
        'contract.section.contracts' => 'Contracten',
        'contract.section.workorders' => 'Werkorders',
        'contract.section.components' => 'Componenten',
        'contract.section.reports' => 'Rapporten',
        'contract.meta.customer' => 'Klant',
        'contract.meta.status' => 'Status',
        'contract.meta.period' => 'Periode',
        'contract.meta.type' => 'Type',
        'contract.col.workorder' => 'Werkorder',
        'contract.col.task' => 'Taak',
        'contract.col.status' => 'Status',
        'contract.col.component' => 'Component',
        'contract.col.serial' => 'Serienr.',
        'contract.col.manufacturer' => 'Merk',
        'contract.col.manufacturer_model' => 'Model',
        'contract.col.motor_type' => 'Motortype',
        'contract.col.model_variant' => 'Modeluitvoering',
        'contract.component.extra_info' => 'Extra Informatie',
        'contract.customer.contract_count_one' => '1 contract',
        'contract.customer.contract_count_many' => '%s contracten',
        'contract.link.pdf' => 'PDF',
        'contract.link.excel' => 'Excel',
        'contract.empty.contracts' => 'Geen contracten gevonden voor deze klant.',
        'contract.empty.workorders' => 'Geen werkorders op dit contract.',
        'contract.empty.components' => 'Geen componenten gevonden op dit contract.',
        'contract.empty.reports' => 'Geen rapportlinks beschikbaar.',
        'contract.error.query_required' => 'Vul een klantnaam, klantnummer of contractnummer in.',
        'contract.error.not_found' => 'Geen klant of contract gevonden.',
        'contract.error.contract_required' => 'Contractnummer ontbreekt.',
        'contract.error.contract_not_found' => 'Contract niet gevonden.',
        'contract.error.load_failed' => 'Gegevens ophalen mislukt. Probeer het later opnieuw.',
        'contract.tasks.count' => '%s onderhoudstaken',
        'contract.workorders.count' => '%s werkorders',
        'contract.loader.wait' => 'Even geduld...',
        'contract.loader.loading' => 'Gegevens ophalen uit Business Central',
        'contract.label.filter_detail' => 'Filter',
        'contract.placeholder.filter_detail' => 'Component-, werkorder- of serienummer',
        'contract.empty.filter' => 'Geen resultaten voor deze filter.',
        'contract.btn.export' => 'Export',
        'contract.export.col.field' => 'Veld',
        'contract.export.col.value' => 'Waarde',
        'contract.export.col.contract_no' => 'Contractnummer',
        'contract.export.col.period_from' => 'Periode van',
        'contract.export.col.period_to' => 'Periode tot',
        'contract.export.col.feature_code' => 'Feature_Code',
        'contract.export.col.feature_value' => 'Value',
    ],

    'en' => [
        'lang.menu_aria' => 'Choose language',
        'lang.switch_to' => 'Switch to %s',
        'app.title' => 'Contracts',
        'contract.hero.title' => 'Contracts & work orders',
        'contract.hero.subtitle' => 'Search by customer name, customer number or contract number.',
        'contract.label.company' => 'Company',
        'contract.label.search' => 'Search term',
        'contract.placeholder.search' => 'Customer name, customer no. or contract no.',
        'contract.btn.search' => 'Search',
        'contract.btn.open_contract' => 'Open contract',
        'contract.btn.back' => 'New search',
        'contract.section.customers' => 'Choose a customer',
        'contract.section.contracts' => 'Contracts',
        'contract.section.workorders' => 'Work orders',
        'contract.section.components' => 'Components',
        'contract.section.reports' => 'Reports',
        'contract.meta.customer' => 'Customer',
        'contract.meta.status' => 'Status',
        'contract.meta.period' => 'Period',
        'contract.meta.type' => 'Type',
        'contract.col.workorder' => 'Work order',
        'contract.col.task' => 'Task',
        'contract.col.status' => 'Status',
        'contract.col.component' => 'Component',
        'contract.col.serial' => 'Serial no.',
        'contract.col.manufacturer' => 'Brand',
        'contract.col.manufacturer_model' => 'Model',
        'contract.col.motor_type' => 'Engine type',
        'contract.col.model_variant' => 'Model variant',
        'contract.component.extra_info' => 'Extra information',
        'contract.customer.contract_count_one' => '1 contract',
        'contract.customer.contract_count_many' => '%s contracts',
        'contract.link.pdf' => 'PDF',
        'contract.link.excel' => 'Excel',
        'contract.empty.contracts' => 'No contracts found for this customer.',
        'contract.empty.workorders' => 'No work orders on this contract.',
        'contract.empty.components' => 'No components found on this contract.',
        'contract.empty.reports' => 'No report links available.',
        'contract.error.query_required' => 'Enter a customer name, customer number or contract number.',
        'contract.error.not_found' => 'No customer or contract found.',
        'contract.error.contract_required' => 'Contract number is missing.',
        'contract.error.contract_not_found' => 'Contract not found.',
        'contract.error.load_failed' => 'Failed to load data. Please try again later.',
        'contract.tasks.count' => '%s maintenance tasks',
        'contract.workorders.count' => '%s work orders',
        'contract.loader.wait' => 'Please wait...',
        'contract.loader.loading' => 'Fetching data from Business Central',
        'contract.label.filter_detail' => 'Filter',
        'contract.placeholder.filter_detail' => 'Component, work order or serial number',
        'contract.empty.filter' => 'No results for this filter.',
        'contract.btn.export' => 'Export',
        'contract.export.col.field' => 'Field',
        'contract.export.col.value' => 'Value',
        'contract.export.col.contract_no' => 'Contract number',
        'contract.export.col.period_from' => 'Period from',
        'contract.export.col.period_to' => 'Period to',
        'contract.export.col.feature_code' => 'Feature_Code',
        'contract.export.col.feature_value' => 'Value',
    ],

    'de' => [
        'lang.menu_aria' => 'Sprache wählen',
        'lang.switch_to' => 'Wechseln zu %s',
        'app.title' => 'Verträge',
        'contract.hero.title' => 'Verträge & Arbeitsaufträge',
        'contract.hero.subtitle' => 'Suche nach Kundenname, Kundennummer oder Vertragsnummer.',
        'contract.label.company' => 'Unternehmen',
        'contract.label.search' => 'Suchbegriff',
        'contract.placeholder.search' => 'Kundenname, Kundennr. oder Vertragsnr.',
        'contract.btn.search' => 'Suchen',
        'contract.btn.open_contract' => 'Vertrag öffnen',
        'contract.btn.back' => 'Neue Suche',
        'contract.section.customers' => 'Kunde wählen',
        'contract.section.contracts' => 'Verträge',
        'contract.section.workorders' => 'Arbeitsaufträge',
        'contract.section.components' => 'Komponenten',
        'contract.section.reports' => 'Berichte',
        'contract.meta.customer' => 'Kunde',
        'contract.meta.status' => 'Status',
        'contract.meta.period' => 'Zeitraum',
        'contract.meta.type' => 'Typ',
        'contract.col.workorder' => 'Arbeitsauftrag',
        'contract.col.task' => 'Aufgabe',
        'contract.col.status' => 'Status',
        'contract.col.component' => 'Komponente',
        'contract.col.serial' => 'Seriennr.',
        'contract.col.manufacturer' => 'Marke',
        'contract.col.manufacturer_model' => 'Modell',
        'contract.col.motor_type' => 'Motortyp',
        'contract.col.model_variant' => 'Modellausführung',
        'contract.component.extra_info' => 'Zusätzliche Informationen',
        'contract.customer.contract_count_one' => '1 Vertrag',
        'contract.customer.contract_count_many' => '%s Verträge',
        'contract.link.pdf' => 'PDF',
        'contract.link.excel' => 'Excel',
        'contract.empty.contracts' => 'Keine Verträge für diesen Kunden gefunden.',
        'contract.empty.workorders' => 'Keine Arbeitsaufträge für diesen Vertrag.',
        'contract.empty.components' => 'Keine Komponenten für diesen Vertrag gefunden.',
        'contract.empty.reports' => 'Keine Berichtlinks verfügbar.',
        'contract.error.query_required' => 'Geben Sie einen Kundennamen, eine Kundennummer oder Vertragsnummer ein.',
        'contract.error.not_found' => 'Kein Kunde oder Vertrag gefunden.',
        'contract.error.contract_required' => 'Vertragsnummer fehlt.',
        'contract.error.contract_not_found' => 'Vertrag nicht gefunden.',
        'contract.error.load_failed' => 'Daten konnten nicht geladen werden. Bitte später erneut versuchen.',
        'contract.tasks.count' => '%s Wartungsaufgaben',
        'contract.workorders.count' => '%s Arbeitsaufträge',
        'contract.loader.wait' => 'Bitte warten...',
        'contract.loader.loading' => 'Daten werden aus Business Central geladen',
        'contract.label.filter_detail' => 'Filter',
        'contract.placeholder.filter_detail' => 'Komponenten-, Arbeitsauftrags- oder Seriennummer',
        'contract.empty.filter' => 'Keine Ergebnisse für diesen Filter.',
        'contract.btn.export' => 'Export',
        'contract.export.col.field' => 'Feld',
        'contract.export.col.value' => 'Wert',
        'contract.export.col.contract_no' => 'Vertragsnummer',
        'contract.export.col.period_from' => 'Zeitraum von',
        'contract.export.col.period_to' => 'Zeitraum bis',
        'contract.export.col.feature_code' => 'Feature_Code',
        'contract.export.col.feature_value' => 'Value',
    ],

    'fr' => [
        'lang.menu_aria' => 'Choisir la langue',
        'lang.switch_to' => 'Passer en %s',
        'app.title' => 'Contrats',
        'contract.hero.title' => 'Contrats & ordres de travail',
        'contract.hero.subtitle' => 'Recherchez par nom client, numéro client ou numéro de contrat.',
        'contract.label.company' => 'Société',
        'contract.label.search' => 'Terme de recherche',
        'contract.placeholder.search' => 'Nom client, n° client ou n° contrat',
        'contract.btn.search' => 'Rechercher',
        'contract.btn.open_contract' => 'Ouvrir le contrat',
        'contract.btn.back' => 'Nouvelle recherche',
        'contract.section.customers' => 'Choisir un client',
        'contract.section.contracts' => 'Contrats',
        'contract.section.workorders' => 'Ordres de travail',
        'contract.section.components' => 'Composants',
        'contract.section.reports' => 'Rapports',
        'contract.meta.customer' => 'Client',
        'contract.meta.status' => 'Statut',
        'contract.meta.period' => 'Période',
        'contract.meta.type' => 'Type',
        'contract.col.workorder' => 'Ordre de travail',
        'contract.col.task' => 'Tâche',
        'contract.col.status' => 'Statut',
        'contract.col.component' => 'Composant',
        'contract.col.serial' => 'N° de série',
        'contract.col.manufacturer' => 'Marque',
        'contract.col.manufacturer_model' => 'Modèle',
        'contract.col.motor_type' => 'Type de moteur',
        'contract.col.model_variant' => 'Version du modèle',
        'contract.component.extra_info' => 'Informations supplémentaires',
        'contract.customer.contract_count_one' => '1 contrat',
        'contract.customer.contract_count_many' => '%s contrats',
        'contract.link.pdf' => 'PDF',
        'contract.link.excel' => 'Excel',
        'contract.empty.contracts' => 'Aucun contrat trouvé pour ce client.',
        'contract.empty.workorders' => 'Aucun ordre de travail sur ce contrat.',
        'contract.empty.components' => 'Aucun composant trouvé sur ce contrat.',
        'contract.empty.reports' => 'Aucun lien de rapport disponible.',
        'contract.error.query_required' => 'Saisissez un nom client, un numéro client ou un numéro de contrat.',
        'contract.error.not_found' => 'Aucun client ou contrat trouvé.',
        'contract.error.contract_required' => 'Numéro de contrat manquant.',
        'contract.error.contract_not_found' => 'Contrat introuvable.',
        'contract.error.load_failed' => 'Échec du chargement des données. Réessayez plus tard.',
        'contract.tasks.count' => '%s tâches de maintenance',
        'contract.workorders.count' => '%s ordres de travail',
        'contract.loader.wait' => 'Veuillez patienter...',
        'contract.loader.loading' => 'Récupération des données depuis Business Central',
        'contract.label.filter_detail' => 'Filtre',
        'contract.placeholder.filter_detail' => 'N° composant, ordre de travail ou n° de série',
        'contract.empty.filter' => 'Aucun résultat pour ce filtre.',
        'contract.btn.export' => 'Export',
        'contract.export.col.field' => 'Champ',
        'contract.export.col.value' => 'Valeur',
        'contract.export.col.contract_no' => 'Numéro de contrat',
        'contract.export.col.period_from' => 'Période du',
        'contract.export.col.period_to' => 'Période au',
        'contract.export.col.feature_code' => 'Feature_Code',
        'contract.export.col.feature_value' => 'Value',
    ],
];

/**
 * Functies
 */

function getUserPrefsPath(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $dir = __DIR__ . '/data/user_prefs';
    $filename = preg_replace('/[^a-z0-9._\-]/', '_', $email) . '.json';
    return $dir . '/' . $filename;
}

function loadUserPrefs(string $email): array
{
    $path = getUserPrefsPath($email);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveUserPref(string $email, string $key, mixed $value): void
{
    $path = getUserPrefsPath($email);
    if ($path === null) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $prefs = loadUserPrefs($email);
    $prefs[$key] = $value;
    file_put_contents($path, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getCurrentLanguage(): string
{
    $lang = (string) ($_SESSION['lang'] ?? 'nl');
    return array_key_exists($lang, SUPPORTED_LANGUAGES) ? $lang : 'nl';
}

function getHtmlLang(): string
{
    return getCurrentLanguage();
}

function getDateLocale(): string
{
    $lang = getCurrentLanguage();
    return LOCALE_BY_LANG[$lang] ?? 'nl-NL';
}

/**
 * Geeft de vertaling voor $key in de actieve taal.
 * Extra $args worden via sprintf ingevoegd (voor %d, %s, etc.).
 */
function LOC(string $key, mixed ...$args): string
{
    $lang = getCurrentLanguage();
    $translations = TRANSLATIONS[$lang] ?? TRANSLATIONS['nl'];
    $string = $translations[$key] ?? (TRANSLATIONS['nl'][$key] ?? $key);

    return $args !== [] ? sprintf($string, ...$args) : $string;
}

function localizationFlagSvg(string $lang): string
{
    $svg = FLAG_SVGS[$lang] ?? '';
    if ($svg === '') {
        return '';
    }

    $safeLang = preg_replace('/[^a-z0-9]/', '', $lang) ?? $lang;
    return str_replace(
        ['id="a"', 'url(#a)', 'id="b"', 'url(#b)'],
        ['id="flag-' . $safeLang . '-a"', 'url(#flag-' . $safeLang . '-a)', 'id="flag-' . $safeLang . '-b"', 'url(#flag-' . $safeLang . '-b)'],
        $svg
    );
}

function localizationUrlWithLang(string $lang): string
{
    $params = $_GET;
    unset($params['lang']);
    $params['lang'] = $lang;
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

function localizationJsTranslations(array $keys): string
{
    $payload = [];
    foreach ($keys as $key) {
        $payload[$key] = LOC($key);
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function renderLanguageSwitcherStyles(): void
{
    echo <<<'CSS'
<style>
.lang-switcher {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 5000;
    font-family: inherit;
}
.lang-switcher-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 30px;
    padding: 0;
    border: 1px solid rgba(0, 82, 155, 0.25);
    border-radius: 6px;
    background: #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
    cursor: pointer;
}
.lang-switcher-toggle:hover {
    background: #f2f9ff;
}
.lang-switcher-toggle svg {
    width: 28px;
    height: auto;
    display: block;
    border-radius: 2px;
    overflow: hidden;
}
.lang-switcher-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 160px;
    margin: 0;
    padding: 6px;
    list-style: none;
    background: #ffffff;
    border: 1px solid #c9d7eb;
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
    display: none;
}
.lang-switcher.is-open .lang-switcher-menu {
    display: block;
}
.lang-switcher-item a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    color: var(--kvt-text, #1f2937);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}
.lang-switcher-item a:hover {
    background: #edf7ff;
}
.lang-switcher-item.is-active a {
    background: #e6f4ff;
}
.lang-switcher-item svg {
    width: 24px;
    height: auto;
    flex-shrink: 0;
    border-radius: 2px;
    overflow: hidden;
}
@media print {
    .lang-switcher {
        display: none !important;
    }
}
</style>
CSS;
}

function renderLanguageSwitcher(): void
{
    $current = getCurrentLanguage();
    $menuAria = htmlspecialchars(LOC('lang.menu_aria'), ENT_QUOTES);

    echo '<div class="lang-switcher" data-lang-switcher>';
    echo '<button type="button" class="lang-switcher-toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . $menuAria . '">';
    echo localizationFlagSvg($current);
    echo '</button>';
    echo '<ul class="lang-switcher-menu" role="menu">';

    foreach (SUPPORTED_LANGUAGES as $code => $meta) {
        if ($code === $current) {
            continue;
        }

        $label = (string) ($meta['label'] ?? $code);
        $href = htmlspecialchars(localizationUrlWithLang($code), ENT_QUOTES);
        $title = htmlspecialchars(LOC('lang.switch_to', $label), ENT_QUOTES);

        echo '<li class="lang-switcher-item" role="none">';
        echo '<a role="menuitem" href="' . $href . '" title="' . $title . '">';
        echo localizationFlagSvg($code);
        echo '<span>' . htmlspecialchars($label) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

function renderLanguageSwitcherScript(): void
{
    echo <<<'JS'
<script>
(function () {
    document.querySelectorAll('[data-lang-switcher]').forEach(function (root) {
        var toggle = root.querySelector('.lang-switcher-toggle');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var isOpen = root.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function () {
            root.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });

        root.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
})();
</script>
JS;
}

/**
 * Page load
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($_SESSION['lang'])) {
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '') {
        $savedPrefs = loadUserPrefs($prefEmail);
        if (isset($savedPrefs['lang']) && array_key_exists($savedPrefs['lang'], SUPPORTED_LANGUAGES)) {
            $_SESSION['lang'] = $savedPrefs['lang'];
        }
    }
}

if (!isset($_SESSION['lang']) || !array_key_exists((string) $_SESSION['lang'], SUPPORTED_LANGUAGES)) {
    $_SESSION['lang'] = 'nl';
}

if (isset($_GET['lang']) && array_key_exists($_GET['lang'], SUPPORTED_LANGUAGES)) {
    $requestedLang = (string) $_GET['lang'];
    $langChanged = $requestedLang !== getCurrentLanguage();
    $_SESSION['lang'] = $requestedLang;
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '' && $langChanged) {
        saveUserPref($prefEmail, 'lang', $requestedLang);
    }

    $isApiAction = isset($_GET['action']) && trim((string) $_GET['action']) !== '';
    if (!$isApiAction && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $params = $_GET;
        unset($params['lang']);
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
        $query = http_build_query($params);
        header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
