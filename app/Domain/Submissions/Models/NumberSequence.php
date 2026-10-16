<?php

declare(strict_types=1);

namespace App\Domain\Submissions\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\NumberSequenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Next document number per form and period (§6.4, §9.2). Rows are only touched by NumberAllocator.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $form_id
 * @property string $period
 * @property int $next_value
 */
#[UseFactory(NumberSequenceFactory::class)]
#[Fillable(['form_id', 'period', 'next_value'])]
class NumberSequence extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<NumberSequenceFactory> */
    use HasFactory;
}
