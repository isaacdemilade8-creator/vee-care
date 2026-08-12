import { build } from 'esbuild';

const src = `
import { configurationPatch, patchHasChanges } from './src/lib/tenant/configuration';

const base = {
  name: "Hospital One",
  branding: { logo: null, favicon: null, primary_color: null, secondary_color: null, accent_color: null, font_family: null },
  modules: { appointments: { enabled: true, required: true, name: "Appointments", description: "d" }, ehr: { enabled: true, required: true, name: "EHR", description: "d" } },
  roles: { doctor: { enabled: true, required: true, label: "Doctor" }, pharmacist: { enabled: true, required: false, label: "Pharmacist" } },
  settings: { locale: "en", timezone: "UTC", date_format: "Y-m-d", time_format: "H:i", default_appointment_duration: 30 },
};

const draft = { ...base, branding: { ...base.branding, accent_color: "#B91C1C" } };
const patch = configurationPatch(base, draft);
console.log("PATCH:", JSON.stringify(patch));
console.log("patchHasChanges:", patchHasChanges(patch));
const noChange = configurationPatch(base, { ...base });
console.log("no-op patch:", JSON.stringify(noChange), "hasChanges:", patchHasChanges(noChange));
`;

const result = await build({
  stdin: { contents: src, resolveDir: '.', sourcefile: 'test.ts', loader: 'ts' },
  bundle: true,
  write: false,
  platform: 'node',
  format: 'cjs',
  external: ['axios'],
});
eval(result.outputFiles[0].text.replace('require("axios")', 'null'));
