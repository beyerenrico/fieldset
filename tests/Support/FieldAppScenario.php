<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Str;

/** A published service report that field workers may fill, plus helpers for valid data. */
final class FieldAppScenario
{
    public Organization $org;

    public User $owner;

    public User $worker;

    public Form $form;

    public FormVersion $version;

    public static function make(string $template = 'service_report'): self
    {
        $s = new self;
        $s->org = makeOrganization();
        $s->owner = ownerOf($s->org);
        $s->worker = memberOf($s->org, SystemRole::FieldWorker);
        app(TenantContext::class)->run($s->org, function () use ($s, $template): void {
            $s->form = app(CreateFormFromTemplate::class)->handle($s->owner, $template);
            $s->version = app(PublishForm::class)->handle($s->form, 'v1', $s->owner);
            app(SyncFormAccess::class)->handle($s->form, [], ['field_worker'], $s->owner);
            $s->form->refresh();
        });

        return $s;
    }

    /** @return array<string, mixed> valid service-report data referencing the given signature attachment */
    public static function serviceReportData(string $signatureUuid, int $lines = 2): array
    {
        $items = [];
        for ($i = 1; $i <= $lines; $i++) {
            $items[] = ['description' => "Line {$i}", 'qty' => '1.5', 'unit' => 'h', 'unit_price' => '65.00'];
        }

        return [
            'customer_name' => 'Beispiel GmbH', 'service_type' => 'repair', 'defect' => 'Door switch misaligned',
            'work_done' => 'Replaced switch', 'items' => $items, 'work_accepted' => true,
            'customer_signature' => ['attachment' => $signatureUuid, 'signerName' => 'Beispiel GmbH', 'signedAt' => '2026-10-16T09:41:00+02:00'],
        ];
    }

    public static function uuid(): string
    {
        return (string) Str::uuid7();
    }

    /** A tiny valid PNG (1×1). */
    public static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
