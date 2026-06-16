<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/contract_data.php';

/**
 * Constants
 */
const CONTRACT_PROGRESS_WORKORDER_PAGE_SIZE = 50;
const CONTRACT_PROGRESS_TASK_PAGE_SIZE = 50;
const CONTRACT_PROGRESS_COMPONENT_CHUNK_SIZE = 20;

/**
 * Functies
 */

function contract_progress_prepare_stream(): void
{
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
}

function contract_progress_emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();
}

function contract_progress_count_rows(string $company, string $entitySet, string $filter, int $ttl = 3600): ?int
{
    global $baseUrl;

    if ($filter === '') {
        return 0;
    }

    try {
        $environment = auth_get_environment_for_company($company, $ttl);
        $auth = auth_get_auth_for_environment($environment);
        $url = contract_company_entity_url($baseUrl, $environment, $company, $entitySet, [
            '$filter' => $filter,
            '$count' => 'true',
            '$top' => '0',
        ]);
        $response = odata_get_json($url, $auth);
        if (isset($response['@odata.count'])) {
            return max(0, (int) $response['@odata.count']);
        }
    } catch (Throwable $ignored) {
    }

    return null;
}

function contract_progress_chunk_count(?int $rowCount, int $pageSize): ?int
{
    if ($rowCount === null) {
        return null;
    }
    if ($rowCount <= 0) {
        return 0;
    }

    return (int) ceil($rowCount / $pageSize);
}

function contract_progress_build_chunk_step_ids(string $prefix, ?int $chunkCount): array
{
    if ($chunkCount === null || $chunkCount <= 0) {
        return [];
    }

    $stepIds = [];
    for ($index = 0; $index < $chunkCount; $index++) {
        $stepIds[] = $prefix . '_' . $index;
    }

    return $stepIds;
}

function contract_progress_emit_step_plan(array $stepIds): void
{
    if ($stepIds === []) {
        return;
    }

    contract_progress_emit(['stepPlan' => $stepIds]);
}

