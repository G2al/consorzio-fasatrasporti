<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Documenti mancanti</title>
</head>
<body style="margin:0;background:#eef3f1;color:#172422;font-family:Arial,Helvetica,sans-serif;line-height:1.55;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3f1;margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:760px;background:#ffffff;border:1px solid #dbe7e2;border-radius:14px;overflow:hidden;box-shadow:0 18px 40px rgba(23,36,34,.08);">
                    <tr>
                        <td style="background:#123f3a;padding:24px 28px;border-bottom:6px solid #d9a441;">
                            <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f5c56b;font-weight:700;">Consorzio FASA Trasporti</div>
                            <div style="margin-top:6px;font-size:25px;line-height:1.2;color:#ffffff;font-weight:700;">Documenti mancanti</div>
                            <div style="margin-top:8px;color:#d9eeea;font-size:14px;">Sezione: <strong>{{ $sectionLabel }}</strong></div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 18px;font-size:16px;color:#273936;">
                                Gentile <strong>{{ $company->name }}</strong>,<br>
                                risultano documenti da caricare o aggiornare nella sezione indicata.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:20px;border:1px solid #dbe7e2;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <th align="left" style="background:#e8f2ef;color:#123f3a;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Elemento</th>
                                    <th align="left" style="background:#e8f2ef;color:#123f3a;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Documento</th>
                                    <th align="left" style="background:#e8f2ef;color:#123f3a;padding:12px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Motivo</th>
                                </tr>
                                @foreach ($items as $item)
                                    <tr>
                                        <td style="padding:13px 14px;border-top:1px solid #edf3f1;color:#172422;font-weight:700;">{{ $item['owner'] }}</td>
                                        <td style="padding:13px 14px;border-top:1px solid #edf3f1;color:#172422;">{{ $item['document'] }}</td>
                                        <td style="padding:13px 14px;border-top:1px solid #edf3f1;color:#8a4b05;">{{ $item['reason'] }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 6px;">
                                <tr>
                                    <td>
                                        <a href="{{ url('/') }}" style="display:inline-block;background:#196b69;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 18px;font-weight:700;font-size:14px;">Accedi al portale</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0;color:#5d716c;font-size:13px;">
                                Questa comunicazione riepiloga solo documenti mancanti o scaduti. I documenti in verifica non sono inclusi.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#f8faf8;border-top:1px solid #dbe7e2;padding:18px 28px;color:#6a7d78;font-size:12px;">
                            Email generata automaticamente dal portale documentale Consorzio FASA Trasporti.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
