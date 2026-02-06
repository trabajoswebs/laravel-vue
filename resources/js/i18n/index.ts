import { createI18n } from 'vue-i18n'
import type { Composer } from 'vue-i18n'

// -------------------------------------------------------------------
// 1. Locales soportados
// -------------------------------------------------------------------
const SUPPORTED_LOCALES = ['es', 'en'] as const
export type Locale = typeof SUPPORTED_LOCALES[number]

// Idioma por defecto
const DEFAULT_LOCALE: Locale = 'es'

const isSupportedLocale = (locale: string): locale is Locale => {
  return SUPPORTED_LOCALES.includes(locale as Locale)
}

// -------------------------------------------------------------------
// 2. Imports estáticos y dinámicos
// -------------------------------------------------------------------
import esMessages from '../locales/es.json'

const localeImports = {
  es: () => Promise.resolve({ default: esMessages }), // Consistente con import()
  en: () => import('../locales/en.json')
} as const

// -------------------------------------------------------------------
// 3. Mensajes iniciales
// -------------------------------------------------------------------
const loadInitialMessages = (): Record<Locale, Record<string, any>> => {
  return {
    es: esMessages,
    en: {}
  }
}

// -------------------------------------------------------------------
// 4. Detección de idioma
// -------------------------------------------------------------------
const getBrowserLocale = (): Locale => {
  if (typeof navigator === 'undefined') return DEFAULT_LOCALE
  const browserLocale = navigator.language.toLowerCase().split('-')[0]
  return isSupportedLocale(browserLocale) ? browserLocale : DEFAULT_LOCALE
}

const getInitialLocale = (): Locale => {
  if (typeof window === 'undefined') return DEFAULT_LOCALE
  
  try {
    const savedLocale = localStorage.getItem('locale')
    if (savedLocale && isSupportedLocale(savedLocale)) {
      return savedLocale
    }
  } catch (error) {
    // localStorage puede no estar disponible (navegadores privados, etc.)
    console.warn('localStorage no disponible:', error)
  }
  
  return getBrowserLocale()
}

const locale: Locale = getInitialLocale()

// -------------------------------------------------------------------
// 5. Instancia de i18n
// -------------------------------------------------------------------
// Detección segura del entorno de desarrollo para diferentes bundlers (Vite/Webpack)
const IS_DEV =
  typeof import.meta !== 'undefined' && (import.meta as any).env && typeof (import.meta as any).env.DEV !== 'undefined'
    ? (import.meta as any).env.DEV
    : (typeof (globalThis as any).process !== 'undefined' &&
       (globalThis as any).process.env &&
       (globalThis as any).process.env.NODE_ENV === 'development')

export const i18n = createI18n({
  legacy: false,
  locale,
  fallbackLocale: DEFAULT_LOCALE,
  messages: loadInitialMessages()
})

// Forzar el tipo Composer cuando legacy es false para evitar uniones con VueI18n
const composer = i18n.global as unknown as Composer

// -------------------------------------------------------------------
// 6. Estado de carga
// -------------------------------------------------------------------
const loadedLocales: Set<Locale> = new Set([DEFAULT_LOCALE])
let i18nInitialized = false

// -------------------------------------------------------------------
// 7. Helpers
// -------------------------------------------------------------------
const I18N_KEY_REGEX = /^[a-z0-9_.-]+$/i

const normalizeCandidate = (value: unknown): string => {
  if (value === null || value === undefined) return ''
  return (typeof value === 'string' ? value : String(value)).trim()
}

export const isLikelyI18nKey = (value: unknown): value is string => {
  const normalized = normalizeCandidate(value)
  if (!normalized) return false
  return I18N_KEY_REGEX.test(normalized) && (normalized.includes('.') || normalized.includes('_') || normalized.includes('-'))
}

export const safeT = (input: unknown, params?: Record<string, unknown> | unknown[]): string => {
  const normalized = normalizeCandidate(input)
  if (!isLikelyI18nKey(normalized)) {
    return normalized
  }

  try {
    return String(composer.t(normalized as any, params as any))
  } catch (error) {
    console.warn('[i18n] safeT fallback for non-key', { input: normalized, error })
    return normalized
  }
}

const updateHtmlLang = (lang: Locale): void => {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = lang
  }
}

const saveToLocalStorage = (key: string, value: string): boolean => {
  if (typeof window === 'undefined') return false
  
  try {
    localStorage.setItem(key, value)
    return true
  } catch (error) {
    console.warn(`No se pudo guardar ${key} en localStorage:`, error)
    return false
  }
}

