<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release\Console;

use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validates a release tag and prints, as JSON, its channel and the image tags it publishes: the release
 * workflow and the local harness tag images from this one rule (§6.5, plan U7, ADR 0067).
 */
#[Description('Validate a release version or tag and print its channel and image tags as JSON')]
#[Signature('release:version {version : Release version or tag, e.g. 2.0.0 or v2.0.0-rc.1}')]
final class ReleaseVersionCommand extends Command
{
    public function handle(): int
    {
        $input = $this->argument('version');

        try {
            $version = ReleaseVersion::fromInput(is_string($input) ? $input : '');
        } catch (ReleaseException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        }

        $this->output->writeln(json_encode([
            'version' => (string) $version,
            'tag' => $version->tag(),
            'channel' => $version->channel()->value,
            'prerelease' => $version->isPrerelease(),
            'image_tags' => $version->imageTags(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
