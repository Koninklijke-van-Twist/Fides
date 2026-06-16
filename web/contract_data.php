<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Constants
 */
const CONTRACT_FETCH_PROGRESS_CHUNK_SIZE = 5;
const CONTRACT_FETCH_COMPONENT_CARD_CHUNK_SIZE = 20;
const CONTRACT_FETCH_MAX_ROWS = 500;
const CONTRACT_LOAD_TIME_LIMIT_SECONDS = 10800;

/**
 * Functies
 */

function contract_build_progress_chunk_step_ids(string $prefix, int $itemCount, int $chunkSize = CONTRACT_FETCH_PROGRESS_CHUNK_SIZE): array
{
    if ($itemCount <= 0) {
        return [];
    }

    $chunkCount = (int) ceil($itemCount / $chunkSize);
    $stepIds = [];
    for ($index = 0; $index < $chunkCount; $index++) {
        $stepIds[] = $prefix . '_' . $index;
    }

    return $stepIds;
}

function contract_count_entity_rows(string $company, string $entitySet, string $filter, int $ttl = 3600): ?int
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

function contract_emit_accurate_chunk_plan(
    ?callable $emitStepPlan,
    string $prefix,
    ?int $itemCount,
    int $chunkSize = CONTRACT_FETCH_PROGRESS_CHUNK_SIZE,
    ?int $maxRows = CONTRACT_FETCH_MAX_ROWS
): void {
    if ($emitStepPlan === null || $itemCount === null || $itemCount <= 0) {
        return;
    }

    $itemsToFetch = $maxRows === null ? $itemCount : min($itemCount, $maxRows);
    $stepIds = contract_build_progress_chunk_step_ids($prefix, $itemsToFetch, $chunkSize);
    if ($stepIds !== []) {
        $emitStepPlan($stepIds);
    }
}

function contract_chunk_progress_emit(?callable $emitChunk, string $step, string $status): void
{
    if ($emitChunk !== null) {
        $emitChunk($step, $status);
    }
}

function contract_extend_load_time_limit(): void
{
    @set_time_limit(CONTRACT_LOAD_TIME_LIMIT_SECONDS);
    @ini_set('max_execution_time', (string) CONTRACT_LOAD_TIME_LIMIT_SECONDS);
}

function contract_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function contract_company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = contract_escape_odata_string($company);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function contract_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    global $baseUrl;

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = contract_company_entity_url($baseUrl, $environment, $company, $entitySet, $query);

    return odata_get_all($url, $auth, $ttl);
}

function contract_try_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    try {
        return contract_fetch_rows($company, $entitySet, $query, $ttl);
    } catch (Throwable $error) {
        return [];
    }
}

function contract_normalize_contract_row(array $row): array
{
    return [
        'contract_no' => trim((string) ($row['Contract_No'] ?? '')),
        'contract_type' => trim((string) ($row['Contract_Type'] ?? '')),
        'description' => trim((string) ($row['Description'] ?? '')),
        'customer_no' => trim((string) ($row['Customer_No'] ?? '')),
        'customer_name' => trim((string) ($row['Name'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
        'starting_date' => (string) ($row['Starting_Date'] ?? ''),
        'end_date' => (string) ($row['End_Date'] ?? ''),
    ];
}

function contract_normalize_customer_row(array $row): array
{
    return [
        'no' => trim((string) ($row['No'] ?? '')),
        'name' => trim((string) ($row['Name'] ?? '')),
        'search_name' => trim((string) ($row['Search_Name'] ?? '')),
    ];
}

function contract_fetch_contract_by_no(string $company, string $contractNo, int $ttl = 3600): ?array
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return null;
    }

    $rows = contract_try_fetch_rows($company, 'AppMaintenanceContracts', [
        '$select' => 'Contract_No,Contract_Type,Description,Customer_No,Name,Status,Starting_Date,End_Date',
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$top' => '1',
    ], $ttl);

    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    return $row !== null ? contract_normalize_contract_row($row) : null;
}

function contract_fetch_contracts_for_customer(string $company, string $customerNo, int $ttl = 3600): array
{
    $escaped = contract_escape_odata_string($customerNo);
    if ($escaped === '') {
        return [];
    }

    $rows = contract_try_fetch_rows($company, 'AppMaintenanceContracts', [
        '$select' => 'Contract_No,Contract_Type,Description,Customer_No,Name,Status,Starting_Date,End_Date',
        '$filter' => "Customer_No eq '" . $escaped . "'",
        '$orderby' => 'Contract_No desc',
        '$top' => '100',
    ], $ttl);

    $contracts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = contract_normalize_contract_row($row);
        if ($normalized['contract_no'] !== '') {
            $contracts[] = $normalized;
        }
    }

    return $contracts;
}

