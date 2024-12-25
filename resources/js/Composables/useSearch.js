import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";

export function useSearch(routeName, id) {
    let search = ref(usePage().props.filters.search);

    const getRoute = () => {
        return id ? route(routeName, id) : route(routeName);
    };
    watch(
        search,
        throttle(function (value) {
            router.get(
                getRoute(),
                { search: value },
                {
                    preserveState: true,
                    replace: true,
                }
            );
        }, 500)
    );

    return {
        search,
    };
}
