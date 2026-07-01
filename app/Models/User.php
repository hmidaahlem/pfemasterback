<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $appends = ['avatar_url'];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'role_id',
        'pdv_id',
        'status',
        'bio',
        'age',
        'experience',
        'avatar',
        'point_de_vente_id',
        'caissier_status',
        'caissier_role',
        'username',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'experience' => 'boolean',
            'caissier_status' => 'string',
        ];
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role?->name ?? $this->caissier_role,
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function pdvsResponsable()
    {
        return $this->hasMany(PointDeVente::class, 'responsable_fb_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function plannings(): HasMany
    {
        return $this->hasMany(Planning::class, 'user_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Planning::class, 'user_id');
    }

    public function scopeCaissiers($query)
    {
        return $query->where('caissier_role', 'CAISSIER');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar || $this->avatar === 'null') {
            return null;
        }

        return url('storage/'.$this->avatar);
    }

    public function isCaissier(): bool
    {
        return $this->role?->name === 'CAISSIER' || $this->caissier_role === 'CAISSIER';
    }
}