function contract_fetch_customer_by_no(string $company, string $customerNo, int $ttl = 3600): ?array
{
    $escaped = contract_escape_odata_string($customerNo);
    if ($escaped === '') {
        return null;
    }

    $rows = contract_try_fetch_rows($company, 'AppCustomerCard', [
        '$select' => 'No,Name,Search_Name',
        '$filter' => "No eq '" . $escaped . "'",
        '$top' => '1',
    ], $ttl);

    $row = is_array($rows[0] ?? null) ? $rows[0] : null;
    return $row !== null ? contract_normalize_customer_row($row) : null;
}

function contract_search_customers_by_name(string $company, string $query, int $ttl = 3600): array
{
    $escaped = contract_escape_odata_string($query);
    if ($escaped === '') {
        return [];
    }

    $filters = [
        "contains(Name,'" . $escaped . "')",
        "contains(Search_Name,'" . $escaped . "')",
    ];

    $seen = [];
    $customers = [];
    foreach ($filters as $filter) {
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
    }

    usort($customers, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return $customers;
}

function contract_contract_exists_on_workorders(string $company, string $contractNo, int $ttl = 3600): bool
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return false;
    }

    $rows = contract_try_fetch_rows($company, 'AppWerkorders', [
        '$select' => 'No',
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$top' => '1',
    ], $ttl);

    return $rows !== [];
}

function contract_search(string $company, string $query, int $ttl = 3600): array
{
    $query = trim($query);
    if ($query === '') {
        return ['kind' => 'empty', 'message_key' => 'contract.error.query_required'];
    }

    $contract = contract_fetch_contract_by_no($company, $query, $ttl);
    if ($contract !== null) {
        return [
            'kind' => 'contract',
            'contract_no' => $contract['contract_no'],
            'contract' => $contract,
        ];
    }

    if (contract_contract_exists_on_workorders($company, $query, $ttl)) {
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
        ];
    }

    $customer = contract_fetch_customer_by_no($company, $query, $ttl);
    if ($customer !== null) {
        return [
            'kind' => 'customer',
            'customer' => $customer,
            'contracts' => contract_fetch_contracts_for_customer($company, $customer['no'], $ttl),
        ];
    }

    $customers = contract_search_customers_by_name($company, $query, $ttl);
    if ($customers === []) {
        return ['kind' => 'empty', 'message_key' => 'contract.error.not_found'];
    }

    if (count($customers) === 1) {
        $single = $customers[0];
        return [
            'kind' => 'customer',
            'customer' => $single,
            'contracts' => contract_fetch_contracts_for_customer($company, $single['no'], $ttl),
        ];
    }

    return [
        'kind' => 'customers',
        'customers' => $customers,
    ];
}

