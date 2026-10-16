@php /** @var list<array{setting: string, before: string, after: string}> $changes */ @endphp
@if ($changes === [])
    <p>{{ __('documents.versions.no_changes') }}</p>
@else
    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
        <thead>
            <tr style="text-align:left">
                <th style="padding:.25rem .5rem">{{ __('documents.versions.setting') }}</th>
                <th style="padding:.25rem .5rem">{{ __('documents.versions.before') }}</th>
                <th style="padding:.25rem .5rem">{{ __('documents.versions.after') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($changes as $change)
                <tr style="border-top:1px solid rgba(127,127,127,.25);vertical-align:top">
                    <td style="padding:.25rem .5rem"><code>{{ $change['setting'] }}</code></td>
                    <td style="padding:.25rem .5rem;word-break:break-word">{{ \Illuminate\Support\Str::limit($change['before'], 200) }}</td>
                    <td style="padding:.25rem .5rem;word-break:break-word">{{ \Illuminate\Support\Str::limit($change['after'], 200) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
