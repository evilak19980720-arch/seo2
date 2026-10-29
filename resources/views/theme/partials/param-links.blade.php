@foreach ($values as $value){{ $loop->first ? '' : ', ' }}@if ($value['href'])<a href="{{ $value['href'] }}">{{ $value['label'] }}</a>@else{{ $value['label'] }}@endif @endforeach
