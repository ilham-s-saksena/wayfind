<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'geojson',
        'is_active',
    ];

    protected $casts = [
        'geojson' => 'array',
        'is_active' => 'boolean',
    ];

    public function floors()
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }

    public function places()
    {
        return $this->hasManyThrough(Place::class, Floor::class);
    }

    public function navigationNodes()
    {
        return $this->hasManyThrough(NavigationNode::class, Floor::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

