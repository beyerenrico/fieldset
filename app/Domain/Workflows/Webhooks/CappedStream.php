<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Webhooks;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Response sink of the webhook client (M4 review S7): keeps at most `$limit` bytes of the body and
 * discards the rest while reporting every chunk as written (so curl does not abort a delivered request).
 * The request timeout bounds the transfer time.
 */
final readonly class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private int $limit) {}

    public function write($string): int
    {
        $room = $this->limit - (int) $this->stream->getSize();
        if ($room > 0) {
            $this->stream->write(substr($string, 0, $room));
        }

        return strlen($string);
    }
}
