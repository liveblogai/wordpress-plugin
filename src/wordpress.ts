import type {
  ButtonHTMLAttributes,
  ComponentType,
  HTMLAttributes,
  PropsWithChildren,
  ReactElement,
} from 'react'

// 这里只描述插件实际使用的 WordPress 6.3+ 公共浏览器 API，运行实现由站点原生脚本提供。
// 参考：https://developer.wordpress.org/block-editor/reference-guides/packages/
type ControlProps = {
  label: string
  value: string
  help?: string
  placeholder?: string
  onChange: (value: string) => void
}

interface WordPress {
  blocks: {
    registerBlockType: <Attributes>(
      name: string,
      settings: {
        title: string
        description: string
        category: string
        icon: ReactElement
        apiVersion: number
        attributes: Record<string, { type: string; default?: unknown }>
        supports: Record<string, unknown>
        edit: (props: {
          attributes: Attributes
          setAttributes: (attributes: Partial<Attributes>) => void
        }) => ReactElement
        save: () => null
      }
    ) => unknown
  }
  blockEditor: {
    InspectorControls: ComponentType<PropsWithChildren>
    useBlockProps: (
      props: HTMLAttributes<HTMLDivElement>
    ) => HTMLAttributes<HTMLDivElement>
  }
  components: {
    Button: ComponentType<
      ButtonHTMLAttributes<HTMLButtonElement> & {
        variant: 'primary' | 'secondary' | 'tertiary'
      }
    >
    Notice: ComponentType<
      PropsWithChildren<{ status: 'warning' | 'error'; isDismissible: boolean }>
    >
    PanelBody: ComponentType<PropsWithChildren<{ title: string }>>
    SelectControl: ComponentType<
      ControlProps & { options: { label: string; value: string }[] }
    >
    TextControl: ComponentType<ControlProps>
  }
  i18n: {
    __: (text: string, domain: string) => string
  }
}

declare global {
  interface Window {
    wp: WordPress
  }
}

export const { registerBlockType } = window.wp.blocks
export const { InspectorControls, useBlockProps } = window.wp.blockEditor
export const { Button, Notice, PanelBody, SelectControl, TextControl } =
  window.wp.components
export const { __ } = window.wp.i18n
