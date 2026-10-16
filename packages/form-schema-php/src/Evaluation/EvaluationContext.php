<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use stdClass;

/**
 * Host-supplied built-ins for logic evaluation (spec §4.3), in the organization's time zone.
 */
final readonly class EvaluationContext
{
    public function __construct(
        public string $today,
        public string $now,
        public ?string $userName = null,
        public ?string $userEmail = null,
        public ?string $orgName = null,
        public int|string|null $formVersion = null,
    ) {}

    /**
     * Accepts the conformance shape: `today`, `now`, `user {name, email}`, `org {name}`, `formVersion`.
     * Missing `today`/`now` default to the current UTC date and time.
     *
     * @param  array<array-key, mixed>  $context
     */
    public static function fromArray(array $context): self
    {
        $user = self::map($context['user'] ?? null);
        $org = self::map($context['org'] ?? null);
        $version = $context['formVersion'] ?? null;

        return new self(
            self::string($context['today'] ?? null) ?? gmdate('Y-m-d'),
            self::string($context['now'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'),
            self::string($user['name'] ?? null),
            self::string($user['email'] ?? null),
            self::string($org['name'] ?? null),
            self::version($version),
        );
    }

    /**
     * The built-in variables as they appear in a logic scope.
     */
    public function builtins(): stdClass
    {
        $user = new stdClass;
        $user->name = $this->userName;
        $user->email = $this->userEmail;

        $org = new stdClass;
        $org->name = $this->orgName;

        $form = new stdClass;
        $form->version = $this->formVersion;

        $scope = new stdClass;
        $scope->{'$today'} = $this->today;
        $scope->{'$now'} = $this->now;
        $scope->{'$user'} = $user;
        $scope->{'$org'} = $org;
        $scope->{'$form'} = $form;

        return $scope;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    private static function version(mixed $value): int|string|null
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (int) $value,
            default => null,
        };
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
