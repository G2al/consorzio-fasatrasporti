<?php

namespace App\Filament\Resources\DocumentApprovals;

use App\Filament\Resources\DocumentApprovals\Pages\ManageDocumentApprovals;
use App\Mail\DocumentRejectedMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class DocumentApprovalResource extends Resource
{
    protected static ?string $model = UploadedDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = 'Approvazione documenti';

    protected static string|\UnitEnum|null $navigationGroup = 'Documenti';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'documento caricato';

    protected static ?string $pluralModelLabel = 'documenti caricati';

    public static function canAccess(): bool
    {
        return in_array(auth('admin')->user()?->role, ['admin', 'reviewer'], true);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = UploadedDocument::query()
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Documenti in attesa di approvazione';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                    ])
                    ->required(),
                Toggle::make('has_expiry')
                    ->label('Ha scadenza')
                    ->live(),
                DatePicker::make('expiry_date')
                    ->label('Scadenza')
                    ->helperText('Se la data non e corretta, modificala senza respingere il documento.')
                    ->visible(fn (Get $get): bool => (bool) $get('has_expiry'))
                    ->required(fn (Get $get): bool => (bool) $get('has_expiry')),
                TextInput::make('internal_expiry_name')
                    ->label('Requisito interno')
                    ->placeholder('Es. CQC')
                    ->maxLength(255),
                DatePicker::make('internal_expiry_date')
                    ->label('Scadenza requisito')
                    ->required(fn (Get $get): bool => filled($get('internal_expiry_name'))),
                DateTimePicker::make('approved_at')
                    ->label('Data approvazione')
                    ->helperText('Compilata automaticamente dal pulsante Approva.'),
                Textarea::make('admin_notes')
                    ->label('Note admin')
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('parent_uploaded_document_id')
                    ->orWhereHas('parentDocument', fn (Builder $query): Builder => $query->whereNull('subtemplate_id'));
            })
            ->with([
                'template.section',
                'subtemplate',
                'parentDocument',
                'attachments',
                'documentable' => fn (MorphTo $morphTo): MorphTo => $morphTo->morphWith([
                    Employee::class => ['user'],
                    Vehicle::class => ['user'],
                ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw("case status when 'pending' then 0 when 'rejected' then 1 when 'approved' then 2 else 3 end")
                ->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('template.section.name')
                    ->label('Sezione')
                    ->badge(),
                TextColumn::make('template.name')
                    ->label('Documento')
                    ->searchable()
                    ->description(fn (UploadedDocument $record): ?string => match (true) {
                        $record->isIntegration() => 'Integrazione: '.($record->integration_name ?: 'senza nome'),
                        filled($record->subtemplate?->name) => 'Sottodocumento: '.$record->subtemplate->name,
                        default => null,
                    }),
                TextColumn::make('company_display')
                    ->label('Società / elemento')
                    ->state(fn (UploadedDocument $record): string => static::companyLabel($record))
                    ->description(fn (UploadedDocument $record): ?string => static::elementDescription($record)),
                TextColumn::make('status')
                    ->label('Stato')
                    ->state(fn (UploadedDocument $record): string => $record->effectiveStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Approvato',
                        'expired' => 'Scaduto',
                        'rejected' => 'Respinto',
                        'missing' => 'Mancante',
                        'pending' => 'In attesa',
                        default => 'In attesa',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'expired' => 'danger',
                        'rejected' => 'danger',
                        'missing' => 'danger',
                        'pending' => 'warning',
                        default => 'warning',
                    }),
                TextColumn::make('expiry_date')
                    ->label('Scadenza')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('internal_expiry_date')
                    ->label('Scad. requisito')
                    ->date('d/m/Y')
                    ->description(fn (UploadedDocument $record): ?string => $record->internal_expiry_name)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label('Approvato il')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Caricato')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Societa')
                    ->options(fn (): array => User::query()
                        ->where('role', 'company')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $companyId = $data['value'] ?? null;

                        if (blank($companyId)) {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($companyId): void {
                            $query
                                ->where(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', User::class)
                                        ->where('documentable_id', $companyId);
                                })
                                ->orWhere(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', Employee::class)
                                        ->whereIn('documentable_id', Employee::query()
                                            ->select('id')
                                            ->where('user_id', $companyId));
                                })
                                ->orWhere(function (Builder $query) use ($companyId): void {
                                    $query
                                        ->where('documentable_type', Vehicle::class)
                                        ->whereIn('documentable_id', Vehicle::query()
                                            ->select('id')
                                            ->where('user_id', $companyId));
                                });
                        });
                    }),
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending' => 'In attesa',
                        'approved' => 'Approvato',
                        'rejected' => 'Respinto',
                    ]),
                Filter::make('created_at')
                    ->label('Data caricamento')
                    ->schema([
                        DatePicker::make('uploaded_from')
                            ->label('Caricato dal'),
                        DatePicker::make('uploaded_until')
                            ->label('Caricato al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['uploaded_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['uploaded_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
                Filter::make('expiry_date')
                    ->label('Scadenza')
                    ->schema([
                        DatePicker::make('expires_from')
                            ->label('Scade dal'),
                        DatePicker::make('expires_until')
                            ->label('Scade al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['expires_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expiry_date', '>=', $date))
                        ->when($data['expires_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('expiry_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Scarica')
                    ->tooltip('Scarica documento')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->iconButton()
                    ->color('gray')
                    ->visible(fn (UploadedDocument $record): bool => filled($record->file_path))
                    ->url(fn (UploadedDocument $record): string => route('admin.downloads.documents.show', $record))
                    ->openUrlInNewTab(),
                Action::make('review')
                    ->label('Revisiona')
                    ->tooltip('Revisiona documento')
                    ->icon(Heroicon::OutlinedEye)
                    ->iconButton()
                    ->color('primary')
                    ->modalHeading(fn (UploadedDocument $record): string => 'Revisione: '.static::documentName($record))
                    ->modalDescription(fn (UploadedDocument $record): string => static::documentableLabel($record))
                    ->modalWidth('7xl')
                    ->modalSubmitActionLabel('Salva decisione')
                    ->modalCancelActionLabel('Chiudi senza modifiche')
                    ->form([
                        Section::make('Dati documento')
                            ->schema([
                                TextInput::make('review_section')
                                    ->label('Sezione')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_template')
                                    ->label('Documento')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_owner')
                                    ->label('Societa / elemento')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_status')
                                    ->label('Stato attuale')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_uploaded_at')
                                    ->label('Caricato il')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('review_file_name')
                                    ->label('Nome file')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(3),
                            ])
                            ->columns(4),
                        Section::make('File caricato')
                            ->description('Apri o controlla il documento prima di salvare la decisione.')
                            ->schema([
                                Html::make(fn (UploadedDocument $record): HtmlString => static::filePreviewContent($record))
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Decisione')
                            ->schema([
                                Radio::make('decision')
                                    ->label('Esito revisione')
                                    ->options([
                                        'approve' => 'Approva documento',
                                        'reject' => 'Respingi documento',
                                    ])
                                    ->default('approve')
                                    ->inline()
                                    ->live()
                                    ->required()
                                    ->columnSpanFull(),
                                Toggle::make('has_expiry')
                                    ->label('Il documento ha scadenza')
                                    ->helperText('Se la data non e corretta, modificala e approva.')
                                    ->live()
                                    ->visible(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration()),
                                DatePicker::make('expiry_date')
                                    ->label('Scadenza')
                                    ->visible(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration() && (bool) $get('has_expiry'))
                                    ->required(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration() && (bool) $get('has_expiry')),
                                TextInput::make('internal_expiry_name')
                                    ->label('Requisito interno')
                                    ->helperText('Seconda scadenza opzionale dentro lo stesso documento, es. CQC.')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration()),
                                DatePicker::make('internal_expiry_date')
                                    ->label('Scadenza requisito')
                                    ->visible(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration())
                                    ->required(fn (Get $get, UploadedDocument $record): bool => $get('decision') === 'approve' && blank($record->subtemplate_id) && ! $record->isIntegration() && filled($get('internal_expiry_name'))),
                                Textarea::make('admin_notes')
                                    ->label('Note di rifiuto')
                                    ->helperText('Usa il respingimento solo se il file e errato, incompleto o non pertinente.')
                                    ->rows(4)
                                    ->required(fn (Get $get): bool => $get('decision') === 'reject')
                                    ->visible(fn (Get $get): bool => $get('decision') === 'reject')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->fillForm(fn (UploadedDocument $record): array => [
                        'decision' => $record->status === 'rejected' ? 'reject' : 'approve',
                        'has_expiry' => $record->has_expiry,
                        'expiry_date' => $record->expiry_date,
                        'internal_expiry_name' => $record->internal_expiry_name,
                        'internal_expiry_date' => $record->internal_expiry_date,
                        'admin_notes' => $record->admin_notes,
                        'review_section' => $record->template->section?->name,
                        'review_template' => static::documentName($record),
                        'review_owner' => static::documentableLabel($record),
                        'review_status' => match ($record->effectiveStatus()) {
                            'approved' => 'Approvato',
                            'missing' => 'Mancante',
                            'expired' => 'Scaduto',
                            'rejected' => 'Respinto',
                            default => 'In attesa',
                        },
                        'review_uploaded_at' => $record->created_at?->format('d/m/Y H:i'),
                        'review_file_name' => basename($record->file_path),
                    ])
                    ->action(function (UploadedDocument $record, array $data): void {
                        if (($data['decision'] ?? null) === 'approve') {
                            $hasExpiry = blank($record->subtemplate_id) && ! $record->isIntegration() && (bool) ($data['has_expiry'] ?? false);

                            $record->update([
                                'status' => 'approved',
                                'has_expiry' => $hasExpiry,
                                'expiry_date' => $hasExpiry ? ($data['expiry_date'] ?? null) : null,
                                'internal_expiry_name' => blank($record->subtemplate_id) && ! $record->isIntegration() && filled($data['internal_expiry_name'] ?? null) && filled($data['internal_expiry_date'] ?? null) ? $data['internal_expiry_name'] : null,
                                'internal_expiry_date' => blank($record->subtemplate_id) && ! $record->isIntegration() && filled($data['internal_expiry_name'] ?? null) && filled($data['internal_expiry_date'] ?? null) ? $data['internal_expiry_date'] : null,
                                'approved_at' => now(),
                                'admin_notes' => null,
                            ]);
                            static::syncAttachmentDecision($record, 'approved');

                            AuditLog::record('document.approved', $record->fresh(['template', 'subtemplate', 'documentable']), 'Documento approvato', [
                                'template' => $record->template->name,
                                'subtemplate' => $record->subtemplate?->name,
                                'expiry_date' => $data['expiry_date'] ?? null,
                                'internal_expiry_name' => $data['internal_expiry_name'] ?? null,
                                'internal_expiry_date' => $data['internal_expiry_date'] ?? null,
                            ]);

                            Notification::make()
                                ->title('Documento approvato')
                                ->body(static::documentName($record).' e stato approvato correttamente.')
                                ->success()
                                ->send();

                            return;
                        }

                        $record->update([
                            'status' => 'rejected',
                            'approved_at' => null,
                            'admin_notes' => $data['admin_notes'],
                        ]);
                        static::syncAttachmentDecision($record, 'rejected', $data['admin_notes']);

                        $record->loadMissing(['template', 'subtemplate', 'documentable']);
                        $company = $record->companyUser();

                        if (config('services.documents.rejection_mail_enabled') && $company?->email) {
                            try {
                                Mail::to($company->email)->send(new DocumentRejectedMail($record));
                            } catch (\Throwable $exception) {
                                Log::warning('Document rejection email failed.', [
                                    'document_id' => $record->id,
                                    'company_id' => $company->id,
                                    'company_email' => $company->email,
                                    'error' => $exception->getMessage(),
                                ]);

                                Notification::make()
                                    ->title('Documento respinto, ma email non inviata')
                                    ->body('Controlla la configurazione SMTP della casella email.')
                                    ->warning()
                                    ->send();
                            }
                        }

                        AuditLog::record('document.rejected', $record->fresh(['template', 'subtemplate', 'documentable']), 'Documento respinto', [
                            'template' => $record->template->name,
                            'subtemplate' => $record->subtemplate?->name,
                            'notes' => $data['admin_notes'],
                        ]);

                        Notification::make()
                            ->title('Documento respinto')
                            ->body(static::documentName($record).' e stato respinto correttamente.')
                            ->danger()
                            ->send();
                    }),
            ]);
    }

    protected static function documentableLabel(UploadedDocument $record): string
    {
        $documentable = $record->documentable;

        return match (true) {
            $documentable instanceof \App\Models\User => $documentable->name,
            $documentable instanceof \App\Models\Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof \App\Models\Vehicle => "{$documentable->plate} ({$documentable->capacity} posti)",
            default => 'Elemento eliminato',
        };
    }

    protected static function documentName(UploadedDocument $record): string
    {
        if ($record->isIntegration()) {
            return $record->template->name.' / Integrazione: '.($record->integration_name ?: 'senza nome');
        }

        return $record->subtemplate
            ? $record->template->name.' / '.$record->subtemplate->name
            : $record->template->name;
    }

    protected static function companyLabel(UploadedDocument $record): string
    {
        return $record->companyUser()?->name ?? 'Societa non disponibile';
    }

    protected static function elementDescription(UploadedDocument $record): ?string
    {
        $documentable = $record->documentable;

        return match (true) {
            $documentable instanceof \App\Models\Employee => 'Dipendente: '.trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof \App\Models\Vehicle => 'Veicolo: '.$documentable->plate.' ('.$documentable->capacity.' posti)',
            default => null,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentApprovals::route('/'),
        ];
    }

    protected static function historyContent(UploadedDocument $record): HtmlString
    {
        $record->loadMissing(['template.section', 'subtemplate', 'documentable']);
        $logs = AuditLog::query()
            ->where('auditable_type', UploadedDocument::class)
            ->where('auditable_id', $record->id)
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();

        $logRows = $logs->map(fn (AuditLog $log): string => '<li><strong>'.e($log->description).'</strong><span>'.e($log->user?->name ?? 'Sistema').' - '.e($log->created_at->format('d/m/Y H:i')).'</span></li>')->join('');

        return new HtmlString('
            <div class="space-y-5">
                <div>
                    <h3 class="text-sm font-semibold">Documento corrente</h3>
                    <p class="text-sm text-gray-600">'.e(static::documentName($record)).' - '.e(static::documentableLabel($record)).'</p>
                    <p class="text-sm"><a href="'.e($record->file_url).'" target="_blank">Apri file corrente</a></p>
                </div>
                <div>
                    <h3 class="text-sm font-semibold">Attivita collegate</h3>
                    <ul class="mt-2 space-y-2">'.($logRows ?: '<li class="text-sm text-gray-600">Nessuna attivita registrata.</li>').'</ul>
                </div>
            </div>
        ');
    }

    protected static function filePreviewContent(UploadedDocument $record): HtmlString
    {
        $record->loadMissing('attachments');

        $fileUrl = e($record->file_url);
        $fileName = e(basename($record->file_path));
        $extension = strtolower(pathinfo($record->file_path, PATHINFO_EXTENSION));

        $preview = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) => '
                <div style="overflow:hidden;border:1px solid rgba(148,163,184,.35);border-radius:12px;background:#111827;">
                    <img src="'.$fileUrl.'" alt="Anteprima documento" style="display:block;width:100%;max-height:68vh;object-fit:contain;background:#111827;">
                </div>',
            $extension === 'pdf' => '
                <iframe src="'.$fileUrl.'" title="Anteprima documento" style="display:block;width:100%;height:68vh;border:1px solid rgba(148,163,184,.35);border-radius:12px;background:#fff;"></iframe>',
            default => '
                <div style="min-height:260px;display:grid;place-items:center;border:1px dashed rgba(148,163,184,.55);border-radius:12px;background:rgba(148,163,184,.08);padding:28px;text-align:center;">
                    <div style="max-width:420px;">
                        <p style="margin:0;font-size:14px;font-weight:700;">Anteprima non disponibile per questo formato.</p>
                        <p style="margin:6px 0 0;font-size:13px;color:#64748b;">Apri il file in una nuova scheda per revisionarlo.</p>
                    </div>
                </div>',
        };

        $attachments = $record->attachments
            ->map(function (UploadedDocument $attachment, int $index): string {
                $name = e($attachment->integration_name ?: basename($attachment->file_path));
                $url = e($attachment->file_url);

                return '
                    <a href="'.$url.'" target="_blank" rel="noreferrer" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid rgba(148,163,184,.25);border-radius:10px;background:#fff;color:#0f172a;text-decoration:none;">
                        <span style="font-size:13px;font-weight:600;">Allegato '.($index + 2).': '.$name.'</span>
                        <span style="font-size:12px;color:#0f766e;font-weight:700;">Apri</span>
                    </a>';
            })
            ->join('');

        return new HtmlString('
            <div style="display:grid;gap:14px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 14px;border:1px solid rgba(148,163,184,.25);border-radius:12px;background:rgba(148,163,184,.08);">
                    <div>
                        <p style="margin:0;font-size:13px;font-weight:700;">'.$fileName.'</p>
                        <p style="margin:3px 0 0;font-size:12px;color:#64748b;">Formato: '.e(strtoupper($extension ?: 'file')).'</p>
                    </div>
                    <a href="'.$fileUrl.'" target="_blank" rel="noreferrer" style="font-size:13px;font-weight:700;color:#0f766e;text-decoration:none;">
                        Apri in nuova scheda
                    </a>
                </div>
                '.($attachments !== '' ? '
                    <div style="display:grid;gap:10px;">
                        <p style="margin:0;font-size:12px;font-weight:700;color:#475569;">File aggiuntivi del sottodocumento</p>
                        '.$attachments.'
                    </div>' : '').'
                '.$preview.'
            </div>
        ');
    }

    protected static function syncAttachmentDecision(UploadedDocument $record, string $status, ?string $notes = null): void
    {
        if (blank($record->subtemplate_id)) {
            return;
        }

        $record->attachments()->update([
            'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'admin_notes' => $status === 'rejected' ? $notes : null,
        ]);
    }
}
