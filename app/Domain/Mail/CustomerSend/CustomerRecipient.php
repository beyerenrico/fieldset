<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The recipient of a "send to customer" mail. Missing values default to the submitted values of the
 * elements with the roles `customer.email` / `customer.name` (top-level scope of the pinned version).
 */
final readonly class CustomerRecipient
{
    public const string EMAIL_ROLE = 'customer.email';

    public const string NAME_ROLE = 'customer.name';

    public const int EMAIL_MAX = 254;

    public const int NAME_MAX = 255;

    public function __construct(public string $email, public ?string $name) {}

    /**
     * Explicit values win; blanks fall back to the role values. Invalid input throws a ValidationException
     * on `email` / `name` (the API renders it as `validation_failed` with JSON Pointers).
     *
     * @throws ValidationException
     */
    public static function resolve(Submission $submission, mixed $email = null, mixed $name = null): self
    {
        $defaults = self::defaults($submission);
        $input = [
            'email' => self::filled($email) ? $email : $defaults['email'],
            'name' => self::filled($name) ? $name : $defaults['name'],
        ];

        /** @var array{email: string, name?: string|null} $valid */
        $valid = Validator::make($input, [
            'email' => ['required', 'string', 'max:'.self::EMAIL_MAX, 'email:filter'],
            'name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
        ])->validate();
        $cleanName = is_string($valid['name'] ?? null) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valid['name'])) : '';

        return new self(trim($valid['email']), $cleanName === '' ? null : $cleanName);
    }

    /** @return array{email: string|null, name: string|null} */
    public static function defaults(Submission $submission): array
    {
        $definition = $submission->formVersion->definition;

        return [
            'email' => self::value($submission, DefinitionTools::keyForRole($definition, self::EMAIL_ROLE)),
            'name' => self::value($submission, DefinitionTools::keyForRole($definition, self::NAME_ROLE)),
        ];
    }

    private static function value(Submission $submission, ?string $key): ?string
    {
        $value = $key === null ? null : ($submission->data->{$key} ?? null);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function filled(mixed $value): bool
    {
        return $value !== null && ! (is_string($value) && trim($value) === '');
    }
}
