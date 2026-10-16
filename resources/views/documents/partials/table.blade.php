{{-- Repeater as a table: header row repeats on every page; empty cells print "—"; photos print as thumbnails. --}}
<div class="{{ $classes }} repeater">
  @if ($node['label'] !== '')<h{{ $level }} class="section-title">{{ $node['label'] }}</h{{ $level }}>@endif
  <table class="rows">
    <thead>
      <tr>
        <th scope="col" class="index">#</th>
        @foreach ($node['headers'] as $i => $header)
          <th scope="col" class="{{ $node['numeric'][$i] ? 'numeric' : '' }}">{{ $header }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse ($node['rows'] as $r => $row)
        <tr>
          <td class="index">{{ $r + 1 }}</td>
          @foreach ($row as $i => $cell)
            @if ($cell['images'] !== [])
              <td class="{{ $node['numeric'][$i] ? 'numeric' : '' }}"><div class="thumbs">@foreach ($cell['images'] as $image)<img src="{{ $image }}" alt="{{ __('documents.render.photo_alt', ['label' => $node['headers'][$i], 'index' => $loop->iteration, 'count' => $loop->count]) }}">@endforeach</div></td>
            @else
              <td class="{{ $node['numeric'][$i] ? 'numeric' : '' }}">{{ $cell['text'] === '' ? '—' : $cell['text'] }}</td>
            @endif
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ count($node['headers']) + 1 }}">—</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
