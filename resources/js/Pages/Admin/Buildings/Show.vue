<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FloorForm from '@/Components/Admin/FloorForm.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    building: {
        type: Object,
        required: true,
    },
});

const submitFloor = (form) => {
    router.post(route('admin.buildings.floors.store', props.building.id), form);
};

const destroyFloor = (floor) => {
    if (confirm(`Delete floor ${floor.name}?`)) {
        router.delete(route('admin.buildings.floors.destroy', [props.building.id, floor.id]));
    }
};
</script>

<template>
    <Head :title="building.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-medium uppercase tracking-wide text-indigo-600">Building</div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ building.name }}</h2>
                </div>
                <Link :href="route('admin.buildings.index')">
                    <SecondaryButton type="button">Back to buildings</SecondaryButton>
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="text-sm font-medium uppercase tracking-wide text-gray-500">Description</div>
                        <p class="mt-2 text-gray-700">
                            {{ building.description || 'No description provided yet.' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Link :href="route('admin.buildings.edit', building.id)">
                            <SecondaryButton type="button">Edit building</SecondaryButton>
                        </Link>
                        <Link :href="route('map', { building: building.id })">
                            <PrimaryButton type="button">View public map</PrimaryButton>
                        </Link>
                    </div>
                </div>
            </div>

            <div class="grid gap-8 lg:grid-cols-[1.3fr_0.7fr]">
                <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-semibold text-gray-800">Floors</h3>
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">
                            {{ building.floors?.length ?? 0 }} floors
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div v-for="floor in building.floors" :key="floor.id" class="flex items-center justify-between rounded-md border border-gray-200 p-4">
                            <div>
                                <div class="font-medium text-gray-900">{{ floor.name }}</div>
                                <div class="text-sm text-gray-500">Level {{ floor.level }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Link :href="route('admin.floors.places.index', floor.id)" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                    Places
                                </Link>
                                <Link :href="route('admin.buildings.floors.editor', [building.id, floor.id])" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                    Map editor
                                </Link>
                                <Link :href="route('admin.buildings.floors.edit', [building.id, floor.id])">
                                    <SecondaryButton type="button">Edit</SecondaryButton>
                                </Link>
                                <DangerButton type="button" @click="destroyFloor(floor)">Delete</DangerButton>
                            </div>
                        </div>

                        <div v-if="!building.floors || building.floors.length === 0" class="rounded-md border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500">
                            No floors yet for this building.
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-800">Add floor</h3>
                    <div class="mt-4">
                        <FloorForm
                            :building="building"
                            submit-label="Create floor"
                            :cancel-route="route('admin.buildings.show', building.id)"
                            @submit="submitFloor"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
