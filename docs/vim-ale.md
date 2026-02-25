# Vim + ALE Integration

This guide covers integrating php-lsp with [ALE](https://github.com/dense-analysis/ale) in Vim/Neovim.

## Setup

Add the following to your `.vimrc` or `init.vim`:

```vim
" Register php-lsp as a linter for PHP files
let g:ale_linters = {
\   'php': ['php-lsp'],
\}

" Set the executable path
let g:ale_php_php_lsp_executable = '/path/to/vendor/bin/php-lsp'
```

## Custom ALE Linter Definition

If ALE doesn't recognize `php-lsp` as a built-in linter, you may need to define it manually:

```vim
call ale#linter#Define('php', {
\   'name': 'php-lsp',
\   'lsp': 'stdio',
\   'executable': '/path/to/vendor/bin/php-lsp',
\   'project_root': function('ale_linters#php#langserver#GetProjectRoot'),
\})
```

## Verifying the Connection

1. Open a PHP file
2. Run `:ALEInfo` to see the active linters and any error messages
3. Check `:messages` for LSP communication logs
