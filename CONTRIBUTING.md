# Contributing to Autoloaded Options Optimizer

Thank you for your interest in contributing to the Autoloaded Options Optimizer plugin! This document provides guidelines and information for contributors.

## Development Workflow

### 1. Fork and Clone
```bash
git clone https://github.com/your-username/mill_autoload_options_checker_plugin.git
cd mill_autoload_options_checker_plugin
git checkout -b feature/your-feature-name
```

### 2. Make Changes
- Follow the existing code style and conventions
- Test your changes thoroughly
- Update documentation if needed

### 3. Commit Changes
Use conventional commit format for better changelog generation:

```bash
# Good examples
git commit -m "feat: add dark mode toggle to admin interface"
git commit -m "fix: resolve memory leak in option processing"
git commit -m "docs: update installation instructions"
git commit -m "refactor: simplify database query logic"

# Bad examples
git commit -m "Fixed bug"
git commit -m "Updated code"
git commit -m "Changes made"
```

### 4. Create Pull Request
- Push your branch to GitHub
- Create a pull request with a clear description
- Reference any related issues

## Conventional Commits

This project uses [Conventional Commits](https://conventionalcommits.org/) format for commit messages. This enables:

- Automated changelog generation
- Automatic version bumping
- Clear commit history

### Format
```
type(scope): description

[optional body]

[optional footer]
```

### Types
- **feat**: New feature
- **fix**: Bug fix
- **docs**: Documentation changes
- **style**: Code style changes (formatting, etc.)
- **refactor**: Code refactoring
- **test**: Adding or updating tests
- **chore**: Maintenance tasks
- **perf**: Performance improvements
- **ci**: CI/CD changes
- **build**: Build system changes
- **revert**: Reverting changes

### Examples
```bash
feat: add bulk option disable functionality
fix(ui): resolve modal overlay positioning issue
docs: update changelog format documentation
refactor: simplify option grouping logic
perf: optimize database queries for large option sets
```

### Scope (Optional)
Scopes help categorize changes:
```bash
feat(telemetry): add manual data submission feature
fix(admin): resolve settings page layout issue
docs(api): update configuration file format
```

## Changelog

The project maintains an automated changelog in `CHANGELOG.md`. Changes are automatically categorized based on commit messages:

- **Added**: New features (`feat:` commits)
- **Changed**: Changes in functionality (`refactor:`, `perf:` commits)
- **Fixed**: Bug fixes (`fix:` commits)
- **Removed**: Removed features

### Manual Changelog Updates
If you need to manually update the changelog:

```bash
# Add entry for current version
./changelog.sh add

# Edit existing entry
./changelog.sh edit 4.1.4

# View changelog for version
./changelog.sh view 4.1.3
```

## Release Process

### Automated Releases
The project uses automated releases via GitHub Actions:

1. Update version in `autoloaded_options_checker.php`
2. Update changelog if needed: `./changelog.sh add`
3. Run release script: `./release.sh patch` (or `minor`/`major`)
4. GitHub Actions automatically creates the release

### Manual Process
```bash
# Update version
# Update changelog
git add .
git commit -m "feat: your new feature"
git tag v4.1.5
git push origin main --tags
```

## Code Style

### PHP
- Follow WordPress coding standards
- Use meaningful variable and function names
- Add comments for complex logic
- Keep functions focused on single responsibilities

### JavaScript
- Use modern ES6+ features where appropriate
- Follow consistent naming conventions
- Add error handling for async operations

### Git
- Write clear, descriptive commit messages
- Keep commits focused and atomic
- Use branches for features and fixes

## Testing

- Test changes in a local WordPress environment
- Verify functionality with different configurations
- Check for PHP/JavaScript errors
- Test with various option sizes and types

## Documentation

- Update README.md for new features
- Document configuration options
- Provide usage examples
- Keep changelog up to date

## Questions?

If you have questions about contributing, please:
1. Check existing issues and documentation
2. Open a new issue for clarification
3. Reach out to the maintainers

Thank you for contributing to the Autoloaded Options Optimizer! 🎉