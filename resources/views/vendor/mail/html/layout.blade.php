<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
/*
 * Anything that cannot be inlined onto an element. The palette matches the
 * app's; see themes/default.css for the inlined half.
 */
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
border-radius: 0 !important;
border-left: none !important;
border-right: none !important;
}

.brand-rule {
border-radius: 0 !important;
}

.content-cell {
padding: 26px 22px 28px !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
/* The button's side borders stand in for padding, so a full-width button has
   to give them up rather than add to its width. */
.button {
display: block !important;
width: 100% !important;
border-left-width: 0 !important;
border-right-width: 0 !important;
}
}

/* Clients that honour the reader's dark mode get the app's dark palette. */
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.body {
background-color: #0b1120 !important;
}

.inner-body {
background-color: #131c2e !important;
border-color: #253148 !important;
}

.header a {
color: #e8edf6 !important;
}

.logo-light {
display: none !important;
max-height: 0 !important;
overflow: hidden !important;
}

.logo-dark {
display: block !important;
max-height: none !important;
overflow: visible !important;
margin: 0 auto !important;
}

h1, h2, h3, strong {
color: #e8edf6 !important;
}

p, li, .table td, blockquote {
color: #c2ccdd !important;
}

.eyebrow, .subcopy p, .subcopy a, .footer p, .signoff, .signoff p {
color: #96a3ba !important;
}

.panel-content {
background-color: #1a2437 !important;
border-color: #253148 !important;
border-left-color: #7c95f5 !important;
}

code {
background-color: #1a2437 !important;
color: #e8edf6 !important;
}

a:not(.button) {
color: #7c95f5 !important;
}

hr, .signoff, .table td {
border-color: #253148 !important;
}

.table th {
border-color: #253148 !important;
color: #96a3ba !important;
}

.footer a {
color: #96a3ba !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Brand rule -->
<tr>
<td class="brand-rule" height="3">&nbsp;</td>
</tr>
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
