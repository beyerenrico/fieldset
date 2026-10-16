<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Qpdf;

use App\Domain\Forms\Import\AcroFormReader;
use App\Domain\Forms\Import\Model\AcroFormDocument;
use App\Domain\Forms\Import\PdfImportException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Reads AcroForm fields with the qpdf CLI (ADR 0024, verified with qpdf 11.3.0). Two passes:
 *
 * 1. `qpdf --json=2 --json-key=acroform --json-key=pages --json-key=encrypt --json-key=qpdf
 *    --json-stream-data=none <file>`: field tree, pages and the object table without stream data.
 * 2. `qpdf --json=2 --json-key=qpdf --json-stream-data=inline --decode-level=generalized
 *    --json-object=N,G … <file>`: only page content streams (label detection) and JavaScript
 *    streams (read as text, never executed).
 *
 * Every call has a timeout and an output cap. Exit code 3 (warnings) means qpdf repaired the file.
 */
final readonly class QpdfAcroFormReader implements AcroFormReader
{
    private const int MAX_STREAMS = 5000;

    private string $binary;

    public function __construct(
        private QpdfDocumentParser $parser,
        #[Config('forms.qpdf_binary', 'qpdf')] string $binary = 'qpdf',
        #[Config('forms.pdf_import.timeout', 60)] private int $timeout = 60,
        #[Config('forms.pdf_import.max_bytes', 20971520)] private int $maxBytes = 20971520,
        #[Config('forms.pdf_import.max_pages', 50)] private int $maxPages = 50,
        #[Config('forms.pdf_import.max_output_bytes', 67108864)] private int $maxOutputBytes = 67108864,
    ) {
        // A relative binary path (e.g. scripts/qpdf) is resolved against the project root.
        $this->binary = str_contains($binary, '/') && ! str_starts_with($binary, '/') ? base_path($binary) : $binary;
    }

    public function read(string $path): AcroFormDocument
    {
        $dump = $this->dump($path);
        $document = $this->parser->parse($dump['structure'], $dump['streams']);

        return new AcroFormDocument($document->pageCount, $document->fields, $document->texts, $document->title, $dump['warnings']);
    }

    /**
     * Raw qpdf output of both passes, after the limit checks (also used to refresh test fixtures).
     *
     * @return array{structure: array<string, mixed>, streams: array<string, string>, warnings: list<string>}
     */
    public function dump(string $path): array
    {
        $this->checkFile($path);
        [$structure, $repaired] = $this->run([
            '--json=2', '--json-key=acroform', '--json-key=pages', '--json-key=encrypt', '--json-key=qpdf',
            '--json-stream-data=none', $path,
        ]);
        $this->checkStructure($structure);

        $refs = $this->parser->streamRefs($structure);
        $streams = [];
        if ($refs !== [] && count($refs) <= self::MAX_STREAMS) {
            $objects = array_map(static fn (string $ref): string => '--json-object='.str_replace(' ', ',', substr($ref, 0, -2)), $refs);
            [$data] = $this->run(['--json=2', '--json-key=qpdf', '--json-stream-data=inline', '--decode-level=generalized', ...$objects, $path]);
            $streams = $this->streams($data);
        }

        return ['structure' => $structure, 'streams' => $streams, 'warnings' => $repaired ? ['damaged_file'] : []];
    }

    private function checkFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new PdfImportException('missing_file');
        }
        if ((int) filesize($path) > $this->maxBytes) {
            throw new PdfImportException('too_large', ['max' => Number::fileSize($this->maxBytes)]);
        }
    }

    /** @param  array<string, mixed>  $structure */
    private function checkStructure(array $structure): void
    {
        if (($structure['encrypt']['encrypted'] ?? false) === true) {
            throw new PdfImportException('encrypted');
        }
        $pages = is_array($structure['pages'] ?? null) ? count($structure['pages']) : 0;
        if ($pages > $this->maxPages) {
            throw new PdfImportException('too_many_pages', ['max' => $this->maxPages, 'pages' => $pages]);
        }
        if ($pages === 0) {
            throw new PdfImportException('malformed');
        }
        $fields = $structure['acroform']['fields'] ?? [];
        if (! is_array($fields) || $fields === []) {
            throw new PdfImportException('no_fields');
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array{0: array<string, mixed>, 1: bool} decoded JSON and whether qpdf repaired the file
     */
    private function run(array $arguments): array
    {
        $process = new Process([$this->binary, ...$arguments], null, null, null, $this->timeout);
        $output = '';
        $errors = '';

        try {
            $process->start();
            foreach ($process as $type => $chunk) {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                    if (strlen($output) > $this->maxOutputBytes) {
                        $process->stop(0);
                        throw new PdfImportException('too_large', ['max' => Number::fileSize($this->maxBytes)]);
                    }
                } elseif (strlen($errors) < 8192) {
                    $errors .= $chunk;
                }
            }
        } catch (ProcessTimedOutException $e) {
            throw new PdfImportException('timeout', [], $e);
        }

        return [$this->decode($output, (int) $process->getExitCode(), $errors), $process->getExitCode() === 3];
    }

    /** @return array<string, mixed> */
    private function decode(string $output, int $exitCode, string $errors): array
    {
        if ($exitCode !== 0 && $exitCode !== 3) {
            Log::info('qpdf could not read an uploaded PDF', ['exit_code' => $exitCode, 'stderr' => mb_substr($errors, 0, 2000)]);

            throw new PdfImportException(match (true) {
                str_contains($errors, 'invalid password') => 'encrypted',
                $exitCode === 2 => 'malformed',
                default => 'reader_failed',
            });
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new PdfImportException('malformed', [], $e);
        }

        return is_array($decoded) ? $decoded : throw new PdfImportException('malformed');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string> `N G R` => decoded bytes
     */
    private function streams(array $data): array
    {
        $streams = [];
        $objects = is_array($data['qpdf'][1] ?? null) ? $data['qpdf'][1] : [];
        foreach ($objects as $key => $object) {
            $encoded = is_array($object) ? ($object['stream']['data'] ?? null) : null;
            if (is_string($encoded) && str_starts_with((string) $key, 'obj:')) {
                $streams[substr((string) $key, 4)] = (string) base64_decode($encoded, true);
            }
        }

        return $streams;
    }
}
