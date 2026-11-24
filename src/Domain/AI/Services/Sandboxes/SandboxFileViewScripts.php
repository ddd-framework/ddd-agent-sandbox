<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

/**
 * The scripts the file tools run INSIDE the sandbox (every byte of a document is parsed in
 * the sandbox; PHP only moves files). Each command merges stderr into stdout, so a cut answer is read back whole from
 * the runner's spill file.
 *
 * - The text view of readFile: numbered lines of a text file; xlsx per sheet (values, a formula where no value is
 *   cached, hidden sheets named and not shown); docx paragraphs and tables; pptx text per slide; pdf text per page
 *   (pdftotext, else pypdf); a binary file is named with its type. At most 12,000 characters.
 * - The search of searchFiles: ripgrep, newest files first, 200 matches and 8,000 characters at most, the rest counted.
 */
class SandboxFileViewScripts
{
    /** @var int The most characters of one view */
    public const int MAX_VIEW_CHARACTERS = 12000;

    /** @var int The most matches of one search */
    public const int MAX_SEARCH_MATCHES = 200;

    /** @var int The most characters of one search answer */
    public const int MAX_SEARCH_CHARACTERS = 8000;

    /** @var int Exit code of the view script for a missing file */
    public const int EXIT_NOT_FOUND = 3;

    /** @var int Exit code of the view script for a folder */
    public const int EXIT_IS_DIRECTORY = 4;

    /** @var string The view script (python3, argv: path offset limit sheet) */
    public const string READ_VIEW_SCRIPT = <<<'PYTHON'
import os, sys, subprocess, warnings
warnings.filterwarnings("ignore")
path, offset, limit, sheet = sys.argv[1], max(1, int(sys.argv[2])), max(1, int(sys.argv[3])), sys.argv[4]
MAX = 12000
out, used = [], [0]
def emit(text):
    if used[0] >= MAX:
        return False
    if used[0] + len(text) + 1 > MAX:
        out.append(text[:max(0, MAX - used[0])] + " …[view cut at 12,000 characters: read a smaller window with offset and limit]")
        used[0] = MAX
        return False
    out.append(text)
    used[0] += len(text) + 1
    return True
def done(code=0):
    sys.stdout.write("\n".join(out) + "\n")
    sys.exit(code)
def short(value, width=80):
    text = str(value).replace("\n", " ").replace("\r", " ")
    return text if len(text) <= width else text[:width - 1] + "…"
if not os.path.lexists(path):
    print("NOT_FOUND"); sys.exit(3)
if os.path.isdir(path):
    print("IS_DIRECTORY"); sys.exit(4)
size = os.path.getsize(path)
ext = os.path.splitext(path)[1].lower()
def view_lines(lines, unit, total):
    last = min(total, offset + limit - 1)
    emit(f"{path} · {unit} {offset}–{last} of {total}" if total else f"{path} · empty")
    for number, line in lines:
        if not emit(f"{number:>6}│{line[:2000]}"):
            break
    if last < total and used[0] < MAX:
        emit(f"More: offset {last + 1}.")
try:
    if ext in (".xlsx", ".xlsm"):
        import openpyxl
        from openpyxl.utils import get_column_letter
        values = openpyxl.load_workbook(path, read_only=True, data_only=True)
        formulas = openpyxl.load_workbook(path, read_only=True, data_only=False)
        names = values.sheetnames
        hidden = [ws.title for ws in formulas.worksheets if getattr(ws, "sheet_state", "visible") != "visible"]
        emit(f"{path} · xlsx · {size} bytes · sheets: " + ", ".join(names) + (f" · hidden sheets not shown: {', '.join(hidden)}" if hidden else ""))
        for name in names:
            if sheet and name != sheet:
                continue
            if name in hidden and name != sheet:
                continue
            ws, wf = values[name], formulas[name]
            rows = list(ws.iter_rows(values_only=True))
            frows = list(wf.iter_rows(values_only=True))
            total = len(rows)
            last = min(total, offset + limit - 1)
            width = max((len(r) for r in rows), default=0)
            emit(f"Sheet \"{name}\" · rows {offset}–{last} of {total} · columns A–{get_column_letter(max(1, width))}")
            for index in range(offset - 1, last):
                row = list(rows[index])
                frow = list(frows[index]) if index < len(frows) else []
                cells = []
                for col, value in enumerate(row):
                    if value is None and col < len(frow) and isinstance(frow[col], str) and frow[col].startswith("="):
                        value = frow[col]
                    cells.append("" if value is None else short(value, 60))
                while cells and cells[-1] == "":
                    cells.pop()
                if not emit(f"{index + 1:>6}│" + " | ".join(cells)):
                    break
            if last < total and used[0] < MAX:
                emit(f"More rows: offset {last + 1}.")
        if sheet and sheet not in names:
            emit(f"No sheet \"{sheet}\"; sheets: " + ", ".join(names))
        done()
    if ext == ".docx":
        import docx
        document = docx.Document(path)
        lines = [p.text for p in document.paragraphs if p.text.strip()]
        for t_index, table in enumerate(document.tables, 1):
            lines.append(f"[table {t_index}]")
            for row in table.rows:
                lines.append(" | ".join(short(c.text, 60) for c in row.cells))
        total = len(lines)
        view_lines([(i + 1, lines[i]) for i in range(offset - 1, min(total, offset + limit - 1))], "paragraphs", total)
        done()
    if ext == ".pptx":
        import pptx
        presentation = pptx.Presentation(path)
        lines = []
        for s_index, slide in enumerate(presentation.slides, 1):
            lines.append(f"[slide {s_index}]")
            for shape in slide.shapes:
                if getattr(shape, "has_text_frame", False) and shape.text_frame.text.strip():
                    lines.extend(shape.text_frame.text.splitlines())
        total = len(lines)
        view_lines([(i + 1, lines[i]) for i in range(offset - 1, min(total, offset + limit - 1))], "lines", total)
        done()
    if ext == ".pdf":
        pages = []
        try:
            info = subprocess.run(["pdfinfo", path], capture_output=True, text=True, timeout=30).stdout
            count = int([l.split(":")[1] for l in info.splitlines() if l.startswith("Pages:")][0])
            for page in range(offset, min(count, offset + min(limit, 50) - 1) + 1):
                text = subprocess.run(["pdftotext", "-layout", "-f", str(page), "-l", str(page), path, "-"], capture_output=True, text=True, timeout=60).stdout
                pages.append((page, text))
        except (FileNotFoundError, IndexError, ValueError):
            import pypdf
            reader = pypdf.PdfReader(path)
            count = len(reader.pages)
            for page in range(offset, min(count, offset + min(limit, 50) - 1) + 1):
                pages.append((page, reader.pages[page - 1].extract_text() or ""))
        last = pages[-1][0] if pages else offset - 1
        emit(f"{path} · pdf · pages {offset}–{last} of {count}")
        for page, text in pages:
            emit(f"[page {page}]")
            for line in text.splitlines():
                if line.strip() and not emit(line.rstrip()[:2000]):
                    break
        if last < count and used[0] < MAX:
            emit(f"More pages: offset {last + 1}.")
        done()
    with open(path, "rb") as handle:
        head = handle.read(8192)
    if b"\0" in head:
        kind = subprocess.run(["file", "-b", path], capture_output=True, text=True).stdout.strip()
        emit(f"{path} · binary file · {size} bytes · {kind}. No text view; inspect it with shell (python, file, identify).")
        done()
    total = 0
    window = []
    with open(path, "r", encoding="utf-8", errors="replace") as handle:
        for number, line in enumerate(handle, 1):
            total = number
            if offset <= number < offset + limit:
                window.append((number, line.rstrip("\n").rstrip("\r")))
    view_lines(window, "lines", total)
    done()
except SystemExit:
    raise
except Exception as error:
    out.append(f"VIEW_FAILED {type(error).__name__}: {short(error, 300)}")
    done(1)
PYTHON;

