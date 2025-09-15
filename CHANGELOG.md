# Changelog

## [4.1.22] - 2025-09-15

### Changed
- Version bump to 4.1.22

## [4.1.21] - 2025-09-15

### Changed
- Version bump to 4.1.21



## [4.1.21] - 2025-09-15

### Changed
- Version bump to 4.1.21

## [4.1.20] - 2025-09-15

### Changed
- Version bump to 4.1.20



## [4.1.20] - 2025-09-15

### Changed
- Version bump to 4.1.20

## [4.1.19] - 2025-09-14

### Changed
- Version bump to 4.1.19



## [4.1.18] - 2025-09-08

### Changed
- Version bump to 4.1.18

## [4.1.17] - 2025-09-08

### Added
- **Code Quality Configuration**: Added `.codacy/codacy.yaml` for automated code quality checks and analysis
- **New Plugin Mappings**:
  - Added mapping for `active_plugins` to WordPress Core
  - Added mappings for Jetpack options (`jetpack_*` and `jp_*`) to `jetpack/jetpack.php`
- **Safe Patterns**: Added `jp_sync_error_log_*` pattern to safe_patterns for Jetpack sync error logs

### Changed
- **Git Ignore Updates**:
  - Added comment for telemetry backend files
  - Changed ignore pattern from `telemetry-backend/` to `/telemetry-backend/`
  - Added specific ignore for `telemetry-backend/telemetry-collector.php`
- **Version Bump**: Updated plugin version to 4.1.17 in both header comment and `AO_PLUGIN_VERSION` constant
- **Configuration Updates**: Reorganized `safe_literals` in `config.json` (moved revslider-related items)
- **Telemetry Backend Improvements**:
  - Enhanced `telemetry-collector.php` with 5 new lines (likely additional data collection points)
  - Major updates to `telemetry-dashboard.php` (672 line changes, 97% rewrite) - improved data handling, deduplication, and UI
- **Telemetry Cron Optimization**: Replaced direct storage of large telemetry arrays in WP-Cron with transient-based approach to prevent bloating the `cron` option in `wp_options`
- **Main Plugin File**: Significant refactoring of `autoloaded_options_checker.php` (2,342 line changes) - likely includes telemetry integration updates and code optimizations

### Fixed
- **Concurrent Write Protection**: Added `LOCK_EX` flag to `file_put_contents` in debug log writing to prevent data corruption from simultaneous writes
- **Duplicate Telemetry Prevention**: Added `$schedule_telemetry` parameter to `ao_get_analysis_data()` to prevent manual "Send now" from scheduling duplicate automatic telemetry
- **Telemetry Deduplication**: Improved handling of unique site submissions in telemetry data
- **Configuration Loading**: Better fallback handling for remote config failures

## [4.1.16] - 2025-09-08

### Changed
- Minor version bump for release

## [4.1.15] - 2025-09-08

### Added
- Add telemetry data management features



## [4.1.15] - 2025-09-08

### Added
- Telemetry backend with deduplication logic
- Dashboard for viewing telemetry data with proper unique site handling

### Changed
- Improve telemetry data quality and deduplication
- Revert autoloaded_options_checker.php to commit 4c765f6

### Fixed
- Ensure stats account for latest submission per unique site only



## [4.1.14] - 2025-09-08

### Added
- Telemetry backend with deduplication logic
- Dashboard for viewing telemetry data with proper unique site handling

### Changed
- Improve telemetry data quality and deduplication
- Revert autoloaded_options_checker.php to commit 4c765f6

### Fixed
- Fix deprecated set-output commands in GitHub Actions
- Ensure stats account for latest submission per unique site only



## [4.1.13] - 2025-09-08

### Changed
- Version bump to 4.1.13



All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.1.6] - 2025-09-08

### Changed
- Fix GitHub Actions permissions
- Test fixed GitHub Actions permissions
- Verify automated changelog and release creation
- Confirm workflow permissions are working correctly

## [4.1.4] - 2025-01-08

### Changed
- Moved Telemetry Settings section to bottom of page next to Manual Option Lookup for better UX
- Updated plugin version to 4.1.4

### Added
- Automated GitHub release workflow using GitHub Actions
- Release script (`release.sh`) for easy version management
- CHANGELOG.md for tracking changes
- Updated README with release automation instructions

## [4.1.3] - 2024-XX-XX

### Added
- Initial release of Autoloaded Options Optimizer
- Database analysis for autoloaded options > 1KB
- Plugin/theme identification using remote config
- Safe autoload disabling for inactive plugins
- Telemetry collection (opt-in)
- Manual option lookup
- Source code finder for unknown options
- Integration with Performance Lab plugin history

### Features
- Real-time configuration updates from GitHub
- Bulk actions for safe options
- Detailed recommendations per plugin
- Responsive admin interface
- Comprehensive option analysis and grouping

---

## Types of changes
- `Added` for new features
- `Changed` for changes in existing functionality
- `Deprecated` for soon-to-be removed features
- `Removed` for now removed features
- `Fixed` for any bug fixes
- `Security` in case of vulnerabilities