function contract_progress_search_customers_by_name(string $company, string $query, int $ttl = 3600): array
{
    $escaped = contract_escape_odata_string($query);
    if ($escaped === '') {
        return [];
    }

    $filters = [
        'search_customers_name' => "contains(Name,'" . $escaped . "')",
        'search_customers_searchname' => "contains(Search_Name,'" . $escaped . "')",
    ];

    $seen = [];
    $customers = [];
    foreach ($filters as $stepId => $filter) {
        $rows = contract_try_fetch_rows($company, 'AppCustomerCard', [
            '$select' => 'No,Name,Search_Name',
            '$filter' => $filter,
            '$top' => '15',
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = contract_normalize_customer_row($row);
            if ($normalized['no'] === '' || isset($seen[$normalized['no']])) {
                continue;
            }
            $seen[$normalized['no']] = true;
            $customers[] = $normalized;
        }
        contract_progress_emit(['step' => $stepId, 'status' => 'done']);
    }

    usort($customers, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return $customers;
}

function contract_progress_search(string $company, string $query, int $ttl = 3600): array
{
    $query = trim($query);
    if ($query === '') {
        return ['kind' => 'empty', 'message_key' => 'contract.error.query_required'];
    }

    contract_progress_emit_step_plan([
        'search_contract',
        'search_workorders',
        'search_customer',
        'search_customers_name',
        'search_customers_searchname',
    ]);

    $contract = contract_fetch_contract_by_no($company, $query, $ttl);
    contract_progress_emit(['step' => 'search_contract', 'status' => 'done']);
    if ($contract !== null) {
        return [
            'kind' => 'contract',
            'contract_no' => $contract['contract_no'],
            'contract' => $contract,
            'skip_contract_fetch' => true,
        ];
    }

    $existsOnWorkorders = contract_contract_exists_on_workorders($company, $query, $ttl);
    contract_progress_emit(['step' => 'search_workorders', 'status' => 'done']);
    if ($existsOnWorkorders) {
        return [
            'kind' => 'contract',
            'contract_no' => $query,
            'contract' => [
                'contract_no' => $query,
                'contract_type' => '',
                'description' => '',
                'customer_no' => '',
                'customer_name' => '',
                'status' => '',
                'starting_date' => '',
                'end_date' => '',
            ],
            'skip_contract_fetch' => true,
        ];
    }

    $customer = contract_fetch_customer_by_no($company, $query, $ttl);
    contract_progress_emit(['step' => 'search_customer', 'status' => 'done']);
    if ($customer !== null) {
        contract_progress_emit_step_plan(['contracts']);

        contract_progress_emit(['step' => 'contracts', 'status' => 'start']);
        $contracts = contract_fetch_contracts_for_customer($company, $customer['no'], $ttl);
        contract_progress_emit(['step' => 'contracts', 'status' => 'done']);

        return [
            'kind' => 'customer',
            'customer' => $customer,
            'contracts' => $contracts,
        ];
    }

    $customers = contract_progress_search_customers_by_name($company, $query, $ttl);
    if ($customers === []) {
        return ['kind' => 'empty', 'message_key' => 'contract.error.not_found'];
    }

    if (count($customers) === 1) {
        $single = $customers[0];
        contract_progress_emit_step_plan(['contracts']);
        contract_progress_emit(['step' => 'contracts', 'status' => 'start']);
        $contracts = contract_fetch_contracts_for_customer($company, $single['no'], $ttl);
        contract_progress_emit(['step' => 'contracts', 'status' => 'done']);

        return [
            'kind' => 'customer',
            'customer' => $single,
            'contracts' => $contracts,
        ];
    }

    return [
        'kind' => 'customers',
        'customers' => $customers,
    ];
}

function contract_progress_fetch_workorders(string $company, string $contractNo, int $ttl = 3600, ?int $knownChunkCount = null): array
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $pageSize = CONTRACT_PROGRESS_WORKORDER_PAGE_SIZE;
    $skip = 0;
    $chunkIndex = 0;
    $workorders = [];

    while (true) {
        if ($knownChunkCount === null) {
            contract_progress_emit(['step' => 'workorders_' . $chunkIndex, 'status' => 'start']);
        }

        $rows = contract_try_fetch_rows($company, 'LVS_MainWorkOrderCard', [
            '$select' => 'No,Contract_No,Component_No,Component_Description,Status,Task_Code,KVT_Report_Status,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel',
            '$filter' => "Contract_No eq '" . $escaped . "'",
            '$orderby' => 'No desc',
            '$top' => (string) $pageSize,
            '$skip' => (string) $skip,
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = contract_normalize_workorder_row($row);
            if ($normalized['no'] !== '') {
                $workorders[] = $normalized;
            }
        }
        contract_progress_emit(['step' => 'workorders_' . $chunkIndex, 'status' => 'done']);

        if (count($rows) < $pageSize) {
            break;
        }

        $skip += $pageSize;
        $chunkIndex++;
    }

    usort($workorders, static function (array $left, array $right): int {
        return strcasecmp((string) ($right['no'] ?? ''), (string) ($left['no'] ?? ''));
    });

    return $workorders;
}

function contract_progress_fetch_tasks(string $company, string $contractNo, int $ttl = 3600, ?int $knownChunkCount = null): array
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $pageSize = CONTRACT_PROGRESS_TASK_PAGE_SIZE;
    $skip = 0;
    $chunkIndex = 0;
    $tasks = [];

    while (true) {
        if ($knownChunkCount === null) {
            contract_progress_emit(['step' => 'tasks_' . $chunkIndex, 'status' => 'start']);
        }

        $rows = contract_try_fetch_rows($company, 'AppComponentCardTasks', [
            '$select' => 'Component_No,Contract_No,Task_Code,Description,Main_Entity,Contract_Status',
            '$filter' => "Contract_No eq '" . $escaped . "'",
            '$top' => (string) $pageSize,
            '$skip' => (string) $skip,
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $componentNo = trim((string) ($row['Component_No'] ?? ''));
            if ($componentNo === '') {
                continue;
            }
            $tasks[] = [
                'component_no' => $componentNo,
                'task_code' => trim((string) ($row['Task_Code'] ?? '')),
                'description' => trim((string) ($row['Description'] ?? '')),
                'main_entity' => trim((string) ($row['Main_Entity'] ?? '')),
                'contract_status' => trim((string) ($row['Contract_Status'] ?? '')),
            ];
        }
        contract_progress_emit(['step' => 'tasks_' . $chunkIndex, 'status' => 'done']);

        if (count($rows) < $pageSize) {
            break;
        }

        $skip += $pageSize;
        $chunkIndex++;
    }

    usort($tasks, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['component_no'] ?? ''), (string) ($right['component_no'] ?? ''));
    });

    return $tasks;
}

function contract_progress_fetch_component_chunks(string $company, array $componentNos, int $ttl = 3600): void
{
    $componentNos = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $componentNos))));

    if ($componentNos === []) {
        return;
    }

    $chunks = array_chunk($componentNos, CONTRACT_PROGRESS_COMPONENT_CHUNK_SIZE);
    if ($chunks !== []) {
        contract_progress_emit_step_plan(contract_progress_build_chunk_step_ids('components', count($chunks)));
    }

    foreach ($chunks as $chunkIndex => $chunk) {
        contract_fetch_component_cards($company, $chunk, $ttl);
        contract_progress_emit(['step' => 'components_' . $chunkIndex, 'status' => 'done']);
    }
}

