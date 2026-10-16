<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Enums;

/**
 * Submission lifecycle (BUILD_PROMPT §6.5). Transitions are the only way to change a status
 * (TransitionSubmission); each one writes a submission_events row and an audit entry.
 */
enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Completed = 'completed';
    case Void = 'void';

    /** @return list<self> */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::InReview, self::Completed],
            self::InReview => [self::ChangesRequested, self::Approved],
            self::ChangesRequested => [self::Submitted],
            self::Approved => [self::Completed],
            self::Completed => [self::Void],
            self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /** Workers may edit data while the submission is a draft or was sent back. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }

    public function label(): string
    {
        return __('submissions.status.'.$this->value);
    }
}
