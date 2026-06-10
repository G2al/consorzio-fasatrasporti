<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CompanyCredentialsMailService;
use App\Services\MissingDocumentsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_dashboard(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_can_open_document_approval_resource(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get('/admin/document-approvals')
            ->assertOk();
    }

    public function test_document_rejection_mail_can_be_disabled(): void
    {
        config(['services.documents.rejection_mail_enabled' => false]);
        Mail::fake();

        $company = User::factory()->make([
            'email' => 'company@example.com',
        ]);

        if (config('services.documents.rejection_mail_enabled') && $company->email) {
            Mail::to($company->email)->send(new \App\Mail\DocumentRejectedMail(new UploadedDocument()));
        }

        Mail::assertNothingSent();
    }

    public function test_admin_can_open_document_exemptions_resource(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get('/admin/document-exemptions')
            ->assertOk();
    }

    public function test_admin_can_open_entity_deletion_requests_resource(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get('/admin/entity-deletion-requests')
            ->assertOk();
    }

    public function test_admin_can_open_audit_log_resource(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()
            ->create([
                'name' => 'Audit Demo SRL',
                'email' => 'audit@example.com',
                'password' => 'Password1',
                'role' => 'company',
            ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
            'action' => 'test.action',
            'description' => 'Azione di test',
            'metadata' => ['foo' => 'bar'],
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/audit-logs')
            ->assertOk();
    }

    public function test_admin_can_manage_backend_users(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        User::query()->create([
            'name' => 'Revisionatore Test',
            'email' => 'reviewer@example.com',
            'password' => 'Password1',
            'role' => 'reviewer',
            'approval_status' => 'approved',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/admin-users')
            ->assertOk()
            ->assertSee('Revisionatore Test');
    }

    public function test_reviewer_can_open_only_allowed_resources(): void
    {
        $this->seed();

        $reviewer = User::query()->create([
            'name' => 'Revisionatore',
            'email' => 'revisionatore@example.com',
            'password' => 'Password1',
            'role' => 'reviewer',
            'approval_status' => 'approved',
        ]);

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/document-approvals')
            ->assertOk();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/document-templates')
            ->assertOk();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/document-exemptions')
            ->assertOk();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/entity-deletion-requests')
            ->assertOk();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/users')
            ->assertOk();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/audit-logs')
            ->assertForbidden();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/sections')
            ->assertForbidden();

        $this->actingAs($reviewer, 'admin')
            ->get('/admin/admin-users')
            ->assertForbidden();
    }

    public function test_company_cannot_access_admin_panel(): void
    {
        $this->seed();

        $company = User::query()->create([
            'name' => 'Company Panel Block SRL',
            'email' => 'company-panel-block@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAs($company, 'admin')
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_download_documents_and_company_zip(): void
    {
        $this->seed();
        Storage::fake('public');

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Export Demo SRL',
            'email' => 'export@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/export-demo/durc.pdf', 'PDF test');

        $document = $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/export-demo/durc.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.documents.show', $document))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.companies.show', [$company, 'all']))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.companies.pdf', [$company, 'all']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $document->forceFill([
            'status' => 'approved',
            'approved_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.templates.show', $template))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.templates.pdf', $template))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        UploadedDocument::query()->whereKey($document->id)->delete();
    }

    public function test_template_pdf_shows_only_latest_approved_document_per_company_and_marks_it_expired(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Duplicato Test SRL',
            'email' => 'duplicato-test@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->where('name', 'DURC')
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/duplicato-test/old-durc.pdf',
            'status' => 'approved',
            'has_expiry' => true,
            'expiry_date' => now()->addDays(30),
            'approved_at' => now()->subDays(30),
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/duplicato-test/new-durc.pdf',
            'status' => 'approved',
            'has_expiry' => true,
            'expiry_date' => now()->subDay(),
            'approved_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.templates.pdf', $template));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertSame(1, substr_count($content, 'Duplicato Test SRL'));
        $this->assertStringContainsString('Scaduto', $content);
        $this->assertStringNotContainsString('Approvato', $content);
    }

    public function test_template_missing_pdf_lists_only_companies_without_the_document(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $companyWithDocument = User::query()->create([
            'name' => 'Societa Presente SRL',
            'email' => 'societa-presente@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $missingCompany = User::query()->create([
            'name' => 'Societa Mancante PDF SRL',
            'email' => 'societa-mancante-pdf@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->where('name', 'DURC')
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        $companyWithDocument->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/missing-pdf/durc.pdf',
            'status' => 'approved',
            'has_expiry' => false,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.templates.missing-pdf', $template));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $content = $response->getContent();

        $this->assertIsString($content);
        $this->assertStringContainsString($missingCompany->name, $content);
        $this->assertStringContainsString('Mancante', $content);
        $this->assertStringNotContainsString($companyWithDocument->name, $content);
    }

    public function test_manual_missing_documents_report_sends_section_emails(): void
    {
        $this->seed();
        Mail::fake();

        $company = User::query()->create([
            'name' => 'Missing Demo SRL',
            'email' => 'missing@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $sent = app(MissingDocumentsReportService::class)->sendManual($company);

        $this->assertGreaterThan(0, $sent['societa']);
        $this->assertSame(0, $sent['dipendenti']);
        $this->assertSame(0, $sent['veicoli']);

        Mail::assertSent(\App\Mail\MissingDocumentsReportMail::class, 1);
        Mail::assertSent(\App\Mail\MissingDocumentsReportMail::class, function ($mail): bool {
            return $mail->hasTo('missing@example.com')
                && $mail->sectionLabel === 'Societa'
                && count($mail->items) > 0;
        });
    }

    public function test_manual_credentials_mail_service_sends_mass_emails_from_json(): void
    {
        $this->seed();
        Mail::fake();

        User::query()->create([
            'name' => 'G2A Luigi SRL',
            'email' => 'g2aluigi@gmail.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $path = storage_path('app/testing/company-credentials-test.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'email' => 'g2aluigi@gmail.com',
                'password' => 'password',
            ],
            [
                'email' => 'esterno@example.com',
                'password' => 'PasswordTemporanea1',
            ],
            [
                'email' => 'senza-password@example.com',
                'password' => '',
            ],
            [
                'email' => 'g2aluigi@gmail.com',
                'password' => 'duplicata',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        config(['services.companies.credentials_json_path' => $path]);

        $result = app(CompanyCredentialsMailService::class)->sendManual();

        $this->assertSame([
            'total' => 4,
            'sent' => 2,
            'skipped' => 2,
            'matched' => 1,
            'unmatched' => 1,
        ], $result);

        Mail::assertSent(\App\Mail\CompanyCredentialsMail::class, 2);
        Mail::assertSent(\App\Mail\CompanyCredentialsMail::class, function ($mail): bool {
            return $mail->hasTo('g2aluigi@gmail.com')
                && $mail->loginEmail === 'g2aluigi@gmail.com'
                && $mail->loginPassword === 'password';
        });

        Mail::assertSent(\App\Mail\CompanyCredentialsMail::class, function ($mail): bool {
            return $mail->hasTo('esterno@example.com')
                && $mail->loginEmail === 'esterno@example.com'
                && $mail->loginPassword === 'PasswordTemporanea1';
        });

        File::delete($path);
    }

    public function test_manual_credentials_mail_service_can_filter_selected_emails(): void
    {
        $this->seed();
        Mail::fake();

        $path = storage_path('app/testing/company-credentials-selected-test.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            [
                'email' => 'prima@example.com',
                'password' => 'Prima123',
            ],
            [
                'email' => 'seconda@example.com',
                'password' => 'Seconda123',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        config(['services.companies.credentials_json_path' => $path]);

        $result = app(CompanyCredentialsMailService::class)->sendManual(['seconda@example.com']);

        $this->assertSame([
            'total' => 2,
            'sent' => 1,
            'skipped' => 0,
            'matched' => 0,
            'unmatched' => 1,
        ], $result);

        Mail::assertSent(\App\Mail\CompanyCredentialsMail::class, 1);
        Mail::assertSent(\App\Mail\CompanyCredentialsMail::class, function ($mail): bool {
            return $mail->hasTo('seconda@example.com')
                && $mail->loginPassword === 'Seconda123';
        });

        File::delete($path);
    }

    public function test_admin_can_open_template_companies_page(): void
    {
        $this->seed();
        Storage::fake('public');

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $companyWithDocument = User::query()->create([
            'name' => 'Societa Con Documento SRL',
            'email' => 'with-document@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        User::query()->create([
            'name' => 'Societa Mancante SRL',
            'email' => 'missing-document@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/export-demo/template.pdf', 'PDF test');

        $companyWithDocument->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/export-demo/template.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/document-templates/'.$template->id.'/societa')
            ->assertOk()
            ->assertSee('Societa Con Documento SRL')
            ->assertSee('Societa Mancante SRL')
            ->assertSee('In attesa')
            ->assertSee('Mancante');
    }

    public function test_admin_can_open_company_document_overview(): void
    {
        $this->seed();
        Storage::fake('public');

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Panoramica Demo SRL',
            'email' => 'panoramica@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/panoramica/demo.pdf', 'PDF test');

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/panoramica/demo.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users/'.$company->id.'/documenti')
            ->assertOk()
            ->assertSee('Panoramica Demo SRL')
            ->assertSee('In attesa')
            ->assertSee('Mancante')
            ->assertSee('Scarica')
            ->assertSee('Revisiona');
    }

    public function test_admin_can_open_company_total_overview(): void
    {
        $this->seed();
        Storage::fake('public');

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Panoramica Totale SRL',
            'email' => 'panoramica-totale@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $secondCompany = User::query()->create([
            'name' => 'Seconda Azienda SRL',
            'email' => 'seconda-azienda@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $template = DocumentTemplate::query()
            ->where('name', 'DURC')
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/panoramica/company-only.pdf', 'PDF test');

        $company->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/panoramica/company-only.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $secondCompany->documents()->create([
            'template_id' => $template->id,
            'file_path' => 'uploaded-documents/panoramica/company-only.pdf',
            'status' => 'approved',
            'has_expiry' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users/panoramica-totale')
            ->assertOk()
            ->assertSee('Panoramica Totale SRL')
            ->assertSee('Seconda Azienda SRL')
            ->assertSee('DURC')
            ->assertSee('In attesa')
            ->assertSee('Sezione Societa')
            ->assertDontSee('Revisiona')
            ->assertDontSee('Scarica')
            ->assertDontSee('Caricato il');
    }

    public function test_company_document_overview_review_link_opens_specific_document_review(): void
    {
        $this->seed();
        Storage::fake('public');

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Revisione Diretta SRL',
            'email' => 'revisione-diretta@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $companyTemplate = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'societa'))
            ->firstOrFail();

        $employeeTemplate = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'dipendenti'))
            ->firstOrFail();

        $vehicleTemplate = DocumentTemplate::query()
            ->whereHas('section', fn ($query) => $query->where('slug', 'veicoli'))
            ->firstOrFail();

        Storage::disk('public')->put('uploaded-documents/panoramica/direct-review.pdf', 'PDF test');

        $companyDocument = $company->documents()->create([
            'template_id' => $companyTemplate->id,
            'file_path' => 'uploaded-documents/panoramica/direct-review.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $employee = Employee::query()->create([
            'user_id' => $company->id,
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'tax_code' => 'RSSMRA80A01H501U',
        ]);

        $employeeDocument = $employee->documents()->create([
            'template_id' => $employeeTemplate->id,
            'file_path' => 'uploaded-documents/panoramica/direct-review.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $vehicle = Vehicle::query()->create([
            'user_id' => $company->id,
            'brand_model' => 'Iveco Daily',
            'plate' => 'AB123CD',
        ]);

        $vehicleDocument = $vehicle->documents()->create([
            'template_id' => $vehicleTemplate->id,
            'file_path' => 'uploaded-documents/panoramica/direct-review.pdf',
            'status' => 'pending',
            'has_expiry' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users/'.$company->id.'/documenti')
            ->assertOk()
            ->assertSee('document-approvals', false)
            ->assertSee('tab=societa', false)
            ->assertSee('tableAction=review', false)
            ->assertSee('tableActionRecord='.$companyDocument->id, false)
            ->assertSee('tab=dipendenti', false)
            ->assertSee('tableActionRecord='.$employeeDocument->id, false)
            ->assertSee('tab=veicoli', false)
            ->assertSee('tableActionRecord='.$vehicleDocument->id, false);
    }

    public function test_admin_can_download_filtered_company_overview_pdf(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $company = User::query()->create([
            'name' => 'Filtro PDF SRL',
            'email' => 'filtro-pdf@example.com',
            'password' => 'Password1',
            'role' => 'company',
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.companies.pdf', [$company, 'all']).'?filter=missing')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="riepilogo-documenti-mancanti-tutti-filtro-pdf-srl.pdf"');
    }

    public function test_admin_can_download_company_total_overview_pdf(): void
    {
        $this->seed();

        $admin = User::query()
            ->where('email', 'admin@admin.com')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.companies.company-overview.pdf').'?filter=all')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="panoramica-totale-societa-tutte.pdf"');
    }
}
