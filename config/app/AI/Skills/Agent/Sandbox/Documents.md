---
decisionCriteria: "The user wants a document for people built in the workspace: a presentation, a PDF report, an Excel workbook or a chart."
shortDescription: "builds presentations, PDF reports, Excel workbooks and charts for people, checked page by page"
isSubSkill: true
subSkillNames: [Agent.Sandbox.Pptx, Agent.Sandbox.Pdf, Agent.Sandbox.Excel]
turnMinimumSteps: 30
description: >-
  Documents for people built in the workspace: a presentation (slides, deck, pptx), a PDF report, an Excel workbook
  or a chart image. Holds the data rules, number and language rules, the brand kit of the installation when it has
  one, the visual check of all pages and the hand-over; its block lists the format skills (Agent.Sandbox.Pptx,
  Agent.Sandbox.Pdf, Agent.Sandbox.Excel). Load it with the format skill before writing the script.
---
# Documents for people

Load the format skill of the document in the same `load_skills` call. A document from several sources (data,
charts, build, check, save): first plan the steps (data · script with charts · build · render and look · save).

Data

- Every figure from a tool result or a file: write the rows into the script as data, never estimate.
- Leave test, template and demo rows out of an overview and say which; an item the user names is always in.
- The user's language and number format (decimal and thousands separators, dates); big figures rounded (7.6 M);
  percent as the user writes it.
- Source line: the system and the date, never tool names.
- Titles carry the statement ("North leads with 412 km"), not only the topic.

Brand kit

- The installation may preload a kit below `$SANDBOX_PRELOAD` (fonts, colours, logo, a Python module, Typst
  templates): `ls $SANDBOX_PRELOAD` and read its README before the first script; use its functions instead of your
  own styling. Do not read the kit's source; `help(<module>.<function>)` for one function.
- Without a kit: one clear sans-serif font (DejaVu Sans, Liberation Sans), one accent colour, plenty of white space.
- Charts: matplotlib with the kit's style or a plain one, `savefig(…, dpi=200, bbox_inches="tight")`, axis labels in
  the user's language and number format.
- Logos: never recoloured or distorted.

Files

- Results under a speaking folder (`out/decks/orders-2026.pptx`). Never overwrite a result of another conversation:
  a new name or a dated folder.

Check before handing over (once)

1. pptx/xlsx: `soffice --headless --convert-to pdf --outdir work <file>` (full image); then render the pages
   `pdftoppm -r 50 -png work/<file>.pdf work/page` and, for many pages, combine them into overview pictures of 6
   pages each (Pillow).
2. ONE `viewWorkspaceImages` call with the pictures (up to 4). Right after it write one short line per page in your
   answer text ("p.3: table ok; p.6: nearly empty → fix"): overlapping or cut text, a nearly empty page, a missing
   chart or conclusion.
3. Fix in the script, rebuild, look at the overview once more. A single page large only on suspicion
   (`pdftoppm -r 80 -f n -l n -png`). Never view the same pictures twice.

Show early: when a tool that shows files to the user is loaded (a file card), show the files right after the first
build, before the check; a fix rebuilds the same paths, so the card stays current, and the user has the result even if
the turn ends early.

Hand over: name every file made (pptx and its pdf) with its path, then a short answer with the key figures and the
hints.
