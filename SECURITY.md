# Security Policy

## Supported versions

Security fixes go to the latest release.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Use GitHub's private reporting instead: open the
[Security tab](https://github.com/HawkForge/laravel-ai-blog/security) of this repository and choose
**Report a vulnerability**. Include what you found, how to reproduce it and the version affected.

You can expect an acknowledgement within a few days and a fix or a plan once the report is confirmed.
Reports are credited in the changelog unless you ask not to be.

## Scope

This package fetches third-party URLs (`SafeFetcher`), parses untrusted feeds and pages, and exposes a
command bus and MCP tools to agents. Reports about SSRF or DNS-rebinding bypasses, robots.txt or size-cap
bypasses, prompt-injection paths from fetched content to a model, command-bus authorization or
idempotency flaws, and audit-trail gaps are especially welcome.
