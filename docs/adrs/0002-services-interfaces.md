# 0002: Services Defined by Interfaces and Provided via DI

Status: accepted

## Decision

A service must be backed by an interface, and reached only through that interface via dependency injection.
Service construction (outside of tests) is to be done only in the project's DI container, and the DI container is the only place that determines which implementation powers any given interface.

## Context

All of the rationale behind SOLID design:

- Loosely-coupled code
- Services are testable and tests can leverage mocks and stubs
- Dependencies are clearly visible
