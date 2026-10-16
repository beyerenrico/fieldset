{{-- Document body (BUILD_PROMPT §8.5). Every value is escaped; only sanitized rich text uses {!! !!}. --}}
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
{!! $fontCss !!}
@include('documents.partials.styles')
</style>
</head>
<body class="layout-{{ $design['layout'] }}">
@if ($watermark !== null)
{{-- Visually decorative, but read by assistive technology (tagged PDF): it changes what the document means. --}}
<div class="watermark">{{ $watermark }}</div>
@endif
<header class="doc-title">
  <h1>{{ $title }}</h1>
  <dl class="doc-meta">
    @foreach ($metaLines as $metaLabel => $metaValue)
      <div><dt>{{ $metaLabel }}</dt><dd>{{ $metaValue }}</dd></div>
    @endforeach
  </dl>
</header>
@foreach ($pages as $page)
@php($pageTitled = count($pages) > 1 && $page['title'] !== '')
<section class="page-block">
  @if ($pageTitled)
    <h2 class="page-title">{{ $page['title'] }}</h2>
  @endif
  <div class="fields">
    @foreach ($page['nodes'] as $node)
      {{-- Heading outline without skipped levels: h1 title → (h2 page title) → section headings. --}}
      @include('documents.partials.node', ['node' => $node, 'level' => $pageTitled ? 3 : 2])
    @endforeach
  </div>
</section>
@endforeach
@if ($signatures !== [])
<section class="signature-block">
  @foreach ($signatures as $node)
    @include('documents.partials.signature', ['node' => $node])
  @endforeach
</section>
@endif
</body>
</html>
