---
decisionCriteria: "The user wants an Excel workbook (xlsx, spreadsheet), with or without a chart."
shortDescription: "builds an Excel workbook (xlsx) with values, header and native chart"
isSubSkill: true
description: >-
  An Excel workbook (Excel, xlsx, spreadsheet) for people, built with openpyxl: figures as values, a fixed header
  row, number formats of the user, a native chart beside the table. Data, brand, check and hand-over come from
  Agent.Sandbox.Documents; a small sum in a scratch file needs only Agent.Sandbox.Shell.
---
# Excel workbook (xlsx)

- Figures as numbers, never as text; no formulas except a simple SUM. Write the rows from the script's data.
- Header: bold, filled, frozen (`ws.freeze_panes = "A2"`), with an auto filter; column widths from the content.
- Number formats per column (`'#,##0'`, `'#,##0.0'`, `'0.0%'`); Excel shows them in the reader's locale.
- One sheet per table; a short note on what the sheet shows in the row above the header only when asked.
- Chart: a native openpyxl chart (`BarChart`, `LineChart`) per statement, titles and axis titles in the user's
  language, anchored one column after the last data column (e.g. `ws.add_chart(chart, "E2")` for data in A–C) or on
  its own sheet; never over cells with data.
- Check: `readFile` on the xlsx (values, sums), then the visual check of Agent.Sandbox.Documents (soffice → pdf,
  rendered pages): the export shows one sheet per page.
