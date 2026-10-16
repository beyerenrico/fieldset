<?php

declare(strict_types=1);

namespace App\Domain\Mail\Templates;

use App\Domain\Identity\Models\Organization;
use App\Domain\Submissions\Models\Submission;

/**
 * Builds the token values offered to mail templates. Document tokens come from the DeliveryPlan.
 */
final class MailTokens
{
    /** Every token a template author may use (help texts, linting in M4). */
    public const array AVAILABLE = [
        'org.name', 'recipient.name', 'submission.number', 'submission.date', 'form.name',
        'document.link', 'document.name', 'document.expires',
    ];

    /** @return array<string, string> */
    public static function for(Organization $organization, ?Submission $submission = null, ?string $recipientName = null): array
    {
        $tokens = ['org.name' => $organization->name, 'recipient.name' => (string) $recipientName];

        if ($submission instanceof Submission) {
            $submission->loadMissing('form');
            $tokens['submission.number'] = (string) $submission->number;
            $tokens['submission.date'] = $submission->submitted_at?->copy()->timezone($organization->timezone)
                ->settings(['locale' => $organization->locale])->isoFormat('L') ?? '';
            $tokens['form.name'] = $submission->form->name;
        }

        return $tokens;
    }
}
