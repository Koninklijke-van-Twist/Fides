<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/fides-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['FIDES_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Fides] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$pathOnly = "/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
$rewrittenPath = odata_bc_url_from_odata_url($pathOnly);
if (strpos($rewrittenPath, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?") !== 0) {
    fail('pad-only URL moet naar de pre-Mímir BC-host, kreeg: ' . $rewrittenPath);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_bc_url_from_odata_url(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No"
);
if (strpos($directCompanyUrl, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?") !== 0) {
    fail('na de circuit-open moet de oude BC-URL gebouwd worden, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout wordt gelogd, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Fides] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$beforeHunter = count($calls);
$hunterRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 60);
if (($hunterRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query voor een tweede environment viel niet terug op de stub');
}
$hunterCall = $calls[$beforeHunter] ?? null;
$expectedHunterPrefix = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?";
if (!is_array($hunterCall) || strpos($hunterCall['url'], $expectedHunterPrefix) !== 0 || $hunterCall['user'] !== 'sandbox-user') {
    fail('tweede environment moet auth_list[Sandbox] gebruiken: ' . json_encode($hunterCall));
}
$loggedAfterHunter = fallback_count();
$beforeSandboxGet = count($calls);
$sandboxRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
$sandboxCall = $calls[$beforeSandboxGet] ?? null;
$expectedSandboxUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxCall) || $sandboxCall['url'] !== $expectedSandboxUrl || $sandboxCall['user'] !== 'sandbox-user') {
    fail('URL-segment Sandbox wint van de meegegeven Production-auth: ' . json_encode($sandboxCall));
}
if (fallback_count() !== $loggedAfterHunter) {
    fail('een open circuit mag niet opnieuw gelogd worden');
}
$beforeMappedGet = count($calls);
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
$mappedCall = $calls[$beforeMappedGet] ?? null;
if (($mappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($mappedCall) || $mappedCall['url'] !== $expectedSandboxUrl || $mappedCall['user'] !== 'sandbox-user') {
    fail('mimir-segment moet de company-map volgen: ' . json_encode($mappedCall));
}

$beforeBoth = count($calls);
odata_direct_companies_as_rows(null);
$productionCompany = $calls[$beforeBoth] ?? null;
$sandboxCompany = $calls[$beforeBoth + 1] ?? null;
if (!is_array($productionCompany) || strpos($productionCompany['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0 || $productionCompany['user'] !== 'bcuser') {
    fail('company-lijst moet Production met de Production-auth ophalen: ' . json_encode($productionCompany));
}
if (!is_array($sandboxCompany) || $sandboxCompany['url'] !== 'https://bc.example:7148/Sandbox/ODataV4/Company' || $sandboxCompany['user'] !== 'sandbox-user') {
    fail('company-lijst moet ook Sandbox ophalen, niet alleen de primaire env: ' . json_encode($sandboxCompany));
}
$beforeFiltered = count($calls);
odata_direct_companies_as_rows('Sandbox');
$filteredCompany = $calls[$beforeFiltered] ?? null;
if (count($calls) !== $beforeFiltered + 1 || !is_array($filteredCompany) || $filteredCompany['user'] !== 'sandbox-user') {
    fail('een environment-filter mag alleen dat environment ophalen: ' . json_encode($filteredCompany));
}

$spaced = odata_bc_url_from_odata_url("https://mimir.invalid/Sand%20box/ODataV4/Company('X')/T?\$select=No");
if ($spaced !== "https://bc.example:7148/Sand%20box/ODataV4/Company('X')/T?\$select=No") {
    fail('env-segment moet precies één keer rawurlencode krijgen, kreeg: ' . $spaced);
}

$savedEnvironment = $environment;
$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    ['user' => 'sandbox-user']
);
$mappedKey = build_cache_key(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    ['user' => 'sandbox-user']
);
$environment = $savedEnvironment;
if (substr($cacheKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox' || strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key moet de echte BC-environment gebruiken, kreeg: ' . $cacheKey);
}
if (substr($mappedKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox' || strpos($mappedKey, '|mimir') !== false) {
    fail('cache-key van een mimir-URL moet de company-map volgen, kreeg: ' . $mappedKey);
}

odata_mimir_circuit_reset();
$loggedBeforeParse = fallback_count();
$parseThrew = false;
try {
    odata_mimir_fetch_all('https://mimir.invalid/not-odata', 10);
} catch (Throwable $exception) {
    $parseThrew = strpos($exception->getMessage(), 'kon niet worden vertaald') !== false;
}
if (!$parseThrew) {
    fail('een onvertaalbare URL moet de oorspronkelijke fout geven');
}
if (odata_mimir_circuit_open()) {
    fail('een fout buiten Mímir mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeParse) {
    fail('een fout buiten Mímir mag geen fallback loggen');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable || strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . ($rethrown instanceof Throwable ? $rethrown->getMessage() : 'geen exception'));
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$authPhp = tempnam(sys_get_temp_dir(), 'fides-auth-');
if (!is_string($authPhp) || $authPhp === '') {
    fail('tijdelijk auth-bestand kon niet worden gemaakt');
}
file_put_contents($authPhp, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
$base = 'loaded-base';
PHP);
try {
    $baseUrl = 'https://mimir.invalid/';
    $environment = 'mimir';
    $auth = [];
    $auth_list = [];
    unset($GLOBALS['base'], $GLOBALS['FIDES_BC_AUTH_LOAD_TRIED']);
    $GLOBALS['FIDES_AUTH_PHP_PATH'] = $authPhp;
    odata_load_auth_config();
    if ($GLOBALS['baseUrl'] !== 'https://loaded-bc.example:7148/'
        || $GLOBALS['environment'] !== 'LoadedEnv'
        || ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '') !== 'loaded-user'
        || ($GLOBALS['auth']['user'] ?? '') !== 'loaded-user'
        || ($GLOBALS['base'] ?? '') !== 'loaded-base'
        || odata_bc_environment() !== 'LoadedEnv') {
        fail('auth.php moet placeholders naar $GLOBALS kopiëren, kreeg baseUrl=' . var_export($GLOBALS['baseUrl'] ?? null, true));
    }

    $GLOBALS['baseUrl'] = 'https://keep.example:7148/';
    $GLOBALS['environment'] = 'StayEnv';
    $GLOBALS['auth'] = [];
    $GLOBALS['auth_list'] = [];
    unset($GLOBALS['FIDES_BC_AUTH_LOAD_TRIED']);
    odata_load_auth_config();
    if ($GLOBALS['baseUrl'] !== 'https://keep.example:7148/' || $GLOBALS['environment'] !== 'StayEnv') {
        fail('een tweede load mag gezette baseUrl/environment niet overschrijven');
    }
    if (($GLOBALS['auth']['user'] ?? '') !== 'loaded-user' || ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '') !== 'loaded-user') {
        fail('lege auth moet alsnog uit auth.php komen');
    }

    require_once $authPhp;
    if ($GLOBALS['baseUrl'] !== 'https://keep.example:7148/' || $GLOBALS['environment'] !== 'StayEnv') {
        fail('require_once van een al geladen auth.php mag $GLOBALS niet wissen');
    }
} finally {
    unset($GLOBALS['FIDES_AUTH_PHP_PATH']);
    @unlink($authPhp);
}

$log = fallback_log();
if (strpos($log, 'sandbox-secret') !== false || strpos($log, 'loaded-secret') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

echo "OK\n";