// -------------------------------------------------------------------
// 8. Cargar locale dinámicamente
// -------------------------------------------------------------------
export const loadLocaleMessages = async (targetLocale: Locale): Promise<boolean> => {
  if (!isSupportedLocale(targetLocale)) {
    console.warn(`Idioma no soportado: ${targetLocale}`)
    return false
  }

  if (loadedLocales.has(targetLocale)) {
    return true
  }

  try {
    const messages = await localeImports[targetLocale]()
    const messageData = messages.default || messages
    
    // Validar que los mensajes no estén vacíos
    if (!messageData || (typeof messageData === 'object' && Object.keys(messageData).length === 0)) {
      throw new Error(`Los mensajes para ${targetLocale} están vacíos`)
    }
    
    ;(composer as any).setLocaleMessage(targetLocale, messageData)
    loadedLocales.add(targetLocale)
    return true
  } catch (error) {
    console.error(`Error cargando idioma ${targetLocale}:`, error)
    return false
  }
}

// -------------------------------------------------------------------
// 9. Funciones auxiliares
// -------------------------------------------------------------------
export const setLocale = async (newLocale: Locale): Promise<boolean> => {
  if (!isSupportedLocale(newLocale)) {
    console.warn(`Idioma no soportado: ${newLocale}`)
    return false
  }

  // No hacer nada si ya está activo este locale
  if (getCurrentLocale() === newLocale) {
    return true
  }

  const loaded = await loadLocaleMessages(newLocale)
  if (!loaded) {
    console.error(`No se pudo cargar el idioma: ${newLocale}`)
    return false
  }

  const previousLocale = getCurrentLocale()
  composer.locale.value = newLocale

  if (typeof window !== 'undefined') {
    saveToLocalStorage('locale', newLocale)
    updateHtmlLang(newLocale)
    
    // Emitir evento de cambio
    try {
      window.dispatchEvent(
        new CustomEvent('locale-changed', { 
          detail: { 
            locale: newLocale, 
            previousLocale
          } 
        })
      )
    } catch (error) {
      console.warn('Error emitiendo evento locale-changed:', error)
    }
  }

  return true
}

export const getCurrentLocale = (): Locale => {
  return composer.locale.value as Locale
}

export const getBrowserLanguage = (): string => {
  if (typeof navigator === 'undefined') return DEFAULT_LOCALE
  return navigator.language.toLowerCase().split('-')[0]
}

export const isBrowserLanguageSupported = (): boolean => {
  return isSupportedLocale(getBrowserLanguage())
}

export const getSupportedBrowserLocale = (): Locale | null => {
  const browserLang = getBrowserLanguage()
  return isSupportedLocale(browserLang) ? browserLang : null
}

export const getAvailableLocales = (): readonly Locale[] => SUPPORTED_LOCALES

export const isLocaleLoaded = (targetLocale: Locale): boolean => {
  return loadedLocales.has(targetLocale)
}

export const getDefaultLocale = (): Locale => DEFAULT_LOCALE

// -------------------------------------------------------------------
// 10. Inicialización controlada
// -------------------------------------------------------------------
export const initializeI18n = async (): Promise<boolean> => {
  if (i18nInitialized) return true
  
  try {
    // Cargar idioma inicial si no es el por defecto
    if (locale !== DEFAULT_LOCALE) {
      const loaded = await loadLocaleMessages(locale)
      if (!loaded) {
        console.warn(`Fallback a ${DEFAULT_LOCALE} por error cargando ${locale}`)
        composer.locale.value = DEFAULT_LOCALE
      }
    }

    updateHtmlLang(getCurrentLocale())
    i18nInitialized = true
    
    // Emitir evento de inicialización
    if (typeof window !== 'undefined') {
      try {
        window.dispatchEvent(
          new CustomEvent('i18n-initialized', { 
            detail: { locale: getCurrentLocale() } 
          })
        )
      } catch (error) {
        // No es crítico si falla
        console.warn('Error emitiendo evento i18n-initialized:', error)
      }
    }
    
    return true
  } catch (error) {
    console.error('Error inicializando i18n:', error)
    return false
  }
}

export const isI18nInitialized = (): boolean => i18nInitialized

// -------------------------------------------------------------------
// 11. Auto-inicialización opcional
// -------------------------------------------------------------------
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  // Usar Promise.resolve().then() para mejor compatibilidad que setTimeout
  Promise.resolve().then(() => {
    initializeI18n().catch(error => {
      console.error('Error en auto-inicialización de i18n:', error)
    })
  })
}

// -------------------------------------------------------------------
// 12. Preload de idiomas
// -------------------------------------------------------------------
export const preloadLocale = (targetLocale: Locale): Promise<boolean> => {
  return loadLocaleMessages(targetLocale)
}

export const preloadAllLocales = async (): Promise<Locale[]> => {
  const results = await Promise.allSettled(
    SUPPORTED_LOCALES.map(async (locale) => {
      const success = await loadLocaleMessages(locale)
      if (!success) throw new Error(`Failed to load ${locale}`)
      return locale
    })
  )
  
  return results
    .filter((result): result is PromiseFulfilledResult<Locale> => result.status === 'fulfilled')
    .map(result => result.value)
}
