<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
