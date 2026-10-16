{{-- Status history of a submission (submission_events, append-only). --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Domain\Submissions\Models\SubmissionEvent> $events */
    $badge = fn (string $status): string => ($s = \App\Domain\Submissions\Enums\SubmissionStatus::tryFrom($status)) !== null
        ? \App\Filament\Admin\Resources\Submissions\SubmissionResource::statusColor($s) : 'gray';
@endphp
@if ($events->isEmpty())
    <p>{{ __('inbox.detail.no_events') }}</p>
@else
    <ol style="display:grid;gap:.75rem;margin:0;padding:0 0 0 1rem;list-style:none;border-inline-start:2px solid rgba(127,127,127,.3)">
        @foreach ($events as $event)
            <li>
                <time datetime="{{ $event->created_at->toIso8601String() }}" style="font-size:.8125rem;opacity:.7">
                    {{ $event->created_at->copy()->setTimezone($timezone)->locale(app()->getLocale())->isoFormat('L LT') }}
                </time>
                <div style="display:flex;flex-wrap:wrap;gap:.375rem;align-items:center">
                    @if ($event->from_status !== null)
                        <x-filament::badge :color="$badge($event->from_status)">{{ __('submissions.status.'.$event->from_status) }}</x-filament::badge>
                        <span aria-hidden="true">→</span>
                    @endif
                    <x-filament::badge :color="$badge($event->to_status)">{{ __('submissions.status.'.$event->to_status) }}</x-filament::badge>
                    <span>· {{ $event->actor?->name ?? __('inbox.detail.system') }}</span>
                </div>
                @if ($event->comment !== null && $event->comment !== '')
                    <p style="margin:.25rem 0 0;white-space:pre-line">{{ $event->comment }}</p>
                @endif
            </li>
        @endforeach
    </ol>
@endif
