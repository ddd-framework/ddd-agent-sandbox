# syntax=docker/dockerfile:1.10
# sandbox-runner: one slot of the agent sandbox of mgamadeus/ddd-agent-sandbox (README.md).
# Two toolkits from one file, selected by the build argument TOOLKIT:
#   base (default): Debian 13 slim, bash, coreutils, ripgrep, jq, Python 3.13 with openpyxl, pandas, python-docx
#   full:           the full toolkit (CLI and picture tools, fonts, LibreOffice, Chromium headless shell, Node 24,
#                   Typst, the full Python list)
# Layer order puts the volatile layers on top: system and fonts, LibreOffice, Chromium, Node,
# Python packages, runner. Every input is pinned: base images by digest, Debian packages by the snapshot date, Python
# and Node packages by exact versions (requirements-*.txt, node/package-lock.json).
#
#   docker build -t ddd-agent-sandbox-runner:base runner
#   docker build -t ddd-agent-sandbox-runner:full --build-arg TOOLKIT=full runner
#
# CONTRACT_VERSION (the package version, set by the release workflow) is compiled into the binary and reported by
# GET /healthz. Project software (fonts, packages) is added in an image built FROM this one from the project's
# image.yaml (app:agent-sandbox:render-image); this image carries no project content.

ARG TOOLKIT=base
ARG CONTRACT_VERSION=0.0.0-dev
# Debian 13.7 (trixie) package state of this date (snapshot.debian.org); bump it to take Debian security updates
ARG DEBIAN_SNAPSHOT=20261120T000000Z

# ---------------------------------------------------------------------------------------------------------------------
# The runner: a static binary (Go standard library plus golang.org/x/sys and go-fuse, pinned in go.sum). The unit
# tests run here as root, so the uid switch is real.
FROM golang:1.27.4-trixie@sha256:f18c3d73a184fb0582828510722e723b63a93f7188d11dd223d9be6f6880e65d AS runner-build
ARG CONTRACT_VERSION
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 GOFLAGS=-buildvcs=false go vet ./... \
    && CGO_ENABLED=0 GOFLAGS=-buildvcs=false go test -count=1 ./... \
    && CGO_ENABLED=0 GOFLAGS=-buildvcs=false go build -trimpath -ldflags="-s -w -X main.contractVersion=${CONTRACT_VERSION}" -o /out/sandbox-runner .

# ---------------------------------------------------------------------------------------------------------------------
# OS: Debian 13.7 slim with CPython 3.13.18 (the official image; the interpreter lives below /usr/local).
FROM python:3.13.18-slim-trixie@sha256:e48f59cdba69a3cbd9ed2cf0f348d90d45a8d350d98134cf1070bc106ee9ed90 AS os
ARG DEBIAN_SNAPSHOT
ENV LANG=C.UTF-8 \
    DEBIAN_FRONTEND=noninteractive \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_ROOT_USER_ACTION=ignore
# tini is PID 1: it reaps the processes a session leaves orphaned (the runner kills them, someone must wait for them).
# git: `drive diff` (git diff --no-index) and the three-way merge (git merge-file), both without a repository
RUN apt-get update --snapshot "$DEBIAN_SNAPSHOT" \
    && apt-get install -y --no-install-recommends --snapshot "$DEBIAN_SNAPSHOT" \
       bash coreutils findutils procps tini ca-certificates ripgrep jq file less git \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------------------------------------------------------
