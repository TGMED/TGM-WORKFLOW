<x-mail::message>
{{-- Eyebrow: the subject, so the mail says what it is about before it says hello --}}
@if (! empty($subject))
<p class="eyebrow">{{ $subject }}</p>
@endif

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Panel: the facts of the thing, set apart from the prose --}}
@isset($panel)
<x-mail::panel>
{!! $panel !!}
</x-mail::panel>
@endisset

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
<div class="signoff">
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif
</div>

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If the \":actionText\" button does not work, paste this link into your browser:",
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
