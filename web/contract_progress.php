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

function contract_progress_emit_step_plan(array $stepIds): void
{
    if ($stepIds === []) {
        return;
    }

    contract_progress_emit(['stepPlan' => $stepIds]);
}

function contract_progress_emit_step(string $step, string $status): void
{
    contract_progress_emit(['step' => $step, 'status' => $status]);
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
        contract_progress_emit_step($stepId, 'done');
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
    contract_progress_emit_step('search_contract', 'done');
    if ($contract !== null) {
        return [
            'kind' => 'contract',
            'contract_no' => $contract['contract_no'],
            'contract' => $contract,
            'skip_contract_fetch' => true,
        ];
    }

    $existsOnWorkorders = contract_contract_exists_on_workorders($company, $query, $ttl);
    contract_progress_emit_step('search_workorders', 'done');
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
    contract_progress_emit_step('search_customer', 'done');
    if ($customer !== null) {
        contract_progress_emit_step_plan(['contracts']);
        contract_progress_emit_step('contracts', 'start');
        $contracts = contract_fetch_contracts_for_customer($company, $customer['no'], $ttl);
        contract_progress_emit_step('contracts', 'done');

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
        contract_progress_emit_step('contracts', 'start');
        $contracts = contract_fetch_contracts_for_customer($company, $single['no'], $ttl);
        contract_progress_emit_step('contracts', 'done');

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

function contract_progress_load_detail(string $company, string $contractNo, int $ttl = 3600, bool $skipContractFetch = false): array
{
    $escaped = contract_escape_odata_string($contractNo);
    $contractFilter = $escaped !== '' ? "Contract_No eq '" . $escaped . "'" : '';

    $countStepPlan = [];
    if (!$skipContractFetch) {
        $countStepPlan[] = 'contract';
    }
    if ($contractFilter !== '') {
        $countStepPlan[] = 'count_workorders';
        $countStepPlan[] = 'count_tasks';
    }
    contract_progress_emit_step_plan($countStepPlan);

    $workorderRowCount = null;
    $taskRowCount = null;
    if ($contractFilter !== '') {
        contract_progress_emit_step('count_workorders', 'start');
        $workorderRowCount = contract_progress_count_rows($company, 'LVS_MainWorkOrderCard', $contractFilter, $ttl);
        contract_progress_emit_step('count_workorders', 'done');

        contract_progress_emit_step('count_tasks', 'start');
        $taskRowCount = contract_progress_count_rows($company, 'AppComponentCardTasks', $contractFilter, $ttl);
        contract_progress_emit_step('count_tasks', 'done');
    }

    $fetchStepPlan = [];
    if (!$skipContractFetch) {
        $fetchStepPlan[] = 'contract';
    }
    if ($workorderRowCount !== null && $workorderRowCount > 0) {
        $fetchStepPlan = array_merge(
            $fetchStepPlan,
            contract_build_progress_chunk_step_ids('workorders', $workorderRowCount)
        );
    }
    if ($taskRowCount !== null && $taskRowCount > 0) {
        $fetchStepPlan = array_merge(
            $fetchStepPlan,
            contract_build_progress_chunk_step_ids('tasks', $taskRowCount)
        );
    }
    contract_progress_emit_step_plan($fetchStepPlan);

    return contract_get_detail(
        $company,
        $contractNo,
        $ttl,
        static function (string $step, string $status): void {
            contract_progress_emit_step($step, $status);
        },
        $skipContractFetch,
        static function (array $stepIds): void {
            contract_progress_emit_step_plan($stepIds);
        }
    );
}

function contract_progress_load_companies(int $ttl = 3600): array
{
    contract_progress_emit_step_plan(['companies']);
    contract_progress_emit_step('companies', 'start');
    $companies = contract_companies_for_page($ttl);
    contract_progress_emit_step('companies', 'done');

    return $companies;
}

/**
 * Page load
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

contract_extend_load_time_limit();
contract_progress_prepare_stream();

$companies = contract_progress_load_companies();
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

    $contractDetailResult = null;

    if ($contractNo !== '') {
        $contractDetailResult = contract_progress_load_detail($company, $contractNo);
    } elseif ($customerNo !== '') {
        contract_progress_emit_step_plan(['customer', 'contracts']);
        contract_progress_emit_step('customer', 'start');
        contract_fetch_customer_by_no($company, $customerNo);
        contract_progress_emit_step('customer', 'done');
        contract_progress_emit_step('contracts', 'start');
        contract_fetch_contracts_for_customer($company, $customerNo);
        contract_progress_emit_step('contracts', 'done');
    } elseif ($searchQuery !== '') {
        $searchResult = contract_progress_search($company, $searchQuery);
        $kind = (string) ($searchResult['kind'] ?? 'empty');
        if ($kind === 'contract') {
            $resolvedContractNo = trim((string) ($searchResult['contract_no'] ?? ''));
            if ($resolvedContractNo !== '') {
                $contractNo = $resolvedContractNo;
                $contractDetailResult = contract_progress_load_detail(
                    $company,
                    $resolvedContractNo,
                    3600,
                    (bool) ($searchResult['skip_contract_fetch'] ?? false)
                );
            }
        }
    }

    $prefetchKey = contract_portal_prefetch_key($company, $contractNo, $customerNo, $searchQuery);
    $pageState = contract_load_page_state($company, $contractNo, $customerNo, $searchQuery, 3600, $contractDetailResult);
    contract_portal_store_prefetch($prefetchKey, $pageState);

    contract_progress_emit_step_plan(['pagina']);
    contract_progress_emit_step('pagina', 'done');
    contract_progress_emit(['complete' => true]);
} catch (Throwable $loadError) {
    contract_progress_emit(['error' => true]);
}
