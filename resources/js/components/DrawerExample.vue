<script setup lang="ts">
import {
    Sheet as Drawer,
    SheetContent as DrawerContent,
    SheetDescription as DrawerDescription,
    SheetFooter as DrawerFooter,
    SheetHeader as DrawerHeader,
    SheetTitle as DrawerTitle,
    SheetTrigger as DrawerTrigger,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { useLanguage } from '../composables/useLanguage';

const { t } = useLanguage();

interface Props {
    side?: 'top' | 'right' | 'bottom' | 'left'
}

const props = withDefaults(defineProps<Props>(), {
    side: 'left'
})
</script>

<template>
    <Drawer>
        <DrawerTrigger>
            <slot name="trigger">
                {{ t('ui.open') }}
            </slot>
        </DrawerTrigger>
        <DrawerContent :side="props.side">
            <DrawerHeader>
                <DrawerTitle>
                    <slot name="title">
                        {{ t('ui.are_you_sure') }}
                    </slot>
                </DrawerTitle>
                <DrawerDescription>
                    <slot name="description">
                        {{ t('ui.action_cannot_be_undone') }}
                    </slot>
                </DrawerDescription>
            </DrawerHeader>
            <DrawerFooter>
                <slot name="footer">
                    <Button>{{ t('ui.submit') }}</Button>
                    <Button variant="outline">
                        {{ t('common.cancel') }}
                    </Button>
                </slot>
            </DrawerFooter>
        </DrawerContent>
    </Drawer>
</template>