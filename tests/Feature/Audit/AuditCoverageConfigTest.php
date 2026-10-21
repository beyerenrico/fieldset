<?php

declare(strict_types=1);

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Designs\Actions\RetireDesignVersion;
use App\Domain\Documents\Designs\Actions\RollbackDesign;
use App\Domain\Documents\Designs\Actions\SaveDesignDraft;
use App\Domain\Documents\Designs\Actions\SetDefaultDesign;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\DuplicateForm;
use App\Domain\Forms\Actions\ExportDefinition;
use App\Domain\Forms\Actions\ImportDefinition;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RetireVersion;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Actions\SetFormStatus;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Actions\DeleteRole;
use App\Domain\Identity\Actions\SaveRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Role;
use App\Domain\Workflows\Actions\SaveWebhookEndpoint;
use App\Domain\Workflows\Actions\SaveWorkflow;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\Workflow;
use Fieldset\Json\Json;
use Spatie\Permission\Models\Permission;
use Tests\Support\AuditTrail;

/*
 * §8.11 AC, part 2 (configuration entities): create, update and delete of forms, versions, designs, workflows,
 * webhooks, roles and groups each write exactly the listed entry; secrets never reach the audit log.
 */

beforeEach(function (): void {
    AuditTrail::reset();
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
});

afterEach(function (): void {
    expect(AuditTrail::seen())->not->toContain('Hook-Secret-42')->not->toContain('Rotated-Secret-43');
});

it('writes one entry per form and form version action', function (): void {
    $form = null;
    expect(AuditTrail::of(function () use (&$form): void {
        [$form] = app(CreateForm::class)->handle($this->owner, 'Service report');
    }))->toBe(['form.created']);
    /** @var Form $form */
    $definition = Json::decode(Json::encode($form->draft_definition));
    $definition->title = 'Service report (new)';

    expect(AuditTrail::of(fn () => app(SaveDraft::class)->handle($form, $definition, $this->owner)))->toBe(['form.updated'])
        ->and(AuditTrail::of(fn () => app(SyncFormAccess::class)->handle($form, [], ['field_worker'], $this->owner)))->toBe(['form.access_changed']);

    $v1 = null;
    expect(AuditTrail::of(function () use ($form, &$v1): void {
        $v1 = app(PublishForm::class)->handle($form->refresh(), 'v1', $this->owner);
    }))->toBe(['form.published']);
    app(PublishForm::class)->handle($form->refresh(), 'v2', $this->owner);

    expect(AuditTrail::of(fn () => app(RollbackForm::class)->handle($form->refresh(), $v1, $this->owner)))->toBe(['form.rolled_back'])
        ->and(AuditTrail::of(fn () => app(RetireVersion::class)->handle($v1->refresh(), $this->owner)))->toBe(['form.version_retired'])
        ->and(AuditTrail::of(fn () => app(DuplicateForm::class)->handle($form->refresh(), $this->owner)))->toBe(['form.duplicated'])
        ->and(AuditTrail::of(fn () => app(ExportDefinition::class)->handle($form->refresh(), null, $this->owner)))->toBe(['form.exported'])
        ->and(AuditTrail::of(fn () => app(ImportDefinition::class)->handle($this->owner, app(ExportDefinition::class)->handle($form, null, $this->owner), 'Imported')))
        ->toBe(['form.exported', 'form.imported'])
        ->and(AuditTrail::of(fn () => app(SetFormStatus::class)->handle($form->refresh(), FormStatus::Archived, $this->owner)))->toBe(['form.status_changed']);
});

