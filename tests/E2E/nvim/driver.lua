-- #109: headless Neovim smoke driver for rector-warm-lsp.
--
-- Run via `nvim --headless -u NONE -l driver.lua <config_dir> <target_file> <min_diagnostics>`.
--
-- `config_dir` is a directory extract_snippet.py has already populated with
-- README.md's own snippets:
--   config_dir/lsp/rector.lua   -- the native `vim.lsp.config` snippet, put
--                                  where Neovim's own lazy-loading convention
--                                  auto-discovers it once it is on 'runtimepath'
--   config_dir/enable.lua       -- the `vim.lsp.enable('rector')` snippet,
--                                  dofile'd rather than hardcoded here, so
--                                  this driver runs what the README says
--                                  today, not a copy of it
--
-- Attach -> save -> diagnostics -> codeAction, the same order the manual
-- test behind #94/#105/#116 used. Every exit path writes one JSON line to
-- stdout before quitting -- a bare Lua error on a headless run is easy to
-- lose in CI's captured output, so nothing here calls assert() without
-- reporting first.

local config_dir = arg[1]
local target_file = arg[2]
local min_diagnostics = tonumber(arg[3])

local function finish(ok, info)
  info = info or {}
  info.ok = ok
  -- headless Neovim's own ex-command output (":w" status line etc.) does
  -- not always end with a newline before the cursor returns to us, so a
  -- leading "\n" here keeps the JSON on its own line rather than glued to
  -- whatever was echoed last -- run_smoke.sh greps for a line starting
  -- with "{".
  io.write("\n" .. vim.json.encode(info) .. "\n")
  io.stdout:flush()
  vim.cmd('qall!')
end

if not config_dir or not target_file or min_diagnostics == nil then
  finish(false, { stage = 'args', error = 'usage: driver.lua <config_dir> <target_file> <min_diagnostics>' })
  return
end

vim.opt.runtimepath:prepend(config_dir)

-- `-u NONE` (deliberately: no user config, no plugins) also skips Neovim's
-- own filetype detection, so `vim.bo.filetype` for a freshly `:edit`ed .php
-- file would stay empty and vim.lsp.enable's FileType autocmd would never
-- fire -- silently, with no error anywhere. `filetype on` restores just the
-- built-in detection, nothing user-supplied.
vim.cmd('filetype on')

local ok_enable, enable_err = pcall(dofile, config_dir .. '/enable.lua')
if not ok_enable then
  finish(false, { stage = 'enable', error = tostring(enable_err) })
  return
end

vim.cmd.edit(target_file)
local bufnr = vim.api.nvim_get_current_buf()

local attached = vim.wait(15000, function()
  return #vim.lsp.get_clients({ bufnr = bufnr, name = 'rector' }) > 0
end, 100)

if not attached then
  finish(false, { stage = 'attach', error = 'no rector client attached to ' .. target_file .. ' within 15s' })
  return
end

local ok_write, write_err = pcall(vim.cmd.write)
if not ok_write then
  finish(false, { stage = 'save', error = tostring(write_err) })
  return
end

-- Diagnostics arrive asynchronously over publishDiagnostics. Poll for the
-- floor this file is expected to reach, but a file expected to reach 0
-- (min_diagnostics == 0) would make that condition true immediately without
-- ever actually waiting -- so give every file the same fixed settle window
-- below regardless of which floor it is polling for. That is the
-- negative-assertion / positive-control rule: "no diagnostics arrived" must
-- mean the file is clean, not that nothing was given time to arrive.
vim.wait(5000, function()
  return #vim.diagnostic.get(bufnr) >= min_diagnostics and min_diagnostics > 0
end, 100)
vim.wait(1500, function() return false end)

local diagnostics = vim.diagnostic.get(bufnr)

local has_code_action = false
if #diagnostics > 0 then
  local client = vim.lsp.get_clients({ bufnr = bufnr, name = 'rector' })[1]
  local params = vim.lsp.util.make_range_params(0, client and client.offset_encoding or 'utf-16')
  params.context = { diagnostics = diagnostics }
  local responses = vim.lsp.buf_request_sync(bufnr, 'textDocument/codeAction', params, 5000)
  if responses then
    for _, response in pairs(responses) do
      if response.result and #response.result > 0 then
        has_code_action = true
      end
    end
  end
end

finish(true, {
  diagnostic_count = #diagnostics,
  has_code_action = has_code_action,
})
