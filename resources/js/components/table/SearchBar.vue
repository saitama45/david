<template>
    <div class="relative w-full sm:max-w-sm items-center">
        <slot></slot>
        <span
            class="absolute start-0 inset-y-0 flex items-center justify-center px-2"
        >
            <MagnifyingGlassIcon class="size-6 text-muted-foreground" />
        </span>
    </div>
</template>
