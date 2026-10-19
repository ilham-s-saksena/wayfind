<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NavigationEdge extends Model
{
    use HasUuids;

    protected $fillable = [
        'from_node_id',
        'to_node_id',
        'distance',
        'accessible',
        'authored_feature_id',
        'geometry',
    ];

    protected $casts = [
        'distance' => 'float',
        'accessible' => 'boolean',
        'geometry' => 'array',
    ];

    public function fromNode()
    {
        return $this->belongsTo(NavigationNode::class, 'from_node_id');
    }

    public function toNode()
    {
        return $this->belongsTo(NavigationNode::class, 'to_node_id');
    }
}
