<div class="fi-prototype-about" style="display:flex;flex-direction:column;gap:.7rem;font-size:.875rem;line-height:1.6">
    @foreach ($paragraphs as $p)
        <p style="margin:0">{!! preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($p)) !!}</p>
    @endforeach
</div>
