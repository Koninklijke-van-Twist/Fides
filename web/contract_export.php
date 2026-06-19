<?php

ini_set('display_errors', '0');

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/contract_data.php';
require_once __DIR__ . '/contract_xlsx.php';

/**
 * Page load
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    exit;
}

$company = trim((string) ($_GET['company'] ?? ''));
$contractNo = trim((string) ($_GET['contract'] ?? ''));

if ($company === '' || $contractNo === '') {
    http_response_code(400);
    exit('Export parameters ontbreken.');
}

try {
    auth_set_current_company_context($company);
    contract_extend_load_time_limit();

    $contractDetail = contract_get_detail($company, $contractNo);
    if (isset($contractDetail['error_key'])) {
        http_response_code(404);
        exit('Contract niet gevonden.');
    }

    $workorders = is_array($contractDetail['workorders'] ?? null) ? $contractDetail['workorders'] : [];
    $workorders = array_values(array_filter($workorders, static function ($workorder): bool {
        return is_array($workorder) && trim((string) ($workorder['no'] ?? '')) !== '';
    }));

    if ($workorders === []) {
        http_response_code(404);
        exit('Geen werkorders om te exporteren.');
    }

    $writer = contract_build_contract_export_xlsx($company, $contractDetail);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $contractNo) . '.xlsx';
    $writer->sendDownload($filename);
} catch (Throwable $exportError) {
    http_response_code(500);
    exit('Export mislukt.');
}
