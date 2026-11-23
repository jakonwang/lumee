import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { NMessageProvider } from 'naive-ui'
import FieldInput from '../src/components/theme/FieldInput.vue'

/** Mount the editor field inside the message provider it needs, bound to a ref like the editor does. */
function mountField(field: Record<string, unknown>, initial: unknown) {
  const value = ref(initial)
  const wrapper = mount(defineComponent({
    setup: () => () => h(NMessageProvider, null, {
      default: () => h(FieldInput, { field: field as never, mediaUrls: {}, modelValue: value.value, 'onUpdate:modelValue': (v: unknown) => { value.value = v } }),
    }),
  }))
  return { wrapper, value }
}

describe('FieldInput', () => {
  it('edits a text field and respects its max length', async () => {
    const { wrapper, value } = mountField({ type: 'text', label: 'Heading', max: 20 }, 'Summer')
    const input = wrapper.find('input')
    expect(input.element.value).toBe('Summer')
    expect(input.attributes('maxlength')).toBe('20')
    await input.setValue('Winter sale')
    expect(value.value).toBe('Winter sale')
  })

  it('turns a checkbox field on and off', async () => {
    const { wrapper, value } = mountField({ type: 'checkbox', label: 'Show' }, false)
    await wrapper.find('[role="switch"]').trigger('click')
    expect(value.value).toBe(true)
  })
})
