# Contributing

This repository follows a strict, test-driven style. Contributions are expected to preserve the existing architecture boundaries and keep the codebase highly unit-testable.

## Code Rules

### Boundaries

- `src/Command` contains entrypoints only (Symfony Console commands and minimal wiring).
- Domain and infrastructure code lives outside `src/Command` in dedicated namespaces/directories (feature-oriented).
- Classes follow the Single Responsibility Principle: one class, one reason to change.

### Dependency Inversion

- Any class with non-trivial logic depends on interfaces (ports), not concrete implementations.
- External effects are behind interfaces (time, process execution, scheduling/event loop, HTTP, output streams, metrics sinks).
- Logic classes do not instantiate collaborators (no `new` for other services inside logic); composition happens in Symfony DI.

### PHP Conventions

- `declare(strict_types=1);` is required in every PHP file.
- Prefer `final` classes and `readonly` dependencies by default.
- Interfaces use the `Interface` suffix.
- PSR-4 autoloading is used; namespaces mirror directory structure.

### Documentation

- `README.md` is kept up to date with any changes to configuration, runtime behavior, metrics, or public commands/endpoints.

## Testing Rules

- Every class that contains logic has a unit test.
- Unit tests are the default; end-to-end tests exist only for integration boundaries (Symfony wiring, signals, HTTP endpoints, real subprocess behavior).
- Unit tests do not rely on wall-clock delays; time is controlled via injected abstractions.
- Tests are hermetic and clean up after themselves; no reliance on committed/generated runtime artifacts.

## Required Composer Scripts

The following scripts must pass before a change is considered ready:

- `composer cs` (coding standards)
- `composer check` (static analysis + tests)

Helpful locally:

- `composer cs-fix` (auto-fix coding standards)
- `composer analyse` (PHPStan only)
- `composer test` (PHPUnit only)
