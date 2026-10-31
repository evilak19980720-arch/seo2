@if (filled($data['text'] ?? null))
<section class="section quote-block quote-block--{{ $variant }}">
<div class="container">
@if ($isFirst ?? false)<h1>{{ $data['heading'] ?? $page?->title }}</h1>@elseif (! empty($data['heading']))<h2>{{ $data['heading'] }}</h2>@endif
<figure class="quote"><blockquote><p>{{ $data['text'] }}</p></blockquote>@if (! empty($data['cite']))<figcaption>{{ $data['cite'] }}</figcaption>@endif</figure>
</div>
</section>
@endif
