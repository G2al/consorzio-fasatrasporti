<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyNotificationDismissal;
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
            ->with(['documentTemplates' => fn ($query) => $query->orderBy('name')])
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

        $templateCounts = Section::query()
            ->withCount('documentTemplates')
            ->get()
            ->pluck('document_templates_count', 'slug');

        $employeeCount = $company->employees()->count();
        $vehicleCount = $company->vehicles()->count();
        $requiredCount = (int) ($templateCounts->get('societa', 0)
            + ($employeeCount * $templateCounts->get('dipendenti', 0))
            + ($vehicleCount * $templateCounts->get('veicoli', 0)));

        $documents = $this->companyDocumentsQuery($company)
            ->with(['template.section', 'documentable'])
            ->get();

        $approved = $documents->where('status', 'approved')->count();
        $pending = $documents->where('status', 'pending')->count();
        $rejected = $documents->where('status', 'rejected')->count();
        $today = now()->startOfDay();
        $expiryLimit = now()->addDays(60)->endOfDay();

        $expiringDocuments = $documents
            ->filter(fn (UploadedDocument $document): bool => $document->status === 'approved'
                && filled($document->expiry_date)
                && $document->expiry_date->lessThanOrEqualTo($expiryLimit))
            ->sortBy('expiry_date')
            ->values();

        return response()->json([
            'summary' => [
                'required' => $requiredCount,
                'uploaded' => $documents->count(),
                'missing' => max($requiredCount - $documents->count(), 0),
                'approved' => $approved,
                'pending' => $pending,
                'rejected' => $rejected,
                'expiring' => $expiringDocuments->count(),
                'employees' => $employeeCount,
                'vehicles' => $vehicleCount,
            ],
            'expiring_documents' => $expiringDocuments
                ->take(8)
                ->map(fn (UploadedDocument $document): array => [
                    'id' => $document->id,
                    'template' => $document->template->name,
                    'section' => $document->template->section->name,
                    'owner' => $this->documentableLabel($document),
                    'expiry_date' => $document->expiry_date?->toDateString(),
                    'days_remaining' => $document->expiry_date
                        ? (int) $today->diffInDays($document->expiry_date->copy()->startOfDay(), false)
                        : null,
                ])
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
            'unread_count' => $notifications->where('is_urgent', true)->count(),
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
            ->with(['template.section', 'documentable'])
            ->get();

        $rejected = $documents
            ->where('status', 'rejected')
            ->sortByDesc('updated_at')
            ->take(6)
            ->map(fn (UploadedDocument $document): array => $this->notificationPayload(
                $document,
                'rejected',
                'Documento respinto',
                $document->template->name.' - '.$this->documentableLabel($document),
                $document->admin_notes ?: 'Controlla le note e carica una nuova versione.',
                $document->updated_at?->toIso8601String(),
                3,
                true,
            ));

        $expiring = $documents
            ->filter(fn (UploadedDocument $document): bool => $document->status === 'approved'
                && filled($document->expiry_date)
                && $document->expiry_date->lessThanOrEqualTo($expiryLimit))
            ->sortBy('expiry_date')
            ->take(6)
            ->map(function (UploadedDocument $document) use ($today): array {
                $days = (int) $today->diffInDays($document->expiry_date->copy()->startOfDay(), false);

                return $this->notificationPayload(
                    $document,
                    $days < 0 ? 'expired' : 'expiring',
                    $days < 0 ? 'Documento scaduto' : 'Documento in scadenza',
                    $document->template->name.' - '.$this->documentableLabel($document),
                    $days < 0
                        ? 'Scaduto il '.$document->expiry_date->format('d/m/Y')
                        : 'Scade il '.$document->expiry_date->format('d/m/Y').' ('.$days.' giorni)',
                    $document->expiry_date?->toDateString(),
                    2,
                    true,
                );
            });

        $approved = $documents
            ->filter(fn (UploadedDocument $document): bool => $document->status === 'approved'
                && filled($document->approved_at)
                && $document->approved_at->greaterThanOrEqualTo(now()->subDays(14))
                && (blank($document->expiry_date) || $document->expiry_date->greaterThan($expiryLimit)))
            ->sortByDesc('approved_at')
            ->take(5)
            ->map(fn (UploadedDocument $document): array => $this->notificationPayload(
                $document,
                'approved',
                'Documento approvato',
                $document->template->name.' - '.$this->documentableLabel($document),
                'Approvato il '.$document->approved_at->format('d/m/Y H:i'),
                $document->approved_at?->toIso8601String(),
                1,
                false,
            ));

        return $rejected
            ->concat($expiring)
            ->concat($approved)
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

    private function documentableLabel(UploadedDocument $document): string
    {
        $documentable = $document->documentable;

        return match (true) {
            $documentable instanceof User => $documentable->name,
            $documentable instanceof Employee => trim("{$documentable->first_name} {$documentable->last_name}"),
            $documentable instanceof Vehicle => "{$documentable->brand_model} ({$documentable->plate})",
            default => 'Elemento eliminato',
        };
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
}
