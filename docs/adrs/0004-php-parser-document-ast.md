# 0004: Use php-parser for Document Structure

Status: accepted

## Decision

Parsed file structure is to use `nikic/php-parser`'s Abstract Syntax Tree (AST) and data types, regardless of the parsing mechanism.
Parsers, including syntax-invalid file recovery, are to convert into it if necessary.

Walking the AST (recovered or otherwise) to extract other facts is still to be centralized per ADR 0001.

## Context

`php-parser` is the de-facto tool in the PHP ecosystem for working with a file's AST.
It is actively maintained and does a good job handling files under different versions of PHP.
Creating custom internal data types and formats is reinventing work and offers little value.

The library, reasonably, does not perfectly handle syntax-invalid files, which are an extremely common case in the mid-edit state seen by this project.
A recovery process must still generate a well-formed AST so downstream consumers have a stable format.
