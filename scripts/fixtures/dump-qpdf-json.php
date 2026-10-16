<?php

declare(strict_types=1);

/*
 * Refreshes the qpdf JSON dumps next to the fixture PDFs (tests/Fixtures/pdf/*.qpdf.json) with the
 * exact qpdf calls of QpdfAcroFormReader, so unit tests can map fields without running qpdf.
 * Usage: php scripts/fixtures/dump-qpdf-json.php   (QPDF_BINARY defaults to scripts/qpdf)
 */

use App\Domain\Forms\Import\Qpdf\QpdfAcroFormReader;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('QPDF_BINARY') === false) {
    config(['forms.qpdf_binary' => 'scripts/qpdf']);
}

$reader = app(QpdfAcroFormReader::class);
foreach (['service-sheet', 'hierarchical'] as $name) {
    $dump = $reader->dump($root."/tests/Fixtures/pdf/{$name}.pdf");
    $dump['streams'] = array_map(base64_encode(...), $dump['streams']);
    $json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents($root."/tests/Fixtures/pdf/{$name}.qpdf.json", $json."\n");
    echo "wrote tests/Fixtures/pdf/{$name}.qpdf.json\n";
}
