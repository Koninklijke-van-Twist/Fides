<?php
/**
 * Auth-template voor Fides. Kopieer naar web/auth.php (niet in git).
 *
 * Mímir heeft voorrang. Houd het BC-blok daaronder staan: als Mímir uitvalt,
 * haalt Fides dezelfde data direct bij Business Central op.
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaat de fetch eerst naar Mímir en valt terug op de BC-variabelen hieronder.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 * Zonder die BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, én fallback als Mímir faalt) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl",
];
