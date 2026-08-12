import { build } from 'esbuild';

const src = `
import { resolveApiBaseUrl } from './src/services/apiBase';
console.log('DEV result:', resolveApiBaseUrl());
`;

const run = async (defines, globals) => {
  const result = await build({
    stdin: { contents: src, resolveDir: '.', sourcefile: 'test.ts', loader: 'ts' },
    bundle: true, write: false, platform: 'node', format: 'cjs',
    define: defines,
  });
  const wrapped = globals + '\n' + result.outputFiles[0].text;
  const script = wrapped.replace('require("axios")', 'null');
  const fn = new Function(script.replace(/^require.*?$/, ''));
  fn();
};

// Dev: no override, page on a tenant host
await run({ 'import.meta.env.DEV': 'true', 'import.meta.env.VITE_API_BASE_URL': 'undefined' }, "globalThis.window = { location: { hostname: 'hospital-two.vee-care.test' } };");
// Dev: explicit override wins
await run({ 'import.meta.env.DEV': 'true', 'import.meta.env.VITE_API_BASE_URL': '"https://api.vee-care.test/api"' }, "globalThis.window = { location: { hostname: 'hospital-two.vee-care.test' } };");
// Dev: platform host
await run({ 'import.meta.env.DEV': 'true', 'import.meta.env.VITE_API_BASE_URL': 'undefined' }, "globalThis.window = { location: { hostname: 'vee-care.test' } };");