# full, layer 1: system tools and fonts 
FROM os AS system-full
ARG DEBIAN_SNAPSHOT
RUN apt-get update --snapshot "$DEBIAN_SNAPSHOT" \
    && apt-get install -y --no-install-recommends --snapshot "$DEBIAN_SNAPSHOT" \
       pandoc poppler-utils qpdf imagemagick libvips-tools libvips42t64 ffmpeg \
       tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng \
       git sqlite3 fd-find 7zip zip unzip xz-utils \
       libcairo2 libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b libgdk-pixbuf-2.0-0 libffi8 \
       fonts-liberation fonts-liberation2 fonts-crosextra-carlito fonts-crosextra-caladea fonts-dejavu-core \
       fonts-noto-core fonts-noto-cjk fonts-noto-color-emoji fontconfig \
    && ln -s /usr/bin/fdfind /usr/local/bin/fd \
    && rm -rf /var/lib/apt/lists/* \
    # ImageMagick: no URL, script or delegate coders; PDF/PS need Ghostscript, which is not in the image anyway
    && policy=/etc/ImageMagick-7/policy.xml \
    && sed -i 's#</policymap>#  <policy domain="coder" rights="none" pattern="{URL,HTTP,HTTPS,FTP,MVG,MSL,TEXT,SHOW,WIN,PLT,EPHEMERAL,PS,PS2,PS3,EPS,PDF,XPS}" />\n  <policy domain="delegate" rights="none" pattern="*" />\n  <policy domain="path" rights="none" pattern="@*" />\n</policymap>#' "$policy" \
    && fc-cache -f

# full, layer 2: LibreOffice without GUI and without Java
FROM system-full AS libreoffice
ARG DEBIAN_SNAPSHOT
RUN apt-get update --snapshot "$DEBIAN_SNAPSHOT" \
    && apt-get install -y --no-install-recommends --snapshot "$DEBIAN_SNAPSHOT" \
       libreoffice-core-nogui libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui \
       libreoffice-draw-nogui \
    && rm -rf /var/lib/apt/lists/*
# the wrapper comes first in PATH: a profile per call, headless (bin/soffice)
COPY bin/soffice /usr/local/bin/soffice
RUN ln -s /usr/local/bin/soffice /usr/local/bin/libreoffice \
    && HOME=/tmp soffice --version | tee /opt/libreoffice-version.txt

# full, layer 3: the Chromium headless shell in the revision Playwright 1.65 expects (1255), plus its libraries
FROM node:24.22-trixie-slim@sha256:af02d57b57719b9657a45199a79264a9437940cd55b3c7b3fc99615654f124a0 AS chromium-fetch
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers
RUN npx --yes playwright@1.65.1 install --only-shell chromium && chmod -R a+rX /opt/pw-browsers

FROM libreoffice AS chromium
ARG DEBIAN_SNAPSHOT
RUN apt-get update --snapshot "$DEBIAN_SNAPSHOT" \
    && apt-get install -y --no-install-recommends --snapshot "$DEBIAN_SNAPSHOT" \
       libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 libxkbcommon0 libxcomposite1 \
       libxdamage1 libxfixes3 libxrandr2 libgbm1 libasound2t64 libdbus-1-3 libxshmfence1 libx11-xcb1 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=chromium-fetch /opt/pw-browsers /opt/pw-browsers
# Playwright (Python and Node) and kaleido (plotly's picture export) find the headless shell through these. Sessions do
# not inherit the container environment: the runner passes exactly the names in RUNNER_SESSION_PASS_ENV (default
# PLAYWRIGHT_BROWSERS_PATH,BROWSER_PATH) into every shell and job.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers \
    BROWSER_PATH=/usr/local/bin/chrome-headless-shell
RUN shell=$(find /opt/pw-browsers -type f -name chrome-headless-shell | head -1) \
    && test -x "$shell" && ! ldd "$shell" | grep 'not found' \
    && ln -s "$shell" /usr/local/bin/chrome-headless-shell

# full, layer 4: Node 24 (binary only, no npm at run time) and the locked libraries below /opt/node. /node_modules
# points there, so require() and import resolve them from any folder, the workspace included.
FROM node:24.22-trixie-slim@sha256:af02d57b57719b9657a45199a79264a9437940cd55b3c7b3fc99615654f124a0 AS node-deps
WORKDIR /opt/node
COPY node/package.json node/package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund && npm cache clean --force

FROM chromium AS node
COPY --from=node-deps /usr/local/bin/node /usr/local/bin/node
COPY --from=node-deps /opt/node /opt/node
RUN ln -s /opt/node/node_modules /node_modules \
    && for tool in tsx tsc; do ln -s /opt/node/node_modules/.bin/$tool /usr/local/bin/$tool; done \
    && node --version && tsx --version

# full, layer 5: Typst in a pinned version, the static musl binary, checksum-verified. No Typst packages: `@preview/…`
# imports need the network, which a session does not have. Fonts of a project come with its image.yaml.
FROM os AS typst-fetch
ARG TARGETARCH
ARG TYPST_VERSION=0.15.1
ARG TYPST_SHA256_AMD64=a6d077d0a95eed5a2eba715b2dae06be954f624ccbf85758a03f389ded33118c
ARG TYPST_SHA256_ARM64=5aa8d74a3d906e60ea12a66ac2f37f8eef1b14cbad7182a745e393a10c23dcee
RUN set -eu; \
    case "${TARGETARCH:-amd64}" in \
      amd64) triple=x86_64-unknown-linux-musl; sha="$TYPST_SHA256_AMD64" ;; \
      arm64) triple=aarch64-unknown-linux-musl; sha="$TYPST_SHA256_ARM64" ;; \
      *) echo "no Typst build for $TARGETARCH" >&2; exit 1 ;; \
    esac; \
    python3 -c 'import sys, urllib.request; urllib.request.urlretrieve(sys.argv[1], "/tmp/typst.tar.xz")' \
      "https://github.com/typst/typst/releases/download/v${TYPST_VERSION}/typst-${triple}.tar.xz"; \
    echo "${sha}  /tmp/typst.tar.xz" | sha256sum -c -; \
    python3 -c 'import tarfile; tarfile.open("/tmp/typst.tar.xz").extractall("/tmp", filter="data")'; \
    install -D -m 0755 "/tmp/typst-${triple}/typst" /out/typst

FROM node AS typst
COPY --from=typst-fetch /out/typst /usr/local/bin/typst
# smoke: the binary runs and compiles a page with a system font (no network needed)
RUN typst --version | grep -q '^typst 0.15.1' \
    && mkdir -p /tmp/typst-smoke && cd /tmp/typst-smoke \
    && printf '#set page(width: 10cm, height: 4cm)\n#set text(font: "DejaVu Sans", weight: "bold")\nSandbox 1.234 km\n' > smoke.typ \
    && typst compile smoke.typ smoke.pdf \
    && head -c 5 smoke.pdf | grep -q '%PDF-' \
    && cd / && rm -rf /tmp/typst-smoke

# ---------------------------------------------------------------------------------------------------------------------
# The toolkit stage below the Python packages
FROM os AS tools-base
FROM typst AS tools-full

# layer 6: Python packages of the toolkit; pip is removed afterwards (no package manager at run time)
FROM tools-${TOOLKIT} AS python
ARG TOOLKIT
COPY requirements-${TOOLKIT}.txt /tmp/requirements.txt
RUN pip install --prefer-binary -r /tmp/requirements.txt \
    && rm /tmp/requirements.txt \
    && python -m pip uninstall -y pip

# layer 7: the runner
FROM python AS final
ARG TOOLKIT
ARG CONTRACT_VERSION
COPY --from=runner-build /out/sandbox-runner /usr/local/bin/sandbox-runner
# the drive CLI of drive mode is the same binary under another name
RUN ln -s /usr/local/bin/sandbox-runner /usr/local/bin/drive
# no setuid or setgid binaries; /workspace exists for runs without a volume (Kubernetes mounts an emptyDir there)
RUN find / -xdev -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null; \
    mkdir -p /workspace && chmod 0777 /workspace \
    && echo "$TOOLKIT" > /etc/sandbox-toolkit \
    && echo "$CONTRACT_VERSION" > /etc/sandbox-contract-version
ENV PORT=8080 \
    LANG=C.UTF-8
# root inside gVisor with only SETUID, SETGID and KILL (Kubernetes securityContext); every session command runs under
# its own unprivileged uid. Read-only root: /workspace and /tmp are emptyDirs.
USER 0:0
WORKDIR /
EXPOSE 8080
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/sandbox-runner"]
