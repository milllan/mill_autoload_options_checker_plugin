1. History section:

-   separate "Performance Lab" plugin and this plugin
-   add "Find soucrce" to history too.

2. "Disable Autoload for All Safe Options"

-   remove section complitly, icluding all the html, css an js. I dont want bulk disable, too risky

3."Child theme" not detected as active, with parent theme options

4. Remove cached config file transient and other transients on uninstall, leave only history of disabled options.

5. Interactive, Collapsible Option Value Viewer.

Problem: The current "View" modal does a print_r inside a <pre> tag. This is fine for simple strings or small arrays. For large, complex, multi-level serialized objects (like those from Elementor or complex themes), it produces a massive, unreadable wall of text. The user can't actually diagnose what's inside.

Solution: When the user clicks "View," check if the option value is a serialized array or object. If it is, render it as an interactive, collapsible HTML tree. This allows the user to explore the data structure, understand what the option is storing, and make a much more informed decision.
