{{-- Per-row result of a member CSV before sending (M3-T11). $report: BulkInvitationReport|null --}}
@php /** @var \App\Domain\Identity\Invitations\BulkInvitationReport|null $report */ @endphp
@if ($report === null)
    <p>{{ __('identity.csv_import.errors.unreadable') }}</p>
@else
    <p style="margin:0 0 .75rem;font-weight:600" role="status">
        {{ __('identity.csv_import.summary', ['valid' => count($report->valid()), 'total' => count($report->rows)]) }}
    </p>
    <div style="position:relative;overflow-x:auto;max-height:24rem;overflow-y:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead>
                <tr>
                    @foreach (['line', 'email', 'result'] as $column)
                        <th scope="col" style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.35);text-align:start">{{ __('identity.csv_import.'.$column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report->rows as $row)
                    <tr>
                        <td style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:top">{{ $row->line }}</td>
                        <td style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:top;overflow-wrap:anywhere">
                            {{ $row->email !== '' ? $row->email : '—' }}
                            @if ($row->isValid())
                                <span style="display:block;font-size:.8125rem;opacity:.7">{{ $row->name }} · {{ implode(', ', array_map(\App\Filament\Admin\Support\OrganizationOptions::roleLabel(...), $row->roles)) }}</span>
                            @endif
                        </td>
                        <td style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:top">
                            @if ($row->isValid())
                                <x-filament::badge color="success">{{ __('identity.csv_import.ok') }}</x-filament::badge>
                                @foreach ($row->notices as $notice)
                                    <span style="display:block;font-size:.8125rem">{{ $notice }}</span>
                                @endforeach
                            @else
                                <ul style="margin:0;padding-inline-start:1rem;color:var(--danger-600, #dc2626)">
                                    @foreach ($row->errors as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
