@php
    $company = $document->companyUser();
    $owner = $document->documentable;
    $extension = strtoupper(pathinfo($document->file_path, PATHINFO_EXTENSION) ?: 'FILE');

    $ownerLabel = match (true) {
        $owner instanceof \App\Models\Employee => trim($owner->first_name.' '.$owner->last_name),
        $owner instanceof \App\Models\Vehicle => $owner->plate.' ('.$owner->capacity.' posti)',
        $owner instanceof \App\Models\User => $owner->name,
        default => 'Elemento non disponibile',
    };

    $sectionLabel = $document->template->section?->name ?? 'Documenti';
@endphp
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Documento respinto</title>
</head>
<body style="margin:0;background:#eef3f1;color:#172422;font-family:Arial,Helvetica,sans-serif;line-height:1.55;">
    <div style="display:none;max-height:0;overflow:hidden;color:#eef3f1;">
        Il documento {{ $document->template->name }} e stato respinto. Consulta le note e carica una versione corretta.
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3f1;margin:0;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #dbe7e2;border-radius:14px;overflow:hidden;box-shadow:0 18px 40px rgba(23,36,34,.08);">
                    <tr>
                        <td style="background:#123f3a;padding:24px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#f5c56b;font-weight:700;">Consorzio FASATrasporti</div>
                                        <div style="margin-top:6px;font-size:24px;line-height:1.2;color:#ffffff;font-weight:700;">Documento respinto</div>
                                    </td>
                                    <td align="right" style="vertical-align:middle;">
                                        <div style="display:inline-block;border:1px solid rgba(245,197,107,.45);border-radius:999px;color:#f8df9d;font-size:12px;font-weight:700;padding:7px 12px;">Richiede nuovo caricamento</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 18px;font-size:16px;color:#273936;">
                                Gentile <strong>{{ $company?->name ?? 'societa' }}</strong>,<br>
                                il documento indicato di seguito e stato verificato dal consorzio e non puo essere approvato nello stato attuale.
                            </p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0;margin:22px 0;border:1px solid #dbe7e2;border-radius:12px;overflow:hidden;">
                                <tr>
                                    <td style="background:#f8faf8;padding:18px 20px;border-bottom:1px solid #dbe7e2;">
                                        <div style="font-size:12px;color:#6a7d78;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">Scheda documento</div>
                                        <div style="margin-top:5px;font-size:20px;color:#172422;font-weight:700;">{{ $document->template->name }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#6a7d78;width:38%;">Sezione</td>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#172422;font-weight:700;">{{ $sectionLabel }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#6a7d78;">Intestatario</td>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#172422;font-weight:700;">{{ $ownerLabel }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#6a7d78;">Formato file</td>
                                                <td style="padding:14px 20px;border-bottom:1px solid #edf3f1;color:#172422;font-weight:700;">{{ $extension }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 20px;color:#6a7d78;">Data verifica</td>
                                                <td style="padding:14px 20px;color:#172422;font-weight:700;">{{ now()->format('d/m/Y H:i') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin:22px 0;padding:18px 20px;border-left:5px solid #c2410c;background:#fff7ed;border-radius:10px;">
                                <div style="font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#9a3412;font-weight:700;">Note del consorzio</div>
                                <p style="margin:8px 0 0;color:#172422;font-size:15px;">
                                    {{ $document->admin_notes ?: 'Il documento caricato non e conforme. Accedi al portale e carica una nuova versione corretta.' }}
                                </p>
                            </div>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;">
                                <tr>
                                    <td style="background:#f3f7f5;border:1px dashed #b8cbc5;border-radius:12px;padding:18px 20px;">
                                        <div style="font-size:13px;color:#6a7d78;text-transform:uppercase;letter-spacing:.06em;font-weight:700;">File caricato</div>
                                        <div style="margin-top:7px;color:#172422;font-size:15px;font-weight:700;">{{ basename($document->file_path) }}</div>
                                        <div style="margin-top:4px;color:#6a7d78;font-size:13px;">Anteprima non disponibile via email per file {{ $extension }}. Apri il documento dal pulsante qui sotto.</div>
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 6px;">
                                <tr>
                                    <td>
                                        <a href="{{ $document->file_url }}" style="display:inline-block;background:#196b69;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 18px;font-weight:700;font-size:14px;">Apri documento</a>
                                    </td>
                                    <td style="padding-left:10px;">
                                        <a href="{{ url('/') }}" style="display:inline-block;background:#ffffff;color:#196b69;text-decoration:none;border:1px solid #196b69;border-radius:8px;padding:12px 17px;font-weight:700;font-size:14px;">Accedi al portale</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0;color:#5d716c;font-size:13px;">
                                Dopo il nuovo caricamento, il documento tornera in verifica. Se ritieni che questa comunicazione non sia corretta, contatta il consorzio.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#f8faf8;border-top:1px solid #dbe7e2;padding:18px 28px;color:#6a7d78;font-size:12px;">
                            Questa email e stata generata automaticamente dal portale documentale Consorzio FASATrasporti.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
