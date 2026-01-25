export function getCsrfToken(): string | null {
    if (typeof document === 'undefined') return null;
    const node = document.querySelector('meta[name=\"csrf-token\"]');
    return node?.getAttribute('content') ?? null;
}
