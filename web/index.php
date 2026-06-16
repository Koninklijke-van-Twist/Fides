<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/contract_data.php';

/**
 * Functies
 */

function portal_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_url(array $params = []): string
{
    $query = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }
    unset($query['lang']);

    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'), '?') ?: 'index.php';
    $lang = getCurrentLanguage();
    $query['lang'] = $lang;

    return $path . '?' . http_build_query($query);
}

function portal_format_period(string $from, string $to): string
{
    $from = trim($from);
    $to = trim($to);
    if ($from !== '' && $to !== '') {
        return $from . ' – ' . $to;
    }
    return $from !== '' ? $from : $to;
}

function portal_component_label(array $group): string
{
    $card = is_array($group['card'] ?? null) ? $group['card'] : [];
    $description = trim((string) ($card['description'] ?? ''));
    if ($description !== '') {
        return $description;
    }

    $workorders = is_array($group['workorders'] ?? null) ? $group['workorders'] : [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }
        $fallback = trim((string) ($workorder['component_description'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }
    }

    return (string) ($group['component_no'] ?? '');
}

function portal_render_report_links(array $workorder): string
{
    $pdf = trim((string) ($workorder['pdf_url'] ?? ''));
    $excel = trim((string) ($workorder['excel_url'] ?? ''));
    if ($pdf === '' && $excel === '') {
        return '<span class="contract-muted">' . portal_h(LOC('contract.empty.reports')) . '</span>';
    }

    $parts = [];
    if ($pdf !== '') {
        $parts[] = '<a class="contract-link" href="' . portal_h($pdf) . '" target="_blank" rel="noopener noreferrer">' . portal_h(LOC('contract.link.pdf')) . '</a>';
    }
    if ($excel !== '') {
        $parts[] = '<a class="contract-link" href="' . portal_h($excel) . '" target="_blank" rel="noopener noreferrer">' . portal_h(LOC('contract.link.excel')) . '</a>';
    }

    return implode(' · ', $parts);
}

/**
 * Page load
 */

$companies = contract_companies_for_page();
$company = trim((string) ($_GET['company'] ?? ($companies[0] ?? '')));
if ($company === '' || !in_array($company, $companies, true)) {
    $company = (string) ($companies[0] ?? '');
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$customerNo = trim((string) ($_GET['customer'] ?? ''));
$contractNo = trim((string) ($_GET['contract'] ?? ''));

$view = 'search';
$errorKey = '';
$searchResult = null;
$customer = null;
$contracts = [];
$contractDetail = null;

auth_set_current_company_context($company);

try {
    if ($contractNo !== '') {
        $contractDetail = contract_get_detail($company, $contractNo);
        if (isset($contractDetail['error_key'])) {
            $errorKey = (string) $contractDetail['error_key'];
            $contractDetail = null;
            $view = 'search';
        } else {
            $view = 'contract';
        }
    } elseif ($customerNo !== '') {
        $customer = contract_fetch_customer_by_no($company, $customerNo);
        if ($customer === null) {
            $errorKey = 'contract.error.not_found';
        } else {
            $contracts = contract_fetch_contracts_for_customer($company, $customerNo);
            $view = 'contracts';
        }
    } elseif ($searchQuery !== '') {
        $searchResult = contract_search($company, $searchQuery);
        $kind = (string) ($searchResult['kind'] ?? 'empty');

        if ($kind === 'contract') {
            $contractNo = (string) ($searchResult['contract_no'] ?? '');
            $contractDetail = contract_get_detail($company, $contractNo);
            if (isset($contractDetail['error_key'])) {
                $errorKey = (string) $contractDetail['error_key'];
            } else {
                $view = 'contract';
            }
        } elseif ($kind === 'customer') {
            $customer = is_array($searchResult['customer'] ?? null) ? $searchResult['customer'] : null;
            $contracts = is_array($searchResult['contracts'] ?? null) ? $searchResult['contracts'] : [];
            $view = 'contracts';
        } elseif ($kind === 'customers') {
            $view = 'customers';
        } else {
            $errorKey = (string) ($searchResult['message_key'] ?? 'contract.error.not_found');
        }
    }
} catch (Throwable $loadError) {
    $errorKey = 'contract.error.load_failed';
}

?><!DOCTYPE html>
<html lang="<?= portal_h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= portal_h(LOC('app.title')) ?></title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="favicon.ico" sizes="any">
    <?php renderLanguageSwitcherStyles(); ?>
    <style>
        .contract-page { max-width: 960px; margin: 0 auto; padding: 16px; }
        .contract-header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .contract-header img { max-height: 42px; width: auto; }
        .contract-card { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .contract-card h2, .contract-card h3 { margin: 0 0 12px; color: var(--kvt-text); }
        .contract-form { display: grid; gap: 12px; }
        .contract-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .contract-form input, .contract-form select, .contract-btn { font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px; }
        .contract-form input, .contract-form select { width: 100%; box-sizing: border-box; }
        .contract-btn { background: var(--kvt-main-blue); color: #fff; border-color: var(--kvt-main-blue); cursor: pointer; text-decoration: none; display: inline-block; text-align: center; }
        .contract-btn-secondary { background: #fff; color: var(--kvt-main-blue); }
        .contract-alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .contract-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
        .contract-list-item { border: 1px solid var(--kvt-line); border-radius: 10px; padding: 12px 14px; }
        .contract-list-item a { color: var(--kvt-main-blue); text-decoration: none; font-weight: 700; }
        .contract-meta { display: grid; gap: 8px; margin-bottom: 12px; }
        .contract-meta-row { display: flex; flex-wrap: wrap; gap: 8px 16px; }
        .contract-meta-label { color: var(--kvt-muted); min-width: 88px; }
        .contract-muted { color: var(--kvt-muted); font-size: 0.92rem; }
        .contract-component { border-top: 1px solid var(--kvt-line); padding-top: 14px; margin-top: 14px; }
        .contract-component:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
        .contract-table-wrap { overflow-x: auto; }
        table.contract-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        table.contract-table th, table.contract-table td { border-bottom: 1px solid var(--kvt-line); padding: 10px 8px; text-align: left; vertical-align: top; }
        table.contract-table th { color: var(--kvt-muted); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .contract-link { color: var(--kvt-main-blue); font-weight: 700; text-decoration: none; }
        .contract-subtitle { color: var(--kvt-muted); margin: 6px 0 0; }
        @media (min-width: 640px) {
            .contract-form-grid { grid-template-columns: 1fr 2fr auto; align-items: end; }
            .contract-form-grid .contract-btn { width: auto; min-width: 120px; }
        }
        .contract-loader {
            position: fixed;
            inset: 0;
            z-index: 12000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(255, 255, 255, 0.92);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }
        .contract-loader.is-visible {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .contract-loader-panel {
            display: grid;
            gap: 12px;
            justify-items: center;
            max-width: 280px;
            text-align: center;
            color: var(--kvt-text);
        }
        .contract-loader-spinner {
            width: 42px;
            height: 42px;
            border: 3px solid rgba(0, 153, 204, 0.2);
            border-top-color: var(--kvt-main-blue);
            border-radius: 50%;
            animation: contract-loader-spin 0.8s linear infinite;
        }
        .contract-loader-title {
            margin: 0;
            font-family: var(--kvt-font-display);
            font-size: 1.1rem;
        }
        .contract-loader-text {
            margin: 0;
            color: var(--kvt-muted);
            font-size: 0.92rem;
        }
        @keyframes contract-loader-spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="contract-page">
    <header class="contract-header">
        <img src="logo-website.png" alt="KVT">
        <?php renderLanguageSwitcher(); ?>
    </header>

    <section class="contract-card">
        <h1 class="brand-display"><?= portal_h(LOC('contract.hero.title')) ?></h1>
        <p class="contract-subtitle"><?= portal_h(LOC('contract.hero.subtitle')) ?></p>

        <form class="contract-form contract-form-grid contract-nav" method="get" action="index.php" style="margin-top: 16px;">
            <input type="hidden" name="lang" value="<?= portal_h(getCurrentLanguage()) ?>">
            <label>
                <?= portal_h(LOC('contract.label.company')) ?>
                <select name="company">
                    <?php foreach ($companies as $companyOption): ?>
                        <option value="<?= portal_h($companyOption) ?>"<?= $companyOption === $company ? ' selected' : '' ?>><?= portal_h($companyOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?= portal_h(LOC('contract.label.search')) ?>
                <input type="search" name="q" value="<?= portal_h($searchQuery) ?>" placeholder="<?= portal_h(LOC('contract.placeholder.search')) ?>" autocomplete="off">
            </label>
            <button class="contract-btn" type="submit"><?= portal_h(LOC('contract.btn.search')) ?></button>
        </form>
    </section>

    <?php if ($errorKey !== ''): ?>
        <div class="contract-alert"><?= portal_h(LOC($errorKey)) ?></div>
    <?php endif; ?>

    <?php if ($view === 'customers' && is_array($searchResult['customers'] ?? null)): ?>
        <section class="contract-card">
            <h2><?= portal_h(LOC('contract.section.customers')) ?></h2>
            <ul class="contract-list">
                <?php foreach ($searchResult['customers'] as $customerRow): ?>
                    <?php if (!is_array($customerRow)) { continue; } ?>
                    <li class="contract-list-item">
                        <a class="contract-nav" href="<?= portal_h(portal_url(['company' => $company, 'customer' => (string) ($customerRow['no'] ?? ''), 'q' => null, 'contract' => null])) ?>">
                            <?= portal_h((string) ($customerRow['name'] ?? '')) ?>
                        </a>
                        <div class="contract-muted"><?= portal_h((string) ($customerRow['no'] ?? '')) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($view === 'contracts' && $customer !== null): ?>
        <section class="contract-card">
            <h2><?= portal_h(LOC('contract.section.contracts')) ?></h2>
            <div class="contract-meta">
                <div class="contract-meta-row">
                    <span class="contract-meta-label"><?= portal_h(LOC('contract.meta.customer')) ?></span>
                    <span><?= portal_h((string) ($customer['name'] ?? '')) ?> (<?= portal_h((string) ($customer['no'] ?? '')) ?>)</span>
                </div>
            </div>
            <?php if ($contracts === []): ?>
                <p class="contract-muted"><?= portal_h(LOC('contract.empty.contracts')) ?></p>
            <?php else: ?>
                <ul class="contract-list">
                    <?php foreach ($contracts as $contractRow): ?>
                        <li class="contract-list-item">
                            <a class="contract-nav" href="<?= portal_h(portal_url(['company' => $company, 'contract' => (string) ($contractRow['contract_no'] ?? ''), 'q' => null, 'customer' => null])) ?>">
                                <?= portal_h((string) ($contractRow['contract_no'] ?? '')) ?>
                            </a>
                            <?php if (trim((string) ($contractRow['description'] ?? '')) !== ''): ?>
                                <div><?= portal_h((string) $contractRow['description']) ?></div>
                            <?php endif; ?>
                            <div class="contract-muted">
                                <?= portal_h((string) ($contractRow['status'] ?? '')) ?>
                                <?php if (portal_format_period((string) ($contractRow['starting_date'] ?? ''), (string) ($contractRow['end_date'] ?? '')) !== ''): ?>
                                    · <?= portal_h(portal_format_period((string) ($contractRow['starting_date'] ?? ''), (string) ($contractRow['end_date'] ?? ''))) ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($view === 'contract' && is_array($contractDetail)): ?>
        <?php
        $contract = is_array($contractDetail['contract'] ?? null) ? $contractDetail['contract'] : [];
        $components = is_array($contractDetail['components'] ?? null) ? $contractDetail['components'] : [];
        $workorders = is_array($contractDetail['workorders'] ?? null) ? $contractDetail['workorders'] : [];
        ?>
        <section class="contract-card">
            <h2><?= portal_h((string) ($contract['contract_no'] ?? $contractNo)) ?></h2>
            <div class="contract-meta">
                <?php if (trim((string) ($contract['description'] ?? '')) !== ''): ?>
                    <div><?= portal_h((string) $contract['description']) ?></div>
                <?php endif; ?>
                <div class="contract-meta-row">
                    <span class="contract-meta-label"><?= portal_h(LOC('contract.meta.customer')) ?></span>
                    <span>
                        <?php if (trim((string) ($contract['customer_name'] ?? '')) !== ''): ?>
                            <?= portal_h((string) $contract['customer_name']) ?> (<?= portal_h((string) ($contract['customer_no'] ?? '')) ?>)
                        <?php else: ?>
                            <?= portal_h((string) ($contract['customer_no'] ?? '—')) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="contract-meta-row">
                    <span class="contract-meta-label"><?= portal_h(LOC('contract.meta.status')) ?></span>
                    <span><?= portal_h((string) (($contract['status'] ?? '') !== '' ? $contract['status'] : '—')) ?></span>
                </div>
                <?php if (trim((string) ($contract['contract_type'] ?? '')) !== ''): ?>
                    <div class="contract-meta-row">
                        <span class="contract-meta-label"><?= portal_h(LOC('contract.meta.type')) ?></span>
                        <span><?= portal_h((string) $contract['contract_type']) ?></span>
                    </div>
                <?php endif; ?>
                <?php $period = portal_format_period((string) ($contract['starting_date'] ?? ''), (string) ($contract['end_date'] ?? '')); ?>
                <?php if ($period !== ''): ?>
                    <div class="contract-meta-row">
                        <span class="contract-meta-label"><?= portal_h(LOC('contract.meta.period')) ?></span>
                        <span><?= portal_h($period) ?></span>
                    </div>
                <?php endif; ?>
                <div class="contract-muted"><?= portal_h(LOC('contract.workorders.count', (string) count($workorders))) ?></div>
            </div>
            <p><a class="contract-btn contract-btn-secondary contract-nav" href="<?= portal_h(portal_url(['contract' => null, 'customer' => null, 'q' => null])) ?>"><?= portal_h(LOC('contract.btn.back')) ?></a></p>
        </section>

        <section class="contract-card">
            <h2><?= portal_h(LOC('contract.section.components')) ?></h2>
            <?php if ($components === []): ?>
                <p class="contract-muted"><?= portal_h(LOC('contract.empty.components')) ?></p>
            <?php else: ?>
                <?php foreach ($components as $group): ?>
                    <?php if (!is_array($group)) { continue; } ?>
                    <?php $card = is_array($group['card'] ?? null) ? $group['card'] : []; ?>
                    <?php $groupWorkorders = is_array($group['workorders'] ?? null) ? $group['workorders'] : []; ?>
                    <?php $groupTasks = is_array($group['tasks'] ?? null) ? $group['tasks'] : []; ?>
                    <article class="contract-component">
                        <h3><?= portal_h(portal_component_label($group)) ?></h3>
                        <div class="contract-muted">
                            <?= portal_h(LOC('contract.col.component')) ?>: <?= portal_h((string) ($group['component_no'] ?? '')) ?>
                            <?php if (trim((string) ($card['serial_no'] ?? '')) !== ''): ?>
                                · <?= portal_h(LOC('contract.col.serial')) ?> <?= portal_h((string) $card['serial_no']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($groupTasks !== []): ?>
                            <p class="contract-muted"><?= portal_h(LOC('contract.tasks.count', (string) count($groupTasks))) ?></p>
                        <?php endif; ?>

                        <?php if ($groupWorkorders === []): ?>
                            <p class="contract-muted"><?= portal_h(LOC('contract.empty.workorders')) ?></p>
                        <?php else: ?>
                            <div class="contract-table-wrap">
                                <table class="contract-table">
                                    <thead>
                                        <tr>
                                            <th><?= portal_h(LOC('contract.col.workorder')) ?></th>
                                            <th><?= portal_h(LOC('contract.col.task')) ?></th>
                                            <th><?= portal_h(LOC('contract.col.status')) ?></th>
                                            <th><?= portal_h(LOC('contract.section.reports')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($groupWorkorders as $workorder): ?>
                                            <?php if (!is_array($workorder)) { continue; } ?>
                                            <tr>
                                                <td><?= portal_h((string) ($workorder['no'] ?? '')) ?></td>
                                                <td><?= portal_h((string) ($workorder['task_code'] ?? '')) ?></td>
                                                <td><?= portal_h((string) ($workorder['status'] ?? '')) ?></td>
                                                <td><?= portal_render_report_links($workorder) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'deleteUrl' => 'odata.php?action=cache_delete',
        'clearUrl' => 'odata.php?action=cache_clear',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
        'css' => '{{root}} .odata-cache-widget{top:16px;right:16px;left:auto;} {{root}} .odata-cache-popout{top:64px;right:16px;left:auto;}',
    ]) ?>
</div>

<div id="contract-loader" class="contract-loader" aria-hidden="true" aria-live="polite" aria-busy="false">
    <div class="contract-loader-panel">
        <div class="contract-loader-spinner" aria-hidden="true"></div>
        <p class="contract-loader-title"><?= portal_h(LOC('contract.loader.wait')) ?></p>
        <p class="contract-loader-text"><?= portal_h(LOC('contract.loader.loading')) ?></p>
    </div>
</div>

<script>
(function () {
    var DELAY_MS = 500;
    var loader = document.getElementById('contract-loader');
    if (!loader) {
        return;
    }

    var timer = null;

    function showLoader() {
        loader.classList.add('is-visible');
        loader.setAttribute('aria-hidden', 'false');
        loader.setAttribute('aria-busy', 'true');
    }

    function scheduleLoader() {
        if (timer !== null) {
            return;
        }
        timer = window.setTimeout(function () {
            timer = null;
            showLoader();
        }, DELAY_MS);
    }

    function isNavigationTrigger(element) {
        if (!element) {
            return false;
        }
        if (element.matches('.contract-nav[href], .contract-nav[type="submit"], .lang-switcher-item a')) {
            if (element.tagName === 'A' && element.target === '_blank') {
                return false;
            }
            return true;
        }
        return false;
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.contract-nav[href], .contract-nav[type="submit"], .lang-switcher-item a');
        if (!isNavigationTrigger(trigger)) {
            return;
        }
        scheduleLoader();
    }, true);

    document.querySelectorAll('form.contract-nav').forEach(function (form) {
        form.addEventListener('submit', function () {
            scheduleLoader();
        });
    });
})();
</script>
<?php renderLanguageSwitcherScript(); ?>
</body>
</html>
