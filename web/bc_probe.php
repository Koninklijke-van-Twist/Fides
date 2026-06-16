<?php
/**
 * Live BC OData probe — alleen voor lokale analyse.
 * Gebruik: php bc_probe.php
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/auth_helper.php';

/** @var string $baseUrl */

function probe_company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function probe_fetch_page(string $url, array $auth): array
{
    $resp = probe_odata_get_json($url, $auth);
    return is_array($resp['value'] ?? null) ? $resp['value'] : [];
}

function probe_odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'JeroenDing-BCProbe/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' from OData: ' . substr((string) $raw, 0, 500));
    }

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from OData');
    }

    return $json;
}

function probe_fetch_companies(string $baseUrl, string $environment, array $auth): array
{
    $prefix = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/';
    $urls = [
        $prefix . 'Companies?$select=Name&$top=50',
        $prefix . 'Company?$select=Name&$top=50',
    ];

    foreach ($urls as $url) {
        try {
            $resp = probe_odata_get_json($url, $auth);
            $rows = is_array($resp['value'] ?? null) ? $resp['value'] : [];
            $names = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['Name'] ?? $row['Display_Name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            if ($names !== []) {
                natcasesort($names);
                return array_values($names);
            }
        } catch (Throwable $ignored) {
        }
    }

    throw new RuntimeException('Geen companies opgehaald voor ' . $environment);
}

function probe_try_entity(string $label, string $url, array $auth): array
{
    try {
        $rows = probe_fetch_page($url, $auth);
        return [
            'ok' => true,
            'label' => $label,
            'count' => count($rows),
            'sample' => array_slice($rows, 0, 5),
        ];
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'label' => $label,
            'error' => $error->getMessage(),
        ];
    }
}

function probe_pick_field(array $rows, string $field): ?string
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function probe_summarize_row(array $row, array $fields): array
{
    $out = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $out[$field] = $row[$field];
        }
    }
    return $out;
}

$environment = auth_get_primary_environment();
$auth = auth_get_auth_for_environment($environment);
$companies = probe_fetch_companies($baseUrl, $environment, $auth);
$company = (string) (getenv('BC_PROBE_COMPANY') ?: ($companies[0] ?? ''));

if ($company === '') {
    fwrite(STDERR, "Geen company gevonden.\n");
    exit(1);
}

$report = [
    'environment' => $environment,
    'company' => $company,
    'available_companies' => $companies,
    'probes' => [],
    'relationship_checks' => [],
];

$entityProbes = [
    'AppCustomerCard' => [
        '$top' => '5',
        '$select' => 'No,Name,Search_Name',
        '$orderby' => 'No asc',
    ],
    'AppMaintenanceContracts' => [
        '$top' => '10',
        '$select' => 'Contract_No,Contract_Type,Description,Customer_No,Name,Status,Starting_Date,End_Date,KVT_Workorder_Count',
        '$orderby' => 'Contract_No asc',
    ],
    'AppWerkorders' => [
        '$top' => '10',
        '$select' => 'No,Contract_No,Component_No,Component_Description,Main_Entity,Status,Task_Code,Job_No',
        '$filter' => "Contract_No ne ''",
        '$orderby' => 'No desc',
    ],
    'LVS_MainWorkOrderCard' => [
        '$top' => '10',
        '$select' => 'No,Contract_No,Component_No,Component_Description,Status,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel,KVT_Report_Status',
        '$filter' => "Contract_No ne ''",
        '$orderby' => 'No desc',
    ],
    'Werkorders' => [
        '$top' => '5',
        '$select' => 'No,Contract_No,Component_No,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel,KVT_Report_Status',
        '$filter' => "Contract_No ne ''",
        '$orderby' => 'No desc',
    ],
    'AppComponentCard' => [
        '$top' => '5',
        '$select' => 'No,Description,Main_Entity,Sub_Entity,Sell_to_Customer_No,Sell_to_Customer_Name,Serial_No,Status',
        '$orderby' => 'No asc',
    ],
    'AppComponentCardTasks' => [
        '$top' => '10',
        '$select' => 'Component_No,Contract_No,Task_Code,Description,Main_Entity,Contract_Status',
        '$filter' => "Contract_No ne ''",
    ],
    'LVS_Componenten' => [
        '$top' => '5',
        '$select' => 'No,Description,Main_Entity,Sell_to_Customer_No,Serial_No',
        '$orderby' => 'No asc',
    ],
    'ComponentOverview' => [
        '$top' => '5',
        '$select' => 'No,Description,Main_Entity,Sell_to_Customer_No,Serial_No',
        '$orderby' => 'No asc',
    ],
    'Attachment' => [
        '$top' => '5',
        '$select' => 'Entry_No,File_Name,Main_Entity,Component_No,Maintenance_Work_Order_No,Customer_No,Job_No',
        '$orderby' => 'Entry_No desc',
    ],
    'sharePointURLlink' => [
        '$top' => '5',
        '$select' => 'Entity_Attachment_Entry_No,File_Name,URL,URL_Imported',
        '$orderby' => 'Entity_Attachment_Entry_No desc',
    ],
];

foreach ($entityProbes as $entitySet => $query) {
    $url = probe_company_entity_url($baseUrl, $environment, $company, $entitySet, $query);
    $report['probes'][$entitySet] = probe_try_entity($entitySet, $url, $auth);
}

