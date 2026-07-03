import assert from 'node:assert/strict';
import { getApiErrorMessage } from './apiError.ts';

assert.equal(getApiErrorMessage({ response: { data: { message: 'Validation failed' } } }), 'Validation failed');
assert.equal(getApiErrorMessage(undefined, 'Retry later'), 'Retry later');
assert.equal(getApiErrorMessage(new Error('Network error')), 'Network error');

console.log('apiError smoke tests passed');
