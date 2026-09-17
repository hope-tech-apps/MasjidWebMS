{{--
    Plain-text part of PasswordSetNoticeMail. Keep it saying what the HTML part says.
    Raw output on purpose: this part is text/plain and is never read as HTML, so
    escaping would print "&amp;" for an organisation named "Masjid & Centre".
--}}
{!! $greeting !!}

The password for {!! $loginEmail !!} at {!! $orgName !!} was set on {!! $setAt !!}. If that was you, there is nothing else to do.

{!! $ifItWasNotYou !!}

This email has no links on purpose. It is sent whenever a password is set for this address at {!! $orgName !!}.
