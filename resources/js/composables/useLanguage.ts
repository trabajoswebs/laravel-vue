import { computed, ref, watch, nextTick, readonly, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePage, router } from '@inertiajs/vue3'
import {
  setLocale,
  getBrowserLanguage,
  isBrowserLanguageSupported,
  getAvailableLocales,
  type Locale
} from '../i18n'

interface LanguageMetadata {
  name: string
  native_name: string
  flag: string
  direction: 'ltr' | 'rtl'
  region?: string
}

interface ServerTranslations {
  locale: string
  fallbackLocale?: string
  messages: Record<string, any>
  supported?: string[]
  metadata?: LanguageMetadata
  error?: boolean
}

interface ChangeLanguageOptions {
  timeout?: number
  retries?: number
  optimistic?: boolean
}

// Cache seguro con limpieza automática
class TranslationCache {
  private cache = new Map<string, { value: string; timestamp: number }>()
  private readonly TTL = 60000 // 1 minuto
  private readonly MAX_SIZE = 1000
  private cleanupInterval?: number // Cambio: usar number en lugar de NodeJS.Timeout

  constructor() {
    this.startCleanup()
  }

  private startCleanup(): void {
    this.cleanupInterval = window.setInterval(() => { // Cambio: usar window.setInterval
      this.cleanup()
    }, 30000) // Limpiar cada 30s
  }

  private cleanup(): void {
    const now = Date.now()
    const toDelete: string[] = []

    for (const [key, data] of this.cache.entries()) {
      if (now - data.timestamp > this.TTL) {
        toDelete.push(key)
      }
    }

    toDelete.forEach(key => this.cache.delete(key))

    // Limitar tamaño total
    if (this.cache.size > this.MAX_SIZE) {
      const entries = Array.from(this.cache.entries())
      entries.sort((a, b) => a[1].timestamp - b[1].timestamp)
      
      const toDeleteCount = this.cache.size - this.MAX_SIZE
      for (let i = 0; i < toDeleteCount; i++) {
        this.cache.delete(entries[i][0])
      }
    }
  }

  get(key: string): string | undefined {
    const entry = this.cache.get(key)
    if (!entry) return undefined
    
    if (Date.now() - entry.timestamp > this.TTL) {
      this.cache.delete(key)
      return undefined
    }
    
    return entry.value
  }

  set(key: string, value: string): void {
    // Validar entrada
    if (!key || typeof key !== 'string' || key.length > 200) return
    if (typeof value !== 'string' || value.length > 5000) return
    
    this.cache.set(key, { value, timestamp: Date.now() })
  }

  clear(): void {
    this.cache.clear()
  }

  destroy(): void {
    if (this.cleanupInterval !== undefined) {
      window.clearInterval(this.cleanupInterval) // Cambio: usar window.clearInterval
      this.cleanupInterval = undefined
    }
    this.clear()
  }

  getStats() {
    return {
      size: this.cache.size,
      ttl: this.TTL,
      maxSize: this.MAX_SIZE,
      entries: Array.from(this.cache.entries()).map(([key, data]) => ({
        key: key.substring(0, 50), // Truncar para logging
        age: Date.now() - data.timestamp
      }))
    }
  }
}

const DEFAULT_METADATA: LanguageMetadata = {
  name: 'Unknown',
  native_name: 'Unknown',
  flag: '🌐',
  direction: 'ltr'
}
const DEFAULT_TIMEOUT = 10000 // 10s
const MAX_RETRIES = 3
const THROTTLE_TIME = 1000 // ms
const MAX_THROTTLE_TIME = 5000 // ms

// Validadores seguros
function isValidLocale(locale: unknown): locale is Locale {
  return typeof locale === 'string' && 
         locale.length >= 2 && 
         locale.length <= 10 && 
         /^[a-zA-Z0-9_-]+$/.test(locale)
}

function sanitizeServerTranslations(data: unknown): ServerTranslations {
  if (!data || typeof data !== 'object') {
    return {
      locale: 'es',
      messages: {},
      fallbackLocale: 'en',
      supported: ['es', 'en'],
      metadata: DEFAULT_METADATA
    }
  }

  const obj = data as Record<string, unknown>
  
  return {
    locale: isValidLocale(obj.locale) ? obj.locale : 'es',
    messages: (obj.messages && typeof obj.messages === 'object') ? obj.messages : {},
    fallbackLocale: isValidLocale(obj.fallbackLocale) ? obj.fallbackLocale : 'en',
    supported: Array.isArray(obj.supported) ? obj.supported.filter(isValidLocale) : ['es', 'en'],
    metadata: (obj.metadata && typeof obj.metadata === 'object') ? 
      { ...DEFAULT_METADATA, ...obj.metadata } : DEFAULT_METADATA,
    error: Boolean(obj.error)
  }
}

