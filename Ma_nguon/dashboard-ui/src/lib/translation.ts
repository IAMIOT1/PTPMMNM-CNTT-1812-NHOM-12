const unsupportedMockTargets = new Set(['aa', 'ab'])

const dictionary: Record<string, string> = {
  'Welcome back to your workspace': 'Chào mừng bạn quay lại không gian làm việc',
  'Everything you need, all in one place': 'Mọi thứ bạn cần, tất cả trong một nơi',
  'Manage your notification preferences': 'Quản lý tùy chọn thông báo của bạn',
  'Choose a payment method': 'Chọn phương thức thanh toán',
  'Save changes': 'Lưu thay đổi',
  'Discard changes': 'Hủy thay đổi',
  'Your invitation has been sent': 'Lời mời của bạn đã được gửi',
}

export interface MockTranslationResult {
  translatedText: string
  usedFallback: boolean
  effectiveTarget: string
}

export async function mockMyMemoryTranslate(
  text: string,
  targetLocale: string,
): Promise<MockTranslationResult> {
  await new Promise((resolve) => window.setTimeout(resolve, 650))
  const usedFallback = unsupportedMockTargets.has(targetLocale.toLowerCase())
  const effectiveTarget = usedFallback ? 'en' : targetLocale
  const translatedText = usedFallback
    ? `[English fallback] ${text}`
    : dictionary[text] ?? `[${targetLocale.toUpperCase()}] ${text}`

  return { translatedText, usedFallback, effectiveTarget }
}
