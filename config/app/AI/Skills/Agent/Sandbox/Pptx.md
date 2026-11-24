---
decisionCriteria: "The user wants a presentation: slides, PowerPoint, pptx, a deck or a pitch."
shortDescription: "builds a PowerPoint deck (pptx) with python-pptx"
isSubSkill: true
description: >-
  A presentation as PowerPoint (slides, pptx, deck, pitch) built with python-pptx (and the installation's kit when
  it has one): the recipe of title, key figures, charts, tables and conclusion, one statement per slide, the slide
  count from the content, a short table beside its chart. Data, brand, check and hand-over come from
  Agent.Sandbox.Documents; slides as PDF without pptx: Agent.Sandbox.Pdf.
---
# Presentation (pptx)

Recipe (one script in `work/`; a kit's deck functions replace the plain python-pptx calls):

```python
from pptx import Presentation
from pptx.util import Cm, Pt
prs = Presentation(); prs.slide_width, prs.slide_height = Cm(33.867), Cm(19.05)   # 16:9
# slides: title · key figures · one chart per statement (matplotlib PNG from work/) · the table · conclusion
prs.save("out/decks/orders-2026.pptx")
# then: soffice → pdf next to the pptx, render the pages, look once (Agent.Sandbox.Documents)
```

- One statement per slide; the title says it. The slide count follows the content: title, key figures, one slide
  per chart, the table, the conclusion. Many rows: continuation slides (at most 12 rows each); never pad, never cram.
- A table of up to 4 rows gets no slide of its own: put it beside its chart or make its figures key figures.
- The source line and the page number at fixed places on every content slide; no extra footers or boxes.
- Fonts missing on other computers: always hand over the PDF twin as well (same folder, same name).
