import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";

export function useReferenceDelete() {
    const confirm = useConfirm();
    const toast = useToast();
    const deleteModel = (route, model) => {
        confirm.require({
            message: `Are you sure you want to delete this ${model.toLowerCase()}?`,
            header: "Confirmation",
            icon: "pi pi-exclamation-triangle",
            rejectProps: {
                label: "Cancel",
                severity: "secondary",
                outlined: true,
            },
            acceptProps: {
                label: "Confirm",
                severity: "danger",
            },
            accept: () => {
                router.delete(route, {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: `${model} Deleted Successfully.`,
                            life: 5000,
                        });
                    },
                    onError: (errors) => {
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail:
                                errors.message ||
                                "An error occurred while deleting.",
                            life: 5000,
                        });
                    },
                });
            },
        });
    };

    return {
        deleteModel,
    };
}
