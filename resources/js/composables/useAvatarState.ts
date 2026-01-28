import { ref } from 'vue';

/**
 * Estado global mínimo para forzar avatar a null tras eliminar sin depender
 * de la recarga de Inertia (page.props no es reactivo profundo).
 *
 * avatarOverride:
 * - undefined => usar page.props normalmente
 * - null => forzar sin avatar (mostrar iniciales)
 * - string => forzar URL específica (no usado hoy, pero queda disponible)
 */
const avatarOverride = ref<string | null | undefined>(undefined);

export function useAvatarState() {
    const setAvatarOverride = (value: string | null | undefined) => {
        avatarOverride.value = value;
    };

    return { avatarOverride, setAvatarOverride };
}
