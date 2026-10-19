<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    use HasUuids;

    protected $fillable = [
        'floor_id',
        'name',
        'slug',
        'type',
        'description',
        'access_node_id',
        'geojson',
        'is_searchable',
        'is_active',
    ];

    protected $casts = [
        'geojson' => 'array',
        'is_searchable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function accessNode()
    {
        return $this->belongsTo(NavigationNode::class, 'access_node_id');
    }

    public function scopeSearchable($query)
    {
        return $query->where('is_searchable', true)->where('is_active', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
