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
                Two-step sign-in was switched off on your account
              </h1>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Assalamu alaikum {{ $user->name }},
              </p>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Two-step sign-in on <strong>{{ $user->email }}</strong> was switched off
                on {{ $performedAt }} by <strong>{{ $performedByLabel }}</strong>.
              </p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background:#f9fafb;border-radius:8px;">
                <tr>
                  <td style="padding:16px;font-size:14px;line-height:1.6;">
                    <span style="color:#6b7280;">Reason given:</span><br>
                    {{ $reason }}
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Your password has not changed and no one has been signed in as you.
                You can sign in with your password as usual, and we would ask you to set
                two-step sign-in up again on your new device from
                <strong>Profile &rarr; Two-step sign-in</strong>. Keep the new recovery
                codes somewhere you can reach without your phone.
              </p>

              <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 16px;">

              <p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;">
                If you did not ask for this, tell whoever runs {{ config('app.name') }} for your
                organisation straight away, and change your password. This message is a
                record — there is nothing in it to click, and no one will ever email you
                asking for a code.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
