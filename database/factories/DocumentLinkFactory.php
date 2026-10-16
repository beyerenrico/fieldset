<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Mail\Models\DocumentLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentLink>
 */
class DocumentLinkFactory extends Factory
{
    protected $model = DocumentLink::class;

    public function definition(): array
    {
        return [
            'token_hash' => DocumentLink::hashToken(Str::random(48)),
            'disk' => 'local',
            'path' => 'documents/'.Str::uuid7().'.pdf',
            'filename' => 'document.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'expires_at' => now()->addDays(14),
        ];
    }
}
