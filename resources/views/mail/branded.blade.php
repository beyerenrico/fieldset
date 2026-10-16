{{-- Branded organization mail (§8.5, §8.8). $bodyHtml is tenant Markdown rendered with HTML escaped
     and token values escaped (TokenReplacer + MarkdownRenderer); everything else is escaped here. --}}
@php /** @var \App\Domain\Mail\Mailables\MailLayout $layout */ @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subjectLine }}</title>
<style>
    .mail-body a { color: {{ $layout->primaryText }}; }
    .mail-body p { margin: 0 0 16px; }
    .mail-body h1, .mail-body h2, .mail-body h3 { color: #18181b; margin: 0 0 12px; line-height: 1.3; }
    .mail-body ul, .mail-body ol { margin: 0 0 16px; padding-left: 24px; }
    .mail-body blockquote { margin: 0 0 16px; padding-left: 12px; border-left: 3px solid {{ $layout->accentColor }}; color: #52525b; }
    @media (max-width: 620px) { .mail-card { width: 100% !important; } .mail-pad { padding-left: 20px !important; padding-right: 20px !important; } }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
<tr>
<td align="center" style="padding:24px 12px;">
<table role="presentation" class="mail-card" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:8px;border-top:4px solid {{ $layout->primaryColor }};">
<tr>
<td class="mail-pad" style="padding:24px 32px 8px;">
@if ($layout->logoCid !== null)
<img src="cid:{{ $layout->logoCid }}" alt="{{ $layout->companyName }}" style="display:block;max-height:56px;max-width:220px;border:0;">
@else
<span style="font-size:20px;font-weight:700;color:{{ $layout->primaryText }};">{{ $layout->companyName }}</span>
@endif
</td>
</tr>
<tr>
<td class="mail-pad mail-body" style="padding:16px 32px 8px;font-size:15px;line-height:1.6;">
{!! $bodyHtml !!}
</td>
</tr>
@foreach ($links as $link)
<tr>
<td class="mail-pad" style="padding:0 32px 16px;">
<a href="{{ $link->url }}" style="display:inline-block;padding:10px 18px;border-radius:6px;background-color:{{ $layout->primaryColor }};color:{{ $layout->onPrimary }};font-weight:600;text-decoration:none;">{{ __('mail.document.download', ['name' => $link->filename]) }}</a>
<div style="margin-top:6px;font-size:12px;color:#71717a;">{{ __('mail.document.expires', ['date' => $link->expiresAt->settings(['locale' => app()->getLocale()])->isoFormat('LL')]) }}</div>
</td>
</tr>
@endforeach
<tr>
<td class="mail-pad" style="padding:16px 32px 24px;border-top:1px solid #e4e4e7;font-size:12px;line-height:1.5;color:#71717a;">
<strong style="color:#52525b;">{{ $layout->companyName }}</strong>
@foreach ($layout->footer as $line)
<br>{!! nl2br(e($line)) !!}
@endforeach
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
