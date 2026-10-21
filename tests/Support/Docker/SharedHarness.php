<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use Throwable;

/**
 * One release harness and one published harness release per test process (KTD23): the bootstrap journey and
 * J1/J12 share them, so the image is built, pushed and mirrored once, as ReleaseHarness::version()
 * (FIELDSET_RELEASE_VERSION, default TEST_VERSION). Stopped at process shutdown (and its resources are all
 * `fieldset-test-harness-*`); a failed start is remembered, so later files fail fast with the cause.
 */
final class SharedHarness
{
    private static ?ReleaseHarness $harness = null;

    private static ?HarnessRelease $release = null;

    private static ?Throwable $error = null;

    /** @return array{0: ReleaseHarness, 1: HarnessRelease} */
    public static function get(): array
    {
        if (self::$error instanceof Throwable) {
            throw self::$error;
        }
        if (! self::$harness instanceof ReleaseHarness || ! self::$release instanceof HarnessRelease) {
            try {
                self::$harness = ReleaseHarness::start();
                register_shutdown_function(self::stop(...));
                self::$release = self::$harness->publish(ReleaseHarness::version());
            } catch (Throwable $exception) {
                self::$error = $exception;
                self::stop();

                throw $exception;
            }
        }

        return [self::$harness, self::$release];
    }

    public static function stop(): void
    {
        self::$harness?->stop();
        self::$harness = null;
        self::$release = null;
    }
}
