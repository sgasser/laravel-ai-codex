# Security Policy

## Reporting Security Issues

Please do not report security issues through public GitHub issues.

Email security reports to Stefan Gasser, or use GitHub's private vulnerability reporting if it is enabled for this repository.

Include:

- affected package version or commit
- Laravel, PHP, and Laravel AI versions
- a minimal reproduction or clear attack path
- whether credentials or user-provided prompts are involved

## Security Model

This package calls the experimental OpenAI Codex endpoint from PHP. It does not require an `OPENAI_API_KEY`.

Applications are responsible for keeping Codex access tokens server-side.

For untrusted input:

- do not expose Codex access tokens to browser clients
- do not log local Codex auth JSON or bearer tokens
- do not expose exception traces to end users
- validate and authorize user requests before sending them to the provider