function contract_normalize_workorder_row(array $row): array
{
    return [
        'no' => trim((string) ($row['No'] ?? '')),
        'contract_no' => trim((string) ($row['Contract_No'] ?? '')),
        'component_no' => trim((string) ($row['Component_No'] ?? '')),
        'component_description' => trim((string) ($row['Component_Description'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
        'task_code' => trim((string) ($row['Task_Code'] ?? '')),
        'report_status' => trim((string) ($row['KVT_Report_Status'] ?? '')),
        'pdf_url' => trim((string) ($row['KVT_URL_Workorder_Report_PDF'] ?? '')),
        'excel_url' => trim((string) ($row['KVT_URL_Workorder_Report_Excel'] ?? '')),
    ];
}

function contract_workorder_has_report_links(array $workorder): bool
{
    return trim((string) ($workorder['pdf_url'] ?? '')) !== ''
        || trim((string) ($workorder['excel_url'] ?? '')) !== '';
}

function contract_fetch_workorders_for_contract(
    string $company,
    string $contractNo,
    int $ttl = 3600,
    ?callable $emitChunk = null,
    ?callable $emitStepPlan = null
): array {
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $query = [
        '$select' => 'No,Contract_No,Component_No,Component_Description,Status,Task_Code,KVT_Report_Status,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel',
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$orderby' => 'No desc',
    ];

    $workorders = [];
    if ($emitChunk === null) {
        $rows = contract_try_fetch_rows($company, 'LVS_MainWorkOrderCard', $query + ['$top' => (string) CONTRACT_FETCH_MAX_ROWS], $ttl);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized = contract_normalize_workorder_row($row);
            if ($normalized['no'] !== '' && contract_workorder_has_report_links($normalized)) {
                $workorders[] = $normalized;
            }
        }
    } else {
        $chunkSize = CONTRACT_FETCH_PROGRESS_CHUNK_SIZE;
        $maxRows = CONTRACT_FETCH_MAX_ROWS;
        contract_emit_accurate_chunk_plan(
            $emitStepPlan,
            'workorders',
            contract_count_entity_rows($company, 'LVS_MainWorkOrderCard', $query['$filter'], $ttl)
        );

        $skip = 0;
        $chunkIndex = 0;
        $rawRowsFetched = 0;

        while ($rawRowsFetched < $maxRows) {
            $stepId = 'workorders_' . $chunkIndex;
            contract_chunk_progress_emit($emitChunk, $stepId, 'start');
            $rows = contract_try_fetch_rows($company, 'LVS_MainWorkOrderCard', $query + [
                '$top' => (string) $chunkSize,
                '$skip' => (string) $skip,
            ], $ttl);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = contract_normalize_workorder_row($row);
                if ($normalized['no'] !== '' && contract_workorder_has_report_links($normalized)) {
                    $workorders[] = $normalized;
                }
            }
            contract_chunk_progress_emit($emitChunk, $stepId, 'done');

            $rawRowsFetched += count($rows);
            if ($rows === [] || count($rows) < $chunkSize || $rawRowsFetched >= $maxRows) {
                break;
            }

            $skip += $chunkSize;
            $chunkIndex++;
        }
    }

    usort($workorders, static function (array $left, array $right): int {
        return strcasecmp((string) ($right['no'] ?? ''), (string) ($left['no'] ?? ''));
    });

    return $workorders;
}

function contract_fetch_component_tasks_for_contract(
    string $company,
    string $contractNo,
    int $ttl = 3600,
    ?callable $emitChunk = null,
    ?callable $emitStepPlan = null
): array {
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $query = [
        '$select' => 'Component_No,Contract_No,Task_Code,Description,Main_Entity,Contract_Status',
        '$filter' => "Contract_No eq '" . $escaped . "'",
    ];

    $tasks = [];
    if ($emitChunk === null) {
        $rows = contract_try_fetch_rows($company, 'AppComponentCardTasks', $query + ['$top' => (string) CONTRACT_FETCH_MAX_ROWS], $ttl);
    } else {
        $chunkSize = CONTRACT_FETCH_PROGRESS_CHUNK_SIZE;
        $maxRows = CONTRACT_FETCH_MAX_ROWS;
        contract_emit_accurate_chunk_plan(
            $emitStepPlan,
            'tasks',
            contract_count_entity_rows($company, 'AppComponentCardTasks', $query['$filter'], $ttl)
        );

        $skip = 0;
        $chunkIndex = 0;
        $rawRowsFetched = 0;
        $rows = [];

        while ($rawRowsFetched < $maxRows) {
            $stepId = 'tasks_' . $chunkIndex;
            contract_chunk_progress_emit($emitChunk, $stepId, 'start');
            $rows = contract_try_fetch_rows($company, 'AppComponentCardTasks', $query + [
                '$top' => (string) $chunkSize,
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
            contract_chunk_progress_emit($emitChunk, $stepId, 'done');

            $rawRowsFetched += count($rows);
            if ($rows === [] || count($rows) < $chunkSize || $rawRowsFetched >= $maxRows) {
                break;
            }

            $skip += $chunkSize;
            $chunkIndex++;
        }
    }

    if ($emitChunk === null) {
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
    }

    usort($tasks, static function (array $left, array $right): int {
        return strcasecmp((string) ($left['component_no'] ?? ''), (string) ($right['component_no'] ?? ''));
    });

    return $tasks;
}

function contract_fetch_component_cards(
    string $company,
    array $componentNos,
    int $ttl = 3600,
    ?callable $emitChunk = null,
    ?callable $emitStepPlan = null
): array {
    $componentNos = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $componentNos))));

    if ($componentNos === []) {
        return [];
    }

    $chunkSize = $emitChunk !== null ? CONTRACT_FETCH_PROGRESS_CHUNK_SIZE : CONTRACT_FETCH_COMPONENT_CARD_CHUNK_SIZE;
    $chunks = array_chunk($componentNos, $chunkSize);
    $byNo = [];

    contract_emit_accurate_chunk_plan($emitStepPlan, 'components', count($componentNos), $chunkSize, null);

    foreach ($chunks as $chunkIndex => $chunk) {
        $stepId = 'components_' . $chunkIndex;
        contract_chunk_progress_emit($emitChunk, $stepId, 'start');

        $filters = [];
        foreach ($chunk as $componentNo) {
            $filters[] = "No eq '" . contract_escape_odata_string($componentNo) . "'";
        }

        $rows = contract_try_fetch_rows($company, 'AppComponentCard', [
            '$select' => 'No,Description,Serial_No,Main_Entity,Sub_Entity,Status',
            '$filter' => '(' . implode(' or ', $filters) . ')',
            '$top' => '100',
        ], $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $no = trim((string) ($row['No'] ?? ''));
            if ($no === '') {
                continue;
            }
            $byNo[$no] = [
                'no' => $no,
                'description' => trim((string) ($row['Description'] ?? '')),
                'serial_no' => trim((string) ($row['Serial_No'] ?? '')),
                'main_entity' => trim((string) ($row['Main_Entity'] ?? '')),
                'sub_entity' => trim((string) ($row['Sub_Entity'] ?? '')),
                'status' => trim((string) ($row['Status'] ?? '')),
            ];
        }

        contract_chunk_progress_emit($emitChunk, $stepId, 'done');
    }

    return $byNo;
}

