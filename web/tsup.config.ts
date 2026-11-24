import { defineConfig } from 'tsup';

// Two entries built together, so what they share (the api, the payload types) lands in shared chunks and exists
// once: `.` (client and types) and `./screen` (the admin screens). Dependencies and peer dependencies stay external.
// `scripts/check-package.mjs` verifies the split after every build.
export default defineConfig({
  entry: {
    index: 'src/index.ts',
    'screen/index': 'src/screen/index.ts',
  },
  format: ['esm'],
  dts: true,
  sourcemap: true,
  splitting: true,
  clean: false,
  target: 'es2022',
  platform: 'browser',
  // Class names must stay readable string literals: Tailwind scans `dist` for them.
  minify: false,
  // The screens are client code (context, hooks, DOM). Bundling drops the sources' own directives, so every output
  // file gets one.
  banner: { js: "'use client';" },
});
