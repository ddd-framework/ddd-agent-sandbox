#!/bin/bash
# Smoke of the full toolkit's heavy tools, run inside the image as an unprivileged uid without network:
#   docker run --rm --network none --read-only --tmpfs /workspace:rw,exec,mode=0777 --tmpfs /tmp:rw,exec,mode=1777 \
#     -u 30000 -e HOME=/tmp/h -v "$PWD/runner/test:/smoke:ro" --entrypoint bash \
#     ddd-agent-sandbox-runner:full /smoke/smoke-full.sh
# On the cluster the same lines run as one exec of a session (they need nothing but /workspace and HOME).
set -u
mkdir -p "$HOME"; cd /workspace
ms() { echo $(( ($(date +%s%N) - $1) / 1000000 )); }
python3 -c 'import docx; d=docx.Document(); d.add_paragraph("Hello sandbox"); d.save("t.docx")'
t=$(date +%s%N); soffice --headless --convert-to pdf t.docx >/dev/null 2>&1; echo "libreoffice docx->pdf (first start) $(ms $t) ms: $(pdftotext t.pdf - 2>&1 | head -1)"
t=$(date +%s%N); soffice --headless --convert-to pdf t.docx >/dev/null 2>&1; echo "libreoffice docx->pdf (second) $(ms $t) ms"
echo '<h1>Hallo</h1>' > p.html
t=$(date +%s%N); chrome-headless-shell --no-sandbox --disable-gpu --screenshot=/workspace/s.png --window-size=400,300 file:///workspace/p.html >/dev/null 2>&1; echo "chromium screenshot $(ms $t) ms: $(file -b s.png | cut -d, -f1-2)"
python3 -c '
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(); pg.goto("file:///workspace/p.html"); pg.pdf(path="/workspace/p.pdf"); b.close()
print("playwright (python) page to pdf ok")'
node -e 'const d=require("docx"); const s=require("sharp"); console.log("node require docx, sharp ok:", typeof d.Document, typeof s)'
echo 'import ExcelJS from "exceljs"; const w: string = typeof ExcelJS; console.log("tsx + ESM import from /workspace ok:", w)' > t.ts && tsx t.ts
printf '#set text(font: "DejaVu Sans")\n= Sandbox\n1.234 km\n' > t.typ && t=$(date +%s%N) && typst compile t.typ t-typst.pdf && echo "typst $(typst --version | cut -d" " -f2) $(ms $t) ms: $(pdffonts t-typst.pdf | grep -c DejaVu) DejaVu fonts"
magick -size 50x50 xc:red r.png && vips copy r.png r.jpg && echo "imagemagick + vips ok"
echo "tesseract langs: $(tesseract --list-langs 2>/dev/null | tail -n +2 | tr '\n' ' ')"
ffmpeg -loglevel error -f lavfi -i testsrc=duration=1:size=64x64 -frames:v 1 f.png && echo "ffmpeg ok"
python3 -c 'import pandas, scipy, sklearn, statsmodels, pyarrow, openpyxl, xlsxwriter, pptx, markitdown, pypdf, pdfplumber, pypdfium2, pikepdf, reportlab, weasyprint, PIL, pillow_heif, pyvips, cv2, cairosvg, matplotlib, seaborn, plotly, kaleido, bs4, lxml; print("python full imports ok"); import pyvips; print("pyvips", pyvips.version(0), pyvips.version(1), "api" if pyvips.API_mode else "abi")'
pip --version 2>&1 | head -1; npm --version 2>&1 | head -1
