import { useToast as toaster } from "primevue/usetoast";
export function useToast() {
    const toast = toaster();

    return {
        toast,
    };
}
