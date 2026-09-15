@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/email-logo-lockup.png') }}" class="logo logo-light" alt="Relaticle" width="200" height="45" style="height: 45px; width: 200px;">
<!--[if !mso]><! -->
<div class="logo-dark-wrap" style="display: none; mso-hide: all; max-height: 0; overflow: hidden;">
<img src="{{ asset('brand/email-logo-lockup-dark.png') }}" class="logo logo-dark" alt="Relaticle" width="200" height="45" style="height: 45px; width: 200px;">
</div>
<!--<![endif]-->
</a>
</td>
</tr>
