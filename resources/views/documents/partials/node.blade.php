{{-- One print node. $level: heading level for titles at this depth (2–6), so the outline never skips a level. --}}
@php($classes = 'node w-'.$node['width'].($node['keep'] ? ' keep' : ''))
@php($level = min(6, $level ?? 2))
@php($sub = min(6, $level + 1))
@switch($node['kind'])
  @case('field')
    <div class="{{ $classes }} field{{ $node['numeric'] ? ' numeric' : '' }}">
      <div class="label">{{ $node['label'] }}</div>
      <div class="value{{ $node['multiline'] ? ' multiline' : '' }}">{{ $node['value'] }}</div>
    </div>
    @break
  @case('section')
    <section class="{{ $classes }} section">
      @if ($node['title'] !== '')<h{{ $level }} class="section-title">{{ $node['title'] }}</h{{ $level }}>@endif
      <div class="fields">
        @foreach ($node['nodes'] as $child)
          @include('documents.partials.node', ['node' => $child, 'level' => $node['title'] !== '' ? $sub : $level])
        @endforeach
      </div>
    </section>
    @break
  @case('columns')
    <div class="{{ $classes }} columns" style="grid-template-columns: repeat({{ count($node['columns']) }}, 1fr)">
      @foreach ($node['columns'] as $column)
        <div class="fields column">
          @foreach ($column as $child)
            @include('documents.partials.node', ['node' => array_merge($child, ['width' => 'full']), 'level' => $level])
          @endforeach
        </div>
      @endforeach
    </div>
    @break
  @case('table')
    @include('documents.partials.table', ['node' => $node, 'classes' => $classes, 'level' => $level])
    @break
  @case('cards')
    <div class="{{ $classes }} repeater">
      @if ($node['label'] !== '')<h{{ $level }} class="section-title">{{ $node['label'] }}</h{{ $level }}>@endif
      @foreach ($node['cards'] as $card)
        <div class="card">
          <h{{ $sub }} class="card-title">{{ $card['title'] }}</h{{ $sub }}>
          <div class="fields">
            @foreach ($card['nodes'] as $child)
              @include('documents.partials.node', ['node' => $child, 'level' => min(6, $sub + 1)])
            @endforeach
          </div>
        </div>
      @endforeach
      @if ($node['cards'] === [])<div class="value">—</div>@endif
    </div>
    @break
  @case('photos')
    <div class="{{ $classes }} photos">
      <div class="label">{{ $node['label'] }}</div>
      @if ($node['omitted'] > 0)
        <div class="value">{{ trans_choice('documents.render.photos_omitted', $node['omitted'], ['count' => $node['omitted']]) }}</div>
      @endif
      @if ($node['images'] === [] && $node['omitted'] === 0)
        <div class="value">—</div>
      @elseif ($node['images'] !== [])
        <div class="photo-grid" style="grid-template-columns: repeat({{ $node['columns'] }}, 1fr)">
          @foreach ($node['images'] as $src)
            <figure class="photo"><img src="{{ $src }}" alt="{{ __('documents.render.photo_alt', ['label' => $node['label'], 'index' => $loop->iteration, 'count' => $loop->count]) }}"></figure>
          @endforeach
        </div>
      @endif
    </div>
    @break
  @case('signature')
    @include('documents.partials.signature', ['node' => $node])
    @break
  @case('heading')
    {{-- A real heading at the current depth (never skips a level); its spec level only sets the size. --}}
    <h{{ $level }} class="{{ $classes }} heading level-{{ $node['level'] }}">{{ $node['text'] }}</h{{ $level }}>
    @break
  @case('paragraph')
    <div class="{{ $classes }} paragraph">{!! $node['html'] !!}</div>
    @break
  @case('image')
    <figure class="{{ $classes }} image"><img src="{{ $node['src'] }}" alt="{{ $node['alt'] }}"></figure>
    @break
  @case('divider')
    <hr class="node w-full divider">
    @break
  @case('page_break')
    <div class="node w-full page-break"></div>
    @break
@endswitch
