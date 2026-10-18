<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\UrlChangeImpact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The app side of the confirmation of `fsa config set domain|url|port|mode` (R21, KTD25, ADR 0077): a socket-less
 * one-off that lists the organizations whose SSO URLs follow the instance URL and counts the links already sent with
 * the old address. Read-only.
 */
#[Description('List what follows the instance URL: organizations with SSO and links already sent (for fsa config set)')]
#[Signature('instance:url-impact
        {--json : Print one JSON document on stdout}')]
final class UrlImpactCommand extends Command
{
    public function handle(UrlChangeImpact $impact): int
    {
        $data = $impact->assess();
        if ($this->option('json') === true) {
            return JsonResult::ok('instance:url-impact', $data)->emit($this);
        }
        $organizations = $data['sso_organizations'];
        $this->components->info(count($organizations).' organization(s) with SSO follow the instance URL.');
        foreach ($organizations as $row) {
            $this->line(sprintf('  %s (%s): %s', $row['organization']['name'], strtoupper($row['provider']['type']), implode(', ', $row['follows_url'])));
        }
        foreach ($data['links'] as $kind => $count) {
            $this->line(sprintf('  %s: %d', str_replace('_', ' ', $kind), $count));
        }

        return self::SUCCESS;
    }
}
