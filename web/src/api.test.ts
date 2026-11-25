import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildSandboxesRequests, createStaticSandboxesApi, fileNameOfContentDisposition, readSandboxFileContent } from './api.ts';
import {
  formatSandboxDuration,
  SANDBOX_ACTION_OUTCOME,
  SANDBOX_COMMAND_STATE,
  SANDBOX_STATE,
  sandboxCommandStateOf,
  isSandboxTurnRunningRefusal,
  sandboxNameOf,
  sandboxParentPathOf,
  sandboxPathCrumbs,
  sandboxPreviewKindOf,
  sandboxResetPhraseOf,
  sandboxTextViewOf,
  usagePercentOf,
} from './screen/format.ts';

describe('sandbox requests', () => {
  const requests = buildSandboxesRequests('/api/admin/maintenance/sandboxes/');

  it('reads the list, one workspace and its parts, keys encoded', () => {
    assert.deepEqual(requests.list(), { url: '/api/admin/maintenance/sandboxes/list' });
    assert.deepEqual(requests.workspace({ workspaceKey: 'space 7/x' }), { url: '/api/admin/maintenance/sandboxes/space%207%2Fx' });
    assert.deepEqual(requests.files({ workspaceKey: 'w', path: '' }), { url: '/api/admin/maintenance/sandboxes/w/files' });
    assert.deepEqual(requests.files({ workspaceKey: 'w', path: 'a/b c' }), { url: '/api/admin/maintenance/sandboxes/w/files?path=a%2Fb%20c' });
    assert.deepEqual(requests.commands({ workspaceKey: 'w', limit: 50 }), { url: '/api/admin/maintenance/sandboxes/w/commands?limit=50' });
    assert.equal(requests.driveStatus({ workspaceKey: 'w' }).url, '/api/admin/maintenance/sandboxes/w/driveStatus');
    assert.equal(requests.events({ workspaceKey: 'w', limit: 10 }).url, '/api/admin/maintenance/sandboxes/w/events?limit=10');
  });

  it('asks the file bytes as a blob, inline or as attachment', async () => {
    const content = requests.content({ workspaceKey: 'w', path: 'out/report.pdf', disposition: 'inline' });
    assert.equal(content.url, '/api/admin/maintenance/sandboxes/w/files/content?path=out%2Freport.pdf&disposition=inline');
    assert.equal(typeof content.responseHandler, 'function');
  });

  it('posts an action with force, reason and confirmation only when given', () => {
    assert.deepEqual(requests.action({ workspaceKey: 'w', action: 'offload' }), {
      url: '/api/admin/maintenance/sandboxes/w/offload',
      method: 'POST',
      body: {},
    });
    assert.deepEqual(requests.action({ workspaceKey: 'w', action: 'reset', force: true, reason: 'stuck', confirmation: 'Team A' }), {
      url: '/api/admin/maintenance/sandboxes/w/reset',
      method: 'POST',
      body: { force: true, reason: 'stuck', confirmation: 'Team A' },
    });
    assert.deepEqual(requests.kill({ workspaceKey: 'w', jobId: 'job/1' }), {
      url: '/api/admin/maintenance/sandboxes/w/jobs/job%2F1/kill',
      method: 'POST',
      body: {},
    });
  });

  it('answers a static file as text and refuses actions', async () => {
    const api = createStaticSandboxesApi({ fileText: arg => (arg.path === 'a.txt' ? 'hello' : undefined) });
    const [requestContent] = api.useSandboxFileContentMutation();
    const content = await requestContent({ workspaceKey: 'w', path: 'a.txt', disposition: 'inline' }).unwrap();
    assert.equal(await content.blob.text(), 'hello');
    assert.equal(content.contentType, 'text/plain');
    await assert.rejects(requestContent({ workspaceKey: 'w', path: 'b.txt', disposition: 'inline' }).unwrap());
    const [runAction] = api.useSandboxActionMutation();
    await assert.rejects(runAction({ workspaceKey: 'w', action: 'start' }).unwrap());
  });
});

describe('sandbox file content: the shared download shape', () => {
  it('resolves to { blob, filename, contentType, isTruncated } (a store ignoring payload.blob needs nothing else)', async () => {
    const response = new Response('abc', {
      headers: {
        'Content-Type': 'text/plain; charset=utf-8',
        'Content-Disposition': "attachment; filename=\"r.txt\"; filename*=UTF-8''r%C3%A9sum%C3%A9.txt",
        'X-Content-Truncated': 'true',
      },
    });
    const content = await readSandboxFileContent(response);
    assert.deepEqual(Object.keys(content).sort(), ['blob', 'contentType', 'filename', 'isTruncated']);
    assert.ok(content.blob instanceof Blob);
    assert.equal(await content.blob.text(), 'abc');
    assert.equal(content.filename, 'résumé.txt');
    assert.equal(content.contentType, 'text/plain; charset=utf-8');
    assert.equal(content.isTruncated, true);
    const { blob: _blob, ...rest } = content;
    assert.deepEqual(JSON.parse(JSON.stringify(rest)), rest, 'everything but the blob is plain data');
  });

  it('reads a plain file name and none', () => {
    assert.equal(fileNameOfContentDisposition('inline; filename="a b.png"'), 'a b.png');
    assert.equal(fileNameOfContentDisposition(null), null);
    assert.equal(fileNameOfContentDisposition('inline'), null);
  });
});

