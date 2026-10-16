{{-- Gotenberg footer: rendered into the bottom margin of every page (pageNumber/totalPages). --}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<style>
{!! $fontCss !!}
html, body { margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.hf { box-sizing: border-box; width: 100%; padding: 2mm {{ $margins['right'] }}mm 0 {{ $margins['left'] }}mm; font-family: {!! $fontFamily !!}; font-size: 8pt; color: #4b5563; border-top: 0.3mm solid #d1d5db; }
.hf p { margin: 0; }
.hf .legal { margin-top: 1mm; font-size: 7pt; color: #6b7280; }
</style>
</head>
<body>
<div class="hf">
  <div class="text">{!! $html !!}</div>
  @if ($legal !== '')<div class="legal">{{ $legal }}</div>@endif
</div>
</body>
</html>
