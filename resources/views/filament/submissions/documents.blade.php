{{--
    Documents of a submission in the admin inbox (M3-T04, M3-T08). View model: SubmissionDocuments.
    Renditions are immutable; "Re-render" and "Send to customer" are header actions of the page.
--}}
@php
    /** @var array<string, mixed>|null $failure */
    /** @var list<array<string, mixed>> $renditions */
    /** @var list<array<string, mixed>> $sends */
    $th = 'padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.35);text-align:start;font-weight:600;white-space:nowrap';
    $td = 'padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:top';
@endphp
<div data-section="submission-documents" style="display:grid;gap:1.25rem">
    @if ($failure !== null)
        <div data-section="rendition-failure" role="alert" style="padding:.75rem 1rem;border:1px solid var(--danger-600, #dc2626);border-inline-start-width:4px;border-radius:.5rem">
            <p style="margin:0;font-weight:600">{{ __('inbox.documents.failure.title') }}</p>
            <p style="margin:.25rem 0 0">{{ $failure['message'] }}</p>
            <p style="margin:.25rem 0 0;font-size:.8125rem">{{ $failure['details'] }} {{ __('inbox.documents.failure.hint') }}</p>
        </div>
    @endif
    @if ($renditions === [])
        <p>{{ __('inbox.detail.no_documents') }}</p>
    @else
        <div style="position:relative;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <caption style="padding-bottom:.25rem;text-align:start;font-weight:600">{{ __('inbox.documents.renditions') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.created_at') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.reason_label') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.versions') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.renderer') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.pages') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.size') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.documents.input_hash') }}</th>
                        <th scope="col" style="{{ $th }}"><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">{{ __('inbox.documents.download') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($renditions as $rendition)
                        <tr data-rendition="{{ $rendition['uuid'] }}">
                            <td style="{{ $td }};white-space:nowrap">{{ $rendition['created_at'] }}</td>
                            <td style="{{ $td }}">{{ $rendition['reason'] }}</td>
                            <td style="{{ $td }}">{{ __('inbox.documents.version_pair', ['form' => $rendition['form_version'], 'design' => $rendition['design'], 'design_version' => $rendition['design_version']]) }}</td>
                            <td style="{{ $td }}">{{ $rendition['renderer_version'] }}</td>
                            <td style="{{ $td }}">{{ $rendition['pages'] }}</td>
                            <td style="{{ $td }};white-space:nowrap">{{ $rendition['size'] }}</td>
                            <td style="{{ $td }}"><code title="{{ $rendition['input_hash'] }}">{{ $rendition['hash'] }}</code></td>
                            <td style="{{ $td }}">
                                @if ($rendition['url'] !== null)
                                    <x-filament::link :href="$rendition['url']" icon="heroicon-o-arrow-down-tray" target="_blank" rel="noopener noreferrer">
                                        {{ __('inbox.documents.download') }}
                                    </x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($sends !== [])
        <div style="position:relative;overflow-x:auto" data-section="submission-sends">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <caption style="padding-bottom:.25rem;text-align:start;font-weight:600">{{ __('mail.customer_send.heading') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" style="{{ $th }}">{{ __('mail.customer_send.recipient') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('inbox.column.status') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('mail.customer_send.delivery') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('mail.customer_send.requested_by') }}</th>
                        <th scope="col" style="{{ $th }}">{{ __('mail.customer_send.requested_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sends as $send)
                        <tr>
                            <td style="{{ $td }};overflow-wrap:anywhere">{{ $send['recipient'] }}</td>
                            <td style="{{ $td }}">
                                <x-filament::badge :color="$send['status_color']">{{ $send['status'] }}</x-filament::badge>
                                @if ($send['error'] !== null)
                                    <p style="margin:.25rem 0 0;font-size:.8125rem;opacity:.8">{{ $send['error'] }}</p>
                                @endif
                            </td>
                            <td style="{{ $td }}">{{ $send['mail_status'] ?? '—' }}</td>
                            <td style="{{ $td }}">{{ $send['requested_by'] }}</td>
                            <td style="{{ $td }};white-space:nowrap">{{ $send['requested_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