function contract_progress_load_detail(string $company, string $contractNo, int $ttl = 3600, bool $skipContractFetch = false): void
{
    $escaped = contract_escape_odata_string($contractNo);
    $contractFilter = $escaped !== '' ? "Contract_No eq '" . $escaped . "'" : '';

    $workorderChunkCount = $contractFilter !== ''
        ? contract_progress_chunk_count(
            contract_progress_count_rows($company, 'LVS_MainWorkOrderCard', $contractFilter, $ttl),
            CONTRACT_PROGRESS_WORKORDER_PAGE_SIZE
        )
        : 0;
    $taskChunkCount = $contractFilter !== ''
        ? contract_progress_chunk_count(
            contract_progress_count_rows($company, 'AppComponentCardTasks', $contractFilter, $ttl),
            CONTRACT_PROGRESS_TASK_PAGE_SIZE
        )
        : 0;

    $stepPlan = [];
    if (!$skipContractFetch) {
        $stepPlan[] = 'contract';
    }
    $stepPlan = array_merge(
        $stepPlan,
        contract_progress_build_chunk_step_ids('workorders', $workorderChunkCount),
        contract_progress_build_chunk_step_ids('tasks', $taskChunkCount)
    );
    contract_progress_emit_step_plan($stepPlan);

    if (!$skipContractFetch) {
        contract_progress_emit(['step' => 'contract', 'status' => 'start']);
        contract_fetch_contract_by_no($company, $contractNo, $ttl);
        contract_progress_emit(['step' => 'contract', 'status' => 'done']);
    }

    $workorders = contract_progress_fetch_workorders($company, $contractNo, $ttl, $workorderChunkCount);
    $tasks = contract_progress_fetch_tasks($company, $contractNo, $ttl, $taskChunkCount);

    $componentNos = [];
    foreach ($workorders as $workorder) {
        $no = trim((string) ($workorder['component_no'] ?? ''));
        if ($no !== '') {
            $componentNos[$no] = $no;
        }
    }
    foreach ($tasks as $task) {
        $no = trim((string) ($task['component_no'] ?? ''));
        if ($no !== '') {
            $componentNos[$no] = $no;
        }
    }

    contract_progress_fetch_component_chunks($company, array_values($componentNos), $ttl);
}

/**
 * Page load
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

contract_progress_prepare_stream();

$companies = contract_companies_for_page();
$prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$savedCompany = '';
if ($prefEmail !== '') {
    $savedCompany = trim((string) (loadUserPrefs($prefEmail)['company'] ?? ''));
}

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
if ($requestedCompany !== '' && in_array($requestedCompany, $companies, true)) {
    $company = $requestedCompany;
} elseif ($savedCompany !== '' && in_array($savedCompany, $companies, true)) {
    $company = $savedCompany;
} else {
    $company = (string) ($companies[0] ?? '');
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$customerNo = trim((string) ($_GET['customer'] ?? ''));
$contractNo = trim((string) ($_GET['contract'] ?? ''));

try {
    auth_set_current_company_context($company);

    if ($contractNo !== '') {
        contract_progress_load_detail($company, $contractNo);
    } elseif ($customerNo !== '') {
        contract_progress_emit_step_plan(['customer', 'contracts']);

        contract_progress_emit(['step' => 'customer', 'status' => 'start']);
        contract_fetch_customer_by_no($company, $customerNo);
        contract_progress_emit(['step' => 'customer', 'status' => 'done']);

        contract_progress_emit(['step' => 'contracts', 'status' => 'start']);
        contract_fetch_contracts_for_customer($company, $customerNo);
        contract_progress_emit(['step' => 'contracts', 'status' => 'done']);
    } elseif ($searchQuery !== '') {
        $searchResult = contract_progress_search($company, $searchQuery);

        $kind = (string) ($searchResult['kind'] ?? 'empty');
        if ($kind === 'contract') {
            $resolvedContractNo = trim((string) ($searchResult['contract_no'] ?? ''));
            if ($resolvedContractNo !== '') {
                contract_progress_load_detail(
                    $company,
                    $resolvedContractNo,
                    3600,
                    (bool) ($searchResult['skip_contract_fetch'] ?? false)
                );
            }
        }
    }

    contract_progress_emit(['complete' => true]);
} catch (Throwable $loadError) {
    contract_progress_emit(['error' => true]);
}
