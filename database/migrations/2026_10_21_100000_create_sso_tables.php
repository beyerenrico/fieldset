<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SSO per organization (BUILD_PROMPT §8.7, ADR 0036):
 * - identity_providers: one OIDC or SAML provider per organization (v1); config and client secret are
 *   encrypted casts (text columns), the uuid is the public id in callback URLs;
 * - sso_domains: claimed email domains; many organizations may claim a domain, only one may verify it
 *   (partial unique index);
 * - identity_provider_mappings: a claim value maps to a role XOR a group;
 * - external_identities: (provider, subject) → user, keyed by provider rather than issuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 8);
            $table->string('name', 120);
            $table->string('status', 16)->default('draft');
            $table->text('config');                                   // encrypted:array cast
            $table->text('client_secret')->nullable();                // encrypted cast, write-only
            $table->boolean('jit_enabled')->default(false);
            $table->foreignId('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('default_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->boolean('enforce_sso')->default(false);
            $table->foreignId('break_glass_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('force_reauth')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_type_check CHECK (type IN ('oidc','saml'))");
        DB::statement("ALTER TABLE identity_providers ADD CONSTRAINT identity_providers_status_check CHECK (status IN ('draft','active','error','disabled'))");

        Schema::create('sso_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);                            // lower-case, punycode, exact match
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_result', 16)->nullable();      // DomainVerification
            $table->unsignedSmallInteger('failed_checks')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'domain']);
        });
        DB::statement('CREATE UNIQUE INDEX sso_domains_verified_domain_unique ON sso_domains (domain) WHERE verified_at IS NOT NULL');
        DB::statement('ALTER TABLE sso_domains ADD CONSTRAINT sso_domains_domain_lower_check CHECK (domain = lower(domain))');
        DB::statement("ALTER TABLE sso_domains ADD CONSTRAINT sso_domains_check_result_check CHECK (last_check_result IS NULL OR last_check_result IN ('verified','missing','mismatch','error'))");

        Schema::create('identity_provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_provider_id')->constrained()->cascadeOnDelete();
            $table->string('claim', 120);
            $table->string('value', 255);
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['identity_provider_id', 'claim', 'value', 'role_id', 'group_id'])->nullsNotDistinct();
        });
        DB::statement('ALTER TABLE identity_provider_mappings ADD CONSTRAINT identity_provider_mappings_target_check CHECK ((role_id IS NULL) <> (group_id IS NULL))');

        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 255);
            $table->string('issuer', 2048);
            $table->string('last_email', 254)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['identity_provider_id', 'subject']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('identity_provider_mappings');
        Schema::dropIfExists('sso_domains');
        Schema::dropIfExists('identity_providers');
    }
};