function contract_build_component_groups(array $workorders, array $tasks, array $componentCards): array
{
    $groups = [];

    foreach ($tasks as $task) {
        $componentNo = (string) ($task['component_no'] ?? '');
        if ($componentNo === '') {
            continue;
        }
        if (!isset($groups[$componentNo])) {
            $groups[$componentNo] = [
                'component_no' => $componentNo,
                'card' => $componentCards[$componentNo] ?? null,
                'tasks' => [],
                'workorders' => [],
            ];
        }
        $groups[$componentNo]['tasks'][] = $task;
    }

    foreach ($workorders as $workorder) {
        $componentNo = (string) ($workorder['component_no'] ?? '');
        if ($componentNo === '') {
            continue;
        }
        if (!isset($groups[$componentNo])) {
            $groups[$componentNo] = [
                'component_no' => $componentNo,
                'card' => $componentCards[$componentNo] ?? null,
                'tasks' => [],
                'workorders' => [],
            ];
        }
        $groups[$componentNo]['workorders'][] = $workorder;
        if ($groups[$componentNo]['card'] === null && ($workorder['component_description'] ?? '') !== '') {
            $groups[$componentNo]['card'] = [
                'no' => $componentNo,
                'description' => (string) $workorder['component_description'],
                'serial_no' => '',
                'main_entity' => '',
                'sub_entity' => '',
                'status' => '',
            ];
        }
    }

    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values($groups);
}

function contract_get_detail(
    string $company,
    string $contractNo,
    int $ttl = 3600,
    ?callable $emitProgress = null,
    bool $skipContractFetch = false,
    ?callable $emitStepPlan = null
): array {
    contract_extend_load_time_limit();

    $contractNo = trim($contractNo);
    if ($contractNo === '') {
        return ['error_key' => 'contract.error.contract_required'];
    }

    $emit = static function (string $step, string $status) use ($emitProgress): void {
        if ($emitProgress !== null) {
            $emitProgress($step, $status);
        }
    };
    $emitChunk = $emitProgress;

    $emit('contract', 'start');
    $contract = $skipContractFetch ? null : contract_fetch_contract_by_no($company, $contractNo, $ttl);
    $emit('contract', 'done');

    $workorders = contract_fetch_workorders_for_contract($company, $contractNo, $ttl, $emitChunk, $emitStepPlan);
    $tasks = contract_fetch_component_tasks_for_contract($company, $contractNo, $ttl, $emitChunk, $emitStepPlan);

    if ($contract === null && $workorders === [] && $tasks === []) {
        return ['error_key' => 'contract.error.contract_not_found'];
    }

    if ($contract === null) {
        $contract = [
            'contract_no' => $contractNo,
            'contract_type' => '',
            'description' => '',
            'customer_no' => '',
            'customer_name' => '',
            'status' => '',
            'starting_date' => '',
            'end_date' => '',
        ];
    }

    $componentNos = [];
    foreach ($workorders as $workorder) {
        $componentNos[] = (string) ($workorder['component_no'] ?? '');
    }
    foreach ($tasks as $task) {
        $componentNos[] = (string) ($task['component_no'] ?? '');
    }

    $componentCards = contract_fetch_component_cards($company, $componentNos, $ttl, $emitChunk, $emitStepPlan);

    return [
        'contract' => $contract,
        'workorders' => $workorders,
        'components' => contract_build_component_groups($workorders, $tasks, $componentCards),
    ];
}

