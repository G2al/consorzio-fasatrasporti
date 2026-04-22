<?php

namespace Tests\Feature;

use App\Notifications\DocumentStatusChanged;
use App\Models\AuditLog;
use App\Models\UploadedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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
            'vat_number' => '12345678901',
            'email' => 'demo@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $registerResponse->json('token');

        $this->withToken($token)
            ->getJson('/api/sections')
            ->assertOk()
            ->assertJsonCount(3, 'sections');

        $employeeId = $this->withToken($token)
            ->postJson('/api/employees', [
                'first_name' => 'Luca',
                'last_name' => 'Bianchi',
                'tax_code' => 'BNCLCU80A01H501X',
            ])
            ->assertCreated()
            ->json('employee.id');

        $documentsResponse = $this->withToken($token)
            ->getJson("/api/employees/{$employeeId}/documents")
            ->assertOk()
            ->assertJsonCount(8, 'documents');

        $templateId = $documentsResponse->json('documents.0.template.id');

        $uploadResponse = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'expiry_date' => '2030-01-01',
                'file' => UploadedFile::fake()->create('documento.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('document.expiry_date', null);

        Storage::disk('public')->assertExists($uploadResponse->json('document.file_path'));
        $firstPath = $uploadResponse->json('document.file_path');

        $secondUploadResponse = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'file' => UploadedFile::fake()->create('documento-nuovo.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonCount(1, 'document.versions');

        Storage::disk('public')->assertExists($firstPath);
        Storage::disk('public')->assertExists($secondUploadResponse->json('document.file_path'));
        $this->assertDatabaseHas('uploaded_document_versions', [
            'uploaded_document_id' => $secondUploadResponse->json('document.id'),
            'file_path' => $firstPath,
        ]);

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.uploaded', 1);

        Notification::fake();

        $document = UploadedDocument::query()->findOrFail($secondUploadResponse->json('document.id'));
        $document->update(['status' => 'approved', 'expiry_date' => '2030-01-01']);

        $this->assertNotNull($document->fresh()->approved_at);
        Notification::assertSentTo($document->fresh()->companyUser(), DocumentStatusChanged::class);
        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notifications.0.type', 'approved');

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

    public function test_company_can_upload_multiple_documents_together(): void
    {
        $this->seed();
        Storage::fake('public');

        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Bulk Trasporti SRL',
            'responsible_name' => 'Anna Verdi',
            'vat_number' => '98765432109',
            'email' => 'bulk@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $registerResponse->json('token');
        $documentsResponse = $this->withToken($token)
            ->getJson('/api/company/documents')
            ->assertOk();

        $templateIds = collect($documentsResponse->json('documents'))
            ->take(2)
            ->pluck('template.id')
            ->values();

        $response = $this->withToken($token)
            ->post('/api/documents/bulk', [
                'documentable_type' => 'company',
                'documents' => [
                    $templateIds[0] => UploadedFile::fake()->create('bilancio.pdf', 24, 'application/pdf'),
                    $templateIds[1] => UploadedFile::fake()->create('durc.pdf', 24, 'application/pdf'),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.status', 'pending')
            ->assertJsonPath('documents.1.status', 'pending');

        collect($response->json('documents'))
            ->each(fn (array $document) => Storage::disk('public')->assertExists($document['file_path']));

        $this->withToken($token)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('summary.uploaded', 2);
    }

    public function test_company_can_update_profile_and_password(): void
    {
        $this->seed();

        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Profilo SRL',
            'responsible_name' => 'Lara Neri',
            'vat_number' => '11122233344',
            'email' => 'profilo@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $token = $registerResponse->json('token');

        $this->withToken($token)
            ->putJson('/api/profile', [
                'name' => 'Profilo Aggiornato SRL',
                'responsible_name' => 'Lara Bianchi',
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
}
