<script setup>
import Logo from "../../../images/logo.png";
</script>

<template>
    <Layout heading="My Profile">
        <div class="flex flex-col gap-5">
            <DivFlexCenter class="h-56 gap-10">
                <img
                    :src="Logo"
                    alt="logo"
                    class="size-56 rounded-lg shadow-lg"
                />

                <DivFlexCol class="gap-5 h-56">
                    <section>
                        <h1 class="font-bold text-2xl">Nathaniel Alvarez</h1>
                        <p class="text-blue-500 font-bold text-sm">
                            Admin, So Encoder
                        </p>
                    </section>

                    <DivFlexCol class="gap-3">
                        <span class="text-xs text-gray-800"
                            >Contact Information</span
                        >
                        <section class="grid grid-cols-2 gap-3">
                            <span class="font-bold text-gray-800 text-sm"
                                >Phone:
                            </span>
                            <span class="font-bold text-blue-500 text-sm"
                                >09288229210
                            </span>
                            <span class="font-bold text-gray-800 text-sm"
                                >Email:
                            </span>
                            <span class="font-bold text-blue-500 text-sm"
                                >nathaniel@gmail.com
                            </span>
                        </section>
                    </DivFlexCol>

                    <DivFlexCol class="gap-3">
                        <span class="text-xs text-gray-800">Remarks</span>
                        <span class="text-sm text-gray-800">N/a</span>
                    </DivFlexCol>
                </DivFlexCol>
            </DivFlexCenter>
        </div>
    </Layout>
</template>
