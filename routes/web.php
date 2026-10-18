<?php

use App\Http\Controllers\Admin\BuildingController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\PlaceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/map');

Route::get('/map', [MapController::class, 'index'])->name('map');
Route::get('/floors/{floor}/navigation', [MapController::class, 'navigation'])->name('floors.navigation');

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
        Route::get('/buildings/create', [BuildingController::class, 'create'])->name('buildings.create');
        Route::post('/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::get('/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');
        Route::get('/buildings/{building}/edit', [BuildingController::class, 'edit'])->name('buildings.edit');
        Route::put('/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
        Route::delete('/buildings/{building}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
        Route::post('/buildings/{building}/toggle', [BuildingController::class, 'toggleStatus'])->name('buildings.toggle');

        Route::get('/buildings/{building}/floors', [FloorController::class, 'index'])->name('buildings.floors.index');
        Route::get('/buildings/{building}/floors/create', [FloorController::class, 'create'])->name('buildings.floors.create');
        Route::post('/buildings/{building}/floors', [FloorController::class, 'store'])->name('buildings.floors.store');
        Route::get('/buildings/{building}/floors/{floor}/edit', [FloorController::class, 'edit'])->name('buildings.floors.edit');
        Route::get('/buildings/{building}/floors/{floor}/editor', [FloorController::class, 'editor'])->name('buildings.floors.editor');
        Route::put('/buildings/{building}/floors/{floor}', [FloorController::class, 'update'])->name('buildings.floors.update');
        Route::put('/buildings/{building}/floors/{floor}/editor', [FloorController::class, 'saveMapData'])->name('buildings.floors.saveMapData');
        Route::delete('/buildings/{building}/floors/{floor}', [FloorController::class, 'destroy'])->name('buildings.floors.destroy');

        Route::get('/floors/{floor}/places', [PlaceController::class, 'index'])->name('floors.places.index');
        Route::get('/floors/{floor}/places/create', [PlaceController::class, 'create'])->name('floors.places.create');
        Route::post('/floors/{floor}/places', [PlaceController::class, 'store'])->name('floors.places.store');
        Route::get('/floors/{floor}/places/{place}/edit', [PlaceController::class, 'edit'])->name('floors.places.edit');
        Route::put('/floors/{floor}/places/{place}', [PlaceController::class, 'update'])->name('floors.places.update');
        Route::delete('/floors/{floor}/places/{place}', [PlaceController::class, 'destroy'])->name('floors.places.destroy');
    });
});

require __DIR__.'/auth.php';
