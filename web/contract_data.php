<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

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

function contract_fetch_workorders_for_contract(string $company, string $contractNo, int $ttl = 3600): array
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $rows = contract_try_fetch_rows($company, 'LVS_MainWorkOrderCard', [
        '$select' => 'No,Contract_No,Component_No,Component_Description,Status,Task_Code,KVT_Report_Status,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel',
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$top' => '500',
    ], $ttl);

    $workorders = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalized = contract_normalize_workorder_row($row);
        if ($normalized['no'] !== '') {
            $workorders[] = $normalized;
        }
    }

    usort($workorders, static function (array $left, array $right): int {
        return strcasecmp((string) ($right['no'] ?? ''), (string) ($left['no'] ?? ''));
    });

    return $workorders;
}

function contract_fetch_component_tasks_for_contract(string $company, string $contractNo, int $ttl = 3600): array
{
    $escaped = contract_escape_odata_string($contractNo);
    if ($escaped === '') {
        return [];
    }

    $rows = contract_try_fetch_rows($company, 'AppComponentCardTasks', [
        '$select' => 'Component_No,Contract_No,Task_Code,Description,Main_Entity,Contract_Status',
        '$filter' => "Contract_No eq '" . $escaped . "'",
        '$top' => '500',
    ], $ttl);

    $tasks = [];
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

    return $tasks;
}

function contract_fetch_component_cards(string $company, array $componentNos, int $ttl = 3600): array
{
    $componentNos = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $componentNos))));

    if ($componentNos === []) {
        return [];
    }

    $chunks = array_chunk($componentNos, 20);
    $byNo = [];

    foreach ($chunks as $chunk) {
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

function contract_get_detail(string $company, string $contractNo, int $ttl = 3600): array
{
    $contractNo = trim($contractNo);
    if ($contractNo === '') {
        return ['error_key' => 'contract.error.contract_required'];
    }

    $contract = contract_fetch_contract_by_no($company, $contractNo, $ttl);
    $workorders = contract_fetch_workorders_for_contract($company, $contractNo, $ttl);
    $tasks = contract_fetch_component_tasks_for_contract($company, $contractNo, $ttl);

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

    $componentCards = contract_fetch_component_cards($company, $componentNos, $ttl);

    return [
        'contract' => $contract,
        'workorders' => $workorders,
        'components' => contract_build_component_groups($workorders, $tasks, $componentCards),
    ];
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
