import type { GlobalThemeOverrides } from 'naive-ui'
import { colors, fonts } from '@lumee/tokens/tokens'

// Naive UI theme mapped onto the Lumée platform palette (fresh green)
export const themeOverrides: GlobalThemeOverrides = {
  common: {
    primaryColor: colors.brand600,
    primaryColorHover: colors.brand500,
    primaryColorPressed: colors.brand700,
    primaryColorSuppl: colors.brand500,
    infoColor: '#2F80C9',
    infoColorHover: '#4793D6',
    infoColorPressed: '#256BAA',
    successColor: colors.success,
    warningColor: colors.warning,
    errorColor: colors.danger,
    textColorBase: colors.ink,
    textColor1: colors.ink,
    textColor2: colors.ink,
    textColor3: colors.inkMuted,
    bodyColor: colors.bg,
    borderColor: colors.border,
    dividerColor: colors.border,
    borderRadius: '8px',
    fontFamily: fonts.body,
  },
  Button: { borderRadiusMedium: '8px', borderRadiusLarge: '10px', borderRadiusSmall: '6px', fontWeightStrong: '600' },
  Card: { borderRadius: '12px', borderColor: colors.border },
  Layout: { siderColor: colors.surface, headerColor: colors.surface, color: colors.bg },
  Menu: {
    itemColorActive: colors.brand100,
    itemColorActiveHover: colors.brand100,
    itemTextColorActive: colors.brand700,
    itemIconColorActive: colors.brand700,
    itemTextColorActiveHover: colors.brand700,
    itemIconColorActiveHover: colors.brand700,
    itemTextColorChildActive: colors.brand700,
    itemIconColorChildActive: colors.brand700,
    itemColorHover: colors.brand50,
    borderRadius: '8px',
  },
  DataTable: { thColor: colors.surfaceMuted, borderRadius: '12px' },
  Tag: { borderRadius: '6px' },
}
