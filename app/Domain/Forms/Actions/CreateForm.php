<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\BlankDefinition;
use App\Domain\Forms\Support\DefinitionChecker;
use App\Domain\Forms\Support\DefinitionReport;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Fieldset\Json\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Creates a form from scratch or from a definition (template, import). The definition must pass the
 * meta-schema; lint findings are returned and shown in the builder.
 */
final readonly class CreateForm
{
    private const int KEY_ATTEMPTS = 5;

    public function __construct(
        private DefinitionChecker $checker,
        private TenantContext $tenant,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{0: Form, 1: DefinitionReport}
     */
    public function handle(User $actor, string $name, ?stdClass $definition = null, ?string $category = null, string $event = 'form.created'): array
    {
        $organization = $this->tenant->organization();
        $locale = $organization?->locale === 'en' ? 'en-GB' : 'de-DE';

        $definition = $definition instanceof stdClass
            ? Json::decode(Json::encode($definition))
            : BlankDefinition::make(BlankDefinition::keyFromName($name), $name, $locale);
        $base = is_string($definition->key ?? null) ? $definition->key : BlankDefinition::keyFromName($name);
        $definition->title = $name;

        $report = $this->checker->check($definition);
        if (! $report->isStructurallyValid()) {
            throw new InvalidDefinitionException($report);
        }

        $form = $this->insert($actor, $base, $name, $category, $definition);
        $this->audit->record($event, $form, ['key' => $form->key, 'name' => $form->name], $actor);

        return [$form, $report];
    }

    /** Inserts with the first free key; a key taken concurrently between lookup and insert is retried. */
    private function insert(User $actor, string $base, string $name, ?string $category, stdClass $definition): Form
    {
        for ($attempt = 1; ; $attempt++) {
            $definition->key = $this->uniqueKey($base);
            try {
                return DB::transaction(fn (): Form => Form::query()->create([
                    'key' => $definition->key,
                    'name' => $name,
                    'category' => $category,
                    'status' => FormStatus::Draft,
                    'draft_definition' => $definition,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::KEY_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function uniqueKey(string $base): string
    {
        $key = $base;
        $i = 2;
        while (Form::query()->where('key', $key)->exists()) {
            $suffix = '_'.$i++;
            $key = mb_substr($base, 0, 64 - strlen($suffix)).$suffix;
        }

        return $key;
    }
}
