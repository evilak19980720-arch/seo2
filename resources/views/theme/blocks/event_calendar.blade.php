@php($months = $ctx->entries('event')->filter(fn ($e) => $e->date())->sortBy(fn ($e) => $e->date()->timestamp)->groupBy(fn ($e) => $e->date()->format('Y-m')))
@if ($months->isNotEmpty())
<section class="section event-calendar event-calendar--{{ $variant }}">
<div class="container">
@include('theme.partials.section-head', ['fallback' => $ctx->t('calendar')])
<div class="event-calendar__months">
@foreach ($months as $month => $events)
<div class="event-calendar__month"><h3>{{ \Illuminate\Support\Carbon::parse($month.'-01')->locale($ctx->lang)->translatedFormat('F Y') }}</h3><ul>
@foreach ($events as $event)
@php($href = $ctx->entryHref($event))
<li><time datetime="{{ $event->date()->toDateString() }}">{{ $event->date()->format('d/m') }}@if ($event->get('ends_at') && $event->get('ends_at') !== $event->date()->toDateString()) – {{ \Illuminate\Support\Carbon::parse($event->get('ends_at'))->format('d/m') }}@endif</time> @if ($href)<a href="{{ $href }}">{{ $event->title }}</a>@else{{ $event->title }}@endif @if ($event->status())<span class="entry__status entry__status--{{ $event->status() }}">{{ $ctx->t('status_'.$event->status()) }}</span>@endif</li>
@endforeach
</ul></div>
@endforeach
</div>
</div>
</section>
@endif
