<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Domain\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries a non-tenant model (User) whose loaded relation (groups) is tenant-scoped. Restoring the
 * relation applies the throwing OrganizationScope while the job is unserialized, before handle().
 * handle() itself queries nothing, so success or failure is decided during unserialization.
 */
final class RestoreUserWithGroupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<list<int>> */
    public static array $seen = [];

    public function __construct(public User $user) {}

    public function handle(): void
    {
        self::$seen[] = $this->user->groups->pluck('id')->all();
    }
}
