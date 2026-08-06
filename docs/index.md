# Documentation

`te4g/starter` is a one-shot code generator for fresh Symfony 8.1 skeleton and webapp projects. Start with the [README](../README.md) for requirements, installation, the database boundary, and development gates.

- [Customization](customization.md) explains how to adapt the generated application-owned workflows and UI.
- [Security operations](security.md) records the boundaries to preserve around neutral responses, rate limits, signed links, sessions, deletion, and logs.
- [Starter design](superpowers/specs/2026-08-06-symfony-starter-design.md) and the [generator implementation plan](superpowers/plans/2026-08-06-symfony-starter-generator.md) provide historical design context.
- [Symfony template integration plan](superpowers/plans/2026-08-06-symfony-template-starter-integration.md) describes the separate thin wrapper for the template repository.

All generated files use the `App\` namespace and belong to the host application. Remove `te4g/starter` after generation; there is no runtime bundle configuration, Flex recipe, installation manifest, or supported rerun path.
