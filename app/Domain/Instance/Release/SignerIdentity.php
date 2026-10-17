<?php

declare(strict_types=1);

namespace App\Domain\Instance\Release;

/**
 * The keyless signer a release manifest must carry (R58, KTD22, ADR 0060, ADR 0070): an anchored
 * `--certificate-identity-regexp` for a GitHub Actions workflow on a release tag, the GitHub OIDC issuer and the
 * `push` trigger. The production identity is built into the image here and into `install.sh` as literals; tests
 * keep the two (and release.yml) identical. It is never read from the manifest, the environment or a flag.
 */
final readonly class SignerIdentity
{
    public const string PRODUCTION_REPOSITORY = 'beyerenrico/form-schema-app';

    public const string PRODUCTION_WORKFLOW = 'release.yml';

    public const string GITHUB_ACTIONS_ISSUER = 'https://token.actions.githubusercontent.com';

    public const string WORKFLOW_TRIGGER = 'push';

    /** `v<semver>` as ReleaseVersion accepts it: X.Y.Z or X.Y.Z-(alpha|beta|rc)[.id…], no build metadata. */
    public const string TAG_PATTERN = 'v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?';

    public function __construct(
        public string $identityRegexp,
        public string $issuer = self::GITHUB_ACTIONS_ISSUER,
        public string $trigger = self::WORKFLOW_TRIGGER,
    ) {
        if (! str_starts_with($identityRegexp, '^') || ! str_ends_with($identityRegexp, '$')) {
            throw new ReleaseException('A signer identity regexp must be anchored with ^ and $.');
        }
    }

    public static function production(): self
    {
        return self::githubWorkflow(self::PRODUCTION_REPOSITORY, self::PRODUCTION_WORKFLOW);
    }

    /** `https://github.com/<owner/repo>/.github/workflows/<file>@refs/tags/v<semver>`, anchored at both ends. */
    public static function githubWorkflow(string $repository, string $workflow): self
    {
        $escape = static fn (string $text): string => addcslashes($text, '.\\+*?[^]$(){}|');

        return new self('^'.$escape("https://github.com/{$repository}/.github/workflows/{$workflow}@refs/tags/").self::TAG_PATTERN.'$');
    }

    /** @return list<string> the cosign verify-blob flags that pin this identity */
    public function cosignArguments(): array
    {
        return [
            '--certificate-identity-regexp', $this->identityRegexp,
            '--certificate-oidc-issuer', $this->issuer,
            '--certificate-github-workflow-trigger', $this->trigger,
        ];
    }
}
