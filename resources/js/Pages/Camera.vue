<script setup>
import { useForm } from "@inertiajs/vue3";
import { Camera } from "lucide-vue-next";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();

const canvas = ref(null);
const video = ref(null);
const ctx = ref(null);
const streamActive = ref(true);
const capturedImage = ref(null);
const currentStream = ref(null);
const emit = defineEmits(["uploadSuccess"]);

const { store_order_id } = defineProps({
    store_order_id: null,
});

const imageForm = useForm({
    store_order_id: store_order_id,
    image: null,
});

const constraints = {
    audio: false,
    video: {
        width: { ideal: 1920 },
        height: { ideal: 1080 },
    },
};

onMounted(async () => {
    if (!video.value || !canvas.value) {
        console.error("Video or canvas element not found");
        return;
    }

    ctx.value = canvas.value.getContext("2d");

    if (!navigator.mediaDevices?.getUserMedia) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Session is not secured, could not open camera.",
            life: 5000,
        });
        return;
    }

    await startCamera();
});

async function startCamera() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        currentStream.value = stream;
        await setStream(stream);
    } catch (error) {
        console.error("Error accessing camera:", error);
    }
}

async function setStream(stream) {
    if (!video.value) return;

    video.value.srcObject = stream;
    streamActive.value = true;
    capturedImage.value = null;
    imageForm.image = null;

    try {
        await video.value.play();
        requestAnimationFrame(draw);
    } catch (error) {
        console.error("Error playing video:", error);
    }
}

function draw() {
    if (!ctx.value || !video.value || !canvas.value || !streamActive.value)
        return;

    const scale = Math.min(
        canvas.value.width / video.value.videoWidth,
        canvas.value.height / video.value.videoHeight
    );

    const x = (canvas.value.width - video.value.videoWidth * scale) / 2;
    const y = (canvas.value.height - video.value.videoHeight * scale) / 2;

    ctx.value.drawImage(
        video.value,
        x,
        y,
        video.value.videoWidth * scale,
        video.value.videoHeight * scale
    );

    requestAnimationFrame(draw);
}

function takePicture() {
    if (!video.value) return;

    streamActive.value = false;

    if (currentStream.value) {
        currentStream.value.getTracks().forEach((track) => track.stop());
    }

    const captureCanvas = document.createElement("canvas");

    captureCanvas.width = video.value.videoWidth;
    captureCanvas.height = video.value.videoHeight;

    const captureCtx = captureCanvas.getContext("2d");

    captureCtx.drawImage(
        video.value,
        0,
        0,
        video.value.videoWidth,
        video.value.videoHeight
    );

    capturedImage.value = captureCanvas.toDataURL("image/png", 1.0);

    fetch(capturedImage.value)
        .then((res) => res.blob())
        .then((blob) => {
            const imageFile = new File([blob], "camera-capture.png", {
                type: "image/png",
            });
            imageForm.image = imageFile;
        });
}

async function retake() {
    if (ctx.value && canvas.value) {
        ctx.value.clearRect(0, 0, canvas.value.width, canvas.value.height);
    }

    streamActive.value = true;
    capturedImage.value = null;
    imageForm.image = null;

    await startCamera();
}

function uploadToDatabase() {
    if (!imageForm.image) {
        console.error("No image captured");
        return;
    }

    imageForm.post(route("upload-image"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Image attached successfully.",
                life: 5000,
            });
            emit("uploadSuccess");
        },
    });
}
</script>

<template>
    <div class="flex flex-col items-center space-y-4">
        <video
            ref="video"
            width="500"
            height="320"
            autoplay
            playsinline
            webkit-playsinline
            muted
            :class="{ hidden: true }"
        ></video>

        <canvas
            ref="canvas"
            width="500"
            height="320"
            class="border border-gray-300 rounded-lg bg-black"
        ></canvas>

        <div class="flex space-x-4">
            <Button v-if="!capturedImage" @click="takePicture">
                <Camera class="mr-2" /> Take Picture
            </Button>
            <template v-else>
                <Button variant="outline" @click="retake">
                    Retake Picture
                </Button>
                <Button @click="uploadToDatabase"> Upload Picture </Button>
            </template>
        </div>
    </div>
</template>
