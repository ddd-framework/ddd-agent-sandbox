'use client';

import { normalizeDddError } from '@mgamadeus/ddd-web/contract';
import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
  type CodeHighlighterLoader,
  codeLanguageOfExtension,
  CodePreview,
  extensionOf,
  MarkdownFilePreview,
  type MarkdownFileRenderer,
  PdfPreview,
  type PdfJsLoader,
  TextPreview,
} from '@mgamadeus/ddd-admin-ui/files';
import { useTranslate } from '@mgamadeus/ddd-admin-ui/i18n';
import { notify } from '@mgamadeus/ddd-admin-ui/logic';
import { Button } from '@mgamadeus/ddd-admin-ui/primitives';
import { Skeleton } from '@mgamadeus/ddd-admin-ui/primitives';
import type { SandboxesApi } from '../api.ts';
import { formatSandboxBytes, SANDBOX_TEXT_PREVIEW_BYTES, type SandboxPreviewKind, sandboxPreviewKindOf, sandboxTextViewOf } from './format.ts';
import type { SandboxFileEntry } from '../types.ts';

export interface SandboxFilePreviewProps {
  api: SandboxesApi;
  workspaceKey: string;
  file: SandboxFileEntry;
  /** pdf.js of the host (without it a PDF offers its download only). */
  loadPdfJs?: PdfJsLoader;
  /** A highlighter of the host, loaded on the first source file shown (without it a source file shows plain). */
  loadCodeHighlighter?: CodeHighlighterLoader;
  /** Draws a Markdown file as markup, as untrusted text (without it a Markdown file shows its source). */
  renderMarkdown?: MarkdownFileRenderer;
}

/** Saves bytes under a name (an object URL and a link; the bytes are never opened). */
const saveBlob = (blob: Blob, fileName: string) => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.rel = 'noopener';
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
};

/**
 * A file of a workspace, read only, as untrusted content: the server picks the type from the bytes; JPEG, PNG, GIF
 * and WebP draw as `<img>`, PDF on canvases through the host's pdf.js, text as text nodes (the first 256 KB); anything
 * else only downloads. A text the server sent is drawn by the file's name: a source file with the host's highlighter
 * (its tokens as text nodes), a Markdown file through the host's renderer with a switch to its source, everything
 * else plain. The package itself never renders file content as HTML; a host's `renderMarkdown` has to treat the text
 * as untrusted.
 */
export const SandboxFilePreview = ({ api, workspaceKey, file, loadPdfJs, loadCodeHighlighter, renderMarkdown }: SandboxFilePreviewProps) => {
  const { t } = useTranslate();
  const [requestContent] = api.useSandboxFileContentMutation();
  const [preview, setPreview] = useState<{ url: string; kind: SandboxPreviewKind; size: number } | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const textView = sandboxTextViewOf(file.name);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    setPreview(null);
    setFailed(null);
    void requestContent({ workspaceKey, path: file.path, disposition: 'inline' })
      .unwrap()
      .then(content => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(content.blob);
        setPreview({ url: objectUrl, kind: sandboxPreviewKindOf(content.contentType || content.blob.type), size: content.blob.size });
      })
      .catch(error => {
        if (!cancelled) setFailed(normalizeDddError(error, t('The file could not be loaded.')).message);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [requestContent, workspaceKey, file.path, t]);

  const download = async () => {
    setDownloading(true);
    try {
      const content = await requestContent({ workspaceKey, path: file.path, disposition: 'attachment' }).unwrap();
      saveBlob(content.blob, content.filename ?? file.name);
    } catch (error) {
      notify.error(normalizeDddError(error, t('%name% could not be downloaded', { replacements: { name: file.name } })).message);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="min-w-0 truncate font-mono text-[13px]" title={file.path}>
          {file.path}
        </p>
        <Button type="button" variant="outline" size="sm" onClick={() => void download()} disabled={downloading}>
          <Download />
          {t('Download', { context: 'action' })}
        </Button>
      </div>
      {failed ? (
        <p className="text-[13px] text-muted-foreground">{failed}</p>
      ) : !preview ? (
        <Skeleton className="h-48 w-full rounded-xl" />
      ) : preview.kind === 'image' ? (
        <img src={preview.url} alt={file.name} className="max-h-[60vh] max-w-full rounded-lg border object-contain" />
      ) : preview.kind === 'pdf' && loadPdfJs ? (
        <PdfPreview url={preview.url} fileName={file.name} loadPdfJs={loadPdfJs} />
      ) : preview.kind === 'text' ? (
        <>
          {textView === 'markdown' ? (
            <MarkdownFilePreview url={preview.url} fileName={file.name} renderMarkdown={renderMarkdown} loadCodeHighlighter={loadCodeHighlighter} />
          ) : textView === 'code' ? (
            <CodePreview
              url={preview.url}
              fileName={file.name}
              language={codeLanguageOfExtension(extensionOf(file.name))}
              loadCodeHighlighter={loadCodeHighlighter}
            />
          ) : (
            <TextPreview url={preview.url} fileName={file.name} />
          )}
          {preview.size >= SANDBOX_TEXT_PREVIEW_BYTES && (
            <p className="text-xs text-muted-foreground">{t('Only the beginning of the file is shown; download it for the rest.')}</p>
          )}
        </>
      ) : (
        <p className="text-[13px] text-muted-foreground">
          {t('No preview for this type (%size%): download it to open it.', { replacements: { size: formatSandboxBytes(file.sizeBytes ?? preview.size) } })}
        </p>
      )}
    </div>
  );
};
