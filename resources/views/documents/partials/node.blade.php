@php($classes = 'node w-'.$node['width'].($node['keep'] ? ' keep' : ''))
@switch($node['kind'])
  @case('field')
    <div class="{{ $classes }} field{{ $node['numeric'] ? ' numeric' : '' }}">
      <div class="label">{{ $node['label'] }}</div>
      <div class="value{{ $node['multiline'] ? ' multiline' : '' }}">{{ $node['value'] }}</div>
    </div>
    @break
  @case('section')
    <section class="{{ $classes }} section">
      @if ($node['title'] !== '')<h3 class="section-title">{{ $node['title'] }}</h3>@endif
      <div class="fields">
        @foreach ($node['nodes'] as $child)
          @include('documents.partials.node', ['node' => $child])
        @endforeach
      </div>
    </section>
    @break
  @case('columns')
    <div class="{{ $classes }} columns" style="grid-template-columns: repeat({{ count($node['columns']) }}, 1fr)">
      @foreach ($node['columns'] as $column)
        <div class="fields column">
          @foreach ($column as $child)
            @include('documents.partials.node', ['node' => array_merge($child, ['width' => 'full'])])
          @endforeach
        </div>
      @endforeach
    </div>
    @break
  @case('table')
    @include('documents.partials.table', ['node' => $node, 'classes' => $classes])
    @break
  @case('cards')
    <div class="{{ $classes }} repeater">
      @if ($node['label'] !== '')<h3 class="section-title">{{ $node['label'] }}</h3>@endif
      @foreach ($node['cards'] as $card)
        <div class="card">
          <div class="card-title">{{ $card['title'] }}</div>
          <div class="fields">
            @foreach ($card['nodes'] as $child)
              @include('documents.partials.node', ['node' => $child])
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
      @if ($node['images'] === [])
        <div class="value">—</div>
      @else
        <div class="photo-grid" style="grid-template-columns: repeat({{ $node['columns'] }}, 1fr)">
          @foreach ($node['images'] as $src)
            <figure class="photo"><img src="{{ $src }}" alt=""></figure>
          @endforeach
        </div>
      @endif
    </div>
    @break
  @case('signature')
    @include('documents.partials.signature', ['node' => $node])
    @break
  @case('heading')
    <div class="{{ $classes }} heading level-{{ $node['level'] }}">{{ $node['text'] }}</div>
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
