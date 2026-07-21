import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";

export function useReferenceRestore() {
    const confirm = useConfirm();
    const toast = useToast();
    const restoreModel = (route, model) => {
        confirm.require({
            message: `Are you sure you want to restore this ${model.toLowerCase()}?`,
            header: "Confirmation",
            icon: "pi pi-refresh",
            rejectProps: {
                label: "Cancel",
                severity: "secondary",
                outlined: true,
            },
            acceptProps: {
                label: "Restore",
                severity: "success",
            },
            accept: () => {
                router.patch(
                    route,
                    {},
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            toast.add({
                                severity: "success",
                                summary: "Success",
                                detail: `${model} Restored Successfully.`,
                                life: 5000,
                            });
                        },
                        onError: (errors) => {
                            toast.add({
                                severity: "error",
                                summary: "Error",
                                detail:
                                    errors.error ||
                                    errors.message ||
                                    "An error occurred while restoring.",
                                life: 5000,
                            });
                        },
                    }
                );
            },
        });
    };

    return {
        restoreModel,
    };
}
