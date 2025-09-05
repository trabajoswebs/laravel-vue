import { createApp, h } from 'vue'
import { Toaster as VueSonner } from 'vue-sonner'

export interface ToasterConfig {
  position?: 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right'
  duration?: number
  closeButton?: boolean
  theme?: 'light' | 'dark' | 'system'
  expand?: boolean
  visibleToasts?: number
  gap?: number
}

export const ToasterPlugin = {
  install(app: any, config: ToasterConfig = {}) {
    // Configuración por defecto profesional
    const defaultConfig: ToasterConfig = {
      position: 'bottom-right',
      duration: 4000,
      closeButton: true,
      theme: 'system',
      ...config
    }

    // Crear contenedor para el Toaster
    const toasterContainer = document.createElement('div')
    toasterContainer.id = 'global-toaster'
    toasterContainer.setAttribute('data-sonner-toaster', '')
    document.body.appendChild(toasterContainer)

    // Crear aplicación Vue para el Toaster
    const toasterApp = createApp({
      name: 'GlobalToaster',
      render() {
        return h(VueSonner, {
          ...defaultConfig,
          class: 'toaster group'
        })
      }
    })

    // Montar el Toaster
    toasterApp.mount(toasterContainer)
  }
}
