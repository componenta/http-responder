#!/usr/bin/env bash
# Temporary, reproducible CI selection until Detector 3 and its readers are published.
set -euo pipefail
directory="$1"
version="$2"
if [ "$version" = "2" ]; then
    composer --working-dir="$directory" require --no-interaction --no-update 'componenta/mimetype-detector:^2.0'
    exit 0
fi
if [ "$version" != "3" ]; then
    echo "Expected detector series 2 or 3." >&2
    exit 1
fi
composer --working-dir="$directory" config repositories.detector vcs https://github.com/componenta/mimetype-detector
composer --working-dir="$directory" config repositories.file-reader vcs https://github.com/componenta/file-reader
composer --working-dir="$directory" config repositories.stream-reader vcs https://github.com/componenta/stream-reader
composer --working-dir="$directory" require --no-interaction --no-update \
    'componenta/mimetype-detector:dev-main#c1ccadf2d665a1cdead7d8c77e7cc6364e25b8be as 3.0.0' \
    'componenta/file-reader:dev-main#0d1f6f2144717777f4af181ed81e758fc0acf3ff as 1.0.0' \
    'componenta/stream-reader:dev-main#a1ec0bc9cba4afdee9ad0592f5e663b399846736 as 1.0.0'
if [ "${3:-}" = "validation" ]; then
    composer --working-dir="$directory" config repositories.validation vcs https://github.com/componenta/validation
    composer --working-dir="$directory" require --no-interaction --no-update \
        'componenta/validation:dev-compat/mimetype-detector-v3#17647e254a1f7919c3faddc9e4982e710df89f4a as 3.3.0'
fi
