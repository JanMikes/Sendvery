# Contributing to Sendvery

Thank you for your interest in contributing to Sendvery.

## Getting Started

1. Fork the repository
2. Clone your fork
3. Create a feature branch from `main`
4. Make your changes
5. Run the test suite
6. Submit a pull request

## Development Setup

```bash
docker compose up -d
docker compose exec app composer install
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

## Quality Standards

All contributions must pass:

```bash
docker compose exec app vendor/bin/phpunit            # Tests
docker compose exec app vendor/bin/phpstan            # Static analysis
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff  # Code style
```

- **100% test coverage** is required
- Follow the patterns in `CLAUDE.md` (CQRS, readonly classes, etc.)
- Use strict types everywhere

## Code Style

- PHP CS Fixer with Symfony preset handles formatting
- Run `docker compose exec app vendor/bin/php-cs-fixer fix` to auto-fix

## Pull Request Guidelines

- Keep PRs focused on a single change
- Include tests for new functionality
- Update relevant documentation
- Describe the "why" in the PR description

## Reporting Issues

Use GitHub Issues. Include:

- Steps to reproduce
- Expected vs actual behavior
- PHP/Symfony version, Docker version
- Relevant logs or error messages

## License

By contributing, you agree that your contributions will be licensed under AGPL-3.0.
