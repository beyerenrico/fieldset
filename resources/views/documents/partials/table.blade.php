<div class="{{ $classes }} repeater">
  @if ($node['label'] !== '')<h3 class="section-title">{{ $node['label'] }}</h3>@endif
  <table class="rows">
    <thead>
      <tr>
        <th class="index">#</th>
        @foreach ($node['headers'] as $i => $header)
          <th class="{{ $node['numeric'][$i] ? 'numeric' : '' }}">{{ $header }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse ($node['rows'] as $r => $row)
        <tr>
          <td class="index">{{ $r + 1 }}</td>
          @foreach ($row as $i => $cell)
            <td class="{{ $node['numeric'][$i] ? 'numeric' : '' }}">{{ $cell }}</td>
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ count($node['headers']) + 1 }}">—</td></tr>
      @endforelse
    </tbody>
  </table>
</div>
