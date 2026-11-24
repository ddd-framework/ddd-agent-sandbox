// `@mgamadeus/ddd-agent-sandbox`: the API client of the sandbox admin routes (`SandboxesControllerTrait` of the
// Composer package mgamadeus/ddd-agent-sandbox) on the host's RTK Query api, and the payload types. The screens
// (list, one workspace with files, processes, commands, log, audit and the audited actions) are `./screen`.
export {
  buildSandboxesRequests,
  createSandboxesApi,
  createStaticSandboxesApi,
  fileNameOfContentDisposition,
  readSandboxFileContent,
  SANDBOXES_TAG,
  sandboxesEndpointDefinitions,
} from './api.ts';
export type { CreateSandboxesApiOptions, SandboxesApi, StaticSandboxesData } from './api.ts';
export type * from './types.ts';
