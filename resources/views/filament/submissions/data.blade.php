{{-- Submitted data with labels of the pinned form version (M3-T09). View model: SubmissionDataPresenter. --}}
@php /** @var list<array{title: string, items: list<array<string, mixed>>}> $pages */ @endphp
@if ($pages === [])
    <p>{{ __('inbox.detail.no_data') }}</p>
@else
    <div style="display:grid;gap:1.5rem">
        @foreach ($pages as $page)
            <section aria-labelledby="submission-page-{{ $loop->index }}">
                <h3 id="submission-page-{{ $loop->index }}" style="margin:0 0 .5rem;font-size:1rem;font-weight:600">{{ $page['title'] }}</h3>
                @include('filament.submissions.partials.items', ['items' => $page['items']])
            </section>
        @endforeach
    </div>
@endif
