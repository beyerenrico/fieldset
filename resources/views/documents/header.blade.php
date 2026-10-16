{{-- Gotenberg header: a complete HTML document rendered into the top margin of every page. --}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<style>
{!! $fontCss !!}
html, body { margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.hf { box-sizing: border-box; width: 100%; padding: 0 {{ $margins['right'] }}mm 0 {{ $margins['left'] }}mm; font-family: {!! $fontFamily !!}; font-size: 9pt; color: #1f2937; display: flex; align-items: center; justify-content: space-between; gap: 6mm; border-bottom: 0.6mm solid {{ $primary }}; padding-bottom: 2mm; }
.hf img { max-height: 12mm; max-width: 50mm; }
.hf .text { flex: 1; }
.hf p { margin: 0; }
</style>
</head>
<body>
<div class="hf">
  @if ($logo !== null)<img src="{{ $logo }}" alt="">@endif
  <div class="text">{!! $html !!}</div>
</div>
</body>
</html>
