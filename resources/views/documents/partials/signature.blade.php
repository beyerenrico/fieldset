<div class="node w-{{ $node['width'] ?? 'half' }} keep signature">
  <div class="label">{{ $node['label'] }}</div>
  <div class="signature-box">
    @if ($node['image'] !== null)<img src="{{ $node['image'] }}" alt="">@endif
  </div>
  <div class="signer">{{ $node['signer'] }}@if ($node['signed_at'] !== '') · {{ $node['signed_at'] }}@endif</div>
</div>