function contract_portal_prefetch_key(string $company, string $contractNo, string $customerNo, string $searchQuery): string
{
    return hash('sha256', json_encode([
        'company' => trim($company),
        'contract' => trim($contractNo),
        'customer' => trim($customerNo),
        'q' => trim($searchQuery),
    ], JSON_UNESCAPED_UNICODE));
}

function contract_portal_store_prefetch(string $key, array $data): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $_SESSION['contract_portal_prefetch'] = [
        'key' => $key,
        'data' => $data,
    ];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function contract_portal_take_prefetch(string $key): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $stored = $_SESSION['contract_portal_prefetch'] ?? null;
    unset($_SESSION['contract_portal_prefetch']);

    if (!is_array($stored) || (string) ($stored['key'] ?? '') !== $key) {
        return null;
    }

    $data = $stored['data'] ?? null;
    return is_array($data) ? $data : null;
}

function contract_load_page_state(
    string $company,
    string $contractNo,
    string $customerNo,
    string $searchQuery,
    int $ttl = 3600,
    ?array $contractDetailResult = null
): array {
    $state = [
        'view' => 'search',
        'errorKey' => '',
        'searchResult' => null,
        'customer' => null,
        'contracts' => [],
        'contractDetail' => null,
    ];

    if ($contractNo !== '') {
        $contractDetail = $contractDetailResult ?? contract_get_detail($company, $contractNo, $ttl);
        if (isset($contractDetail['error_key'])) {
            $state['errorKey'] = (string) $contractDetail['error_key'];
        } else {
            $state['view'] = 'contract';
            $state['contractDetail'] = $contractDetail;
        }

        return $state;
    }

    if ($customerNo !== '') {
        $customer = contract_fetch_customer_by_no($company, $customerNo, $ttl);
        if ($customer === null) {
            $state['errorKey'] = 'contract.error.not_found';
        } else {
            $state['view'] = 'contracts';
            $state['customer'] = $customer;
            $state['contracts'] = contract_fetch_contracts_for_customer($company, $customerNo, $ttl);
        }

        return $state;
    }

    if ($searchQuery === '') {
        return $state;
    }

    $searchResult = contract_search($company, $searchQuery, $ttl);
    $state['searchResult'] = $searchResult;
    $kind = (string) ($searchResult['kind'] ?? 'empty');

    if ($kind === 'contract') {
        $resolvedContractNo = (string) ($searchResult['contract_no'] ?? '');
        $contractDetail = contract_get_detail($company, $resolvedContractNo, $ttl);
        if (isset($contractDetail['error_key'])) {
            $state['errorKey'] = (string) $contractDetail['error_key'];
        } else {
            $state['view'] = 'contract';
            $state['contractDetail'] = $contractDetail;
        }

        return $state;
    }

    if ($kind === 'customer') {
        $state['view'] = 'contracts';
        $state['customer'] = is_array($searchResult['customer'] ?? null) ? $searchResult['customer'] : null;
        $state['contracts'] = is_array($searchResult['contracts'] ?? null) ? $searchResult['contracts'] : [];

        return $state;
    }

    if ($kind === 'customers') {
        $state['view'] = 'customers';

        return $state;
    }

    $state['errorKey'] = (string) ($searchResult['message_key'] ?? 'contract.error.not_found');

    return $state;
}

function contract_default_companies(): array
{
    return [
        'Koninklijke van Twist',
        'Hunter van Twist',
        'KVT Gas',
    ];
}

function contract_companies_for_page(int $ttl = 3600): array
{
    try {
        $result = auth_discover_companies_across_active_environments($ttl);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
        if ($companies !== []) {
            return $companies;
        }
    } catch (Throwable $ignored) {
    }

    return contract_default_companies();
}
