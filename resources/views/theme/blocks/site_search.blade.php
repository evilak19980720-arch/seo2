<section class="section site-search" data-search>
<div class="container">
@include('theme.partials.section-head')
<form class="site-search__form" role="search" action="" method="get"><input class="lobby__search" type="search" name="q" data-q placeholder="{{ $ctx->t('search_site') }}" aria-label="{{ $ctx->t('search_site') }}"><button class="btn" type="submit">{{ $ctx->t('search') }}</button></form>
<ol class="site-search__results" data-results></ol>
<p class="muted" data-none hidden>{{ $ctx->t('no_search_results') }}</p>
</div>
<script>(function(){var r=document.currentScript.parentNode,q=r.querySelector('[data-q]'),out=r.querySelector('[data-results]'),none=r.querySelector('[data-none]'),idx=null;function norm(s){return(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'')}function show(){var t=norm(q.value).trim();out.innerHTML='';if(!t||!idx){none.hidden=true;return}var words=t.split(/\s+/),hits=idx.filter(function(d){var h=norm(d.t+' '+d.d);return words.every(function(w){return h.indexOf(w)>-1})}).slice(0,30);hits.forEach(function(d){var li=document.createElement('li'),a=document.createElement('a'),p=document.createElement('p');a.href=d.u;a.textContent=d.t;p.textContent=d.d;li.appendChild(a);li.appendChild(p);out.appendChild(li)});none.hidden=hits.length>0}fetch('/search.json').then(function(x){return x.json()}).then(function(d){idx=d;var m=location.search.match(/[?&]q=([^&]*)/);if(m){q.value=decodeURIComponent(m[1].replace(/\+/g,' '))}show()});q.addEventListener('input',show);r.querySelector('form').addEventListener('submit',function(e){e.preventDefault();show()})})();</script>
</section>
