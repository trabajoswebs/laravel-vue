import type { User } from '@/types';

type AvatarSource = Pick<User, 'avatar' | 'avatar_url' | 'avatar_thumb_url' | 'avatar_version'>;

const AVATAR_CANDIDATES: Array<keyof AvatarSource> = ['avatar_thumb_url', 'avatar_url', 'avatar'];

function sanitizeCandidate(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    return trimmed !== '' ? trimmed : null;
}

function removeVersionParam(url: string): string {
    const [path, query] = url.split('?');
    if (!query) {
        return url;
    }

    const filtered = query
        .split('&')
        .filter((segment) => segment && !segment.startsWith('v='));

    return filtered.length > 0 ? `${path}?${filtered.join('&')}` : path;
}

export function resolveUserAvatarUrl<T extends AvatarSource | null | undefined>(
    user: T,
    extraCacheBuster?: string | number | null,
): string | null {
    if (!user) {
        return null;
    }

    let raw: string | null = null;
    for (const key of AVATAR_CANDIDATES) {
        raw = sanitizeCandidate(user[key]);
        if (raw) {
            break;
        }
    }

    if (!raw) {
        return null;
    }

    const version = sanitizeCandidate(user.avatar_version) ?? (extraCacheBuster != null ? String(extraCacheBuster) : null);
    if (!version) {
        return raw;
    }

    const base = removeVersionParam(raw);
    const separator = base.includes('?') ? '&' : '?';

    return `${base}${separator}v=${encodeURIComponent(version)}`;
}
