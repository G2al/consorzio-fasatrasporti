<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyNotificationDismissal;
use App\Models\DocumentExemption;
use App\Models\Employee;
use App\Models\Section;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CompanyDataController extends Controller
{
    public function sections(): JsonResponse
    {
        $sections = Section::query()
            ->with(['documentTemplates' => fn ($query) => $query->with('subtemplates')->orderBy('name')])
            ->orderBy('id')
            ->get()
            ->map(fn (Section $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'slug' => $section->slug,
                'templates' => $section->documentTemplates->map(fn ($template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'is_required' => $template->is_required,
                    'description' => $template->description,
                    'subtemplates' => $template->subtemplates->map(fn ($subtemplate): array => [
                        'id' => $subtemplate->id,
                        'name' => $subtemplate->name,
                        'is_required' => $subtemplate->is_required,
                        'description' => $subtemplate->description,
                    ])->values(),
                ])->values(),
            ]);

        return response()->json([
            'sections' => $sections,
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        /** @var User $company */
        $company = $request->user();

        $employeeCount = $company->employees()->count();
        $vehicleCount = $company->vehicles()->count();
        $companySection = Section::query()
            ->where('slug', 'societa')
            ->with(['documentTemplates' => fn ($query) => $query->orderBy('name')])
            ->first();
        $requiredCount = $companySection?->documentTemplates->count() ?? 0;
        $approvedExemptionsCount = $company->documentExemptions()
            ->where('status', 'approved')
            ->whereNull('subtemplate_id')
            ->whereHas('template.section', fn ($query) => $query->where('slug', 'societa'))
            ->count();
        $requiredCount = max($requiredCount - $approvedExemptionsCount, 0);

        $documents = $company->documents()
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->whereNull('parent_uploaded_document_id')
            ->whereHas('template.section', fn ($query) => $query->where('slug', 'societa'))
            ->latest('updated_at')
            ->get();

        $documentsByTemplate = $documents
            ->whereNull('subtemplate_id')
            ->groupBy('template_id');
        $exemptTemplateIds = $company->documentExemptions()
            ->where('status', 'approved')
            ->whereNull('subtemplate_id')
            ->whereHas('template.section', fn ($query) => $query->where('slug', 'societa'))
            ->pluck('template_id')
            ->all();
        $documentRows = ($companySection?->documentTemplates ?? collect())
            ->reject(fn ($template): bool => in_array($template->id, $exemptTemplateIds, true))
            ->map(function ($template) use ($documentsByTemplate): array {
                $document = $this->currentDocument($documentsByTemplate->get($template->id));

                return [
                    'document' => $document,
                    'status' => $document?->effectiveStatus() ?? 'missing',
                ];
            });

        $approved = $documentRows->where('status', 'approved')->count();
        $pending = $documentRows->where('status', 'pending')->count();
        $rejected = $documentRows->where('status', 'rejected')->count();
        $expired = $documentRows->where('status', 'expired')->count();
        $uploaded = $documentRows->filter(fn (array $row): bool => $row['document'] instanceof UploadedDocument && $row['status'] !== 'expired')->count();
        $missing = $documentRows->whereIn('status', ['missing', 'expired'])->count();
        $today = now()->startOfDay();
        $expiryLimit = now()->addDays(60)->endOfDay();

        $expiringDocuments = $documents
            ->flatMap(fn (UploadedDocument $document): array => $this->documentDeadlinePayloads($document, $expiryLimit, $today, includeExpired: false))
            ->sortBy('expiry_date')
            ->values();

        return response()->json([
            'summary' => [
                'required' => $requiredCount,
                'uploaded' => $uploaded,
                'missing' => $missing,
                'approved' => $approved,
                'pending' => $pending,
                'rejected' => $rejected,
                'expired' => $expired,
                'expiring' => $expiringDocuments->count(),
                'employees' => $employeeCount,
                'vehicles' => $vehicleCount,
            ],
            'expiring_documents' => $expiringDocuments
                ->take(8)
                ->values(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        /** @var User $company */
        $company = $request->user();

        $notifications = $this->visibleNotifications($company)
            ->map(function (array $item): array {
                unset($item['priority'], $item['sort_date']);

                return $item;
            });

        return response()->json([
            'unread_count' => $notifications->count(),
            'notifications' => $notifications,
        ]);
    }

    public function dismissNotification(Request $request, string $notification): JsonResponse
    {
        /** @var User $company */
        $company = $request->user();

        abort_if(strlen($notification) > 190, 422, 'Notifica non valida.');

        CompanyNotificationDismissal::query()->firstOrCreate([
            'user_id' => $company->id,
            'notification_key' => $notification,
        ]);

        return response()->json([
            'message' => 'Notifica eliminata.',
        ]);
    }

    public function dismissAllNotifications(Request $request): JsonResponse
    {
        /** @var User $company */
        $company = $request->user();

        $now = now();
        $rows = $this->visibleNotifications($company)
            ->map(fn (array $notification): array => [
                'user_id' => $company->id,
                'notification_key' => $notification['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            CompanyNotificationDismissal::query()->upsert(
                $rows,
                ['user_id', 'notification_key'],
                ['updated_at'],
            );
        }

        return response()->json([
            'message' => 'Notifiche eliminate.',
            'dismissed_count' => count($rows),
        ]);
    }

    private function buildNotifications(User $company): Collection
    {
        $today = now()->startOfDay();
        $expiryLimit = now()->addDays(30)->endOfDay();

        $documents = $this->companyDocumentsQuery($company)
            ->with(['template.section', 'subtemplate', 'documentable'])
            ->get();

        $exemptions = $this->companyExemptionsQuery($company)
            ->with(['template.section', 'subtemplate', 'exemptable'])
            ->get();

        $rejected = $documents
            ->where('status', 'rejected')
            ->sortByDesc('updated_at')
            ->take(6)
            ->map(fn (UploadedDocument $document): array => $this->notificationPayload(
                $document,
                'rejected',
                'Documento respinto',
                $this->documentName($document).' - '.$this->documentableLabel($document),
                $document->admin_notes ?: 'Controlla le note e carica una nuova versione.',
                $document->updated_at?->toIso8601String(),
                3,
                true,
            ));

        $expiring = $documents
            ->flatMap(fn (UploadedDocument $document): array => $this->documentDeadlinePayloads($document, $expiryLimit, $today))
            ->sortBy('expiry_date')
            ->take(6)
            ->map(fn (array $deadline): array => $this->deadlineNotificationPayload($deadline));

        $approved = $documents
            ->filter(fn (UploadedDocument $document): bool => $document->status === 'approved'
                && filled($document->approved_at)
                && $document->approved_at->greaterThanOrEqualTo(now()->subDays(14))
                && (blank($document->expiry_date) || $document->expiry_date->greaterThan($expiryLimit))
                && (blank($document->internal_expiry_date) || $document->internal_expiry_date->greaterThan($expiryLimit)))
            ->sortByDesc('approved_at')
            ->take(5)
            ->map(fn (UploadedDocument $document): array => $this->notificationPayload(
                $document,
                'approved',
                'Documento approvato',
                $this->documentName($document).' - '.$this->documentableLabel($document),
                'Approvato il '.$document->approved_at->format('d/m/Y H:i'),
                $document->approved_at?->toIso8601String(),
                1,
                false,
            ));

        $exemptionNotifications = $exemptions
            ->filter(fn (DocumentExemption $exemption): bool => in_array($exemption->status, ['pending', 'approved', 'rejected'], true))
            ->sortByDesc('updated_at')
            ->take(8)
            ->map(fn (DocumentExemption $exemption): array => $this->exemptionNotificationPayload($exemption));

        return $rejected
            ->concat($expiring)
            ->concat($approved)
            ->concat($exemptionNotifications)
            ->sortByDesc(fn (array $item): string => sprintf('%02d-%s', $item['priority'], $item['sort_date'] ?? ''))
            ->take(12)
            ->values();
    }

    private function visibleNotifications(User $company): Collection
    {
        $dismissed = $company->notificationDismissals()
            ->pluck('notification_key')
            ->all();

        return $this->buildNotifications($company)
            ->reject(fn (array $item): bool => in_array($item['id'], $dismissed, true))
            ->take(12)
            ->values();
    }

    private function companyDocumentsQuery(User $company)
    {
        return UploadedDocument::query()
            ->where(function ($query) use ($company): void {
                $query
                    ->where(function ($query) use ($company): void {
                        $query
                            ->where('documentable_type', User::class)
                            ->where('documentable_id', $company->id);
                    })
                    ->orWhere(function ($query) use ($company): void {
                        $query
                            ->where('documentable_type', Employee::class)
                            ->whereIn('documentable_id', Employee::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    })
                    ->orWhere(function ($query) use ($company): void {
                        $query
                            ->where('documentable_type', Vehicle::class)
                            ->whereIn('documentable_id', Vehicle::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    });
            });
    }

    private function companyExemptionsQuery(User $company)
    {
        return DocumentExemption::query()
            ->where(function ($query) use ($company): void {
                $query
                    ->where(function ($query) use ($company): void {
                        $query
                            ->where('exemptable_type', User::class)
                            ->where('exemptable_id', $company->id);
                    })
                    ->orWhere(function ($query) use ($company): void {
                        $query
                            ->where('exemptable_type', Employee::class)
                            ->whereIn('exemptable_id', Employee::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    })
                    ->orWhere(function ($query) use ($company): void {
                        $query
                            ->where('exemptable_type', Vehicle::class)
                            ->whereIn('exemptable_id', Vehicle::query()
                                ->select('id')
                                ->where('user_id', $company->id));
                    });
            });
    }

    private function documentableLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof Vehicle => "{$documentable->plate} ({$documentable->capacity} posti)",
            default => 'Elemento eliminato',
        };
    }

    private function documentDeadlinePayloads(UploadedDocument $document, $expiryLimit, $today, bool $includeExpired = true): array
    {
        if ($document->status !== 'approved') {
            return [];
        }

        $deadlines = [];

        if (filled($document->expiry_date) && $document->expiry_date->lessThanOrEqualTo($expiryLimit) && ($includeExpired || $document->expiry_date->greaterThanOrEqualTo($today))) {
            $deadlines[] = $this->documentDeadlinePayload(
                $document,
                'document',
                'Scadenza documento',
                $document->expiry_date,
                $today,
            );
        }

        if (filled($document->internal_expiry_date) && $document->internal_expiry_date->lessThanOrEqualTo($expiryLimit) && ($includeExpired || $document->internal_expiry_date->greaterThanOrEqualTo($today))) {
            $deadlines[] = $this->documentDeadlinePayload(
                $document,
                'internal',
                $document->internal_expiry_name ?: 'Requisito interno',
                $document->internal_expiry_date,
                $today,
            );
        }

        return $deadlines;
    }

    private function documentDeadlinePayload(UploadedDocument $document, string $kind, string $label, $date, $today): array
    {
        return [
            'id' => $document->id.'-'.$kind,
            'document_id' => $document->id,
            'kind' => $kind,
            'label' => $label,
            'template' => $this->documentName($document),
            'section' => $document->template->section->name,
            'owner' => $this->documentableLabel($document),
            'expiry_date' => $date?->toDateString(),
            'days_remaining' => $date ? (int) $today->diffInDays($date->copy()->startOfDay(), false) : null,
            'target' => $this->notificationTarget($document),
        ];
    }

    private function deadlineNotificationPayload(array $deadline): array
    {
        $days = (int) $deadline['days_remaining'];
        $date = $deadline['expiry_date'];
        $body = $days < 0
            ? $deadline['label'].' scaduta il '.date('d/m/Y', strtotime($date))
            : $deadline['label'].' scade il '.date('d/m/Y', strtotime($date)).' ('.$days.' giorni)';
        $versionKey = substr(sha1($date.'|'.$deadline['kind'].'|'.$body), 0, 12);

        return [
            'id' => ($days < 0 ? 'expired' : 'expiring').'-'.$deadline['document_id'].'-'.$deadline['kind'].'-'.$versionKey,
            'type' => $days < 0 ? 'expired' : 'expiring',
            'title' => $days < 0 ? 'Scadenza superata' : 'Scadenza vicina',
            'subtitle' => $deadline['template'].' - '.$deadline['owner'],
            'body' => $body,
            'date' => $date,
            'target' => $deadline['target'],
            'is_urgent' => true,
            'priority' => 2,
            'sort_date' => $date,
        ];
    }

    private function exemptionLabel(DocumentExemption $exemption): string
    {
        $exemptable = $exemption->exemptable;

        return match (true) {
            $exemptable instanceof User => $exemptable->name,
            $exemptable instanceof Employee => trim("{$exemptable->first_name} {$exemptable->last_name}"),
            $exemptable instanceof Vehicle => "{$exemptable->plate} ({$exemptable->capacity} posti)",
            default => 'Elemento eliminato',
        };
    }

    private function exemptionNotificationPayload(DocumentExemption $exemption): array
    {
        [$type, $title, $body, $priority, $isUrgent] = match ($exemption->status) {
            'approved' => [
                'exemption_approved',
                'Esenzione approvata',
                'Il documento non e piu richiesto.',
                2,
                false,
            ],
            'rejected' => [
                'exemption_rejected',
                'Esenzione rifiutata',
                $exemption->admin_notes ?: 'La richiesta non e stata accettata. Carica il documento richiesto.',
                3,
                true,
            ],
            default => [
                'exemption_pending',
                'Esenzione in attesa',
                'La richiesta e in attesa di verifica da parte dell admin.',
                1,
                false,
            ],
        };

        $date = ($exemption->reviewed_at ?: $exemption->updated_at)?->toIso8601String();
        $versionKey = substr(sha1($date.'|'.$body.'|'.$exemption->status), 0, 12);

        return [
            'id' => $type.'-'.$exemption->id.'-'.$versionKey,
            'type' => $type,
            'title' => $title,
            'subtitle' => $this->exemptionName($exemption).' - '.$this->exemptionLabel($exemption),
            'body' => $body,
            'date' => $date,
            'target' => $this->exemptionNotificationTarget($exemption),
            'is_urgent' => $isUrgent,
            'priority' => $priority,
            'sort_date' => $date,
        ];
    }

    private function notificationPayload(
        UploadedDocument $document,
        string $type,
        string $title,
        string $subtitle,
        string $body,
        ?string $date,
        int $priority,
        bool $isUrgent,
    ): array {
        $versionKey = substr(sha1($date.'|'.$body.'|'.$document->updated_at?->toIso8601String()), 0, 12);

        return [
            'id' => $type.'-'.$document->id.'-'.$versionKey,
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'body' => $body,
            'date' => $date,
            'target' => $this->notificationTarget($document),
            'is_urgent' => $isUrgent,
            'priority' => $priority,
            'sort_date' => $date,
        ];
    }

    private function notificationTarget(UploadedDocument $document): string
    {
        return match ($document->documentable_type) {
            User::class => 'index.html',
            Employee::class => 'dipendenti.html',
            Vehicle::class => 'veicoli.html',
            default => 'index.html',
        };
    }

    private function exemptionNotificationTarget(DocumentExemption $exemption): string
    {
        return match ($exemption->exemptable_type) {
            User::class => 'index.html',
            Employee::class => 'dipendenti.html',
            Vehicle::class => 'veicoli.html',
            default => 'index.html',
        };
    }

    private function documentName(UploadedDocument $document): string
    {
        return $document->subtemplate
            ? $document->template->name.' / '.$document->subtemplate->name
            : $document->template->name;
    }

    private function exemptionName(DocumentExemption $exemption): string
    {
        return $exemption->subtemplate
            ? $exemption->template->name.' / '.$exemption->subtemplate->name
            : $exemption->template->name;
    }

    private function currentDocument(?Collection $documents): ?UploadedDocument
    {
        if (! $documents || $documents->isEmpty()) {
            return null;
        }

        return $documents->first(fn (UploadedDocument $document): bool => ! $document->isExpired())
            ?: $documents->first();
    }
}
