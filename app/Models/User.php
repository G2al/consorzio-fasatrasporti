<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'responsible_name',
        'vat_number',
        'api_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(UploadedDocument::class, 'documentable');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'company_id');
    }

    public function notificationDismissals(): HasMany
    {
        return $this->hasMany(CompanyNotificationDismissal::class);
    }

    public function uploadedDocuments(): MorphMany
    {
        return $this->documents();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->role === 'admin';
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->documents()->get()->each->delete();
            $user->employees()->get()->each->delete();
            $user->vehicles()->get()->each->delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
