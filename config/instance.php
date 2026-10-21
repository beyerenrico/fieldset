<?php

declare(strict_types=1);

/*
 * Instance runtime (plan KTD11, ADR 0074). `fsa` mounts the runtime directory read-only into app, queue,
 * scheduler and every one-off and sets FIELDSET_RUNTIME_PATH to it; its runtime.json carries the instance URL, extra
 * hosts, the tunnel and the operation gate. Unset (checkout installs, dev, CI): the app behaves as before, with
 * APP_URL, TRUSTED_HOSTS and TRUSTED_PROXIES only.
 */
return [
    'runtime_path' => env('FIELDSET_RUNTIME_PATH') ?: null,
];