export function useLanguage() {
  const { t: i18nT, locale } = useI18n()
  const page = usePage()

  // reactive state
  const isChanging = ref(false)
  const lastChangeTime = ref(0)
  const changeAttempts = ref(0)
  const lastError = ref<string | null>(null)
  const optimisticLocale = ref<Locale | null>(null)
  
  // Cola para prevenir race conditions
  const changeLanguageQueue = ref<Promise<boolean> | null>(null)

  // Cache de traducciones seguro
  const translationCache = new TranslationCache()

  // Initialize serverTranslations de manera segura
  const serverTranslations = ref<ServerTranslations>(
    sanitizeServerTranslations((page.props as any)?.serverTranslations)
  )

  const currentServerLocale = computed(() => {
    const l = serverTranslations.value?.locale
    return (l && isValidLocale(l)) ? l : 'es'
  })

  const currentLanguage = computed<Locale>(() => {
    if (optimisticLocale.value && isChanging.value) {
      return optimisticLocale.value
    }
    const current = locale.value as string
    const available = getAvailableLocales()
    return (available.includes(current as Locale) ? (current as Locale) : ('es' as Locale))
  })

  const browserLanguage = computed(() => getBrowserLanguage())
  const isBrowserSupported = computed(() => isBrowserLanguageSupported())

  const supportedLanguages = computed<Locale[]>(() => {
    const serverSupported = serverTranslations.value.supported || []
    const availableLocales = [...getAvailableLocales()]
    if (!Array.isArray(serverSupported)) return availableLocales
    const validSupported = serverSupported.filter((lang): lang is Locale => {
      return isValidLocale(lang) && availableLocales.includes(lang as Locale)
    })
    return validSupported.length > 0 ? validSupported : availableLocales
  })

  watch(
    () => (page.props as any)?.serverTranslations,
    (newTranslations) => {
      if (!newTranslations) return
      
      try {
        const translations = sanitizeServerTranslations(newTranslations)
        const prevTranslations = serverTranslations.value
        
        serverTranslations.value = translations

        // Limpiar optimistic locale si coincide
        if (optimisticLocale.value === translations.locale) {
          optimisticLocale.value = null
        }

        // Sincronizar locale si es necesario
        if (
          translations.locale !== currentLanguage.value &&
          supportedLanguages.value.includes(translations.locale as Locale) &&
          !isChanging.value
        ) {
          nextTick(() => {
            setLocale(translations.locale as Locale).catch(e => {
              console.error('Error syncing locale:', e)
              lastError.value = e instanceof Error ? e.message : 'Sync error'
            })
          })
        }

        // Manejar errores del servidor
        if (translations.error && !prevTranslations?.error) {
          console.error('Server translation error detected')
          lastError.value = 'Server translation error'
        }
      } catch (error) {
        console.error('Error processing server translations:', error)
        lastError.value = error instanceof Error ? error.message : 'Processing error'
      }
    },
    { immediate: true, deep: true }
  )

  onBeforeUnmount(() => {
    optimisticLocale.value = null
    isChanging.value = false
    lastError.value = null
    changeLanguageQueue.value = null
    translationCache.destroy()
  })

  // Función interna que realiza el cambio real
  const performLanguageChange = async (
    newLocale: Locale,
    options: ChangeLanguageOptions = {}
  ): Promise<boolean> => {
    const {
      timeout = DEFAULT_TIMEOUT,
      retries = MAX_RETRIES,
      optimistic = true
    } = options
    
    if (!isValidLocale(newLocale)) {
      lastError.value = 'Invalid locale provided'
      return false
    }
    
    const now = Date.now()
    const backoffMultiplier = Math.min(changeAttempts.value, 5)
    const throttleTime = Math.min(THROTTLE_TIME * (backoffMultiplier + 1), MAX_THROTTLE_TIME)
    
    if (now - lastChangeTime.value < throttleTime) {
      lastError.value = `Please wait ${Math.ceil(throttleTime / 1000)} seconds between language changes`
      return false
    }
    
    if (isChanging.value) {
      lastError.value = 'Language change already in progress'
      return false
    }
    
    if (currentLanguage.value === newLocale && !optimisticLocale.value) {
      return true
    }
    
    if (!supportedLanguages.value.includes(newLocale)) {
      lastError.value = `Unsupported language: ${newLocale}`
      return false
    }

    isChanging.value = true
    lastChangeTime.value = now
    lastError.value = null

    // Aplicar cambio optimístico
    if (optimistic) {
      optimisticLocale.value = newLocale
      try {
        await setLocale(newLocale)
      } catch (e) {
        console.warn('Optimistic locale update failed:', e)
      }
    }

    try {
      for (let attempt = 0; attempt <= retries; attempt++) {
        const isRetry = attempt > 0
        if (isRetry) {
          // Exponential backoff
          const wait = Math.min(1000 * Math.pow(2, attempt - 1), 10000)
          await new Promise(r => setTimeout(r, wait))
          console.info(`Language change retry ${attempt}/${retries} for locale: ${newLocale}`)
        }

        try {
          await new Promise<void>((resolve, reject) => {
            let settled = false
            const timeoutId = setTimeout(() => {
              if (!settled) {
                settled = true
                reject(new Error('timeout'))
              }
            }, timeout)

            router.post(`/language/change/${newLocale}`, {}, {
              preserveScroll: true,
              preserveState: false,
              onSuccess: (respPage: any) => {
                try {
                  // Detectar si es respuesta JSON vs Inertia
                  const isJsonResponse = respPage && typeof respPage === 'object' && 
                                        ('success' in respPage || 'data' in respPage) && 
                                        !('props' in respPage)
                  
                  if (isJsonResponse) {
                    // Respuesta JSON del servidor
                    if (settled) return
                    settled = true
                    clearTimeout(timeoutId)
                    
                    if (respPage.success) {
                      // Actualizar serverTranslations si están disponibles
                      if (respPage.serverTranslations) {
                        const newTranslations = sanitizeServerTranslations(respPage.serverTranslations)
                        serverTranslations.value = newTranslations
                      }
                      changeAttempts.value = 0
                      resolve()
                    } else {
                      const errorMsg = respPage.message || respPage.error || 'Unknown error'
                      reject(new Error(`Controller error: ${errorMsg}`))
                    }
                    return
                  }
                  
                  // Respuesta Inertia tradicional
                  const flash = respPage?.props?.flash || {}
                  
                  // Verificar múltiples condiciones de error
                  const hasError = flash.error || 
                                 flash.success === false ||
                                 (!flash.success && flash.message && 
                                  String(flash.message).toLowerCase().includes('error'))
                  
                  if (hasError) {
                    if (settled) return
                    settled = true
                    clearTimeout(timeoutId)
                    const errorMsg = flash.error || flash.message || 'Unknown error'
                    reject(new Error(`Controller error: ${errorMsg}`))
                    return
                  }
                  
                  if (settled) return
                  settled = true
                  clearTimeout(timeoutId)
                  
                  const serverLang = respPage?.props?.serverTranslations?.locale
                  if (serverLang === newLocale) {
                    changeAttempts.value = 0
                    resolve()
                  } else {
                    reject(new Error(`Server locale mismatch: expected ${newLocale}, got ${serverLang}`))
                  }
                } catch (processingError) {
                  if (settled) return
                  settled = true
                  clearTimeout(timeoutId)
                  reject(new Error(`Response processing error: ${processingError}`))
                }
              },
              onError: (errors: any) => {
                if (settled) return
                settled = true
                clearTimeout(timeoutId)
                const msg = typeof errors === 'object'
                  ? Object.values(errors).flat().join(', ')
                  : String(errors)
                reject(new Error(`Server error: ${msg}`))
              }
            })
          })

          return true
        } catch (err) {
          changeAttempts.value++
          const isTimeout = err instanceof Error && err.message === 'timeout'
          const isControllerError = err instanceof Error && err.message.includes('Controller error')
          
          if (isTimeout) {
            lastError.value = 'Request timed out. Please check your connection.'
          } else {
            lastError.value = err instanceof Error ? err.message : 'Unknown error'
          }
          
          // Para errores del controlador, no hacer reintentos
          if (isControllerError) {
            if (optimistic && optimisticLocale.value === newLocale) {
              optimisticLocale.value = null
              try {
                await setLocale(currentServerLocale.value as Locale)
              } catch (revertError) {
                console.error('Failed to revert optimistic update:', revertError)
              }
            }
            return false
          }
          
          const isLastAttempt = attempt === retries
          if (isLastAttempt) {
            if (optimistic && optimisticLocale.value === newLocale) {
              optimisticLocale.value = null
              try {
                await setLocale(currentServerLocale.value as Locale)
              } catch (revertError) {
                console.error('Failed to revert optimistic update:', revertError)
              }
            }
            return false
          }
        }
      }
      return false
    } finally {
      isChanging.value = false
    }
  }

  const changeLanguage = async (
    newLocale: Locale,
    options: ChangeLanguageOptions = {}
  ): Promise<boolean> => {
    // Esperar a que termine el cambio anterior
    if (changeLanguageQueue.value) {
      try {
        await changeLanguageQueue.value
      } catch {
        // Ignorar errores previos
      }
    }

    // Crear nueva promesa y asignarla a la cola
    const changePromise = performLanguageChange(newLocale, options)
    changeLanguageQueue.value = changePromise

    try {
      return await changePromise
    } finally {
      // Limpiar cola solo si esta promesa es la actual
      if (changeLanguageQueue.value === changePromise) {
        changeLanguageQueue.value = null
      }
    }
  }

  const toggleLanguage = async (options?: ChangeLanguageOptions): Promise<boolean> => {
    try {
      const available = supportedLanguages.value
      if (available.length < 2) {
        lastError.value = 'Not enough languages available to toggle'
        return false
      }
      const currentIndex = available.indexOf(currentLanguage.value)
      const nextIndex = currentIndex >= 0 ? (currentIndex + 1) % available.length : 0
      const nextLocale = available[nextIndex]
      return await changeLanguage(nextLocale, options)
    } catch (error) {
      lastError.value = error instanceof Error ? error.message : 'Toggle error'
      return false
    }
  }

  const currentLanguageMeta = computed<LanguageMetadata>(() => {
    try {
      const serverMeta = serverTranslations.value.metadata
      if (serverMeta && serverMeta.name && serverMeta.name !== 'Unknown') return serverMeta
      const localMeta = getLocalLanguageMetadata(currentLanguage.value)
      return localMeta || DEFAULT_METADATA
    } catch (error) {
      return DEFAULT_METADATA
    }
  })

  const oppositeLanguage = computed<Locale>(() => {
    try {
      const available = supportedLanguages.value
      if (available.length < 2) return available[0] || ('es' as Locale)
      const opposite = available.find(lang => lang !== currentLanguage.value)
      return opposite ?? available[0]
    } catch {
      return 'en' as Locale
    }
  })

  const oppositeLanguageMeta = computed<LanguageMetadata>(() => {
    try {
      return getLocalLanguageMetadata(oppositeLanguage.value) || DEFAULT_METADATA
    } catch {
      return DEFAULT_METADATA
    }
  })

  const getLocalLanguageMetadata = (locale: Locale): LanguageMetadata | null => {
    const localMetadata: Record<Locale, LanguageMetadata> = {
      es: {
        name: 'Spanish',
        native_name: 'Español',
        flag: '🇪🇸',
        direction: 'ltr',
        region: 'ES'
      },
      en: {
        name: 'English',
        native_name: 'English',
        flag: '🇺🇸',
        direction: 'ltr',
        region: 'US'
      }
    }
    return (localMetadata as any)[locale] || null
  }

  const applyParams = (text: string, params?: Record<string, any> | any[]): string => {
    if (!params || !text) return text || ''
    let result = String(text)
    
    try {
      const sanitizeParam = (value: any): string => {
        if (value == null) return ''
        const str = String(value)
        
        // Sanitización más robusta
        return str
          .replace(/[&<>"']/g, (match) => {
            const entities: Record<string, string> = {
              '&': '&amp;',
              '<': '&lt;',
              '>': '&gt;',
              '"': '&quot;',
              "'": '&#39;'
            }
            return entities[match] || match
          })
          .substring(0, 1000) // Limitar longitud
      }

      if (Array.isArray(params)) {
        params.forEach((param, index) => {
          if (index > 20) return // Limitar cantidad de parámetros
          const safeValue = sanitizeParam(param)
          const placeholder = new RegExp(`:${index}\\b`, 'g')
          result = result.replace(placeholder, safeValue)
        })
      } else if (typeof params === 'object') {
        let paramCount = 0
        Object.entries(params).forEach(([key, value]) => {
          if (paramCount++ > 20) return // Limitar cantidad
          if (!/^[a-zA-Z0-9_]+$/.test(key)) return // Validar clave
          
          const safeValue = sanitizeParam(value)
          const placeholder = new RegExp(`:${key}\\b`, 'g')
          result = result.replace(placeholder, safeValue)
        })
      }
    } catch (error) {
      console.warn('Parameter application error:', error)
      lastError.value = 'Parameter application error'
    }
    
    return result
  }

  const getServerTranslation = (key: string, fallback?: string): string => {
    if (!key || typeof key !== 'string') return fallback || key || ''
    if (key.length > 200) return fallback || key // Evitar claves muy largas
    
    const cacheKey = `${currentServerLocale.value}:${key}`
    const cached = translationCache.get(cacheKey)
    if (cached) return cached
    
    try {
      const keys = key.split('.')
      if (keys.length > 10) return fallback || key // Limitar profundidad
      
      let value: any = serverTranslations.value.messages
      for (const k of keys) {
        if (!k || typeof k !== 'string') break
        if (value && typeof value === 'object' && k in value) {
          value = value[k]
        } else {
          const result = fallback || key
          translationCache.set(cacheKey, result)
          return result
        }
      }
      
      if (typeof value === 'string' && value.trim()) {
        translationCache.set(cacheKey, value)
        return value
      }
      
      const result = fallback || key
      translationCache.set(cacheKey, result)
      return result
    } catch (error) {
      console.warn(`Translation error for: ${key}`, error)
      const result = fallback || key
      translationCache.set(cacheKey, result)
      lastError.value = `Translation error for: ${key}`
      return result
    }
  }

  const hasServerTranslation = (key: string): boolean => {
    if (!key || typeof key !== 'string' || key.length > 200) return false
    
    try {
      const keys = key.split('.')
      if (keys.length > 10) return false
      
      let value: any = serverTranslations.value.messages
      for (const k of keys) {
        if (!k || typeof k !== 'string') return false
        if (value && typeof value === 'object' && k in value) {
          value = value[k]
        } else {
          return false
        }
      }
      return typeof value === 'string' && value.trim() !== ''
    } catch {
      return false
    }
  }

  const translateHybrid = (key: string, params?: Record<string, any> | any[]): string => {
    if (!key) return ''
    
    try {
      if (hasServerTranslation(key)) {
        const translation = getServerTranslation(key)
        return params ? applyParams(translation, params) : translation
      }
      
      const i18nResult = params ? i18nT(key, params) : i18nT(key)
      return i18nResult !== key ? i18nResult : key
    } catch (error) {
      console.warn(`Translation error for: ${key}`, error)
      lastError.value = `Translation error for: ${key}`
      return key
    }
  }

  const isCurrentLanguage = (locale: Locale): boolean => {
    try {
      return currentLanguage.value === locale
    } catch {
      return false
    }
  }

  const getLanguageName = (locale: Locale): string => {
    try {
      const meta = getLocalLanguageMetadata(locale)
      return meta?.name || locale
    } catch {
      return locale
    }
  }

  const getLanguageFlag = (locale: Locale): string => {
    try {
      const meta = getLocalLanguageMetadata(locale)
      return meta?.flag || '🌐'
    } catch {
      return '🌐'
    }
  }

  const getLastError = (): string | null => lastError.value
  const clearError = (): void => { lastError.value = null }
  
  const getErrorDetails = () => ({
    lastError: lastError.value,
    changeAttempts: changeAttempts.value,
    isChanging: isChanging.value,
    lastChangeTime: lastChangeTime.value,
    hasServerError: serverTranslations.value.error || false,
    hasPendingChange: changeLanguageQueue.value !== null
  })

  const clearTranslationCache = (): void => {
    translationCache.clear()
    console.info('Translation cache cleared')
  }

  const getCacheStats = () => translationCache.getStats()

  const cancelPendingRequests = (): void => {
    router.cancel()        // <- aborta la visita activa de Inertia
    isChanging.value = false
    optimisticLocale.value = null
    changeLanguageQueue.value = null
    console.info('Pending language change state cleared')
  }
  

  return {
    currentLanguage,
    isChanging: readonly(isChanging),
    serverTranslations: readonly(serverTranslations),
    currentServerLocale,
    browserLanguage,
    isBrowserSupported,
    supportedLanguages,
    currentLanguageMeta,
    oppositeLanguage,
    oppositeLanguageMeta,
    changeLanguage,
    toggleLanguage,
    getServerTranslation,
    hasServerTranslation,
    t: translateHybrid,
    isCurrentLanguage,
    getLanguageName,
    getLanguageFlag,
    getLastError,
    clearError,
    getErrorDetails,
    clearTranslationCache,
    getCacheStats,
    cancelPendingRequests,
    applyParams
  }
}