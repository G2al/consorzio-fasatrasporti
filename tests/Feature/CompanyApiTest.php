<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentCategory;
use App\Models\DocumentExemption;
use App\Models\DocumentSubtemplate;
use App\Models\DocumentTemplate;
use App\Models\EntityDeletionRequest;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_can_register_list_templates_and_upload_employee_document(): void
    {
        $this->seed();
        Storage::fake('public');

        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Trasporti Demo SRL',
            'responsible_name' => 'Mario Rossi',
            'responsible_phone' => '+393201887833',
            'vat_number' => '12345678901',
            'email' => 'demo@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $registerResponse
            ->assertJsonPath('user.approval_status', 'pending')
            ->assertJsonMissingPath('token');

        $this->postJson('/api/login', [
            'email' => 'demo@example.com',
            'password' => 'Password1',
        ])->assertUnprocessable();

        $token = $this->approveAndLogin('demo@example.com');

        $this->withToken($token)
            ->getJson('/api/sections')
            ->assertOk()
            ->assertJsonCount(3, 'sections');

        $employeeId = $this->withToken($token)
            ->postJson('/api/employees', [
                'first_name' => 'Luca',
                'last_name' => 'Bianchi',
                'phone' => '+393201887833',
            ])
            ->assertCreated()
            ->json('employee.id');

        $documentsResponse = $this->withToken($token)
            ->getJson("/api/employees/{$employeeId}/documents")
            ->assertOk()
            ->assertJsonCount(8, 'documents');

        $templateId = $documentsResponse->json('documents.0.template.id');
        $exemptionTemplateId = $documentsResponse->json('documents.1.template.id');

        $this->withToken($token)
            ->postJson('/api/document-exemptions', [
                'template_id' => $exemptionTemplateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'requested_reason' => 'Non applicabile a questo dipendente.',
            ])
            ->assertCreated()
            ->assertJsonPath('exemption.status', 'pending');

        $this->withToken($token)
            ->getJson("/api/employees/{$employeeId}/documents")
            ->assertOk()
            ->assertJsonPath('documents.1.status', 'exemption_pending');

        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'exemption_pending');

        DocumentExemption::query()
            ->where('template_id', $exemptionTemplateId)
            ->firstOrFail()
            ->update([
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);

        $this->withToken($token)
            ->getJson("/api/employees/{$employeeId}/documents")
            ->assertOk()
            ->assertJsonCount(7, 'documents');

        $exemptionNotificationId = $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'exemption_approved')
            ->json('notifications.0.id');

        $this->withToken($token)
            ->deleteJson("/api/notifications/{$exemptionNotificationId}")
            ->assertOk();

        $uploadResponse = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'has_expiry' => '1',
                'expiry_date' => '2030-01-01',
                'internal_expiry_name' => 'CQC',
                'internal_expiry_date' => '2030-06-01',
                'file' => UploadedFile::fake()->create('documento.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('document.has_expiry', true)
            ->assertJsonPath('document.expiry_date', '2030-01-01')
            ->assertJsonPath('document.internal_expiry_name', 'CQC')
            ->assertJsonPath('document.internal_expiry_date', '2030-06-01');

        $this->withHeaders(['Accept' => 'application/json'])
            ->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'has_expiry' => '0',
                'file' => UploadedFile::fake()->create('foto.jpg', 24, 'image/jpeg'),
            ])
            ->assertUnprocessable();

        Storage::disk('public')->assertExists($uploadResponse->json('document.file_path'));
        $firstPath = $uploadResponse->json('document.file_path');

        $secondUploadResponse = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'has_expiry' => '0',
                'file' => UploadedFile::fake()->create('documento-nuovo.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('document.has_expiry', false)
            ->assertJsonPath('document.expiry_date', null);

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondUploadResponse->json('document.file_path'));

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.uploaded', 0);

        $this->withToken($token)
            ->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('employees.0.documents_count', 1)
            ->assertJsonPath('employees.0.approved_documents_count', 0)
            ->assertJsonPath('employees.0.pending_documents_count', 1);

        $document = UploadedDocument::query()->findOrFail($secondUploadResponse->json('document.id'));
        $document->update([
            'status' => 'approved',
            'has_expiry' => true,
            'expiry_date' => '2030-01-01',
            'internal_expiry_name' => 'CQC',
            'internal_expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertNotNull($document->fresh()->approved_at);
        $this->withToken($token)
            ->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('employees.0.documents_count', 1)
            ->assertJsonPath('employees.0.approved_documents_count', 1)
            ->assertJsonPath('employees.0.pending_documents_count', 0);

        $notificationsResponse = $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.type', 'expiring');

        $notificationId = $notificationsResponse->json('notifications.0.id');

        $this->withToken($token)
            ->deleteJson("/api/notifications/{$notificationId}")
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications');

        $this->assertDatabaseHas('company_notification_dismissals', [
            'notification_key' => $notificationId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.uploaded',
        ]);
    }

    public function test_admin_user_cannot_login_to_company_api(): void
    {
        $this->seed();

        $this->postJson('/api/login', [
            'email' => 'admin@admin.com',
            'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_bulk_upload_endpoint_is_not_available(): void
    {
        $this->seed();

        $this->postJson('/api/register', [
            'name' => 'Bulk Trasporti SRL',
            'responsible_name' => 'Anna Verdi',
            'vat_number' => '98765432109',
            'email' => 'bulk@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('bulk@example.com');

        $this->withToken($token)
            ->postJson('/api/documents/bulk', [
                'documentable_type' => 'company',
                'documents' => [],
            ])
            ->assertNotFound();
    }

    public function test_expired_approved_document_returns_to_upload_without_deleting_old_file(): void
    {
        $this->seed();
        Storage::fake('public');

        $this->postJson('/api/register', [
            'name' => 'Scadenza Demo SRL',
            'responsible_name' => 'Mario Rossi',
            'vat_number' => '11122233344',
            'email' => 'scadenza@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('scadenza@example.com');
        $company = User::query()->where('email', 'scadenza@example.com')->firstOrFail();
        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/scadenza/vecchio.pdf', 'old');

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/scadenza/vecchio.pdf',
            'status' => 'approved',
            'has_expiry' => true,
            'expiry_date' => now()->subDay()->toDateString(),
            'approved_at' => now()->subMonth(),
        ]);

        $documents = $this->withToken($token)
            ->getJson('/api/company/documents')
            ->assertOk()
            ->json('documents');
        $expiredDocument = collect($documents)
            ->firstWhere('template.id', $template->id);

        $this->assertSame('expired', $expiredDocument['status']);
        $this->assertTrue($expiredDocument['uploaded_document']['is_expired']);

        $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $template->id,
                'documentable_type' => 'company',
                'has_expiry' => '0',
                'file' => UploadedFile::fake()->create('nuovo.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending');

        Storage::disk('public')->assertExists('uploaded-documents/scadenza/vecchio.pdf');
        $this->assertSame(2, $company->documents()->where('template_id', $template->id)->count());
    }

    public function test_rejected_document_is_exposed_as_missing_and_counted_as_missing(): void
    {
        $this->seed();
        Storage::fake('public');

        $this->postJson('/api/register', [
            'name' => 'Respinto Demo SRL',
            'responsible_name' => 'Mario Rossi',
            'vat_number' => '12121212121',
            'email' => 'respinto@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('respinto@example.com');
        $company = User::query()->where('email', 'respinto@example.com')->firstOrFail();
        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/respinto/documento.pdf', 'rejected');

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/respinto/documento.pdf',
            'status' => 'rejected',
            'has_expiry' => false,
            'admin_notes' => 'Documento non conforme.',
        ]);

        $documents = $this->withToken($token)
            ->getJson('/api/company/documents')
            ->assertOk()
            ->json('documents');

        $document = collect($documents)
            ->firstWhere('template.id', $template->id);

        $this->assertSame('missing', $document['status']);
        $this->assertSame('missing', $document['uploaded_document']['effective_status']);

        $dashboard = $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.rejected', 0)
            ->assertJsonPath('summary.uploaded', 0);

        $this->assertGreaterThan(0, $dashboard->json('summary.missing'));
    }

    public function test_company_can_upload_multiple_integrations_for_approved_document(): void
    {
        $this->seed();
        Storage::fake('public');

        $this->postJson('/api/register', [
            'name' => 'Integrazioni Demo SRL',
            'responsible_name' => 'Mario Rossi',
            'vat_number' => '22233344455',
            'email' => 'integrazioni@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('integrazioni@example.com');
        $company = User::query()->where('email', 'integrazioni@example.com')->firstOrFail();
        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();
        $category = DocumentCategory::query()->create([
            'section_id' => $template->section_id,
            'name' => 'Sicurezza',
            'sort_order' => 1,
        ]);
        $template->update(['category_id' => $category->id]);

        Storage::disk('public')->put('uploaded-documents/integrazioni/padre.pdf', 'parent');

        $parent = $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/integrazioni/padre.pdf',
            'status' => 'approved',
            'has_expiry' => false,
            'approved_at' => now(),
        ]);

        $this->withToken($token)
            ->post('/api/document-integrations', [
                'template_id' => $template->id,
                'documentable_type' => 'company',
                'integration_notes' => 'Documenti integrativi richiesti.',
                'files' => [
                    UploadedFile::fake()->create('integrazione-a.pdf', 24, 'application/pdf'),
                    UploadedFile::fake()->create('integrazione-b.pdf', 24, 'application/pdf'),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.status', 'pending')
            ->assertJsonPath('documents.0.parent_uploaded_document_id', $parent->id)
            ->assertJsonPath('documents.0.is_integration', true)
            ->assertJsonPath('documents.0.has_expiry', false);

        $this->assertSame('approved', $parent->fresh()->status);
        $this->assertSame(2, UploadedDocument::query()
            ->where('parent_uploaded_document_id', $parent->id)
            ->where('status', 'pending')
            ->count());

        $documents = $this->withToken($token)
            ->getJson('/api/company/documents')
            ->assertOk()
            ->json('documents');
        $document = collect($documents)->firstWhere('template.id', $template->id);

        $this->assertSame('Sicurezza', $document['template']['category']['name']);
        $this->assertCount(2, $document['uploaded_document']['integrations']);
    }

    public function test_company_can_upload_multiple_files_for_a_subdocument_with_single_status(): void
    {
        $this->seed();
        Storage::fake('public');

        $this->postJson('/api/register', [
            'name' => 'Subdocumenti Demo SRL',
            'responsible_name' => 'Mario Rossi',
            'responsible_phone' => '+393201887833',
            'vat_number' => '33344455566',
            'email' => 'subdocumenti@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('subdocumenti@example.com');

        $employeeId = $this->withToken($token)
            ->postJson('/api/employees', [
                'first_name' => 'Luca',
                'last_name' => 'Bianchi',
                'phone' => '+393201887833',
            ])
            ->assertCreated()
            ->json('employee.id');

        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'dipendenti'))
            ->firstOrFail();

        $subtemplate = DocumentSubtemplate::query()->create([
            'template_id' => $template->id,
            'name' => 'Allegati formativi',
            'description' => 'Carica piu attestati nello stesso sottodocumento.',
            'sort_order' => 1,
        ]);

        $response = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $template->id,
                'subtemplate_id' => $subtemplate->id,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'has_expiry' => '0',
                'files' => [
                    UploadedFile::fake()->create('attestato-a.pdf', 24, 'application/pdf'),
                    UploadedFile::fake()->create('attestato-b.pdf', 24, 'application/pdf'),
                    UploadedFile::fake()->create('attestato-c.pdf', 24, 'application/pdf'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('document.subtemplate_id', $subtemplate->id)
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonCount(2, 'document.attachments');

        $rootId = $response->json('document.id');

        $this->assertDatabaseHas('uploaded_documents', [
            'id' => $rootId,
            'subtemplate_id' => $subtemplate->id,
            'parent_uploaded_document_id' => null,
            'status' => 'pending',
        ]);

        $this->assertSame(2, UploadedDocument::query()
            ->where('parent_uploaded_document_id', $rootId)
            ->where('subtemplate_id', $subtemplate->id)
            ->count());

        $documents = $this->withToken($token)
            ->getJson("/api/employees/{$employeeId}/documents")
            ->assertOk()
            ->json('documents');

        $document = collect($documents)
            ->firstWhere('template.id', $template->id);
        $subdocument = collect($document['subdocuments'])
            ->firstWhere('subtemplate.id', $subtemplate->id);

        $this->assertSame('pending', $subdocument['status']);
        $this->assertSame($rootId, $subdocument['uploaded_document']['id']);
        $this->assertCount(2, $subdocument['uploaded_document']['attachments']);
    }


    public function test_company_can_update_profile_and_password(): void
    {
        $this->seed();

        $this->postJson('/api/register', [
            'name' => 'Profilo SRL',
            'responsible_name' => 'Lara Neri',
            'vat_number' => '11122233344',
            'email' => 'profilo@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('profilo@example.com');

        $this->withToken($token)
            ->putJson('/api/profile', [
                'name' => 'Profilo Aggiornato SRL',
                'responsible_name' => 'Lara Bianchi',
                'responsible_phone' => '+393209998877',
                'vat_number' => '44433322211',
                'email' => 'profilo-new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Profilo Aggiornato SRL')
            ->assertJsonPath('user.email', 'profilo-new@example.com');

        $this->withToken($token)
            ->putJson('/api/profile/password', [
                'current_password' => 'Password1',
                'password' => 'NuovaPass1',
                'password_confirmation' => 'NuovaPass1',
            ])
            ->assertOk();

        $this->assertSame(2, AuditLog::query()
            ->whereIn('action', ['company.profile_updated', 'company.password_updated'])
            ->count());
    }

    public function test_company_can_request_employee_and_vehicle_deletion_with_notifications(): void
    {
        $this->seed();

        $this->postJson('/api/register', [
            'name' => 'Eliminazioni SRL',
            'responsible_name' => 'Lara Neri',
            'vat_number' => '77788899900',
            'email' => 'eliminazioni@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('eliminazioni@example.com');

        $employeeId = $this->withToken($token)
            ->postJson('/api/employees', [
                'first_name' => 'Marco',
                'last_name' => 'Test',
                'phone' => '+393201234567',
            ])
            ->assertCreated()
            ->json('employee.id');

        $vehicleId = $this->withToken($token)
            ->postJson('/api/vehicles', [
                'plate' => 'AB123CD',
                'capacity' => 7,
            ])
            ->assertCreated()
            ->json('vehicle.id');

        $employeeRequestId = $this->withToken($token)
            ->postJson("/api/employees/{$employeeId}/deletion-requests", [
                'requested_reason' => 'Dipendente non piu attivo.',
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.type', 'employee')
            ->json('request.id');

        $this->withToken($token)
            ->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('employees.0.deletion_request.id', $employeeRequestId)
            ->assertJsonPath('employees.0.deletion_request.status', 'pending');

        $vehicleRequestId = $this->withToken($token)
            ->postJson("/api/vehicles/{$vehicleId}/deletion-requests", [
                'requested_reason' => 'Veicolo sostituito.',
            ])
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.type', 'vehicle')
            ->json('request.id');

        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.type', 'entity_deletion_pending')
            ->assertJsonPath('unread_count', 2);

        $employeeRequest = EntityDeletionRequest::query()->findOrFail($employeeRequestId);
        $employeeRequest->approve();

        $vehicleRequest = EntityDeletionRequest::query()->findOrFail($vehicleRequestId);
        $vehicleRequest->reject('Mantieni questo veicolo in archivio.');

        $this->withToken($token)
            ->getJson('/api/vehicles')
            ->assertOk()
            ->assertJsonPath('vehicles.0.deletion_request.status', 'rejected');

        $notifications = $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('notifications');

        $types = collect($notifications)->pluck('type')->all();

        $this->assertContains('entity_deletion_approved', $types);
        $this->assertContains('entity_deletion_rejected', $types);
        $this->assertDatabaseMissing('employees', ['id' => $employeeId]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicleId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'entity_deletion_request.requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'entity_deletion_request.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'entity_deletion_request.rejected']);
    }

    public function test_company_registration_sends_telegram_notification_when_enabled(): void
    {
        $this->seed();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.registration_chat_id' => '-5262387162',
            'services.telegram.registration_enabled' => true,
            'services.telegram.allow_during_tests' => true,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/api/register', [
            'name' => 'Telegram Trasporti SRL',
            'responsible_name' => 'Giovanni Verdi',
            'responsible_phone' => '+393201887833',
            'vat_number' => '55566677788',
            'email' => 'telegram@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        Http::assertSent(function ($request): bool {
            $text = (string) $request['text'];

            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $request['chat_id'] === '-5262387162'
                && str_contains($text, '🆕 <b>Nuova registrazione</b>')
                && str_contains($text, '🏢 <b>Società:</b> Telegram Trasporti SRL')
                && str_contains($text, '+393201887833')
                && str_contains($text, 'telegram@example.com');
        });
    }

    public function test_document_upload_sends_telegram_notification_when_enabled(): void
    {
        $this->seed();
        Storage::fake('public');

        config([
            'services.telegram.document_bot_token' => 'document-token',
            'services.telegram.document_chat_id' => '-5224166839',
            'services.telegram.document_enabled' => true,
            'services.telegram.allow_during_tests' => true,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/api/register', [
            'name' => 'Documento Bot SRL',
            'responsible_name' => 'Mario Rossi',
            'vat_number' => '55544433322',
            'email' => 'document-bot@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $this->approveAndLogin('document-bot@example.com');
        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $template->id,
                'documentable_type' => 'company',
                'has_expiry' => '0',
                'file' => UploadedFile::fake()->create('documento.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated();

        Http::assertSent(function ($request): bool {
            $text = (string) $request['text'];

            return $request->url() === 'https://api.telegram.org/botdocument-token/sendMessage'
                && $request['chat_id'] === '-5224166839'
                && str_contains($text, 'Nuovo documento caricato')
                && str_contains($text, 'Documento Bot SRL')
                && str_contains($text, 'in attesa di approvazione');
        });
    }

    private function approveAndLogin(string $email): string
    {
        User::query()
            ->where('email', $email)
            ->update([
                'approval_status' => 'approved',
                'approved_at' => now(),
            ]);

        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'Password1',
        ])->assertOk()->json('token');
    }
}
