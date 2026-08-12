import assert from 'node:assert/strict';
import { normalizeHostname, resolveHostContext } from './hostname.ts';

const platform = 'vee-care.test';

assert.equal(normalizeHostname('Hospital-One.VEE-CARE.TEST.'), 'hospital-one.vee-care.test');
assert.equal(normalizeHostname('localhost:5173'), 'localhost');
assert.equal(normalizeHostname('[::1]:8000'), '::1');

// Apex and control-plane subdomains are platform hosts.
assert.equal(resolveHostContext('vee-care.test', { platformDomain: platform }).kind, 'platform');
assert.equal(resolveHostContext('admin.vee-care.test', { platformDomain: platform }).kind, 'platform');
assert.equal(resolveHostContext('api.vee-care.test', { platformDomain: platform }).kind, 'platform');
assert.equal(resolveHostContext('www.vee-care.test', { platformDomain: platform }).kind, 'platform');

// Loopback hosts are treated as the platform (backend dev fallback).
assert.equal(resolveHostContext('localhost', { platformDomain: platform }).kind, 'platform');
assert.equal(resolveHostContext('127.0.0.1:8000', { platformDomain: platform }).kind, 'platform');
assert.equal(resolveHostContext('::1', { platformDomain: platform }).kind, 'platform');

// Tenant subdomains carry the slug.
const hospital = resolveHostContext('hospital-one.vee-care.test', { platformDomain: platform });
assert.equal(hospital.kind, 'tenant');
assert.equal(hospital.slug, 'hospital-one');

// Multi-level and unrelated hosts are unknown.
assert.equal(resolveHostContext('a.b.vee-care.test', { platformDomain: platform }).kind, 'unknown');
assert.equal(resolveHostContext('example.com', { platformDomain: platform }).kind, 'unknown');
assert.equal(resolveHostContext('', { platformDomain: platform }).kind, 'unknown');

// Custom platform subdomains are honored.
assert.equal(
  resolveHostContext('staff.vee-care.test', {
    platformDomain: platform,
    platformSubdomains: ['api', 'admin', 'www', 'staff'],
  }).kind,
  'platform',
);

console.log('hostname smoke tests passed');
