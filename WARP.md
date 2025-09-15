# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Repository Overview

This is a WordPress plugin called **Autoloaded Options Optimizer** that analyzes and optimizes large autoloaded options in WordPress databases to improve site performance. The plugin is a single-file PHP solution with a config-driven architecture.

## Common Development Tasks

### Release Management

Create a new release:
```bash
# 1. Update version in both locations in autoloaded_options_checker.php
# - Line 6: Version: X.X.X (header comment)
# - Line 18: define('AO_PLUGIN_VERSION', 'X.X.X');

# 2. Commit version change
git add autoloaded_options_checker.php
git commit -m "Bump version to X.X.X"

# 3. Create and push tag (triggers automated release)
git tag vX.X.X
git push origin main
git push origin vX.X.X
```

### Testing and Validation

Test plugin functionality in WordPress:
```bash
# Create plugin zip for testing
zip -r test-plugin.zip . -x "*.git*" ".github/*" "*.md" "composer.json" "telemetry-backend/*" ".gitignore"

# Check PHP syntax
php -l autoloaded_options_checker.php
```

### Configuration Updates

Update the plugin's intelligence config:
```bash
# Edit the main configuration file
# This controls how options are identified and categorized
vim known-options.json

# Test configuration validity
php -c "echo json_last_error_msg() . PHP_EOL;" -r "json_decode(file_get_contents('known-options.json'));"
```

## Architecture & Key Components

### Core Architecture

The plugin follows a **config-driven intelligence** approach:

1. **Single File Design**: The entire plugin is in `autoloaded_options_checker.php` (~2,400 lines)
2. **Remote Configuration**: `known-options.json` contains the intelligence for identifying plugins/options
3. **Telemetry System**: Optional data collection system in `telemetry-backend/` directory
4. **Auto-updater**: Integrated GitHub-based updater for live deployments

### Key Classes and Functions

- `AO_Remote_Config_Manager`: Singleton that manages remote/local config loading with caching
- `ao_get_analysis_data()`: Core analysis function that processes wp_options table
- `ao_collect_telemetry_data()`: Handles anonymous data collection for plugin improvement
- AJAX handlers: `ao_ajax_disable_autoload_options`, `ao_ajax_get_option_value`, `ao_ajax_find_option_in_files`

### Configuration System

The `known-options.json` file uses a **plugin-centric structure** that contains:
- `plugins`: Object where each plugin contains its options, file path, and metadata
- `safe_literals` & `safe_patterns`: Global options safe to disable regardless of plugin
- `version`: Configuration version for cache busting

**Key advantages of the new structure:**
- No duplicate plugin names across options
- All plugin data grouped in one place
- Inline safety marking with `safe:` prefix
- Rich metadata per plugin (recommendations, file paths)

### File Structure

```
├── autoloaded_options_checker.php  # Main plugin file (~2,400 lines)
├── known-options.json              # Remote intelligence config (plugin-centric)
├── telemetry-backend/              # Optional telemetry collection system
│   ├── telemetry-collector.php     # Receives telemetry data
│   ├── telemetry-analyzer.php      # Processes collected data
│   └── telemetry-dashboard.php     # Views telemetry insights
└── .github/workflows/              # Automated release system
    ├── release.yml                 # Creates GitHub releases from tags
    ├── changelog.yml               # Updates changelog automatically
    └── conventional-commits.yml    # Validates commit messages
```

## Development Guidelines

### Code Style

- Follow WordPress coding standards
- Use WordPress core functions where possible (security, database access)
- Plugin uses vanilla JavaScript (no jQuery dependencies)
- All database queries use $wpdb with proper sanitization
- Extensive use of WordPress hooks and filters

### Commit Message Format

Use conventional commits for automated changelog generation:

```bash
feat: add new functionality
fix: resolve bug or issue
docs: documentation changes
chore: maintenance tasks
refactor: code improvements without feature changes
```

### Configuration Updates

When adding new plugins to `known-options.json`:

1. **Plugin-Centric Structure**: Group all options under the plugin name
2. **Pattern Matching**: Use `fnmatch()` patterns (`*` wildcards supported)
3. **Inline Safety**: Prefix safe options with `safe:`
4. **Theme Context**: Use `theme:slug` format for theme files
5. **Rich Metadata**: Include recommendations and file paths

Example plugin entry:
```json
"my-plugin": {
    "name": "My Plugin Display Name",
    "file": "my-plugin/my-plugin.php",
    "recommendation": "Options marked with <code>safe:</code> are cache data and safe to disable.",
    "options": [
        "my_plugin_settings",
        "my_plugin_*",
        "safe:my_plugin_cache_*",
        "safe:my_plugin_temp_data"
    ]
}
```

**Safety Marking:**
- Regular options: `"option_name"` or `"option_pattern_*"`
- Safe options: `"safe:option_name"` or `"safe:option_pattern_*"`
- Theme files: `"file": "theme:theme-slug"`
- Core options: `"file": "core"`

### Security Considerations

- Never disable autoload for security plugin options (firewall, login security)
- Be extremely careful with "safe" classifications
- Validate all user inputs through WordPress sanitization functions
- Use wp_remote_get() with timeout limits for external requests

## Integration Points

### WordPress Performance Lab

The plugin integrates with Performance Lab's `perflab_aao_disabled_options` option for history tracking compatibility.

### WPML/Multisite Considerations

Special handling for:
- WPML options require careful context checking
- Theme-specific options use active theme detection
- Multisite installations need additional validation

### Telemetry System

If enabled, collects anonymous data about:
- Unknown options >1KB (names and sizes only)
- WordPress/PHP versions
- Plugin/theme counts (no names for privacy)
- Configuration version for compatibility