describe('sandbox command state', () => {
  it('takes the server status, else exit code = exited, none = unknown (never running by default)', () => {
    assert.equal(sandboxCommandStateOf({ status: 'KILLED', exitCode: null }), 'KILLED');
    assert.equal(sandboxCommandStateOf({ status: 'TIMED_OUT' }), 'TIMED_OUT');
    assert.equal(sandboxCommandStateOf({ status: null, exitCode: 0 }), 'EXITED');
    assert.equal(sandboxCommandStateOf({ status: null, exitCode: null }), 'UNKNOWN');
    assert.equal(sandboxCommandStateOf({}), 'UNKNOWN');
  });
});

describe('sandbox status maps carry their translation context', () => {
  it('every definition has the context its labels are marked with', () => {
    for (const definition of Object.values(SANDBOX_STATE)) assert.equal((definition as { context?: string }).context, 'sandbox-state');
    for (const definition of Object.values(SANDBOX_ACTION_OUTCOME)) assert.equal((definition as { context?: string }).context, 'sandbox-action-outcome');
    for (const definition of Object.values(SANDBOX_COMMAND_STATE)) assert.equal((definition as { context?: string }).context, 'sandbox-command-state');
  });
});

describe('sandbox formats', () => {
  it('names a workspace and its reset phrase by display name, else by key', () => {
    assert.equal(sandboxNameOf({ displayName: 'Team A', workspaceKey: 'space-3' }), 'Team A');
    assert.equal(sandboxNameOf({ displayName: '  ', workspaceKey: 'space-3' }), 'space-3');
    assert.equal(sandboxResetPhraseOf({ displayName: null, workspaceKey: 'space-3' }), 'space-3');
  });

  it('previews only raster images, PDF and plain text', () => {
    assert.equal(sandboxPreviewKindOf('image/png'), 'image');
    assert.equal(sandboxPreviewKindOf('application/pdf'), 'pdf');
    assert.equal(sandboxPreviewKindOf('text/plain; charset=utf-8'), 'text');
    assert.equal(sandboxPreviewKindOf('image/svg+xml'), 'none');
    assert.equal(sandboxPreviewKindOf('text/html'), 'none');
    assert.equal(sandboxPreviewKindOf('application/octet-stream'), 'none');
  });

  it('draws a text by the name of its file: Markdown, a source file, else plain', () => {
    assert.equal(sandboxTextViewOf('README.md'), 'markdown');
    assert.equal(sandboxTextViewOf('notes.MARKDOWN'), 'markdown');
    assert.equal(sandboxTextViewOf('src/Service.php'), 'code');
    assert.equal(sandboxTextViewOf('app.ts'), 'code');
    assert.equal(sandboxTextViewOf('data.json'), 'code');
    assert.equal(sandboxTextViewOf('notes.txt'), 'text');
    assert.equal(sandboxTextViewOf('table.csv'), 'text');
    assert.equal(sandboxTextViewOf('output.log'), 'text');
    assert.equal(sandboxTextViewOf('Makefile'), 'text');
    assert.equal(sandboxTextViewOf('.env'), 'text');
    // a name that only looks like a picture, a film or an archive is still the text the server sent
    assert.equal(sandboxTextViewOf('picture.png'), 'text');
    assert.equal(sandboxTextViewOf('film.mp4'), 'text');
  });

  it('walks workspace paths and limits', () => {
    assert.equal(sandboxParentPathOf('a/b/c'), 'a/b');
    assert.equal(sandboxParentPathOf('a'), '');
    assert.deepEqual(sandboxPathCrumbs('a/b'), [{ name: 'a', path: 'a' }, { name: 'b', path: 'a/b' }]);
    assert.equal(usagePercentOf(50, 200), 25);
    assert.equal(usagePercentOf(500, 200), 100);
    assert.equal(usagePercentOf(50, null), null);
    assert.equal(formatSandboxDuration(null), '—');
    assert.equal(isSandboxTurnRunningRefusal({ status: 409 }), true);
    assert.equal(isSandboxTurnRunningRefusal({ status: 400 }), false);
  });
});
