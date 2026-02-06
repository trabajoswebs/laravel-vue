import { computed, ref, watch, nextTick, readonly, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePage, router } from '@inertiajs/vue3'
import {
  setLocale,
  getBrowserLanguage,
  isBrowserLanguageSupported,
  getAvailableLocales,
  isLikelyI18nKey,
  safeT,
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

/*
  useLanguage - Versión documentada y comentada línea a línea
  -----------------------------------------------------------
  Este fichero contiene las funciones y la composición `useLanguage` que
  manejan la selección de idioma en la app. Cada línea tiene un comentario
  explicativo (en español) y, cuando procede, un ejemplo de resultado.

  Nota: Las anotaciones de ejemplo son orientativas y muestran valores
  típicos que produciría la ejecución en condiciones normales.
*/

// ------------------ VALIDADORES SEGUROS ------------------

// Comprueba si el valor suministrado es un Locale válido (string con 2-10 chars y sin caracteres raros)
function isValidLocale(locale: unknown): locale is Locale {
  // Verifica que sea string. // Ej.: 'es' => true
  return typeof locale === 'string' && 
         // Longitud mínima 2. // Ej.: 'e' => false, 'es' => true
         locale.length >= 2 && 
         // Longitud máxima 10. // Ej.: 'long-locale' (11) => false
         locale.length <= 10 && 
         // Solo caracteres alfanuméricos, '_' o '-'. // Ej.: 'en-US' => true, 'es¡' => false
         /^[a-zA-Z0-9_-]+$/.test(locale)
}

// Normaliza/valida la estructura que llega del servidor con traducciones
function sanitizeServerTranslations(data: unknown): ServerTranslations {
  // Si no existe o no es objeto, devolvemos valores por defecto seguros.
  // Ejemplo de retorno por defecto: { locale: 'es', messages: {}, fallbackLocale: 'en', supported: ['es','en'], metadata: DEFAULT_METADATA }
  if (!data || typeof data !== 'object') {
    return {
      locale: 'es',                    // Valor por defecto de locale. // Ej.: 'es'
      messages: {},                    // Mensajes vacíos. // Ej.: {}
      fallbackLocale: 'en',            // Fallback por defecto. // Ej.: 'en'
      supported: ['es', 'en'],         // Idiomas soportados por defecto. // Ej.: ['es','en']
      metadata: DEFAULT_METADATA       // Metadatos por defecto. // Ej.: DEFAULT_METADATA
    }
  }

  // Tratamos `data` como un objeto genérico para extraer campos con seguridad.
  const obj = data as Record<string, unknown>
  
  return {
    // Si obj.locale es válido, lo usamos; si no, 'es'. // Ej.: obj.locale = 'fr' => 'fr', obj.locale = 123 => 'es'
    locale: isValidLocale(obj.locale) ? obj.locale : 'es',

    // 'messages' debe ser un objeto: si no, usamos {}. // Ej.: obj.messages = {hello: 'Hola'} => {hello: 'Hola'}
    messages: (obj.messages && typeof obj.messages === 'object') ? obj.messages : {},

    // Fallback validado. // Ej.: obj.fallbackLocale = 'en' => 'en'
    fallbackLocale: isValidLocale(obj.fallbackLocale) ? obj.fallbackLocale : 'en',

    // 'supported' debe ser array y filtramos por locales válidos; si no, devolvemos ['es','en'].
    // Ej.: obj.supported = ['es','fr','bad!'] => ['es','fr']
    supported: Array.isArray(obj.supported) ? obj.supported.filter(isValidLocale) : ['es', 'en'],

    // Metadata: si existe y es objeto, mezclamos con DEFAULT_METADATA para tener siempre campos mínimos.
    // Ej.: obj.metadata = { name: 'Español' } => { ...DEFAULT_METADATA, name: 'Español' }
    metadata: (obj.metadata && typeof obj.metadata === 'object') ? 
      { ...DEFAULT_METADATA, ...obj.metadata } : DEFAULT_METADATA,

    // Normalizamos `error` a boolean. // Ej.: obj.error = true => true
    error: Boolean(obj.error)
  }
}


// ------------------ HOOK PRINCIPAL: useLanguage ------------------

export function useLanguage() {
  // Importamos helpers de i18n e Inertia.
  const { locale } = useI18n() // locale: referencia reactiva al idioma actual // Ej.: locale.value === 'es'
  const page = usePage()                 // page: props de Inertia // Ej.: page.props.serverTranslations => {...}

  // ---------- Estado reactivo local ----------
  const isChanging = ref(false)                     // Indica si ya hay un cambio en curso. // Ej.: false
  const lastChangeTime = ref(0)                     // Timestamp del último cambio. // Ej.: 1690000000000
  const changeAttempts = ref(0)                     // Número de intentos fallidos recientes. // Ej.: 1
  const lastError = ref<string | null>(null)        // Mensaje del último error. // Ej.: 'timeout'
  const optimisticLocale = ref<Locale | null>(null) // Locale aplicado de forma optimista antes de confirmar. // Ej.: 'en'
  
  // Cola para prevenir race conditions entre cambios de idioma.
  const changeLanguageQueue = ref<Promise<boolean> | null>(null) // Ej.: Promise<boolean>

  // Cache de traducciones con limpieza automática (clase definida fuera de este extracto)
  const translationCache = new TranslationCache() // Instancia del cache. // Ej.: translationCache.getStats() => {size:0, ...}

  // Inicializamos serverTranslations con valores saneados extraídos de las props de Inertia
  const serverTranslations = ref<ServerTranslations>(
    sanitizeServerTranslations((page.props as any)?.serverTranslations)
  ) // Ej.: { locale: 'es', messages: {...}, supported: ['es','en'] }

  // Computed: locale que el servidor reporta actualmente (con validación)
  const currentServerLocale = computed(() => {
    const l = serverTranslations.value?.locale // Leer locale desde serverTranslations. // Ej.: 'es'
    return (l && isValidLocale(l)) ? l : 'es'   // Si inválido, fallback 'es'. // Ej.: 'es'
  })

  // Computed: locale actual que usa la app (considera cambio optimista)
  const currentLanguage = computed<Locale>(() => {
    // Si hay cambio optimista y está en proceso, devolvemos ese valor.
    if (optimisticLocale.value && isChanging.value) {
      return optimisticLocale.value // Ej.: 'en'
    }
    const current = locale.value as string // locale.value proviene de vue-i18n. // Ej.: 'es'
    const available = getAvailableLocales() // Lista de locales disponibles en cliente. // Ej.: ['es','en']
    return (available.includes(current as Locale) ? (current as Locale) : ('es' as Locale))
    // Si current no está en available, devolvemos 'es'. // Ej.: 'fr' no en available => 'es'
  })

  // Computed: idioma del navegador (sin validar) // Ej.: 'es-ES' o 'en'
  const browserLanguage = computed(() => getBrowserLanguage())

  // Computed: si el idioma del navegador es soportado // Ej.: true/false
  const isBrowserSupported = computed(() => isBrowserLanguageSupported())

  // Computed: idiomas soportados en la app, combinación servidor + cliente
  const supportedLanguages = computed<Locale[]>(() => {
    const serverSupported = serverTranslations.value.supported || [] // Lista enviada por servidor. // Ej.: ['es','en']
    const availableLocales = [...getAvailableLocales()]                // Locales locales/cliente. // Ej.: ['es','en']
    if (!Array.isArray(serverSupported)) return availableLocales      // Si servidor no envía array, usamos locales del cliente.

    // Filtramos sólo locales válidos y que además estén físicamente disponibles en cliente
    const validSupported = serverSupported.filter((lang): lang is Locale => {
      return isValidLocale(lang) && availableLocales.includes(lang as Locale)
    }) // Ej.: serverSupported=['es','fr']; available=['es','en'] => validSupported=['es']

    // Si tras filtrar hay al menos uno, devolvemos validSupported; sino devolvemos availableLocales
    return validSupported.length > 0 ? validSupported : availableLocales
  })

  // ---------- Observador de cambios en las props servidor (Inertia) ----------
  watch(
    () => (page.props as any)?.serverTranslations, // Observa los cambios en page.props.serverTranslations
    (newTranslations) => {
      if (!newTranslations) return // Si no hay nada nuevo, salir
      
      try {
        const translations = sanitizeServerTranslations(newTranslations) // Saneamos la payload entrante
        const prevTranslations = serverTranslations.value
        
        serverTranslations.value = translations // Actualizamos el reactive ref con lo saneado

        // Si teníamos un cambio optimista que coincide con lo que el servidor confirma, lo limpiamos
        if (optimisticLocale.value === translations.locale) {
          optimisticLocale.value = null // Ej.: optimisticLocale 'en' confirmado por servidor => limpiarlo
        }

        // Si el servidor indica un locale distinto al actual y está soportado, intentamos sincronizarlo
        if (
          translations.locale !== currentLanguage.value &&
          supportedLanguages.value.includes(translations.locale as Locale) &&
          !isChanging.value
        ) {
          nextTick(() => {
            // Intentamos setear el locale (setLocale es una función que actualiza i18n y/o back-end)
            setLocale(translations.locale as Locale).catch(e => {
              console.error('Error syncing locale:', e)
              lastError.value = e instanceof Error ? e.message : 'Sync error' // Ej.: 'Network error'
            })
          })
        }

        // Si servidor ha reportado un error en traducciones y antes no había error, lo registramos
        if (translations.error && !prevTranslations?.error) {
          console.error('Server translation error detected')
          lastError.value = 'Server translation error' // Ej.: 'Server translation error'
        }
      } catch (error) {
        console.error('Error processing server translations:', error)
        lastError.value = error instanceof Error ? error.message : 'Processing error'
      }
    },
    { immediate: true, deep: true } // Ejecutar inmediatamente y observar profundamente
  )

  // Limpieza cuando el componente que usa el hook se desmonta
  onBeforeUnmount(() => {
    optimisticLocale.value = null
    isChanging.value = false
    lastError.value = null
    changeLanguageQueue.value = null
    translationCache.destroy() // Limpiamos la caché y cancelamos timers internos
  })

  // ---------- Lógica interna: cambio real de idioma con retries, timeouts y optimismo ----------
  const performLanguageChange = async (
    newLocale: Locale,
    options: ChangeLanguageOptions = {}
  ): Promise<boolean> => {
    const {
      timeout = DEFAULT_TIMEOUT, // Tiempo máximo de espera por petición (ms) // Ej.: 10000
      retries = MAX_RETRIES,     // Reintentos permitidos // Ej.: 3
      optimistic = true          // Si aplicar cambio optimista antes de confirmar // Ej.: true
    } = options
    
    // Validación básica del locale solicitado
    if (!isValidLocale(newLocale)) {
      lastError.value = 'Invalid locale provided' // Ej.: 'Invalid locale provided'
      return false
    }
    
    const now = Date.now()
    const backoffMultiplier = Math.min(changeAttempts.value, 5) // Limitar multiplicador de backoff
    const throttleTime = Math.min(THROTTLE_TIME * (backoffMultiplier + 1), MAX_THROTTLE_TIME) // Tiempo mínimo entre cambios
    
    // Evitar cambios demasiado frecuentes (throttling)
    if (now - lastChangeTime.value < throttleTime) {
      lastError.value = `Please wait ${Math.ceil(throttleTime / 1000)} seconds between language changes`
      return false
    }
    
    // Si ya hay un cambio en curso, no arrancamos otro
    if (isChanging.value) {
      lastError.value = 'Language change already in progress'
      return false
    }
    
    // Si ya estamos en el locale requerido y no hay optimismo pendiente, devolvemos true directamente
    if (currentLanguage.value === newLocale && !optimisticLocale.value) {
      return true
    }
    
    // Comprobamos que el idioma esté soportado (según server+cliente)
    if (!supportedLanguages.value.includes(newLocale)) {
      lastError.value = `Unsupported language: ${newLocale}`
      return false
    }

    // Marcamos que el cambio ha comenzado
    isChanging.value = true
    lastChangeTime.value = now
    lastError.value = null

    // Aplicar cambio optimista si está habilitado: actualizamos UI localmente primero
    if (optimistic) {
      optimisticLocale.value = newLocale // Ej.: optimisticLocale='fr'
      try {
        await setLocale(newLocale) // setLocale actualiza i18n localmente (puede fallar)
      } catch (e) {
        console.warn('Optimistic locale update failed:', e)
      }
    }

    try {
      // Intentos con reintentos y backoff exponencial
      for (let attempt = 0; attempt <= retries; attempt++) {
        const isRetry = attempt > 0
        if (isRetry) {
          // Espera exponencial con límite
          const wait = Math.min(1000 * Math.pow(2, attempt - 1), 10000)
          await new Promise(r => setTimeout(r, wait))
          console.info(`Language change retry ${attempt}/${retries} for locale: ${newLocale}`)
        }

        try {
          // Hacemos la petición al backend usando Inertia (router.post)
          await new Promise<void>((resolve, reject) => {
            let settled = false
            const timeoutId = setTimeout(() => {
              if (!settled) {
                settled = true
                reject(new Error('timeout')) // Si el tiempo se agota, rechazamos
              }
            }, timeout)

            router.post(`/language/change/${newLocale}`, {}, {
              preserveScroll: true,
              preserveState: false,
              onSuccess: (respPage: any) => {
                try {
                  // Detectar si la respuesta es JSON puro (API) vs visita Inertia con props
                  const isJsonResponse = respPage && typeof respPage === 'object' && 
                                        ('success' in respPage || 'data' in respPage) && 
                                        !('props' in respPage)
                  
                  if (isJsonResponse) {
                    // Respuesta JSON: comprobamos éxito y actualizamos serverTranslations si vienen
                    if (settled) return
                    settled = true
                    clearTimeout(timeoutId)
                    
                    if (respPage.success) {
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
                  
                  // Respuesta Inertia (clásica): leemos flash y props
                  const flash = respPage?.props?.flash || {}
                  
                  // Comprobaciones múltiples para detectar fallo en flash
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
                  
                  // Si el servidor reporta serverTranslations, comprobamos que el locale coincida
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

          // Si llegamos aquí, la petición se confirmó correctamente
          return true
        } catch (err) {
          // Error en intento: sumamos intentos y decidimos si reintentamos
          changeAttempts.value++
          const isTimeout = err instanceof Error && err.message === 'timeout'
          const isControllerError = err instanceof Error && err.message.includes('Controller error')
          
          if (isTimeout) {
            lastError.value = 'Request timed out. Please check your connection.'
          } else {
            lastError.value = err instanceof Error ? err.message : 'Unknown error'
          }
          
          // Para errores del controlador no hacemos reintentos: revertir optimismo y salir
          if (isControllerError) {
            if (optimistic && optimisticLocale.value === newLocale) {
              optimisticLocale.value = null
              try {
                await setLocale(currentServerLocale.value as Locale) // Revertir a locale servidor
              } catch (revertError) {
                console.error('Failed to revert optimistic update:', revertError)
              }
            }
            return false
          }
          
          const isLastAttempt = attempt === retries
          if (isLastAttempt) {
            // Si es el último intento fallido, revertir optimismo si aplica
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
      // Al final de todo (éxito o fallo) indicamos que ya no estamos cambiando
      isChanging.value = false
    }
  }

  // ---------- API pública: wrapper que gestiona la cola para no solapar cambios ----------
  const changeLanguage = async (
    newLocale: Locale,
    options: ChangeLanguageOptions = {}
  ): Promise<boolean> => {
    // Si ya hay una promesa en curso, esperamos a que termine para evitar race conditions
    if (changeLanguageQueue.value) {
      try {
        await changeLanguageQueue.value
      } catch {
        // Ignoramos errores previos, seguimos con nuevo intento
      }
    }

    // Creamos la promesa del cambio y la ponemos en la cola
    const changePromise = performLanguageChange(newLocale, options)
    changeLanguageQueue.value = changePromise

    try {
      return await changePromise
    } finally {
      // Si la promesa en la cola es la que acabó, limpiamos la cola
      if (changeLanguageQueue.value === changePromise) {
        changeLanguageQueue.value = null
      }
    }
  }
  
  // ------------------------------------------------------------------------------------------
  // 🚦 Gestión de idioma
  // ------------------------------------------------------------------------------------------

  // Cambia al siguiente idioma disponible en la lista de supportedLanguages
  // Ej: si el idioma actual es "es" y supportedLanguages = ["es","en"] => cambiará a "en".
  const toggleLanguage = async (options?: ChangeLanguageOptions): Promise<boolean> => {
    try {
      const available = supportedLanguages.value // Ej. ["es", "en"]
      if (available.length < 2) {
        lastError.value = 'Not enough languages available to toggle'
        return false
      }
      const currentIndex = available.indexOf(currentLanguage.value) // Ej. indexOf("es") => 0
      const nextIndex = currentIndex >= 0 ? (currentIndex + 1) % available.length : 0 // Ej. 1
      const nextLocale = available[nextIndex] // Ej. "en"
      return await changeLanguage(nextLocale, options) // Ejecuta cambio
    } catch (error) {
      lastError.value = error instanceof Error ? error.message : 'Toggle error'
      return false
    }
  }

  // Metadatos del idioma actual (nombre, bandera, etc.)
  // Usa serverTranslations.metadata o fallback local
  // Ej: currentLanguage = "es" => { name:"Spanish", native_name:"Español", flag:"🇪🇸", ... }
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

  // Idioma opuesto al actual (cuando hay solo 2 idiomas disponibles)
  // Ej: currentLanguage="es", supportedLanguages=["es","en"] => devuelve "en".
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

  // Metadatos del idioma opuesto
  // Ej: oppositeLanguage="en" => { name:"English", native_name:"English", flag:"🇺🇸", ... }
  const oppositeLanguageMeta = computed<LanguageMetadata>(() => {
    try {
      return getLocalLanguageMetadata(oppositeLanguage.value) || DEFAULT_METADATA
    } catch {
      return DEFAULT_METADATA
    }
  })

  // Retorna metadatos de idiomas locales predefinidos
  // Ej: getLocalLanguageMetadata("es") => { name:"Spanish", native_name:"Español", flag:"🇪🇸" ... }
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

  // ------------------------------------------------------------------------------------------
  // 📖 Traducciones
  // ------------------------------------------------------------------------------------------

  // Aplica parámetros seguros a un texto, previniendo XSS
  // Ej: applyParams("Hola :name", {name:"<b>Johan</b>"}) => "Hola &lt;b&gt;Johan&lt;/b&gt;"
  const applyParams = (text: string, params?: Record<string, any> | any[]): string => {
    if (!params || !text) return text || ''
    let result = String(text)

    try {
      const sanitizeParam = (value: any): string => {
        if (value == null) return ''
        const str = String(value)
        return str
          .replace(/[&<>"']/g, (match) => {
            const entities: Record<string, string> = {
              '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }
            return entities[match] || match
          })
          .substring(0, 1000) // limitar longitud
      }

      if (Array.isArray(params)) {
        params.forEach((param, index) => {
          if (index > 20) return // máximo 20 parámetros
          const safeValue = sanitizeParam(param)
          const placeholder = new RegExp(`:${index}\\b`, 'g')
          result = result.replace(placeholder, safeValue)
        })
      } else if (typeof params === 'object') {
        let paramCount = 0
        Object.entries(params).forEach(([key, value]) => {
          if (paramCount++ > 20) return // máximo 20
          if (!/^[a-zA-Z0-9_]+$/.test(key)) return // validar clave
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

  // Obtiene traducción desde el servidor con cache
  // Ej: getServerTranslation("greeting.hello", "Hola") => "Hello" (si existe en server)
  const getServerTranslation = (key: string, fallback?: string): string => {
    if (!key || typeof key !== 'string') return fallback || key || ''
    if (key.length > 200) return fallback || key

    const cacheKey = `${currentServerLocale.value}:${key}`
    const cached = translationCache.get(cacheKey)
    if (cached) return cached // Ej. "Hello"

    try {
      const keys = key.split('.') // Ej. "greeting.hello" => ["greeting","hello"]
      if (keys.length > 10) return fallback || key

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

  // Verifica si existe una traducción en serverTranslations
  // Ej: hasServerTranslation("greeting.hello") => true
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

  // Traducción híbrida (servidor + cliente)
  // Ej: translateHybrid("greeting.hello", {name:"Johan"}) => "Hola Johan"
  const translateHybrid = (key: string, params?: Record<string, any> | any[]): string => {
    const normalizedKey = typeof key === 'string' ? key.trim() : ''
    if (!normalizedKey) return ''

    // Si no parece una clave, devolvemos el texto tal cual para evitar que el compilador intente enlazarlo.
    if (!isLikelyI18nKey(normalizedKey)) {
      return normalizedKey
    }

    try {
      if (hasServerTranslation(normalizedKey)) {
        const translation = getServerTranslation(normalizedKey)
        return params ? applyParams(translation, params) : translation
      }

      const i18nResult = params ? safeT(normalizedKey, params) : safeT(normalizedKey)
      return i18nResult !== normalizedKey ? i18nResult : normalizedKey
    } catch (error) {
      console.warn(`Translation error for: ${normalizedKey}`, error)
      lastError.value = `Translation error for: ${normalizedKey}`
      return normalizedKey
    }
  }

  // ------------------------------------------------------------------------------------------
  // ⚠️ Manejo de errores
  // ------------------------------------------------------------------------------------------

  // Verifica si un idioma es el actual
  // Ej: isCurrentLanguage("es") => true
  const isCurrentLanguage = (locale: Locale): boolean => {
    try {
      return currentLanguage.value === locale
    } catch {
      return false
    }
  }

  // Obtiene el nombre del idioma
  // Ej: getLanguageName("en") => "English"
  const getLanguageName = (locale: Locale): string => {
    try {
      const meta = getLocalLanguageMetadata(locale)
      return meta?.name || locale
    } catch {
      return locale
    }
  }

  // Obtiene la bandera del idioma
  // Ej: getLanguageFlag("es") => "🇪🇸"
  const getLanguageFlag = (locale: Locale): string => {
    try {
      const meta = getLocalLanguageMetadata(locale)
      return meta?.flag || '🌐'
    } catch {
      return '🌐'
    }
  }

  // Último error registrado
  const getLastError = (): string | null => lastError.value

  // Limpia errores
  const clearError = (): void => { lastError.value = null }

  // Devuelve detalles de errores y estado interno
  // Ej: getErrorDetails() => {lastError:"timeout", changeAttempts:2, ...}
  const getErrorDetails = () => ({
    lastError: lastError.value,
    changeAttempts: changeAttempts.value,
    isChanging: isChanging.value,
    lastChangeTime: lastChangeTime.value,
    hasServerError: serverTranslations.value.error || false,
    hasPendingChange: changeLanguageQueue.value !== null
  })

  // ------------------------------------------------------------------------------------------
  // 🗃️ Cache y control
  // ------------------------------------------------------------------------------------------

  // Limpia cache de traducciones
  const clearTranslationCache = (): void => {
    translationCache.clear()
    console.info('Translation cache cleared')
  }

  // Estadísticas de cache (hits, misses...)
  const getCacheStats = () => translationCache.getStats()

  // Cancela solicitudes pendientes de Inertia
  // Resetea estado de cambio de idioma
  const cancelPendingRequests = (): void => {
    router.cancel() // aborta visita activa
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
