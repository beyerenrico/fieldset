<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Documents\Branding\StoreLogo;
use App\Domain\Documents\Branding\UpdateBranding;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Demo\DefaultDemoPassword;
use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Identity\Demo\DemoPasswordSource;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Actions\SaveWebhookEndpoint;
use App\Domain\Workflows\Actions\SaveWorkflow;
use App\Domain\Workflows\Enums\TriggerType;
use Illuminate\Database\Seeder;

/**
 * Demo organization with one user per persona (DemoAccounts), branding (colors, company details, a generated logo),
 * the default document design and a published service report with "send to customer" enabled.
 * Password for every demo user it creates: "password", unless a DemoPasswordSource is bound (2.0: `fsa install --demo`
 * and `fsa demo` bind random passwords whenever the instance is reachable beyond loopback, R29, ADR 0081). An
 * existing account keeps its password. Not run by DatabaseSeeder in production; `app:demo-seed` runs it there.
 */
final class DemoSeeder extends Seeder
{
    public const string PASSWORD = DefaultDemoPassword::PASSWORD;

    public function run(CreateOrganization $create, AddMember $add, SyncMemberGroups $groups, TenantContext $tenant, DemoPasswordSource $passwords): void
    {
        if (Organization::query()->where('slug', DemoAccounts::ORGANIZATION_SLUG)->exists()) {
            return;
        }

        $owner = $this->user(DemoAccounts::OWNER, DemoAccounts::OWNER_NAME, $passwords);
        $org = $create->handle(['name' => DemoAccounts::ORGANIZATION_NAME, 'slug' => DemoAccounts::ORGANIZATION_SLUG, 'locale' => 'de'], $owner);

        [$north, $office] = $tenant->run($org, fn (): array => [
            Group::query()->create(['name' => 'Team Nord', 'description' => 'Montage und Service Nord']),
            Group::query()->create(['name' => 'Büro', 'description' => 'Innendienst']),
        ]);
        $groupIds = ['north' => $north->id, 'office' => $office->id];

        foreach (DemoAccounts::PEOPLE as $email => [$name, $roles, $memberOf]) {
            $user = $this->user($email, $name, $passwords);
            $add->handle($org, $user, $roles);
            $groups->handle($org, $user, array_map(static fn (string $key): int => $groupIds[$key], $memberOf));
        }

        // A published service report that the field crew may fill (demo for the Field App). The template
        // sets settings.sendToCustomer, so workers can email the PDF; the default design renders it.
        $tenant->run($org, function () use ($org, $owner, $office): void {
            $this->brand($org);
            app(DesignResolver::class)->defaultDesign();
            $form = app(CreateFormFromTemplate::class)->handle($owner, 'service_report');
            app(PublishForm::class)->handle($form, 'Erste Version', $owner);
            app(SyncFormAccess::class)->handle($form, [], ['field_worker'], $owner);
            $this->workflows($form, $office, $owner);
        });
    }

    /**
     * Demo automation (§8.3 AC): the office reviews every service report, then the PDF goes to the
     * customer's email field. A webhook endpoint shows the integration setup; it stays disabled.
     */
    private function workflows(Form $form, Group $office, User $owner): void
    {
        app(SaveWorkflow::class)->handle(null, [
            'name' => 'Prüfen und an Kunden senden',
            'description' => 'Das Büro prüft jeden Servicebericht; danach erhält der Kunde das PDF.',
            'trigger_type' => TriggerType::SubmissionSubmitted->value,
            'trigger_config' => ['form_ids' => [$form->id]],
            'steps' => [
                ['type' => 'request_review', 'data' => ['assignee_type' => 'group', 'assignee_group_id' => $office->id, 'sla_hours' => 24]],
                ['type' => 'send_email', 'data' => [
                    'to_fields' => ['customer.email'], 'document' => 'attachment',
                    'subject' => 'Ihr Servicebericht {{ submission.number }}',
                    'body' => "Guten Tag {{ recipient.name }},\n\nanbei erhalten Sie den Servicebericht {{ submission.number }} von {{ org.name }}.\n\nFreundliche Grüße",
                ]],
            ],
        ], $owner);
        app(SaveWebhookEndpoint::class)->handle(null, ['name' => 'ERP (Beispiel)', 'url' => 'https://erp.example.com/hooks/form-schema', 'active' => false], $owner);
    }

    /** Demo branding for PDFs and mails: colors, company details and a small generated logo. */
    private function brand(Organization $org): void
    {
        app(UpdateBranding::class)->handle($org, [
            'primary_color' => '#0f766e',
            'accent_color' => '#d97706',
            'font' => 'inter',
            'logo_path' => app(StoreLogo::class)->handle($org, self::logoPng()),
            'company' => [
                'legal_name' => 'Demo Haustechnik GmbH',
                'address' => "Musterstraße 12\n10115 Berlin",
                'email' => 'service@demo.test',
                'phone' => '+49 30 1234567',
                'website' => 'https://demo.test',
                'vat_id' => 'DE123456789',
                'bank_name' => 'Demo Bank',
                'iban' => 'DE02120300000000202051',
                'bic' => 'BYLADEM1001',
                'legal_footer' => 'Amtsgericht Berlin-Charlottenburg HRB 000000 · Geschäftsführung: Olivia Owner',
            ],
        ]);
    }

    /** A 360×96 PNG: teal badge with the initials "DH" (GD built-in font, no font files needed). */
    public static function logoPng(): string
    {
        $image = imagecreatetruecolor(360, 96);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 96, 95, (int) imagecolorallocate($image, 15, 118, 110));
        imagestring($image, 5, 36, 40, 'DH', (int) imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 112, 40, 'Demo Haustechnik', (int) imagecolorallocate($image, 15, 118, 110));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A demo account; only one the seeder creates asks the password source, an existing one keeps its password. */
    private function user(string $email, string $name, DemoPasswordSource $passwords): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->forceFill(['name' => $name, 'password' => $passwords->passwordFor($email), 'email_verified_at' => now()])->save();
        }

        return $user;
    }
}
