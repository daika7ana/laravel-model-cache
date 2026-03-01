# Contributing to Laravel Model Cache

Thanks for your interest in contributing.

> This project is a fork of the original package:
> https://github.com/ymigval/laravel-model-cache

## Code of Conduct

By participating, you agree to follow the [Laravel Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Before You Start

- Check existing issues and pull requests first.
- Keep pull requests focused on one change (feature, fix, or docs).
- Add or update tests for behavior changes.

## Local Setup

1. Fork this repository.
2. Clone your fork.
3. Add upstream remote:

```bash
git remote add upstream https://github.com/ymigval/laravel-model-cache.git
```

4. Install dependencies:

```bash
composer install
```

## Development Workflow

1. Create a branch:

```bash
git checkout -b feature/your-feature-name
```

2. Make your changes.
3. Run style checks and tests.
4. Update docs if behavior changes.
5. Open a pull request.

## Coding Standards

This project follows PSR-12 and Laravel-style conventions.

Check style:

```bash
composer run-script check-style
```

Auto-fix style:

```bash
composer run-script fix-style
```

## Testing

Run the full test suite before opening a pull request:

```bash
composer test
```

When fixing a bug, include a test that fails before your fix and passes after it.

## Documentation Guidelines

If your change affects usage or setup, update documentation in:

- `README.md` for high-level overview and quick start
- `docs/` for HOW TO guides and practical workflows

Current docs index: `docs/README.md`.

## Pull Request Checklist

- [ ] Branch is based on latest `main`
- [ ] Changes are focused and scoped
- [ ] Code style checks pass
- [ ] Tests pass
- [ ] README/docs updated when needed
- [ ] PR description explains what changed and why

## Reporting Issues

When opening an issue, include:

- Laravel version
- PHP version
- Cache driver in use
- Minimal reproduction steps
- Expected result vs actual result

## Release Process

Releases are handled by maintainers.
