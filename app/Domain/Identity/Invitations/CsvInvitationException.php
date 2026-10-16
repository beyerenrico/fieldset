<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use RuntimeException;

/** A CSV file that cannot be used at all (size, encoding, header, row limit); the message is translated. */
final class CsvInvitationException extends RuntimeException {}
