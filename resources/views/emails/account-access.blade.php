@php($isInvite = $mode === \App\Mail\AccountAccessMail::MODE_INVITE)
<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;padding:32px;">
          <tr>
            <td>
              <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">
                @if($isInvite)
                  {{ $orgName ? $orgName.' — set up your account' : 'Set up your account' }}
                @else
                  Reset your password
                @endif
              </h1>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Assalamu alaikum {{ $user->name }},
              </p>

              <p style="margin:0 0 24px;font-size:15px;line-height:1.6;">
                @if($isInvite)
                  An account has been created for you{{ $orgName ? ' at '.$orgName : '' }}.
                  Choose your own password to finish setting it up — nobody else knows it,
                  and nobody else can see it.
                @else
                  We received a request to reset the password for <strong>{{ $user->email }}</strong>.
                  Choose a new one using the button below.
                @endif
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                  <td style="background:#286c56;border-radius:8px;">
                    <a href="{{ $url }}"
                       style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                      {{ $isInvite ? 'Set my password' : 'Reset my password' }}
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#6b7280;">
                This link expires in {{ $expiresInMinutes }} minutes and can only be used once.
              </p>

              <p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#6b7280;">
                If the button does not work, copy this address into your browser:<br>
                <span style="word-break:break-all;">{{ $url }}</span>
              </p>

              <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 16px;">

              <p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;">
                @if($isInvite)
                  If you were not expecting this, you can ignore this email — the account
                  cannot be used until a password is set.
                @else
                  If you did not ask for this, you can ignore this email. Your password
                  will not change until someone opens the link above.
                @endif
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
