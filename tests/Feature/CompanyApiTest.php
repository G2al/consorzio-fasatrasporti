<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentExemption;
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

        $uploadResponse = $this->withToken($token)
            ->post('/api/documents', [
                'template_id' => $templateId,
                'documentable_type' => 'employee',
                'documentable_id' => $employeeId,
                'has_expiry' => '1',
                'expiry_date' => '2030-01-01',
                'file' => UploadedFile::fake()->create('documento.pdf', 24, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('document.has_expiry', true)
            ->assertJsonPath('document.expiry_date', '2030-01-01');

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
        $document->update(['status' => 'approved', 'expiry_date' => '2030-01-01']);

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
            ->assertJsonPath('notifications.0.type', 'approved');

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
