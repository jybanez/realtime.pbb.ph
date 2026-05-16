<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_operator',
        'user_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_operator' => 'boolean',
    ];

    public function realtimeClients()
    {
        return $this->belongsToMany(RealtimeClient::class, 'realtime_client_user', 'user_id', 'client_id')
            ->withPivot('assignment_role')
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return (string) $this->user_type === 'admin';
    }

    public function isRegular(): bool
    {
        return (string) $this->user_type === 'regular';
    }

    public function canAccessAdminSurface(): bool
    {
        return $this->isAdmin() || (bool) $this->is_operator;
    }

    public function canAccessClient(int $clientId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->realtimeClients()->whereKey($clientId)->exists();
    }

    /**
     * @return array<int, int>
     */
    public function assignedClientIds(): array
    {
        if ($this->isAdmin()) {
            return RealtimeClient::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->realtimeClients()->pluck('realtime_clients.id')->map(fn ($id) => (int) $id)->all();
    }
}
