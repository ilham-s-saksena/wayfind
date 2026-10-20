<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\Admin\PlaceController;
use Tests\TestCase;

class PlaceControllerTest extends TestCase
{
    public function test_points_inside_on_edges_and_on_vertices_are_inside_but_external_points_are_not(): void
    {
        $controller = new TestablePlaceController;
        $building = [[$this->square()]];

        $this->assertTrue($controller->containsPoint([5, 5], $building));
        $this->assertTrue($controller->containsPoint([5, 0], $building));
        $this->assertTrue($controller->containsPoint([0, 0], $building));
        $this->assertFalse($controller->containsPoint([11, 5], $building));
    }

    public function test_polygon_containment_includes_identical_and_boundary_sharing_polygons(): void
    {
        $controller = new TestablePlaceController;
        $building = [[$this->square()]];

        $this->assertTrue($controller->containsPolygon([$this->square()], $building));
        $this->assertTrue($controller->containsPolygon([[
            [0, 2], [5, 2], [5, 8], [0, 8], [0, 2],
        ]], $building));
        $this->assertTrue($controller->containsPolygon([[
            [2, 2], [8, 2], [8, 8], [2, 8], [2, 2],
        ]], $building));
        $this->assertFalse($controller->containsPolygon([[
            [8, 2], [12, 2], [12, 8], [8, 8], [8, 2],
        ]], $building));
    }

    public function test_polygon_edges_cannot_cross_outside_a_concave_building_between_vertices(): void
    {
        $controller = new TestablePlaceController;
        $concaveBuilding = [[[
            [0, 0], [10, 0], [10, 3], [3, 3], [3, 10], [0, 10], [0, 0],
        ]]];
        $candidate = [[
            [1, 8], [8, 1], [1, 1], [1, 8],
        ]];

        $this->assertFalse($controller->containsPolygon($candidate, $concaveBuilding));
    }

    public function test_polygon_holes_are_not_treated_as_filled_building_area(): void
    {
        $controller = new TestablePlaceController;
        $buildingWithHole = [[
            [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
            [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        ]];

        $this->assertFalse($controller->containsPoint([5, 5], $buildingWithHole));
        $this->assertTrue($controller->containsPoint([4, 5], $buildingWithHole));
        $this->assertFalse($controller->containsPolygon([[
            [4.5, 4.5], [5.5, 4.5], [5.5, 5.5], [4.5, 5.5], [4.5, 4.5],
        ]], $buildingWithHole));
        $this->assertFalse($controller->containsPolygon([[
            [1, 1], [9, 1], [9, 9], [1, 9], [1, 1],
        ]], $buildingWithHole));
        $this->assertTrue($controller->containsPolygon([
            [[1, 1], [9, 1], [9, 9], [1, 9], [1, 1]],
            [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        ], $buildingWithHole));
    }

    public function test_multi_point_and_multi_polygon_apply_boundary_inclusive_containment(): void
    {
        $controller = new TestablePlaceController;
        $building = [[$this->square()]];

        $this->assertTrue($controller->containsMultiPoint([[0, 0], [5, 5]], $building));
        $this->assertFalse($controller->containsMultiPoint([[5, 5], [11, 5]], $building));
        $this->assertTrue($controller->containsMultiPolygon([
            [$this->square()],
            [[[0, 1], [2, 1], [2, 2], [0, 2], [0, 1]]],
        ], $building));
        $this->assertFalse($controller->containsMultiPolygon([
            [$this->square()],
            [[[10, 1], [12, 1], [12, 2], [10, 2], [10, 1]]],
        ], $building));
    }

    public function test_malformed_geometry_is_rejected_without_throwing(): void
    {
        $controller = new TestablePlaceController;
        $building = [[$this->square()]];

        $this->assertFalse($controller->containsPoint(['bad', 2], $building));
        $this->assertFalse($controller->containsPolygon([[[0, 0], [1, 1]]], $building));
        $this->assertFalse($controller->containsPolygon([], $building));
        $this->assertFalse($controller->containsMultiPoint([], $building));
        $this->assertFalse($controller->containsMultiPolygon([], $building));
        $this->assertFalse($controller->containsMultiPoint(['malformed'], $building));
        $this->assertFalse($controller->containsMultiPolygon(['malformed'], $building));
        $this->assertFalse($controller->containsFeature([
            'geometry' => ['type' => 'Polygon', 'coordinates' => 'malformed'],
        ], $building));
    }

    /** @return array<int, array<int, float|int>> */
    private function square(): array
    {
        return [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]];
    }
}

class TestablePlaceController extends PlaceController
{
    public function containsPoint(array $point, array $polygons): bool
    {
        return $this->pointIsInsideAnyPolygon($point, $polygons);
    }

    public function containsPolygon(array $rings, array $polygons): bool
    {
        return $this->polygonIsInsideAnyPolygon($rings, $polygons);
    }

    public function containsMultiPoint(array $points, array $polygons): bool
    {
        return $this->multiPointIsInsideAnyPolygon($points, $polygons);
    }

    public function containsMultiPolygon(array $polygonSets, array $polygons): bool
    {
        return $this->multiPolygonIsInsideAnyPolygon($polygonSets, $polygons);
    }

    public function containsFeature(array $feature, array $polygons): bool
    {
        return $this->featureIsInsideBuilding($feature, $polygons);
    }
}
