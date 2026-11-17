import { Children, cloneElement, isValidElement, type ReactElement, type ReactNode } from 'react'
import { useAppStore } from '../../store/appStore'
import { translateText } from '../../lib/i18n'

const translatableProps = new Set([
  'alt',
  'aria-label',
  'aria-description',
  'caption',
  'description',
  'empty',
  'eyebrow',
  'header',
  'hint',
  'label',
  'legend',
  'name',
  'placeholder',
  'subtitle',
  'title',
])

function getTextContent(node: ReactNode): string | undefined {
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) {
    const parts = node.map(getTextContent)
    return parts.every((part) => part !== undefined) ? parts.join('') : undefined
  }
  return undefined
}

function localizeColumn(column: unknown, language: 'en' | 'vi') {
  if (!column || typeof column !== 'object' || Array.isArray(column)) return column
  const record = column as Record<string, unknown>
  return typeof record.header === 'string'
    ? { ...record, header: translateText(language, record.header) }
    : column
}

function localizeNode(node: ReactNode, language: 'en' | 'vi'): ReactNode {
  if (typeof node === 'string') return translateText(language, node)
  if (Array.isArray(node)) return node.map((item) => localizeNode(item, language))
  if (!isValidElement(node)) return node

  const element = node as ReactElement<Record<string, unknown>>
  const properties: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(element.props)) {
    if (key === 'children') {
      const children = Children.toArray(value as ReactNode)
      const localizedChildren: ReactNode[] = []
      let textParts: (string | number)[] = []
      const flushText = () => {
        if (textParts.length === 0) return
        const text = textParts.map(String).join('')
        localizedChildren.push(textParts.length === 1 && typeof textParts[0] === 'number'
          ? textParts[0]
          : translateText(language, text))
        textParts = []
      }
      for (const child of children) {
        if (typeof child === 'string' || typeof child === 'number') {
          textParts.push(child)
        } else {
          flushText()
          localizedChildren.push(localizeNode(child, language))
        }
      }
      flushText()
      properties.children = localizedChildren
    } else if (translatableProps.has(key) && typeof value === 'string') {
      properties[key] = translateText(language, value)
    } else if (key === 'columns' && Array.isArray(value)) {
      properties.columns = value.map((column) => localizeColumn(column, language))
    } else if (key === 'data' && Array.isArray(value)) {
      properties.data = value.map((item) => {
        if (!item || typeof item !== 'object' || Array.isArray(item)) return item
        const record = item as Record<string, unknown>
        return typeof record.name === 'string'
          ? { ...record, name: translateText(language, record.name) }
          : item
      })
    } else if (isValidElement(value) || Array.isArray(value)) {
      properties[key] = localizeNode(value as ReactNode, language)
    } else {
      properties[key] = value
    }
  }

  if (element.type === 'option' && properties.value === undefined) {
    const originalText = getTextContent(element.props.children as ReactNode)
    if (originalText !== undefined) properties.value = originalText
  }

  return cloneElement(element, properties)
}

export function LocalizedContent({ children }: { children: ReactNode }) {
  const language = useAppStore((state) => state.language)
  return <>{localizeNode(children, language)}</>
}
