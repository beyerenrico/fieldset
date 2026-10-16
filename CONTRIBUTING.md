# Contributing

Thanks for helping! A few rules keep the project healthy:

1. **Tests first.** Every change comes with Pest (PHP) or Vitest (JS) tests. Run `php artisan test --compact`.
2. **Quality gates.** `vendor/bin/pint --test`, `composer analyse`, ESLint/TypeScript checks must pass.
3. **Spec changes** update `spec/form-definition/<version>/README.md`, its `CHANGELOG.md`, the
   meta-schema and `spec/conformance/` in the same pull request.
4. **Conventional commits** (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`).
5. **Developer Certificate of Origin.** Sign off your commits (`git commit -s`). The application is
   AGPL-3.0; the spec and SDK packages are MIT.
6. **No secrets** in the repository. Keep `.env.example` current.

Architecture decisions live in `docs/adr/`. Propose new ones as ADRs in your pull request.
