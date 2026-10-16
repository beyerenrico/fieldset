<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Support;

use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use FormSchema\Evaluation\EvaluationContext;
use FormSchema\Evaluation\EvaluationResult;
use FormSchema\Evaluation\Evaluator;
use Illuminate\Support\Carbon;

/**
 * Server-side, authoritative evaluation of submission data (spec §5.5, BUILD_PROMPT §7.1): recomputes
 * every calculated value and ignores client-supplied ones. Built-ins use the organization's time zone.
 */
final readonly class SubmissionEvaluator
{
    public function __construct(private Evaluator $evaluator) {}

    public function evaluate(FormVersion $version, mixed $data, User $user, Organization $organization, ?Carbon $at = null): EvaluationResult
    {
        return $this->evaluator->evaluate($version->definition, $data, $this->context($version, $user, $organization, $at));
    }

    public function context(FormVersion $version, User $user, Organization $organization, ?Carbon $at = null): EvaluationContext
    {
        $now = ($at ?? Carbon::now())->copy()->setTimezone($organization->timezone);

        return new EvaluationContext(
            today: $now->format('Y-m-d'),
            now: $now->format('Y-m-d\TH:i:sP'),
            userName: $user->name,
            userEmail: $user->email,
            orgName: $organization->name,
            formVersion: $version->version,
        );
    }
}
