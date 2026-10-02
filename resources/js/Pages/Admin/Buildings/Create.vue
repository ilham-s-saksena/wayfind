<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BuildingForm from '@/Components/Admin/BuildingForm.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    building: {
        type: Object,
        default: null,
    },
});

const submit = (form) => {
    router.post(route('admin.buildings.store'), form);
};
</script>

<template>
    <Head title="New building" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">Create building</h2>
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <BuildingForm
                    :building="building"
                    submit-label="Create building"
                    :cancel-route="route('admin.buildings.index')"
                    @submit="submit"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
