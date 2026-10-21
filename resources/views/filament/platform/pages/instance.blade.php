@php
    /** @var \App\Filament\Platform\Pages\Instance $this */
    $o = $this->overview();
    $release = $o['release'];
    $access = $o['access'];
    $mail = $o['mail'];
    $backup = $o['backup'];
    $disk = $o['disk'];
    $updates = $o['updates'];
    $runText = fn (?array $run): string => $run === null
        ? __('instance.backup.none')
        : __('instance.backup.statuses.'.($run['status'] ?? 'unknown')).' · '.$this->when($run['started_at'] ?? null)
            .(is_string($run['reason'] ?? null) ? ' ('.$run['reason'].')' : '');
    $dl = 'display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem;margin:0;font-size:.875rem';
    $dd = 'margin:0;font-variant-numeric:tabular-nums;word-break:break-all';
    $code = 'font-family:ui-monospace,monospace;font-size:.8125rem';
@endphp
{{-- The instance page (R47, plan U20, ADR 0089). Filament components and inline styles only: no theme compiles Tailwind
     classes for our views (LEARNINGS #42), and the panel CSP allows no inline scripts. Read-only: no form, no action. --}}
<x-filament-panels::page>
    <x-filament::section data-instance-section="problems">
        <x-slot name="heading">{{ __('instance.problems.heading') }}</x-slot>
        @if ($o['problems'] === [])
            <p style="margin:0" data-instance-problems="none">{{ __('instance.problems.none') }}</p>
        @else
            <ul style="list-style:none;margin:0;padding:0;display:grid;gap:.75rem">
                @foreach ($o['problems'] as $problem)
                    <li data-instance-problem="{{ $problem->code }}" data-instance-severity="{{ $problem->severity }}" style="display:grid;gap:.25rem">
                        <div style="display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap">
                            <x-filament::badge :color="$problem->severity">{{ __('instance.severities.'.$problem->severity) }}</x-filament::badge>
                            <span>{{ $problem->message }}</span>
                        </div>
                        @if ($problem->command !== null)
                            <div style="font-size:.875rem">{{ __('instance.command') }} <code data-instance-command style="{{ $code }}">{{ $problem->command }}</code></div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(20rem,1fr))">
        <x-filament::section data-instance-section="release">
            <x-slot name="heading">{{ __('instance.release.heading') }}</x-slot>
            <dl style="{{ $dl }}">
                <dt style="opacity:.75">{{ __('instance.release.version') }}</dt><dd style="{{ $dd }}" data-instance-version>{{ $release['version'] }}</dd>
                @if ($release['revision'] !== null)
                    <dt style="opacity:.75">{{ __('instance.release.revision') }}</dt><dd style="{{ $dd }}">{{ $release['revision'] }}</dd>
                @endif
                @foreach ($release['digests'] as $role => $reference)
                    <dt style="opacity:.75">{{ $role }}</dt><dd style="{{ $dd }};{{ $code }}" data-instance-digest="{{ $role }}">{{ $reference }}</dd>
                @endforeach
            </dl>
            @if ($release['digests'] === [])
                <p style="margin:.5rem 0 0;font-size:.875rem">{{ __('instance.release.no_digests') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section data-instance-section="access">
            <x-slot name="heading">{{ __('instance.access.heading') }}</x-slot>
            <dl style="{{ $dl }}">
                <dt style="opacity:.75">{{ __('instance.access.mode') }}</dt>
                <dd style="{{ $dd }}" data-instance-mode="{{ $access['mode'] ?? 'unknown' }}">{{ $access['mode'] === null ? __($access['fieldset'] ? 'instance.unknown' : 'instance.access.not_fieldset') : __('instance.access.modes.'.$access['mode']) }}</dd>
                <dt style="opacity:.75">{{ __('instance.access.url') }}</dt><dd style="{{ $dd }}">{{ $access['url'] }}</dd>
                @if ($access['local_url'] !== null && $access['local_url'] !== $access['url'])
                    <dt style="opacity:.75">{{ __('instance.access.local_url') }}</dt><dd style="{{ $dd }}">{{ $access['local_url'] }}</dd>
                @endif
                @if ($access['tunnel'] !== null)
                    <dt style="opacity:.75">{{ __('instance.access.tunnel') }}</dt><dd style="{{ $dd }}">{{ $access['tunnel'] }}</dd>
                @endif
            </dl>
            @if ($access['fieldset'])
                <p style="margin:.5rem 0 0;font-size:.875rem">{{ __('instance.command') }} <code style="{{ $code }}">fieldset config show</code></p>
            @endif
        </x-filament::section>

        <x-filament::section data-instance-section="mail">
            <x-slot name="heading">{{ __('instance.mail.heading') }}</x-slot>
            <dl style="{{ $dl }}">
                <dt style="opacity:.75">{{ __('instance.mail.mode') }}</dt>
                <dd style="{{ $dd }}" data-instance-mail="{{ $mail['capture'] ? 'capture' : $mail['transport'] }}">{{ $mail['capture'] ? __('instance.mail.capture') : ($mail['host'] !== null ? __('instance.mail.smtp', ['host' => $mail['host']]) : $mail['transport']) }}</dd>
                @if ($mail['catcher_url'] !== null)
                    <dt style="opacity:.75">{{ __('instance.mail.catcher') }}</dt>
                    <dd style="{{ $dd }}"><x-filament::link :href="$mail['catcher_url']" target="_blank" rel="noopener noreferrer" data-instance-catcher>{{ $mail['catcher_url'] }}</x-filament::link></dd>
                @endif
            </dl>
            @if ($mail['capture'])
                <p style="margin:.5rem 0 0;font-size:.875rem">{{ __('instance.mail.capture_hint') }}</p>
                @if ($access['fieldset'])
                    <p style="margin:.25rem 0 0;font-size:.875rem">{{ __('instance.command') }} <code style="{{ $code }}">fieldset config set mail smtp</code></p>
                @endif
            @endif
            @if ($mail['catcher_url'] !== null && $access['mode'] !== 'domain' && $access['fieldset'])
                <p style="margin:.25rem 0 0;font-size:.875rem">{{ __('instance.mail.catcher_login') }} <code style="{{ $code }}">fieldset config show --catcher-login</code></p>
            @endif
        </x-filament::section>

        <x-filament::section data-instance-section="backup">
            <x-slot name="heading">{{ __('instance.backup.heading') }}</x-slot>
            <dl style="{{ $dl }}">
                <dt style="opacity:.75">{{ __('instance.backup.schedule') }}</dt>
                <dd style="{{ $dd }}">{{ match (true) { $backup['schedule'] === null => __('instance.unknown'), $backup['schedule'] === 'off' => __('instance.backup.off'), default => __('instance.backup.daily', ['time' => $backup['schedule'], 'zone' => $backup['time_zone'] ?? 'UTC']) } }}</dd>
                <dt style="opacity:.75">{{ __('instance.backup.next_run') }}</dt><dd style="{{ $dd }}">{{ $this->when($backup['next_run']) }}</dd>
                <dt style="opacity:.75">{{ __('instance.backup.last') }}</dt><dd style="{{ $dd }}" data-instance-backup-last="{{ $backup['last']['status'] ?? 'none' }}">{{ $runText($backup['last']) }}</dd>
                <dt style="opacity:.75">{{ __('instance.backup.last_success') }}</dt><dd style="{{ $dd }}">{{ $backup['last_success'] === null ? __('instance.backup.none') : $this->when($backup['last_success']['started_at'] ?? null) }}</dd>
                @if ($backup['last_success'] !== null)
                    <dt style="opacity:.75">{{ __('instance.backup.size') }}</dt><dd style="{{ $dd }}">{{ $this->bytes($backup['last_success']['bytes'] ?? null) }}</dd>
                    <dt style="opacity:.75">{{ __('instance.backup.encrypted') }}</dt><dd style="{{ $dd }}">{{ __(($backup['last_success']['encrypted'] ?? false) === true ? 'instance.yes' : 'instance.no') }}</dd>
                @endif
            </dl>
            @if ($access['fieldset'])
                <p style="margin:.5rem 0 0;font-size:.875rem">{{ __('instance.backup.commands') }} <code style="{{ $code }}">fieldset backup</code> · <code style="{{ $code }}">fieldset config set backup-schedule HH:MM</code></p>
            @endif
        </x-filament::section>

        <x-filament::section data-instance-section="disk">
            <x-slot name="heading">{{ __('instance.disk.heading') }}</x-slot>
            @if ($disk === null)
                <p style="margin:0">{{ __('instance.disk.unknown') }}</p>
            @else
                <dl style="{{ $dl }}">
                    <dt style="opacity:.75">{{ __('instance.disk.free') }}</dt><dd style="{{ $dd }}" data-instance-disk-percent="{{ $disk['percent'] }}">{{ $this->bytes($disk['free']) }} ({{ __('instance.disk.percent', ['percent' => $disk['percent']]) }})</dd>
                    <dt style="opacity:.75">{{ __('instance.disk.total') }}</dt><dd style="{{ $dd }}">{{ $this->bytes($disk['total']) }}</dd>
                </dl>
            @endif
        </x-filament::section>

        <x-filament::section data-instance-section="updates">
            <x-slot name="heading">{{ __('instance.updates.heading') }}</x-slot>
            @if (! $updates['enabled'])
                <p style="margin:0" data-instance-updates="off">{{ __('instance.updates.off') }}</p>
                @if ($access['fieldset'])
                    <p style="margin:.5rem 0 0;font-size:.875rem">{{ __('instance.command') }} <code style="{{ $code }}">fieldset config set update-check on</code></p>
                @endif
            @else
                <dl style="{{ $dl }}" data-instance-updates="{{ $updates['available'] ? 'available' : 'on' }}">
                    <dt style="opacity:.75">{{ __('instance.updates.checked_at') }}</dt><dd style="{{ $dd }}">{{ $updates['checked_at'] === null ? __('instance.updates.never') : $this->when($updates['checked_at']) }}</dd>
                    <dt style="opacity:.75">{{ __('instance.updates.latest') }}</dt><dd style="{{ $dd }}">{{ $updates['latest'] ?? '—' }}</dd>
                    @if ($updates['notes_url'] !== null)
                        <dt style="opacity:.75">{{ __('instance.updates.notes') }}</dt>
                        <dd style="{{ $dd }}">
                            @if ($updates['notes_link'] !== null)
                                <x-filament::link :href="$updates['notes_link']" target="_blank" rel="noopener noreferrer" data-instance-notes>{{ $updates['notes_link'] }}</x-filament::link>
                            @else
                                <span data-instance-notes-text>{{ $updates['notes_url'] }}</span>
                            @endif
                        </dd>
                    @endif
                    @if ($updates['error'] !== null)
                        <dt style="opacity:.75">{{ __('instance.updates.error') }}</dt><dd style="{{ $dd }}" data-instance-update-error>{{ __('instance.updates.errors.'.(str_starts_with($updates['error'], 'http_') ? 'http' : $updates['error']), ['status' => substr($updates['error'], 5)]) }}</dd>
                    @endif
                </dl>
                <p style="margin:.5rem 0 0;font-size:.875rem">{{ match (true) { $updates['available'] => __('instance.updates.available', ['version' => $updates['latest']]), $updates['latest'] === null => __('instance.updates.unknown'), default => __('instance.updates.current', ['version' => $updates['current']]) } }}
                    @if ($updates['available'])
                        <code style="{{ $code }}">fieldset upgrade</code>
                    @endif
                </p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
