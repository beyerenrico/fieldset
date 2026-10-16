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
<div class="watermark" aria-hidden="true">{{ $watermark }}</div>
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
<section class="page-block">
  @if (count($pages) > 1 && $page['title'] !== '')
    <h2 class="page-title">{{ $page['title'] }}</h2>
  @endif
  <div class="fields">
    @foreach ($page['nodes'] as $node)
      @include('documents.partials.node', ['node' => $node])
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
