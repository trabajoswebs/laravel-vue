<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { useAvatarState } from '@/composables/useAvatarState';
import type { User } from '@/types';
import { computed } from 'vue';
import { resolveUserAvatarUrl } from '@/utils/avatar';

interface Props {
    user: User;
    showEmail?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
});

const { getInitials } = useInitials();
const { avatarOverride } = useAvatarState();

const avatarUrl = computed(() => {
    if (avatarOverride.value !== undefined) {
        return avatarOverride.value;
    }
    return resolveUserAvatarUrl(props.user, props.user?.updated_at ?? null);
});
// Compute whether we should show the avatar image
const showAvatar = computed(() => Boolean(avatarUrl.value));
</script>

<template>
    <div class="h-8 w-8 overflow-hidden rounded-lg">
        <Avatar v-if="showAvatar" class="h-8 w-8 overflow-hidden rounded-lg">
            <AvatarImage :src="avatarUrl!" :key="avatarUrl" :alt="user.name" />
        </Avatar>
        <div v-else class="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-sm font-semibold text-black dark:text-white">
            {{ getInitials(user.name) }}
        </div>
    </div>

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="showEmail" class="truncate text-xs text-muted-foreground">{{ user.email }}</span>
    </div>
</template>
