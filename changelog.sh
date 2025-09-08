#!/bin/bash

# Changelog helper script for Autoloaded Options Optimizer Plugin
# Usage: ./changelog.sh [add|view|edit]

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[NOTE]${NC} $1"
}

# Check if CHANGELOG.md exists
if [ ! -f "CHANGELOG.md" ]; then
    print_error "CHANGELOG.md not found!"
    exit 1
fi

case "$1" in
    "add")
        # Get current version
        CURRENT_VERSION=$(grep "Version:" autoloaded_options_checker.php | head -1 | sed 's/.*Version:\s*//')
        DATE=$(date +%Y-%m-%d)

        print_status "Adding changelog entry for version $CURRENT_VERSION"

        # Create temporary file for editing
        TEMP_FILE=$(mktemp)

        # Create changelog entry template
        cat > "$TEMP_FILE" << EOF
## [$CURRENT_VERSION] - $DATE

### Added
-

### Changed
-

### Fixed
-

### Removed
-

EOF

        # Check if entry already exists
        if grep -q "## \[$CURRENT_VERSION\]" CHANGELOG.md; then
            print_warning "Changelog entry for $CURRENT_VERSION already exists!"
            read -p "Do you want to edit it? (y/N): " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                # Extract existing entry
                sed -n "/## \[$CURRENT_VERSION\]/,/## \[[0-9]/p" CHANGELOG.md | sed '$d' > "$TEMP_FILE"
            else
                rm "$TEMP_FILE"
                exit 0
            fi
        fi

        # Open editor
        ${EDITOR:-nano} "$TEMP_FILE"

        # Check if user made changes
        if [ -s "$TEMP_FILE" ]; then
            # Remove empty sections
            sed -i '/^### [A-Z][a-z]*$/N;s/\n-$//' "$TEMP_FILE"
            sed -i '/^### [A-Z][a-z]*$/,/^$/d' "$TEMP_FILE"

            # Remove empty lines at end
            sed -i -e :a -e '/^\n*$/d;N;ba' "$TEMP_FILE"

            if grep -q "## \[$CURRENT_VERSION\]" CHANGELOG.md; then
                # Replace existing entry
                sed -i "/## \[$CURRENT_VERSION\]/,/## \[[0-9]/{
                    /## \[$CURRENT_VERSION\]/r $TEMP_FILE
                    d
                }" CHANGELOG.md
                sed -i "/## \[$CURRENT_VERSION\]/,/## \[[0-9]/{
                    /## \[$CURRENT_VERSION\]/!d
                }" CHANGELOG.md
            else
                # Insert new entry after header
                sed -i "s/# Changelog/# Changelog\n\n$(cat "$TEMP_FILE")/" CHANGELOG.md
            fi

            print_status "Changelog updated successfully!"
        else
            print_warning "No changes made to changelog."
        fi

        rm "$TEMP_FILE"
        ;;

    "view")
        VERSION=${2:-$(grep "Version:" autoloaded_options_checker.php | head -1 | sed 's/.*Version:\s*//')}
        print_status "Viewing changelog for version $VERSION"

        if grep -q "## \[$VERSION\]" CHANGELOG.md; then
            sed -n "/## \[$VERSION\]/,/## \[[0-9]/p" CHANGELOG.md | sed '$d'
        else
            print_warning "No changelog entry found for version $VERSION"
        fi
        ;;

    "edit")
        VERSION=${2:-$(grep "Version:" autoloaded_options_checker.php | head -1 | sed 's/.*Version:\s*//')}
        print_status "Editing changelog for version $VERSION"

        if grep -q "## \[$VERSION\]" CHANGELOG.md; then
            # Extract existing entry to temp file
            TEMP_FILE=$(mktemp)
            sed -n "/## \[$VERSION\]/,/## \[[0-9]/p" CHANGELOG.md | sed '$d' > "$TEMP_FILE"

            # Edit the entry
            ${EDITOR:-nano} "$TEMP_FILE"

            # Replace in changelog
            sed -i "/## \[$VERSION\]/,/## \[[0-9]/{
                /## \[$VERSION\]/r $TEMP_FILE
                d
            }" CHANGELOG.md
            sed -i "/## \[$VERSION\]/,/## \[[0-9]/{
                /## \[$VERSION\]/!d
            }" CHANGELOG.md

            rm "$TEMP_FILE"
            print_status "Changelog updated!"
        else
            print_warning "No changelog entry found for version $VERSION"
            print_info "Use './changelog.sh add' to create a new entry"
        fi
        ;;

    "help"|*)
        echo "Changelog Helper Script"
        echo ""
        echo "Usage: $0 [command] [options]"
        echo ""
        echo "Commands:"
        echo "  add                    Add a new changelog entry for current version"
        echo "  view [version]         View changelog for specific version (default: current)"
        echo "  edit [version]         Edit changelog for specific version (default: current)"
        echo "  help                   Show this help message"
        echo ""
        echo "Examples:"
        echo "  $0 add                 # Add entry for current version"
        echo "  $0 view 4.1.3          # View changelog for version 4.1.3"
        echo "  $0 edit 4.1.3          # Edit changelog for version 4.1.3"
        ;;
esac