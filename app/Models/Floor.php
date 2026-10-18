<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Floor extends Model
{
    use HasUuids;

    protected $fillable = [
        'building_id',
        'name',
        'level',
        'map_data',
        'background_image',
        'background_visible',
        'background_opacity',
    ];

    protected $casts = [
        'level' => 'integer',
        'map_data' => 'array',
        'background_visible' => 'boolean',
        'background_opacity' => 'float',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function navigationNodes()
    {
        return $this->hasMany(NavigationNode::class)->orderBy('id');
    }

    public function navigationEdges()
    {
        return $this->hasManyThrough(NavigationEdge::class, NavigationNode::class, 'floor_id', 'from_node_id');
    }

    public function places()
    {
        return $this->hasMany(Place::class)->orderBy('name');
    }
}