it('writes one entry per design action', function (): void {
    app(CreateDesign::class)->handle('Standard', $this->owner); // the organization's first design is the default
    $design = null;
    expect(AuditTrail::of(function () use (&$design): void {
        $design = app(CreateDesign::class)->handle('Customer', $this->owner);
    }))->toBe(['design.created']);
    /** @var Design $design */
    $v1 = DesignVersion::query()->where('design_id', $design->id)->sole();

    expect(AuditTrail::of(fn () => app(SaveDesignDraft::class)->handle($design, 'Customer', [...$design->settings, 'paper' => 'letter'], $this->owner)))
        ->toBe(['design.updated'])
        ->and(AuditTrail::of(fn () => app(PublishDesign::class)->handle($design->refresh(), 'v2', $this->owner)))->toBe(['design.published'])
        ->and(AuditTrail::of(fn () => app(SetDefaultDesign::class)->handle($design->refresh(), $this->owner)))->toBe(['design.default_set'])
        ->and(AuditTrail::of(fn () => app(RollbackDesign::class)->handle($design->refresh(), $v1, $this->owner)))->toBe(['design.rolled_back'])
        ->and(AuditTrail::of(fn () => app(RetireDesignVersion::class)->handle($v1->refresh(), $this->owner)))->toBe(['design.version_retired']);

    // Publishing from the editor with pending edits: the publish entry is the one entry (EditDesign).
    expect(AuditTrail::of(function () use ($design): void {
        $saved = app(SaveDesignDraft::class)->handle($design->refresh(), 'Customer', [...$design->settings, 'paper' => 'a4'], $this->owner, audit: false);
        app(PublishDesign::class)->handle($saved, 'v4', $this->owner);
    }))->toBe(['design.published']);
});

it('writes one entry per workflow and webhook endpoint change, secret rotation included', function (): void {
    $data = ['name' => 'Notify office', 'trigger_type' => 'submission.submitted', 'steps' => [
        ['type' => 'send_email', 'data' => ['to_addresses' => ['office@acme.example'], 'to_fields' => [], 'to_submitter' => false, 'to_group_ids' => [],
            'subject' => 'New report', 'body' => 'Hello', 'document' => 'none']],
    ]];
    $workflow = null;
    expect(AuditTrail::of(function () use ($data, &$workflow): void {
        $workflow = app(SaveWorkflow::class)->handle(null, $data, $this->owner);
    }))->toBe(['created']);
    /** @var Workflow $workflow */
    expect(AuditTrail::of(fn () => app(SaveWorkflow::class)->handle($workflow, [...$data, 'name' => 'Notify the office', 'active' => false], $this->owner)))->toBe(['updated'])
        ->and(AuditTrail::of(fn () => $workflow->delete()))->toBe(['deleted']);

    $endpoint = null;
    expect(AuditTrail::of(function () use (&$endpoint): void {
        $endpoint = app(SaveWebhookEndpoint::class)->handle(null, ['name' => 'CRM', 'url' => 'https://crm.example.com/in', 'secret' => 'Hook-Secret-42'], $this->owner);
    }))->toBe(['created']);
    /** @var WebhookEndpoint $endpoint */
    expect(AuditTrail::of(fn () => app(SaveWebhookEndpoint::class)->handle($endpoint, ['name' => 'CRM', 'url' => 'https://crm.example.com/in', 'secret' => 'Rotated-Secret-43'], $this->owner)))
        ->toBe(['updated'])
        ->and(AuditTrail::of(fn () => app(SaveWebhookEndpoint::class)->handle($endpoint, ['name' => 'CRM', 'url' => 'https://crm.example.com/in'], $this->owner)))->toBe([])
        ->and(AuditTrail::of(fn () => $endpoint->delete()))->toBe(['deleted']);
});

it('writes one entry per role and group change', function (): void {
    $permission = Permission::findByName('reports.view');
    $role = null;
    expect(AuditTrail::of(function () use ($permission, &$role): void {
        $role = app(SaveRole::class)->handle($this->owner, $this->org, null, 'Accountant', [$permission->id]);
    }))->toBe(['role.created']);
    /** @var Role $role */
    expect(AuditTrail::of(fn () => app(SaveRole::class)->handle($this->owner, $this->org, $role, 'Accounting', [$permission->id])))->toBe(['role.updated'])
        ->and(AuditTrail::of(fn () => app(DeleteRole::class)->handle($this->owner, $role)))->toBe(['role.deleted']);

    $group = null;
    expect(AuditTrail::of(function () use (&$group): void {
        $group = Group::query()->create(['name' => 'Crew North']);
    }))->toBe(['created']);
    /** @var Group $group */
    expect(AuditTrail::of(fn () => $group->update(['name' => 'Crew South'])))->toBe(['updated'])
        ->and(AuditTrail::of(fn () => $group->delete()))->toBe(['deleted']);
});
