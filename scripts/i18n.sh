#!/bin/sh

# Compile all .po files to .mo files in languages/ directory using a temporary alpine container
docker run --rm \
  -v "$(pwd):/app" \
  -w /app \
  alpine:latest \
  /bin/sh -c "apk add --no-cache gettext && \
    for file in languages/*.po; do \
      if [ -f \"\$file\" ]; then \
        msgfmt -o \"\${file%.po}.mo\" \"\$file\"; \
        echo \"Compiled \$file -> \${file%.po}.mo\"; \
      fi \
    done && \
    echo 'All language files compiled successfully!'"
