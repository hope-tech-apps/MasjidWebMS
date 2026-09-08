@php($isMessage = $kind === 'message')
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
                @if($isMessage)
                  You have a new message
                @else
                  A new update in {{ $groupLabel }}
                @endif
              </h1>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                @if($recipientName)
                  Assalamu alaikum {{ $recipientName }},
                @else
                  Assalamu alaikum,
                @endif
              </p>

              <p style="margin:0 0 24px;font-size:15px;line-height:1.6;">
                @if($isMessage)
                  There is a new message for you in <strong>{{ $groupLabel }}</strong> at
                  {{ $orgName }}. Sign in to read it and reply.
                @else
                  {{ $orgName }} posted a new update in <strong>{{ $groupLabel }}</strong>.
                  Sign in to read it.
                @endif
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                  <td style="background:#286c56;border-radius:8px;">
                    <a href="{{ $signInUrl }}"
                       style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                      {{ $isMessage ? 'Read the message' : 'Read the update' }}
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#6b7280;">
                For your family's privacy, the details are only shown after you sign in — never in this email.
              </p>

              <p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#6b7280;">
                If the button does not work, copy this address into your browser:<br>
                <span style="word-break:break-all;">{{ $signInUrl }}</span>
              </p>

              <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 16px;">

              <p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;">
                You are receiving this because you are on the roster at {{ $orgName }}.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
