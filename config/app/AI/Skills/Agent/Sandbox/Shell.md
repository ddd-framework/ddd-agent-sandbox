---
requiredAccountRights: ['useSandbox']
decisionCriteria: "The user wants a file made, read or changed (Excel, Word, PDF, a presentation, CSV, a script), a calculation or analysis over data, or asks what is in the workspace."
shortDescription: "works in the persistent workspace like on a computer: files, documents, calculations, scripts"
subSkillNames: [Agent.Sandbox.Documents]
description: >-
  Your own persistent workspace with a shell in an isolated sandbox: create, read and change files (Excel/xlsx,
  Word/docx, PDF, presentations/pptx, CSV, text), compute and analyse data, run scripts and long jobs, look at
  pictures and rendered pages, see what the workspace holds. Data of the application comes from its own skills
  first, then goes into a file here. Documents for people (decks, reports, workbooks, charts): load
  Agent.Sandbox.Documents as well.
---
# Workspace

Your workspace is a Linux computer at `/workspace`: short steps, look at results, fix, go on.

- Commands: `shell` (bash; cd and export persist). Python 3 (pandas, openpyxl, python-docx, python-pptx,
  matplotlib), pdftotext, pdfinfo, rg, jq; the full image adds soffice, Node and typst. No network unless the
  installation allows hosts; nothing can be installed.
- Read: `readFile` (numbered lines; xlsx, docx, pptx and pdf as text). Look at a picture: `viewWorkspaceImages` (up
  to 4 paths). Find: `searchFiles` (text or paths).
- Write: `writeFile` for a whole text file or script, `editFile` for one exact passage of a text file (read it first;
  copy it without line numbers). Change xlsx/docx with a script, never by hand.

Rules

- Data from the application: read it with the tools of its skill, then write it into a file (a script with the rows
  as data, or a CSV) and build the result from that. Never invent rows.
- Helper scripts and intermediate files go to your `work/<conversation>/` (the shell's start folder; kept 7 days).
  Results go to a folder with a speaking name (`out/top10-orders.xlsx`; kept 30 days). `lib/` and `NOTES.md` last:
  only when the user asks to keep something, or a tool is clearly reusable here; say so. File tools take paths
  relative to /workspace; in the shell write `/workspace/<path>`.
- Templates and kits of the installation are read-only below `$SANDBOX_PRELOAD` (`/workspace/.preload`): read their
  README first, copy what you change.
- A CSV: read its first lines before parsing (the separator may be `;`), then compute in one script.
- Check what you made: a print, `readFile`, pdfinfo/pdftotext; never a second full rebuild.
- Work longer than a minute: `shell` with background true, then ask with the jobId.
- `NOTES.md`: short, current (what lives in `lib/`, conventions); update it when you add to `lib/`.
- The first workspace answer of a conversation shows the workspace state and `NOTES.md`. A named file: open it
  directly. What is in the workspace: that state and `searchFiles` with a glob, never scratch files.
- Report briefly: what you made, the path, the key figures (a sum, the row count).
- Nothing leaves the workspace from here: no mail, no upload.
