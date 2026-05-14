import type { FormInput } from '../types.js';

export function uniqueByName(inputs: FormInput[]): FormInput[] {
  const map = new Map<string, FormInput>();
  for (const input of inputs) {
    if (!input?.name) continue;
    if (input.name === '_token' || input.name === '_method') continue;
    if (!map.has(input.name)) map.set(input.name, input);
  }
  return Array.from(map.values());
}

export function sampleValue(input: FormInput, variant: 'valid' | 'updated' | 'empty' = 'valid'): string {
  const lowerName = (input.name || '').toLowerCase();
  const type = (input.type || '').toLowerCase();

  if (variant === 'empty') return '';
  if (lowerName.includes('email') || type === 'email') return variant === 'updated' ? 'updated@example.com' : 'sample@example.com';
  if (lowerName.includes('password') || type === 'password') return variant === 'updated' ? 'playwright1234' : 'playwright';
  if (lowerName.includes('title')) return variant === 'updated' ? 'Updated Title' : 'Sample Title';
  if (lowerName.includes('name')) return variant === 'updated' ? 'Updated Name' : 'Sample Name';
  if (lowerName.includes('text') || type === 'textarea') return variant === 'updated' ? 'Updated text value.' : 'Sample text value.';
  if (type === 'number') return variant === 'updated' ? '99' : '10';
  if (type === 'date') return '2026-01-01';
  return variant === 'updated' ? 'Updated Value' : 'Sample Value';
}
