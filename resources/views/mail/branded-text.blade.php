{{-- Plain-text part of the branded mail: Markdown source with plain token values. --}}
{!! $bodyText !!}
@foreach ($links as $link)

{!! __('mail.document.download', ['name' => $link->filename]) !!}: {!! $link->url !!}
{!! __('mail.document.expires', ['date' => $link->expiresAt->settings(['locale' => app()->getLocale()])->isoFormat('LL')]) !!}
@endforeach

--
{!! $layout->companyName !!}
@foreach ($layout->footer as $line)
{!! $line !!}
@endforeach
