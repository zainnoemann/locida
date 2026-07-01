import type { FormInput } from './types.js';

export function uniqueByName(inputs: FormInput[]): FormInput[] {
  const map = new Map<string, FormInput>();
  for (const input of inputs) {
    if (!input?.name) continue;
    if (input.name === '_token' || input.name === '_method') continue;
    if (!map.has(input.name)) map.set(input.name, input);
  }
  return Array.from(map.values());
}

import { faker } from '@faker-js/faker';

export function sampleValue(input: FormInput, variant: 'valid' | 'updated' | 'empty' = 'valid'): string {
  const lowerName = (input.name || '').toLowerCase();
  const type = (input.type || '').toLowerCase();

  if (variant === 'empty') return '';

  if (input.options && input.options.length > 0) {
    const validOptions = input.options.filter(o => o.value !== '');
    if (validOptions.length > 0) {
      return variant === 'updated' 
        ? validOptions[validOptions.length - 1].value 
        : validOptions[0].value;
    }
  }

  // Faker for email
  if (lowerName.includes('email') || type === 'email') {
    return variant === 'updated' ? `updated_${faker.internet.email()}` : faker.internet.email();
  }
  // Password usually needs to be static for auth, but here it's dummy data
  if (lowerName.includes('password') || type === 'password') {
    return variant === 'updated' ? 'playwright1234' : 'playwright'; 
  }
  if (lowerName.includes('title')) {
    return variant === 'updated' ? `Updated ${faker.lorem.words(3)}` : faker.lorem.words(3);
  }
  if (lowerName.includes('name')) {
    return variant === 'updated' ? `Updated ${faker.person.fullName()}` : faker.person.fullName();
  }
  if (lowerName.includes('phone') || type === 'tel') {
    return faker.phone.number();
  }
  if (type === 'number') {
    return faker.number.int({ min: 1, max: 100 }).toString();
  }
  if (type === 'date') {
    return faker.date.recent().toISOString().split('T')[0];
  }
  if (lowerName.includes('text') || type === 'textarea') {
    return variant === 'updated' ? `Updated ${faker.lorem.sentence()}` : faker.lorem.sentence();
  }
  
  return variant === 'updated' ? 'Updated Value' : faker.lorem.word();
}
