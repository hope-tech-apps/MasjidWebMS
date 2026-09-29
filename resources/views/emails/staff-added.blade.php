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
                {{ $orgName }} added you as a {{ $roleLabel }}
              </h1>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Assalamu alaikum {{ $user->name }},
              </p>

              <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                {{ $orgName }} has added you to Manara as a {{ $roleLabel }}.
                You already have a Manara login, so nothing about your password has changed
                and you do not need to set one.
              </p>

              @if(count($classNames) > 0)
                <p style="margin:0 0 8px;font-size:15px;line-height:1.6;">Your classes there:</p>
                <ul style="margin:0 0 16px;padding-left:20px;font-size:15px;line-height:1.6;">
                  @foreach($classNames as $className)
                    <li>{{ $className }}</li>
                  @endforeach
                </ul>
              @endif

              <p style="margin:0 0 24px;font-size:15px;line-height:1.6;">
                Sign in as usual. If you belong to more than one school, choose
                {{ $orgName }} from the school menu at the top of the page.
              </p>

              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                  <td style="background:#286c56;border-radius:8px;">
                    <a href="{{ $signInUrl }}"
                       style="display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                      Sign in
                    </a>
                  </td>
                </tr>
              </table>

              <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 16px;">

              <p style="margin:0;font-size:12px;line-height:1.6;color:#9ca3af;">
                If you were not expecting this, please contact {{ $orgName }}{{ $contactEmail ? ' at '.$contactEmail : '' }}.
                This message contains no link that changes your password or ends your sessions.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
