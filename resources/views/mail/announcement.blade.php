{{-- Comunicado da Foxy (e-mail). Texto escrito pela equipe; só quebras de linha e links simples. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<body style="margin:0;background:#f5f5f8;font-family:'Segoe UI',Arial,sans-serif;color:#1f1b2d;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f8;padding:24px 0;">
        <tr><td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;border:1px solid #e6e1f5;">
                <tr><td style="background:#221a33;border-radius:16px 16px 0 0;padding:18px 24px;color:#ffffff;font-family:Georgia,serif;letter-spacing:0.2em;font-size:18px;">FOXY</td></tr>
                <tr><td style="padding:24px;font-size:15px;line-height:1.6;">{!! nl2br(e($text)) !!}</td></tr>
                <tr><td style="padding:0 24px 22px;font-size:12px;color:#6b6880;border-top:1px solid #eeeaf8;padding-top:14px;">
                    Você recebe este e-mail porque tem uma conta num produto da Foxy.
                    @if ($unsubscribeUrl)<a href="{{ $unsubscribeUrl }}" style="color:#5b3fd1;">Não quero receber comunicados por e-mail</a>.@endif
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