$woContractSampleUrl = probe_company_entity_url($baseUrl, $environment, $company, 'AppWerkorders', [
    '$top' => '1',
    '$select' => 'No,Contract_No,Component_No,Component_Description',
    '$filter' => "Contract_No ne ''",
    '$orderby' => 'No desc',
]);
$woContractSample = probe_fetch_page($woContractSampleUrl, $auth);
$sampleWoContractNo = probe_pick_field($woContractSample, 'Contract_No');

$maintenanceContractUrl = probe_company_entity_url($baseUrl, $environment, $company, 'AppMaintenanceContracts', [
    '$top' => '1',
    '$select' => 'Contract_No,Customer_No,Name,Description,KVT_Workorder_Count',
    '$filter' => "Contract_No ne ''",
    '$orderby' => 'Contract_No desc',
]);
$maintenanceContractSample = probe_fetch_page($maintenanceContractUrl, $auth);
$sampleMaintenanceContractNo = probe_pick_field($maintenanceContractSample, 'Contract_No');
$sampleCustomerNo = probe_pick_field($maintenanceContractSample, 'Customer_No');

$report['relationship_checks']['contract_number_formats'] = [
    'sample_workorder_contract_no' => $sampleWoContractNo,
    'sample_maintenance_contract_no' => $sampleMaintenanceContractNo,
];

foreach (array_filter([$sampleWoContractNo, $sampleMaintenanceContractNo]) as $contractNo) {
    $escapedContract = str_replace("'", "''", $contractNo);
    $lookupUrl = probe_company_entity_url($baseUrl, $environment, $company, 'AppMaintenanceContracts', [
        '$select' => 'Contract_No,Customer_No,Name,Status,KVT_Workorder_Count',
        '$filter' => "Contract_No eq '" . $escapedContract . "'",
        '$top' => '1',
    ]);
    $report['relationship_checks']['maintenance_lookup_' . $contractNo] = probe_try_entity(
        'maintenance_lookup_' . $contractNo,
        $lookupUrl,
        $auth
    );
}

if ($sampleWoContractNo !== null) {
    $escapedContract = str_replace("'", "''", $sampleWoContractNo);
    $contractFilter = "Contract_No eq '" . $escapedContract . "'";

    $contractChecks = [
        'werkorders_app' => probe_company_entity_url($baseUrl, $environment, $company, 'AppWerkorders', [
            '$select' => 'No,Contract_No,Component_No,Component_Description,Status,Task_Code',
            '$filter' => $contractFilter,
            '$top' => '20',
        ]),
        'werkorders_lvs' => probe_company_entity_url($baseUrl, $environment, $company, 'LVS_MainWorkOrderCard', [
            '$select' => 'No,Contract_No,Component_No,Component_Description,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel,KVT_Report_Status',
            '$filter' => $contractFilter,
            '$top' => '20',
        ]),
        'component_tasks' => probe_company_entity_url($baseUrl, $environment, $company, 'AppComponentCardTasks', [
            '$select' => 'Component_No,Contract_No,Task_Code,Description,Main_Entity',
            '$filter' => $contractFilter,
            '$top' => '50',
        ]),
    ];

    $report['relationship_checks']['sample_workorder_contract'] = probe_summarize_row(
        is_array($woContractSample[0] ?? null) ? $woContractSample[0] : [],
        ['No', 'Contract_No', 'Component_No', 'Component_Description']
    );

    foreach ($contractChecks as $key => $url) {
        $result = probe_try_entity($key, $url, $auth);
        if ($result['ok'] ?? false) {
            $rows = is_array($result['sample'] ?? null) ? $result['sample'] : [];
            $components = [];
            $pdfCount = 0;
            $excelCount = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $comp = trim((string) ($row['Component_No'] ?? ''));
                if ($comp !== '') {
                    $components[$comp] = true;
                }
                if (trim((string) ($row['KVT_URL_Workorder_Report_PDF'] ?? '')) !== '') {
                    $pdfCount++;
                }
                if (trim((string) ($row['KVT_URL_Workorder_Report_Excel'] ?? '')) !== '') {
                    $excelCount++;
                }
            }
            $result['distinct_component_nos'] = count($components);
            $result['rows_with_pdf_url'] = $pdfCount;
            $result['rows_with_excel_url'] = $excelCount;
        }
        $report['relationship_checks'][$key] = $result;
    }
}

if ($sampleCustomerNo !== null) {
    $escapedCustomer = str_replace("'", "''", $sampleCustomerNo);
    $customerContractsUrl = probe_company_entity_url($baseUrl, $environment, $company, 'AppMaintenanceContracts', [
        '$select' => 'Contract_No,Description,Status,Starting_Date,End_Date,KVT_Workorder_Count',
        '$filter' => "Customer_No eq '" . $escapedCustomer . "'",
        '$orderby' => 'Contract_No asc',
        '$top' => '20',
    ]);
    $report['relationship_checks']['contracts_for_sample_customer'] = probe_try_entity(
        'contracts_for_sample_customer',
        $customerContractsUrl,
        $auth
    );
    $report['relationship_checks']['sample_customer_no'] = $sampleCustomerNo;
}

$report['relationship_checks']['workorders_with_report_urls'] = probe_try_entity(
    'workorders_with_report_urls',
    probe_company_entity_url($baseUrl, $environment, $company, 'LVS_MainWorkOrderCard', [
        '$top' => '5',
        '$select' => 'No,Contract_No,Component_No,Component_Description,KVT_URL_Workorder_Report_PDF,KVT_URL_Workorder_Report_Excel,KVT_Report_Status,Status',
        '$filter' => "KVT_URL_Workorder_Report_PDF ne ''",
        '$orderby' => 'No desc',
    ]),
    $auth
);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
