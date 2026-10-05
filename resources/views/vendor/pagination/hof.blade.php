@if($paginator->hasPages())
<nav aria-label="分页"><ul class="pager">
@if($paginator->onFirstPage())<li><span aria-disabled="true">上一页</span></li>@else<li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a></li>@endif
@foreach($elements ?? [] as $element)
@if(is_string($element))<li><span aria-hidden="true">{{ $element }}</span></li>@endif
@if(is_array($element))@foreach($element as $page => $url)<li>@if($page === $paginator->currentPage())<span aria-current="page">{{ $page }}</span>@else<a href="{{ $url }}" aria-label="第{{ $page }}页">{{ $page }}</a>@endif</li>@endforeach
@endif
@endforeach
@if($paginator->hasMorePages())<li><a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a></li>@else<li><span aria-disabled="true">下一页</span></li>@endif
</ul></nav>
@endif