    /**
     * The command that runs the view script on one file.
     *
     * @param string $relativePath
     * @param int $offset
     * @param int $limit
     * @param string $sheetName
     * @return string
     */
    public static function buildReadViewCommand(string $relativePath, int $offset, int $limit, string $sheetName): string
    {
        return 'python3 -I -W ignore - ' . escapeshellarg($relativePath) . ' ' . $offset . ' ' . $limit . ' ' . escapeshellarg($sheetName)
            . " 2>&1 <<'__RB_VIEW_SCRIPT__'\n" . static::READ_VIEW_SCRIPT . "\n__RB_VIEW_SCRIPT__";
    }

    /**
     * The ripgrep command of searchFiles: matching lines (`path:line:text`) of a pattern, or the matching paths of a
     * glob alone; newest files first; then a line `__TOTAL__ <n>`.
     *
     * @param string|null $pattern
     * @param string|null $glob
     * @return string
     */
    public static function buildSearchCommand(?string $pattern, ?string $glob): string
    {
        // the exclusions after the caller's glob: in ripgrep the later glob wins, so `**/*` cannot pull them back in
        $globArguments = ($glob !== null ? ' --glob ' . escapeshellarg($glob) : '') . ' --glob ' . escapeshellarg('!.runner') . ' --glob ' . escapeshellarg('!lost+found');
        $ripgrep = $pattern !== null
            ? 'rg --line-number --no-heading --color=never --ignore-case --hidden --max-columns=300 --max-columns-preview --sortr=modified' . $globArguments . ' -e ' . escapeshellarg($pattern) . ' -- .'
            : 'rg --files --hidden --sortr=modified' . $globArguments . ' -- .';
        return 'rbsearch=$(mktemp); ' . $ripgrep . ' > "$rbsearch" 2>&1; rbcode=$?; '
            . 'head -n ' . static::MAX_SEARCH_MATCHES . ' "$rbsearch" | sed "s#^\\./##" | head -c ' . static::MAX_SEARCH_CHARACTERS . '; echo; '
            . 'echo "__TOTAL__ $(wc -l < "$rbsearch") $rbcode"; rm -f "$rbsearch"';
    }
}
