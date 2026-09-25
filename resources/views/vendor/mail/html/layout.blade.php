<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}

.content-cell {
padding: 24px !important;
}

.footer-cell {
padding: 24px !important;
}
}

@media only screen and (max-width: 500px) {
.button-wrap {
width: 100% !important;
}

.button {
box-sizing: border-box !important;
display: block !important;
text-align: center !important;
width: 100% !important;
}
}

@media (prefers-color-scheme: dark) {
body {
background-color: #18181b !important;
color: #e4e4e7 !important;
}

.wrapper {
background-color: #18181b !important;
}

.body {
background-color: #18181b !important;
border-color: #18181b !important;
}

.inner-body {
background-color: #27272a !important;
border-color: #3f3f46 !important;
}

.logo-light {
display: none !important;
}

.logo-dark-wrap {
display: block !important;
max-height: none !important;
overflow: visible !important;
}

h1, h2, h3 {
color: #fafafa !important;
}

p, li, td {
color: #e4e4e7 !important;
}

a {
color: #c4b5fd !important;
}

.button-primary {
background-color: #7c3aed !important;
border-color: #7c3aed !important;
color: #ffffff !important;
}

.panel {
border-left-color: #8b5cf6 !important;
}

.table th {
border-bottom-color: #3f3f46 !important;
color: #fafafa !important;
}

.table td {
color: #e4e4e7 !important;
}

.panel-content, .panel-content p {
background-color: #2e1065 !important;
color: #ede9fe !important;
}

.list-row {
border-top-color: #3f3f46 !important;
}

.list-title, .list-label, .list-label a {
color: #fafafa !important;
}

.list-value {
color: #e4e4e7 !important;
}

.list-meta {
color: #a1a1aa !important;
}

.subcopy {
border-top-color: #3f3f46 !important;
}

.subcopy p {
color: #a1a1aa !important;
}

.footer-cell {
color: #a1a1aa !important;
}

.footer-cell p, .footer-cell a {
color: #a1a1aa !important;
}
}

[data-ogsc] body, [data-ogsc] .wrapper, [data-ogsc] .body {
background-color: #18181b !important;
border-color: #18181b !important;
}

[data-ogsc] .inner-body {
background-color: #27272a !important;
border-color: #3f3f46 !important;
}

[data-ogsc] .logo-light {
display: none !important;
}

[data-ogsc] .logo-dark-wrap {
display: block !important;
max-height: none !important;
overflow: visible !important;
}

[data-ogsc] h1, [data-ogsc] h2, [data-ogsc] h3 {
color: #fafafa !important;
}

[data-ogsc] p, [data-ogsc] li, [data-ogsc] td {
color: #e4e4e7 !important;
}

[data-ogsc] a {
color: #c4b5fd !important;
}

[data-ogsc] .button-primary {
background-color: #7c3aed !important;
border-color: #7c3aed !important;
color: #ffffff !important;
}

[data-ogsc] .panel-content {
background-color: #2e1065 !important;
}

[data-ogsc] .panel {
border-left-color: #8b5cf6 !important;
}

[data-ogsc] .panel-content, [data-ogsc] .panel-content p {
color: #ede9fe !important;
}

[data-ogsc] .list-row, [data-ogsc] .subcopy {
border-top-color: #3f3f46 !important;
}

[data-ogsc] .list-title, [data-ogsc] .list-label, [data-ogsc] .list-label a {
color: #fafafa !important;
}

[data-ogsc] .list-value {
color: #e4e4e7 !important;
}

[data-ogsc] .list-meta, [data-ogsc] .subcopy p {
color: #a1a1aa !important;
}

[data-ogsc] .table th {
border-bottom-color: #3f3f46 !important;
color: #fafafa !important;
}

[data-ogsc] .table td {
color: #e4e4e7 !important;
}

[data-ogsc] .footer-cell, [data-ogsc] .footer-cell p, [data-ogsc] .footer-cell a {
color: #a1a1aa !important;
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
{!! $preheader ?? '' !!}
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
