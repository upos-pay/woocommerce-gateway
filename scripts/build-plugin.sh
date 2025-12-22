#!/bin/bash

# Define colors
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Determine project root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Define plugin info
PLUGIN_SLUG="upos-woocommerce"
BUILD_DIR="$PROJECT_ROOT/build"
DEST_DIR="$BUILD_DIR/$PLUGIN_SLUG"

echo -e "${BLUE}Starting build process for $PLUGIN_SLUG...${NC}"

# 1. Clean up previous build
if [ -d "$BUILD_DIR" ]; then
    echo "Cleaning up old build directory..."
    rm -rf "$BUILD_DIR"
fi
mkdir -p "$DEST_DIR"

# 2. Copy files to build directory
echo "Copying files..."
cp "$PROJECT_ROOT/upos-woocommerce.php" "$DEST_DIR/"
cp "$PROJECT_ROOT/readme.txt" "$DEST_DIR/"
cp "$PROJECT_ROOT/README.md" "$DEST_DIR/"
cp "$PROJECT_ROOT/LICENSE" "$DEST_DIR/"
cp "$PROJECT_ROOT/uninstall.php" "$DEST_DIR/"
cp -R "$PROJECT_ROOT/includes" "$DEST_DIR/"
cp -R "$PROJECT_ROOT/assets" "$DEST_DIR/"
cp -R "$PROJECT_ROOT/languages" "$DEST_DIR/"
cp -R "$PROJECT_ROOT/templates" "$DEST_DIR/" 2>/dev/null || true

# 3. Install Production PHP Dependencies
echo "Installing PHP dependencies (no-dev)..."
cp "$PROJECT_ROOT/composer.json" "$DEST_DIR/"

# Switch to destination dir to run composer
cd "$DEST_DIR"
if composer install --no-dev --optimize-autoloader --no-scripts --quiet; then
    echo -e "${GREEN}[OK] Composer dependencies installed.${NC}"
else
    echo -e "${RED}[ERROR] Composer install failed.${NC}"
    exit 1
fi
# Remove composer files after install to keep it clean
rm composer.json
# Return to project root (strictly not needed as we use absolute paths, but good practice)
cd "$PROJECT_ROOT"

# 4. Final cleanup
echo "Final cleanup..."
find "$DEST_DIR" -name ".DS_Store" -delete
find "$DEST_DIR" -name ".git*" -delete

# 5. Create Archive using PHP
echo "Creating ZIP archive..."
php -r "
\$rootPath = realpath('$DEST_DIR');
\$zipFile = '$BUILD_DIR/$PLUGIN_SLUG.zip';
\$zip = new ZipArchive();
if (\$zip->open(\$zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    \$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\$rootPath), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach (\$files as \$name => \$file) {
        if (!\$file->isDir()) {
            \$filePath = \$file->getRealPath();
            \$relativePath = '$PLUGIN_SLUG/' . substr(\$filePath, strlen(\$rootPath) + 1);
            \$zip->addFile(\$filePath, \$relativePath);
        }
    }
    \$zip->close();
    exit(0);
} else {
    exit(1);
}
"

if [ $? -eq 0 ]; then
    echo -e "${GREEN}[SUCCESS] Build complete!${NC}"
    echo -e "ZIP File: ${BLUE}$BUILD_DIR/$PLUGIN_SLUG.zip${NC}"
else
    echo -e "${RED}[ERROR] Failed to create ZIP archive.${NC}"
    exit 1
fi
