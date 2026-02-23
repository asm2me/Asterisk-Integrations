# Contributing

Thank you for considering a contribution to this project.

---

## Getting Started

1. **Fork** the repository and clone your fork locally.
2. Create a **feature branch** from `main`:
   ```bash
   git checkout -b feature/my-feature
   ```
3. Make your changes, following the coding standards below.
4. **Test** your changes manually against a real Asterisk / ViciDial instance, or with a mock.
5. Open a **pull request** against `main` with a clear description.

---

## Coding Standards

- PHP **8.0+** syntax only.
- Follow **PSR-12** code style (4-space indentation, one class per file, etc.).
- All new public methods must have a **docblock** with `@param` and `@return` types.
- Keep the module **framework-free** — no Laravel, Symfony, or any other framework dependency.
- Do not add Composer packages without discussion in an issue first.

---

## Reporting Bugs

Use the [Bug Report](.github/ISSUE_TEMPLATE/bug_report.md) issue template. Include:

- PHP version (`php --version`)
- Asterisk / ViciDial version
- The exact code that reproduces the problem
- The full error message or unexpected output

---

## Requesting Features

Use the [Feature Request](.github/ISSUE_TEMPLATE/feature_request.md) issue template. Describe the use case clearly so it can be evaluated against the project scope.

---

## Pull Request Checklist

Before submitting a PR, verify:

- [ ] Code follows PSR-12 style
- [ ] New public methods have docblocks
- [ ] No new framework dependencies introduced
- [ ] `example.php` or `example_ami.php` updated if the public API changed
- [ ] `config.php` updated if new configuration keys were added
- [ ] `README.md` updated to reflect any API or config changes

---

## Commit Messages

Use the imperative mood and keep the subject line under 72 characters:

```
Add park() support to AsteriskManager
Fix CallbackHandler not parsing JSON body
Update config.php with ami_reconnect option
```
