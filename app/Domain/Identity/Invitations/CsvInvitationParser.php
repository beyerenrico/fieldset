<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

/**
 * Reads a member CSV for bulk invitations (BUILD_PROMPT §8.12, M3-T11): a header row with `email` and
 * `roles` (plus optional `name`, `groups`; German and common spellings accepted), `;` or `,` as column
 * separator (detected from the header), several roles/groups per cell separated by `|` (or by the other
 * of `,`/`;`). UTF-8 with or without BOM; files that are not valid UTF-8 are read as Windows-1252,
 * which is what spreadsheet programs write by default in Germany.
 */
final class CsvInvitationParser
{
    public const int MAX_ROWS = 500;

    public const int MAX_BYTES = 512 * 1024;

    /** @var array<string, list<string>> */
    private const array COLUMNS = [
        'email' => ['email', 'e-mail', 'e_mail', 'mail', 'email address', 'e-mail-adresse'],
        'name' => ['name', 'full name', 'full_name', 'vollständiger name'],
        'roles' => ['roles', 'role', 'rollen', 'rolle'],
        'groups' => ['groups', 'group', 'gruppen', 'gruppe'],
    ];

    private const array REQUIRED = ['email', 'roles'];

    /** @return list<CsvInvitationRow> */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new CsvInvitationException(__('identity.csv_import.errors.too_large', ['max' => intdiv(self::MAX_BYTES, 1024)]));
        }

        $contents = $this->utf8($contents);
        $delimiter = $this->delimiter($contents);
        $records = $this->records($contents, $delimiter);
        $header = $records[1] ?? throw new CsvInvitationException(__('identity.csv_import.errors.empty'));
        unset($records[1]);
        $columns = $this->columns($header);

        $rows = [];
        foreach ($records as $line => $fields) {
            if (implode('', array_map(trim(...), $fields)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new CsvInvitationException(__('identity.csv_import.errors.too_many_rows', ['max' => self::MAX_ROWS]));
            }
            $cell = fn (string $column): string => isset($columns[$column]) ? trim($fields[$columns[$column]] ?? '') : '';
            $rows[] = new CsvInvitationRow($line, $cell('email'), $cell('name'), $this->split($cell('roles'), $delimiter), $this->split($cell('groups'), $delimiter));
        }

        return $rows !== [] ? $rows : throw new CsvInvitationException(__('identity.csv_import.errors.empty'));
    }

    private function utf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return mb_check_encoding($contents, 'UTF-8') ? $contents : (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private function delimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\r\n");
        $firstLine = $firstLine === false ? '' : $firstLine;

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /** @return array<int, list<string>> fields by line number (1-based; line 1 is the header) */
    private function records(string $contents, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new CsvInvitationException(__('identity.csv_import.errors.unreadable'));
        }
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $contents));
        rewind($stream);

        $records = [];
        $line = 0;
        while (($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            $records[$line] = array_map(fn (?string $field): string => (string) $field, $fields);
        }
        fclose($stream);

        return $records;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int> column → field index
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $title) {
            $normalized = mb_strtolower(trim($title));
            foreach (self::COLUMNS as $column => $aliases) {
                if (! isset($columns[$column]) && in_array($normalized, $aliases, true)) {
                    $columns[$column] = $index;
                }
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing !== []) {
            throw new CsvInvitationException(__('identity.csv_import.errors.missing_columns', ['columns' => implode(', ', $missing)]));
        }

        return $columns;
    }

    /** @return list<string> */
    private function split(string $cell, string $delimiter): array
    {
        $separators = preg_quote('|'.($delimiter === ';' ? ',' : ';'), '/');
        $parts = preg_split('/['.$separators.']/', $cell);

        return array_values(array_unique(array_filter(array_map(trim(...), $parts === false ? [] : $parts), fn (string $p): bool => $p !== '')));
    }
}
