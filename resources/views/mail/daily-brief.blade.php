<x-mail::message>
# Today's summary

{{ $headline }}

<x-mail::button :url="$url">
Open Today
</x-mail::button>

{{ $body }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
