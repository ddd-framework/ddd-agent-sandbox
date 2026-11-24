---
decisionCriteria: "The user wants a PDF report or slides as PDF without PowerPoint."
shortDescription: "builds a PDF report or PDF slides with Typst"
isSubSkill: true
description: >-
  A PDF report or slides as PDF without PowerPoint, set with Typst (full image), from the installation's report
  template when it preloads one, with charts from matplotlib. Data, brand, check and hand-over come from
  Agent.Sandbox.Documents; a PowerPoint file: Agent.Sandbox.Pptx.
---
# PDF report (Typst)

Start from the kit's example report when `$SANDBOX_PRELOAD` has one, never from scratch:

1. Figures from the tool results into a script, never estimated.
2. Charts: a matplotlib script in `work/` writes PNGs into `work/`.
3. Copy the example `.typ` to `work/report.typ` (or write a short one: title, key figures, chart, table,
   conclusion); every figure in the user's number format.
4. `cd /workspace && typst compile --root /workspace work/report.typ out/reports/<name>.pdf` (add `--font-path` of the
   kit's fonts when it has some).
5. `pdfinfo` (a report: 2–4 pages) and `pdftotext`: every key figure is there; then the check of
   Agent.Sandbox.Documents.

- Slides as PDF: a 16:9 page (`#set page(width: 33.867cm, height: 19.05cm)`), every first-level heading starts a
  slide. A chart on a slide gets a height (`image("…", height: 9cm)`), never `width: 100%`: a picture that does not fit
  moves to the next slide and leaves a nearly empty one.
- No Typst in the workspace ("command not found", base image): build the PDF with python-pptx and soffice, or with
  matplotlib's PdfPages for a chart report, and say so.
