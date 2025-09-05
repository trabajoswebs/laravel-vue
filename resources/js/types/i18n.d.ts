declare module 'vue-i18n' {
  import { Ref } from 'vue'
  
  export interface Locale {
    [key: string]: any
  }
  
  export interface I18nOptions {
    locale?: string
    fallbackLocale?: string
    messages?: Record<string, Locale>
    legacy?: boolean
    globalInjection?: boolean
  }
  
  export interface Composer {
    t: (key: string, ...args: any[]) => string
    locale: Ref<string>
    availableLocales: string[]
    fallbackLocale: Ref<string>
  }
  
  export interface VueI18n {
    t: (key: string, ...args: any[]) => string
    locale: string
    availableLocales: string[]
    fallbackLocale: string
  }
  
  export interface I18n {
    global: Composer | VueI18n
    mode: 'composition' | 'legacy'
    install: (app: any) => void
  }
  
  export function createI18n(options: I18nOptions): I18n
  
  export function useI18n(): Composer
}

declare module '*.json' {
  const value: any
  export default value
}