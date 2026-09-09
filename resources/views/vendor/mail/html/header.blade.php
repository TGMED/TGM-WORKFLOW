@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- The wordmark is dark ink, so clients that honour the reader's dark mode
     get the light-ink variant instead. Anything that ignores the media query
     keeps the light-background version, which is the safe default. --}}
<img src="{{ asset('images/tgm-logo.png') }}" class="logo logo-light" alt="{{ $slot }}" width="168" height="18">
<!--[if !mso]><! -->
<img src="{{ asset('images/tgm-logo-dark.png') }}" class="logo logo-dark" alt="{{ $slot }}" width="168" height="18" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
<!--<![endif]-->
</a>
</td>
</tr>
