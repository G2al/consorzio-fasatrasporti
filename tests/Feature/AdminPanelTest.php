<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DocumentTemplate;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $document->forceFill([
            'status' => 'approved',
            'approved_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.downloads.templates.show', $template))
            ->assertOk()
            ->assertDownload();

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
}
