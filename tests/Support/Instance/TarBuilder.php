<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

/**
 * Crafts tar archives byte by byte for the restore tests (plan U17): the members GNU tar writes (ustar headers, GNU
 * long names, pax headers) and the hostile ones it would refuse to create from a real file system without root
 * (devices, links to anywhere, `../` and absolute names), so SafeExtractor sees exactly what an attacker could send.
 */
final class TarBuilder
{
    private string $bytes = '';

    public function file(string $name, string $content, int $mode = 0644): self
    {
        return $this->member($name, '0', $content, $mode);
    }

    public function directory(string $name): self
    {
        return $this->member($name, '5', '', 0755);
    }

    public function symlink(string $name, string $target): self
    {
        return $this->member($name, '2', '', 0777, $target);
    }

    public function hardLink(string $name, string $target): self
    {
        return $this->member($name, '1', '', 0644, $target);
    }

    public function special(string $name, string $type): self
    {
        return $this->member($name, $type, '', 0644);
    }

    /** A GNU long name (`././@LongLink`, type L) followed by the member it names. */
    public function longFile(string $name, string $content): self
    {
        $this->member('././@LongLink', 'L', $name."\0", 0644);

        return $this->member(substr($name, 0, 99), '0', $content, 0644);
    }

    /** A pax extended header (type x) with the given records, then the member it describes. */
    public function paxFile(array $records, string $headerName, string $content): self
    {
        $data = '';
        foreach ($records as $key => $value) {
            $record = " {$key}={$value}\n";
            $length = strlen($record) + 1;
            while (strlen((string) $length) + strlen($record) !== $length) {
                $length++;
            }
            $data .= $length.$record;
        }
        $this->member('PaxHeaders/x', 'x', $data, 0644);

        return $this->member($headerName, '0', $content, 0644);
    }

    /** The archive with its two end-of-archive blocks. */
    public function bytes(): string
    {
        return $this->bytes.str_repeat("\0", 1024);
    }

    public function writeTo(string $path, bool $gzip = false): string
    {
        file_put_contents($path, $gzip ? (string) gzencode($this->bytes()) : $this->bytes());

        return $path;
    }

    private function member(string $name, string $type, string $content, int $mode, string $link = ''): self
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            .sprintf('%07o', $mode)."\0".sprintf('%07o', 33)."\0".sprintf('%07o', 33)."\0"
            .sprintf('%011o', strlen($content))."\0".sprintf('%011o', 1_760_000_000)."\0"
            .'        '.$type.str_pad(substr($link, 0, 100), 100, "\0")
            ."ustar\0".'00'.str_pad('www-data', 32, "\0").str_pad('www-data', 32, "\0")
            .str_repeat("\0", 8).str_repeat("\0", 8).str_repeat("\0", 155);
        $header = str_pad($header, 512, "\0");
        $sum = array_sum(array_map(ord(...), str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
        $this->bytes .= $header.$content.str_repeat("\0", (512 - strlen($content) % 512) % 512);

        return $this;
    }
}
