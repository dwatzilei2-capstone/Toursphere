<?php
/**
 * Writes the synthetic Certificate of Registration used by the Fuel Type browser test.
 * The document states AUTOGAS explicitly so the form must pre-fill LPG / Autogas.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/support/document_pdf.php';

$path = ROOT_PATH . '/tmp/fuel-type-ui-registration.pdf';
if (in_array('--clean', $argv, true)) {
    if (is_file($path)) unlink($path);
    echo "Fuel type browser test document removed.\n";
    exit;
}
if (!is_dir(ROOT_PATH . '/tmp')) mkdir(ROOT_PATH . '/tmp', 0777, true);
file_put_contents($path, toursphere_test_document_pdf([
    'REPUBLIC OF THE PHILIPPINES',
    'LAND TRANSPORTATION OFFICE',
    'CERTIFICATE OF REGISTRATION',
    'TEST ONLY - FICTIONAL DOCUMENT FOR TOURSPHERE TESTING',
    'PLATE NUMBER: FUE-1234',
    'MV FILE NUMBER: TEST-MV-99-000010',
    'ENGINE NUMBER: TEST-ENG-FUE-000010',
    'CHASSIS NUMBER: TEST-CHS-FUE-000010',
    'MAKE / MODEL: Toyota HiAce',
    'SERIES / MODEL: HiAce',
    'BODY TYPE: CARGO VAN',
    'YEAR MODEL: 2026',
    'FUEL TYPE: AUTOGAS',
    'MAXIMUM PAYLOAD: 1,200 KG',
    'CARGO VOLUME: 6.2 M3',
    'COLOR: WHITE',
    'CLASSIFICATION: CARGO',
]));
echo $path;
