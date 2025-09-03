# Autoloaded Options Optimizer

A lean, config-driven WordPress plugin for analyzing and optimizing `autoloaded` options to improve site performance.

> This tool is designed for developers and advanced users to safely diagnose and clean up bloated `wp_options` tables, a common cause of slow page loads.

![Autoloaded Options Optimizer Screenshot](https://raw.githubusercontent.com/milllan/mill_autoload_options_checker_plugin/main/assets/screenshot-1.png)

---

## Core Philosophy

The development of this plugin is guided by a few key principles:

-   **Lean & Performant:** The plugin is a single file with no frontend assets and minimal overhead. It uses vanilla JavaScript and core WordPress functions to keep things fast and dependency-free.
-   **Config-Driven Intelligence:** All logic for identifying plugins, themes, and "safe" options is managed by a remote `config.json` file. This allows the plugin's knowledge base to be updated instantly without requiring a full plugin release.
-   **User Safety First:** The plugin prioritizes safety above all else. Actions are intentionally limited to prevent users from accidentally breaking their site. It will only allow disabling autoload for options belonging to inactive plugins/themes or those explicitly marked as safe in the configuration.
-   **Context-Aware:** The tool intelligently uses site context (like the active theme) to apply rules correctly and avoid false positives.

## Key Features

-   **Analyze & Group:** Queries the database for all autoloaded options greater than 1KB and groups them by the responsible plugin or theme.
-   **Remote Intelligence:** Fetches a `config.json` from GitHub to identify options. It uses `fnmatch()` for both exact and wildcard (`*`) pattern matching, providing flexible and powerful identification rules.
-   **Safe Actions:** Allows users to disable autoload for single options or in bulk. Actions are only enabled for options from inactive plugins or those whitelisted as "safe."
-   **Detailed Diagnostics:**
    -   **View Content:** A modal allows you to view the raw, unserialized content of any option.
    -   **Manual Lookup:** A form to look up any option by name, not just large ones.
    -   **Find Source:** A powerful diagnostic tool that searches plugin and theme files to find the origin of "Unknown" options.
-   **Compatibility:** Reads and writes to the history log of the official **Performance Lab** plugin (`perflab_aao_disabled_options`) for seamless interoperability.
-   **Automatic Updates:** Includes an integrated updater to pull new versions directly from this GitHub repository.

## Installation

1.  Download the latest `autoloaded_options_checker.zip` from the [Releases](https://github.com/milllan/mill_autoload_options_checker_plugin/releases) page.
2.  In your WordPress admin dashboard, navigate to **Plugins -> Add New -> Upload Plugin**.
3.  Upload the ZIP file and activate the plugin.
4.  Navigate to **Tools -> Autoloaded Options Optimizer** to access the tool.

## How It Works: The Analysis Process

The plugin follows a clear priority order to identify the source of each large autoloaded option:

1.  **Config Mapping:** It first checks for a match in the `plugin_mappings` of the `config.json` file. This is the most accurate method.
2.  **Context Check:** If a mapping has a `context` key (e.g., for a specific theme), it verifies that the context is currently active on the site.
3.  **Core Transient Fallback:** If no mapping is found, it identifies generic WordPress core transients (e.g., options starting with `_transient_`).
4.  **Guessing:** As a last resort, it makes an educated guess based on common plugin prefixes (e.g., `wpseo_`, `elementor_`).
5.  **Unknown:** If none of the above methods succeed, the option is marked as "Unknown," and the "Find Source" tool becomes available.

## The Intelligence: `config.json`

The brain of this plugin is the `config.json` file hosted in this repository. This allows for a clean separation of logic and data.

-   `plugin_mappings`: The primary lookup table. The key is an option name or a pattern (e.g., `my_plugin_*`), and the value contains the plugin/theme name and its main file path.
-   `safe_literals` & `safe_patterns`: These arrays contain option names and patterns that are known to be safe for disabling autoload, even if their parent plugin is active. These are typically cache entries, logs, or admin-only data.
-   `recommendations`: Provides user-facing advice and warnings for specific plugins or options, which are displayed in the "Recommendations" card on the admin page.

## Contributing

While this is primarily a personal tool, feedback and contributions that align with the core philosophy are welcome.

-   **Bug Reports & Suggestions:** Please open an [Issue](https://github.com/milllan/mill_autoload_options_checker_plugin/issues) with a clear description of the problem or feature request.
-   **Improving `config.json`:** If you identify an "Unknown" option and successfully trace its source, please submit a Pull Request to add it to the `config.json`. This is the easiest and most valuable way to contribute!

## License

This plugin is licensed under the GPL v2 or later.
