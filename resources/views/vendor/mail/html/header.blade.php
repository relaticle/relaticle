@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('brand/email-logo.png') }}" class="logo logo-light" alt="Relaticle" width="150" height="40" style="height: 40px; width: 150px;">
<!--[if !mso]><! -->
<div class="logo-dark-wrap" style="display: none; mso-hide: all; max-height: 0; overflow: hidden;">
<img src="{{ asset('brand/email-logo-dark.png') }}" class="logo logo-dark" alt="" width="150" height="40" style="height: 40px; width: 150px;">
</div>
<!--<![endif]-->
</a>
</td>
</tr>
