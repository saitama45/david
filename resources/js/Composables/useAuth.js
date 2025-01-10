import { usePage } from "@inertiajs/vue3";
export function useAuth() {
    const permissions = usePage().props.auth.permissions;

    const hasAccess = (access) => {
        return permissions.includes(access);
    };

    return {
        hasAccess,
    };
}
