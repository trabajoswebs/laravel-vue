const readCookie = (name: string): string | null => {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : null;
};

export function getCsrfToken(): string | null {
    // Preferimos la cookie XSRF-TOKEN porque Laravel la regenera tras el login
    // y evita usar un token obsoleto cuando el front no recarga la página.
    const cookieToken = readCookie('XSRF-TOKEN');
    if (cookieToken) return cookieToken;

    // Fallback al meta inicial (útil en la primera carga SSR)
    if (typeof document === 'undefined') return null;
    const node = document.querySelector('meta[name="csrf-token"]');
    return node?.getAttribute('content') ?? null;
}
