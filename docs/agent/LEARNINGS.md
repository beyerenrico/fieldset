# LEARNINGS (gotchas; check before retrying a fix)

1. Host machine runs brew Postgres (5432), Redis (6379) and MySQL. Use Docker services on
   non-default host ports (Postgres 54329, Redis 63799, Mailpit 18025/11025, Gotenberg 13000).
2. npm `typescript@latest` is 7.0.2 (native compiler). Check typescript-eslint peer range before pinning.
3. Host tools: PHP 8.4.26 (pdo_pgsql, intl, gd, bcmath, gmp), Composer 2.8, Node 24, Docker 29,
   qpdf NOT installed on host (it lives in the queue image; tests needing it run in Docker or skip
   with a clear marker only when the binary is absent — never silently).
4. Interactive CLIs (pest --init, filament:install without flags) hang: always pass --no-interaction / write files by hand.
5. Tool output on this host is compact JSON (laravel/pao): e.g. {"tool":"pest","result":"passed",...}.
