import { useToast } from "primevue/usetoast";

export function useReferenceStore() {
    const toast = useToast();
    const isCreateModalVisible = ref(false);
    const openCreateModal = () => {
        isCreateModalVisible.value = true;
    };

    const store = (route, form, model) => {
        form.post(route, {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: model + " Create Successfully.",
                    life: 5000,
                });
                form.reset();
                isCreateModalVisible.value = false;
            },
        });
    };

    return {
        isCreateModalVisible,
        openCreateModal,
        store,
    };
}
