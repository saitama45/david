import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";

export function useEditQuantity(orderForm) {
    const confirm = useConfirm();
    const { toast } = useToast();
    const isEditQuantityModalOpen = ref(false);
    const formQuantity = useForm({
        id: null,
        quantity: 0,
    });

    const openEditQuantityModal = (id, quantity) => {
        formQuantity.id = id;
        formQuantity.quantity = quantity;
        isEditQuantityModalOpen.value = true;
    };
    const editQuantity = () => {
        if (
            orderForm.variant &&
            orderForm.variant === "ice cream" &&
            formQuantity.quantity < 5
        ) {
            formQuantity.setError("quantity", "Quantity should be at least 5");
            return;
        }
        if (formQuantity.quantity < 0.1) {
            formQuantity.setError("quantity", "Quantity should be more than 0");
            return;
        }

        const index = orderForm.orders.findIndex(
            (item) => item.id === formQuantity.id
        );

        orderForm.orders[index].quantity = formQuantity.quantity;
        orderForm.orders[index].total_cost = parseFloat(
            orderForm.orders[index].quantity * orderForm.orders[index].cost
        ).toFixed(2);

        toast.add({
            severity: "success",
            summary: "Success",
            detail: "Quantity Updated",
            life: 3000,
        });

        formQuantity.reset();
        formQuantity.clearErrors();
        isEditQuantityModalOpen.value = false;
    };

    return {
        isEditQuantityModalOpen,
        formQuantity,
        openEditQuantityModal,
        editQuantity,
    };
}
