@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/email-logo.png') }}" class="logo logo-light" alt="Relaticle" width="140" height="45" style="height: 45px; width: 140px;">
<!--[if !mso]><! -->
<div class="logo-dark-wrap" style="display: none; mso-hide: all; max-height: 0; overflow: hidden;">
<img src="{{ asset('brand/email-logo-dark.png') }}" class="logo logo-dark" alt="" width="140" height="45" style="height: 45px; width: 140px;">
</div>
<!--<![endif]-->
</a>
</td>
</tr>
