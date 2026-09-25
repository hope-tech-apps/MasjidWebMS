{!! $orgName !!}

{!! $title !!}

{!! $greeting !!}

{!! $body !!}
@if ($blocksText !== '')

{!! $blocksText !!}
@endif
@if ($link)

More details: {!! $link !!}
@endif

--
You are receiving this because you are on {!! $orgName !!}'s contact list.
@if (! empty($unsubscribeUrl))
Unsubscribe from {!! $orgName !!}'s emails: {!! $unsubscribeUrl !!}
This does not affect receipts, registration confirmations, or replies to messages you send.
@endif
{{--
    The text/plain alternative of a newsletter broadcast. Every value is printed
    with {!! !!} on purpose: this part is not HTML, so Blade's escaping would put
    "&amp;" in front of a reader. Nothing here can execute — a text/plain part is
    shown as characters. The blocks arrive as text NewsletterRenderer wrote.
--}}
