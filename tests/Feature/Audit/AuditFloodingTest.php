<?php

declare(strict_types=1);

use App\Domain\Audit\FailedLoginAudit;
use App\Domain\Audit\LockoutAudit;
use App\Domain\Audit\Models\Activity;
use Tests\Support\AuditTrail;

/*
 * Unauthenticated clients cannot flood the append-only audit log (§8.11, ADR 0038): one `auth.lockout` per
 * throttle window, login path and IP whatever addresses are tried; at most FailedLoginAudit::LIMIT
 * `auth.failed` per IP and window, then a single `auth.failed_suppressed`.
 */

beforeEach(fn () => clearAuditLog());

it('writes one lockout entry per window, path and IP, however many addresses are tried', function (): void {
    $events = AuditTrail::of(function (): void {
        foreach (range(1, 25) as $i) {
            LockoutAudit::record("victim{$i}@acme.example", 'fortify', '203.0.113.7', 60);
        }
    });
    expect($events)->toBe(['auth.lockout'])
        ->and(Activity::query()->sole()->properties->only(['email', 'via'])->all())->toBe(['email' => 'victim1@acme.example', 'via' => 'fortify']);

    // Another IP or another login path is another window.
    LockoutAudit::record('victim1@acme.example', 'fortify', '203.0.113.8', 60);
    LockoutAudit::record('victim1@acme.example', 'admin', '203.0.113.7', 60);
    expect(Activity::query()->where('event', 'auth.lockout')->count())->toBe(3);

    // After the window the next lockout is recorded again.
    $this->travel(61)->seconds();
    LockoutAudit::record('victim2@acme.example', 'fortify', '203.0.113.7', 60);
    expect(Activity::query()->where('event', 'auth.lockout')->count())->toBe(4);
});

it('caps failed sign-ins per IP and window, then writes one suppression entry', function (): void {
    $events = AuditTrail::of(function (): void {
        foreach (range(1, FailedLoginAudit::LIMIT + 5) as $i) {
            $this->postJson('/auth/login', ['email' => "user{$i}@acme.example", 'password' => 'wrong'])->assertUnprocessable();
        }
    });

    expect($events)->toBe([...array_fill(0, FailedLoginAudit::LIMIT, 'auth.failed'), 'auth.failed_suppressed'])
        ->and(Activity::query()->where('event', 'auth.failed_suppressed')->sole()->properties->only(['limit', 'window_minutes'])->all())
        ->toBe(['limit' => FailedLoginAudit::LIMIT, 'window_minutes' => FailedLoginAudit::WINDOW_MINUTES]);

    // Another IP has its own budget; the first IP gets a new one after the window.
    $other = AuditTrail::of(fn () => $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson('/auth/login', ['email' => 'user1@acme.example', 'password' => 'wrong'])->assertUnprocessable());
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->travel(FailedLoginAudit::WINDOW_MINUTES * 60 + 1)->seconds();
    $later = AuditTrail::of(fn () => $this->postJson('/auth/login', ['email' => 'late@acme.example', 'password' => 'wrong'])->assertUnprocessable());

    expect($other)->toBe(['auth.failed'])->and($later)->toBe(['auth.failed']);
});
