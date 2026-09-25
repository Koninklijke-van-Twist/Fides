<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/odata.php';
require_once dirname(__DIR__) . '/contract_data.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/fides-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/fides-mimir-mock.php';
$authPath = dirname(__DIR__) . '/auth.php';
$authBackup = null;
$cacheSnapshot = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_reset_discovery_cache(): void
{
    unset(
        $GLOBALS['demeter_company_environment_map'],
        $GLOBALS['demeter_companies_by_environment'],
        $GLOBALS['demeter_active_environments']
    );
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$rawBody = (string) file_get_contents('php://input');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
    'body' => $rawBody,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'C1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default Mímir-base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$spaceUrl = contract_company_entity_url(
    'https://bc.example',
    'Production',
    'Koninklijke van Twist',
    'AppMaintenanceContracts',
    [
        '$select' => 'Contract_No,Description',
        '$filter' => "Contract_No eq 'C1'",
    ]
);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'AppMaintenanceContracts'
        && ($parsedSpace['query']['$select'] ?? '') === 'Contract_No,Description'
        && ($parsedSpace['query']['$filter'] ?? '') === "Contract_No eq 'C1'",
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$apostropheUrl = contract_company_entity_url(
    '',
    'Production',
    "Van Twist's",
    'LVS_MainWorkOrderCard',
    ['$select' => 'No,Description']
);
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'lege baseUrl en apostrof in bedrijfsnaam',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'LVS_MainWorkOrderCard'
        && ($parsedApostrophe['query']['$select'] ?? '') === 'No,Description',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$threw = false;
try {
    auth_get_auth_for_environment('Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen auth-configuratie');
}
test_assert('BC-auth ontbreekt blijft exception zonder Mímir', $threw);

$threw = false;
try {
    auth_discover_companies_across_active_environments(30);
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen actieve environments');
}
test_assert('company-discovery faalt snel zonder BC-config en zonder Mímir', $threw);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = '';
unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth']);
test_reset_discovery_cache();

try {
    $discovered = auth_discover_companies_across_active_environments(30);
    test_assert(
        'Mímir company-discovery zonder BC-creds',
        ($discovered['companies'] ?? []) === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"]
            && ($discovered['map']['Koninklijke van Twist'] ?? '') === 'Production'
            && ($discovered['map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && ($discovered['primary_environment'] ?? '') === 'Production',
        json_encode($discovered, JSON_UNESCAPED_UNICODE)
    );

    $auth = auth_get_auth_for_environment('Production');
    test_assert('lege auth-sentinel in Mímir-modus', $auth === []);

    $context = auth_set_current_company_context('Hunter van Twist', 30);
    test_assert(
        'company-context zonder BC-auth',
        ($context['environment'] ?? '') === 'Sandbox' && ($context['auth'] ?? null) === []
    );

    $active = auth_get_active_environments();
    test_assert(
        'environments uit Mímir als auth_list ontbreekt',
        $active === ['Production', 'Sandbox'],
        json_encode($active)
    );

    unset($GLOBALS['baseUrl']);
    $beforeCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $rows = contract_fetch_rows('Koninklijke van Twist', 'AppMaintenanceContracts', [
        '$select' => 'Contract_No,Description',
        '$filter' => "Contract_No eq 'C1'",
    ], 60);
    $row = is_array($rows[0] ?? null) ? $rows[0] : [];
    test_assert(
        'contract_fetch_rows via Mímir zonder baseUrl',
        ($row['company'] ?? '') === 'Koninklijke van Twist'
            && ($row['table'] ?? '') === 'AppMaintenanceContracts'
            && ($row['filter'] ?? '') === "Contract_No eq 'C1'"
            && ($row['max_age'] ?? null) === 60
            && in_array('Contract_No', $row['select'] ?? [], true),
        json_encode($row, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    test_assert('Mímir slaat Fides-filecache over', count($afterCache) === count($beforeCache));

    $count = contract_count_entity_rows(
        'Koninklijke van Twist',
        'AppComponentCardTasks',
        "Contract_No eq 'C1'",
        60
    );
    test_assert('count-read gaat naar Mímir', $count === 1, var_export($count, true));

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $companyRow): string {
        return (string) ($companyRow['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawCountQuery = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Fides-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
        $body = json_decode((string) ($request['body'] ?? ''), true);
        if (
            str_contains((string) ($request['uri'] ?? ''), '/mimir/api/query.php')
            && is_array($body)
            && ($body['table'] ?? '') === 'AppComponentCardTasks'
            && ($body['filter'] ?? '') === "Contract_No eq 'C1'"
            && ($body['max_age'] ?? null) === 60
        ) {
            $sawCountQuery = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('count-query gebruikt Mímir query.php', $sawCountQuery);

    test_reset_discovery_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-dup/api';
    $overlapThrew = false;
    try {
        auth_discover_companies_across_active_environments(30);
    } catch (RuntimeException $error) {
        $overlapThrew = str_contains($error->getMessage(), 'Bedrijfsnaam-overlap');
    }
    test_assert('overlap tussen Mímir-environments blijft een fout', $overlapThrew);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth_list = [
        'Production' => [
            'mode' => 'basic',
            'user' => 'bcuser',
            'pass' => 'bcpass',
        ],
    ];
    $auth = $auth_list['Production'];
    if (is_file($authPath)) {
        $authBackup = file_get_contents($authPath);
    }
    file_put_contents($authPath, "<?php\n\$baseUrl = " . var_export($baseUrl, true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($auth_list, true) . ";\n\$mimirApi = '';\n");
    test_reset_discovery_cache();
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcRows = odata_get_all($baseUrl . '/Production/ODataV4/Companies?$select=Name', $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($authBackup === null) {
        @unlink($authPath);
    } else {
        file_put_contents($authPath, $authBackup);
    }
    @unlink($mockScript);
    @unlink($mockLog);
    $cacheAfter = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    foreach (array_diff($cacheAfter, $cacheSnapshot) as $createdCache) {
        @unlink($createdCache);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
