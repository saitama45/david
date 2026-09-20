import { router } from "@inertiajs/vue3";
import { onMounted, onBeforeUnmount } from "vue";

export function useStockRefresh(only, paused = () => false) {
    let timer;
    let navigating = false;
    let removeStart;
    let removeFinish;

    onMounted(() => {
        removeStart = router.on("start", () => { navigating = true; });
        removeFinish = router.on("finish", () => { navigating = false; });
        timer = window.setInterval(() => {
            if (!document.hidden && !navigating && !paused()) {
                router.reload({ only, preserveState: true, preserveScroll: true });
            }
        }, 10000);
    });

    onBeforeUnmount(() => {
        window.clearInterval(timer);
        removeStart?.();
        removeFinish?.();
    });
}
