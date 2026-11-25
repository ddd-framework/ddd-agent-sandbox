# @mgamadeus/ddd-agent-sandbox

React frontend of the agent sandbox for backends built with [mgamadeus/ddd](https://github.com/ddd-framework/ddd): the
RTK Query client of the sandbox admin routes (`SandboxesControllerTrait` of the Composer package
`mgamadeus/ddd-agent-sandbox`, same version) and the admin screens on the blocks of `@mgamadeus/ddd-admin-ui`.

```ts
import { createSandboxesApi } from '@mgamadeus/ddd-agent-sandbox';
import { SandboxesScreen, SandboxDetailScreen } from '@mgamadeus/ddd-agent-sandbox/screen';

export const sandboxesApi = createSandboxesApi({ baseApi: adminApi, apiPrefix: '/api/admin/maintenance/sandboxes' });
```

- `.` — client, endpoint definitions, payload types, a static api for reviews (`createStaticSandboxesApi`).
- `./screen` — `SandboxesScreen` (list with state, usage, mount, actions), `SandboxDetailScreen` (files with safe
  previews, unsaved changes, processes and jobs, commands, conversations, session log, audit; the host's loaders
  `loadPdfJs`, `loadCodeHighlighter` and `renderMarkdown` are optional and reach the file preview), `useSandboxActions`
  (start, offload, kill a job, end, reset; forced actions need a reason, a reset the workspace's name).
- `./i18n-keys.json` — the text manifest (keys, contexts, translator hints) for the host's text export.

Peers: `@mgamadeus/ddd-admin-ui` ≥ 0.8.0, `@mgamadeus/ddd-web`, `lucide-react`, React 19. The screens need
`AdminUiProvider` above them and Tailwind scanning `node_modules/@mgamadeus/ddd-agent-sandbox/dist`.

File names, command lines and outputs are foreign text: the screens show them as text only; files are served by the
backend as untrusted content (attachment, sandbox CSP; raster pictures and PDF inline). A text file is drawn by its
name: plain, as a source file (coloured when the host passes `loadCodeHighlighter`; tokens as text nodes) or as
Markdown (markup only through the host's `renderMarkdown`, which has to be the untrusted pipeline:
`source => <MarkdownPreview source={source} untrusted />` of `@mgamadeus/ddd-admin-ui/markdown`).

MIT
