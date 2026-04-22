<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
