<?php

declare(strict_types=1);

namespace App\Domain\Mail\Templates;

use InvalidArgumentException;

/**
 * A subject and a Markdown body with {{ tokens }} (§8.8). Workflow steps (M4) store their own
 * templates and hand them to BrandedMail; nothing in a template is ever executed.
 */
final readonly class MailTemplate
{
    public const int SUBJECT_MAX = 255;

    public const int BODY_MAX = 20000;

    public function __construct(public string $subject, public string $body)
    {
        if (mb_strlen($subject) > self::SUBJECT_MAX || mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('Mail template is too long.');
        }
    }

    /** @param  array{subject?: mixed, body?: mixed}  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['subject'] ?? null) ? $data['subject'] : '',
            is_string($data['body'] ?? null) ? $data['body'] : '',
        );
    }

    /** @return array{subject: string, body: string} */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'body' => $this->body];
    }
}
