<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCompany extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'document', 'domain', 'tier', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SlaPolicy::class);
    }
}
