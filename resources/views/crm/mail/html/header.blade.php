@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; font-family: 'Space Grotesk', Arial, sans-serif; font-size: 22px; font-weight: 700; color: #0c0e12; text-decoration: none;">
{{ config('crm.brand.company') }}<span style="display: inline-block; width: 9px; height: 9px; margin-left: 4px; background-color: {{ config('crm.brand.color') }};"></span> <span style="font-weight: 500; color: #6e7a8e;">CRM</span>
</a>
</td>
</tr>
