<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BuildingForm from '@/Components/Admin/BuildingForm.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    building: {
        type: Object,
        required: true,
    },
});

const submit = (form) => {
    router.put(route('admin.buildings.update', props.building.id), form);
};
</script>

<template>
    <Head :title="`Edit ${building.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Edit building</h2>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <BuildingForm
                    :building="building"
                    submit-label="Update building"
                    :cancel-route="route('admin.buildings.show', building.id)"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
